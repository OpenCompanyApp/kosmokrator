<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\SafeDisplay;
use Psr\Log\LoggerInterface;

/**
 * Manages the conversation context window: pre-flight checks, auto-compaction, pruning, and system prompt assembly.
 *
 * Called before each LLM turn by AgentLoop. Delegates to ContextCompactor (LLM-based summarization),
 * ContextPruner (fast token recovery), ContextBudget (threshold calculation), and ProtectedContextBuilder
 * (preserved system messages). Maintains a circuit breaker that disables auto-compaction after 3 consecutive failures.
 */
final class ContextManager
{
    private int $consecutiveCompactionFailures = 0;

    /** @var array<string, int|float|string|bool> */
    private array $lastBudgetSnapshot = [];

    /**
     * @param  string  $baseSystemPrompt  The core instruction prompt loaded from config
     * @param  int  $memoryInjectLimit  Max memories to inject into the system prompt
     * @param  int  $sessionRecallLimit  Max past sessions to recall via semantic search
     */
    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly RendererInterface $ui,
        private readonly LoggerInterface $log,
        private readonly string $baseSystemPrompt,
        private readonly ?ContextCompactor $compactor,
        private readonly ?ContextPruner $pruner,
        private readonly ?ModelCatalog $models,
        private readonly ?SessionManager $sessionManager,
        private readonly ?TaskStore $taskStore,
        private readonly ?ContextBudget $budget = null,
        private readonly ?ProtectedContextBuilder $protectedContextBuilder = null,
        private readonly int $memoryInjectLimit = 6,
        private readonly int $sessionRecallLimit = 3,
    ) {}

    /**
     * Pre-flight context window check — runs before each LLM turn.
     * Tries micro-pruning first, then LLM-based compaction if still over threshold.
     * Returns [tokensIn, tokensOut] from any compaction LLM calls.
     *
     * @return array{int, int} [compaction_tokens_in, compaction_tokens_out]
     */
    public function preFlightCheck(ConversationHistory $history, AgentMode $mode = AgentMode::Edit, ?AgentContext $agentContext = null): array
    {
        if ($this->compactor === null && $this->pruner === null) {
            return [0, 0];
        }

        try {
            $snapshot = $this->snapshot($history, $mode, $agentContext);
            if (! $snapshot['is_above_warning']) {
                $this->resetCircuitBreakerIfHealthy();

                return [0, 0];
            }

            $this->log->info('Pre-flight context warning', $snapshot);

            if ($this->pruner !== null) {
                $saved = $this->pruner->prune($history);
                if ($saved > 0) {
                    $this->log->info('Pre-flight micro-prune applied', ['tokens_saved' => $saved]);
                    $snapshot = $this->snapshot($history, $mode, $agentContext);
                }
            }

            if (! $snapshot['is_above_warning']) {
                $this->resetCircuitBreakerIfHealthy();

                return [0, 0];
            }

            if ($this->consecutiveCompactionFailures >= 3) {
                $this->log->warning('Auto-compaction circuit breaker active', [
                    'consecutive_failures' => $this->consecutiveCompactionFailures,
                ]);
                if ($snapshot['is_at_blocking_limit']) {
                    $history->trimOldest();
                }

                return [0, 0];
            }

            if ($this->compactor !== null && ($snapshot['is_above_auto_compact'] || $snapshot['is_at_blocking_limit'])) {
                return $this->performCompaction($history, $mode, $agentContext);
            }

            if ($snapshot['is_at_blocking_limit']) {
                $history->trimOldest();
            }

            return [0, 0];
        } catch (\Throwable $e) {
            $this->log->error('Pre-flight check failed', ['error' => $e->getMessage()]);

            return [0, 0];
        }
    }

    /** Pre-flight check for headless (subagent) loops — same logic as preFlightCheck. */
    public function headlessPreFlightCheck(ConversationHistory $history, AgentMode $mode = AgentMode::Edit, ?AgentContext $agentContext = null): array
    {
        return $this->preFlightCheck($history, $mode, $agentContext);
    }

    /**
     * Perform LLM-based context compaction: summarize old messages, extract memories, persist results.
     *
     * @return array{int, int} [compaction_tokens_in, compaction_tokens_out]
     */
    public function performCompaction(ConversationHistory $history, AgentMode $mode = AgentMode::Edit, ?AgentContext $agentContext = null): array
    {
        if ($this->compactor === null) {
            $this->log->warning('Compaction requested but no compactor configured');

            return [0, 0];
        }

        $this->log->info('Starting context compaction');
        SafeDisplay::call(fn () => $this->ui->showCompacting(), $this->log);

        try {
            $protectedMessages = $this->protectedContextBuilder?->build($mode, $agentContext) ?? [];
            $plan = $this->compactor->buildPlan($history, $protectedMessages);
            $tokensIn = $plan->tokensIn;
            $tokensOut = $plan->tokensOut;

            if ($plan->isEmpty()) {
                SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
                SafeDisplay::call(fn () => $this->ui->showNotice('Nothing to compact.'), $this->log);

                return [$tokensIn, $tokensOut];
            }

            $this->sessionManager?->persistCompactionPlan($plan);
            $history->applyCompactionPlan($plan);

            if ($this->sessionManager !== null) {
                $title = mb_substr($plan->summary, 0, 80);
                $expiresAt = date('c', time() + (14 * 86400));
                $this->sessionManager->addMemory('compaction', $title, $plan->summary, 'working', false, $expiresAt);
            }

            $extraction = $this->compactor->extractMemories($plan->summary);
            $tokensIn += $extraction['tokens_in'];
            $tokensOut += $extraction['tokens_out'];

            if ($this->sessionManager !== null) {
                foreach ($extraction['memories'] as $item) {
                    $this->sessionManager->addMemory(
                        $item['type'],
                        $item['title'],
                        $item['content'],
                        $item['memory_class'] ?? 'durable',
                        (bool) ($item['pinned'] ?? false),
                    );
                }
                $this->sessionManager->consolidateMemories();
            }

            $this->consecutiveCompactionFailures = 0;

            SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
            SafeDisplay::call(fn () => $this->ui->showNotice('Context compacted.'), $this->log);
            $this->log->info('Compaction complete', [
                'memories_extracted' => count($extraction['memories']),
                'messages_after' => count($history->messages()),
                'compaction_tokens_in' => $plan->tokensIn,
                'compaction_tokens_out' => $plan->tokensOut,
                'summary_length' => strlen($plan->summary),
                'protected_messages' => count($plan->protectedMessages),
            ]);

            return [$tokensIn, $tokensOut];
        } catch (\Throwable $e) {
            SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
            $this->consecutiveCompactionFailures++;
            $messagesBefore = count($history->messages());
            $history->trimOldest();
            $this->log->error('Compaction failed, falling back to trimOldest', [
                'error' => $e->getMessage(),
                'messages_before' => $messagesBefore,
                'messages_after' => count($history->messages()),
                'consecutive_failures' => $this->consecutiveCompactionFailures,
            ]);

            return [0, 0];
        }
    }

    /** Push the rebuilt system prompt (with memories, mode suffix, tasks) to the LLM client. */
    public function refreshSystemPrompt(AgentMode $mode, ?ConversationHistory $history = null, ?AgentContext $agentContext = null): void
    {
        $this->llm->setSystemPrompt($this->buildSystemPrompt($mode, $history, $agentContext));
    }

    /**
     * Assemble the full system prompt: base instructions + relevant memories + session recall + mode suffix + tasks + parent brief.
     *
     * @param  bool  $markSurfacedMemories  Whether to tag injected memories as "surfaced" for deduplication
     */
    public function buildSystemPrompt(
        AgentMode $mode,
        ?ConversationHistory $history = null,
        ?AgentContext $agentContext = null,
        bool $markSurfacedMemories = true,
    ): string
    {
        $query = $history?->latestUserContext();
        $prompt = $this->baseSystemPrompt;

        if ($this->sessionManager !== null && ($this->sessionManager->getSetting('memories') ?? 'on') !== 'off') {
            $memories = $this->sessionManager->selectRelevantMemories($query, $this->memoryInjectLimit, $markSurfacedMemories);
            $prompt .= MemoryInjector::format($memories);

            if ($query !== '') {
                $recall = $this->sessionManager->searchSessionHistory($query, $this->sessionRecallLimit);
                $prompt .= MemoryInjector::formatSessionRecall($recall);
            }
        }

        $prompt .= $mode->systemPromptSuffix();

        if ($agentContext !== null && $agentContext->task !== '') {
            $prompt .= "\n\n## Parent Brief\n".$agentContext->task;
        }

        if ($this->taskStore !== null && ! $this->taskStore->isEmpty()) {
            $prompt .= "\n\n## Current Tasks\n".$this->taskStore->renderTree();
        }

        return $prompt;
    }

    /**
     * Check whether the conversation history exceeds the auto-compact threshold.
     */
    public function shouldCompactHistory(ConversationHistory $history, AgentMode $mode = AgentMode::Edit, ?AgentContext $agentContext = null): bool
    {
        $snapshot = $this->snapshot($history, $mode, $agentContext);

        return $snapshot['is_above_auto_compact'];
    }

    public function getModelName(): string
    {
        return $this->llm->getProvider().'/'.$this->llm->getModel();
    }

    public function getContextWindow(): int
    {
        if ($this->models !== null) {
            return $this->models->contextWindow($this->llm->getModel());
        }

        return 200_000;
    }

    public function getCompactor(): ?ContextCompactor
    {
        return $this->compactor;
    }

    public function getPruner(): ?ContextPruner
    {
        return $this->pruner;
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    public function getLastBudgetSnapshot(): array
    {
        return $this->lastBudgetSnapshot;
    }

    /** Build a budget snapshot with threshold flags, used to decide compaction/pruning strategy. */
    private function snapshot(ConversationHistory $history, AgentMode $mode, ?AgentContext $agentContext): array
    {
        $estimated = $this->estimateContextTokens($history, $mode, $agentContext);
        $model = $this->getModelName();
        if ($this->budget !== null) {
            $snapshot = $this->budget->snapshot($estimated, $model);
        } else {
            $threshold = $this->compactor?->getThresholdTokens($model) ?? (int) ($this->getContextWindow() * 0.8);
            $snapshot = [
                'estimated_tokens' => $estimated,
                'context_window' => $this->getContextWindow(),
                'effective_window' => $this->getContextWindow(),
                'warning_threshold' => $threshold,
                'auto_compact_threshold' => $threshold,
                'blocking_threshold' => $this->getContextWindow(),
                'percent_left' => max(0, (int) round((($this->getContextWindow() - $estimated) / $this->getContextWindow()) * 100)),
                'is_above_warning' => $estimated >= $threshold,
                'is_above_auto_compact' => $estimated >= $threshold,
                'is_at_blocking_limit' => false,
            ];
        }
        $snapshot['consecutive_failures'] = $this->consecutiveCompactionFailures;
        $this->lastBudgetSnapshot = $snapshot;

        return $snapshot;
    }

    /**
     * Estimate total tokens the next LLM call will consume (system prompt + conversation messages).
     */
    private function estimateContextTokens(ConversationHistory $history, AgentMode $mode, ?AgentContext $agentContext): int
    {
        $prompt = $this->buildSystemPrompt($mode, $history, $agentContext, false);

        return TokenEstimator::estimate($prompt) + TokenEstimator::estimateMessages($history->messages());
    }

    /**
     * Reset the compaction failure counter once context pressure drops below the warning threshold.
     */
    private function resetCircuitBreakerIfHealthy(): void
    {
        if ($this->consecutiveCompactionFailures <= 0) {
            return;
        }

        $this->log->info('Reset auto-compaction circuit breaker after context pressure dropped', [
            'previous_failures' => $this->consecutiveCompactionFailures,
        ]);
        $this->consecutiveCompactionFailures = 0;
    }
}
