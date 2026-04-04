<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\Exception\KosmokratorException;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\SafeDisplay;
use Psr\Log\LoggerInterface;

/**
 * Central coordinator for LLM context window management.
 *
 * Monitors token usage via ContextBudget, triggers ContextPruner for lightweight
 * micro-pruning and ContextCompactor for full summarisation when limits are approached.
 * Also assembles the system prompt by combining the base prompt with injected memories
 * (MemoryInjector), mode-specific suffixes, parent briefs, and active tasks.
 */
final class ContextManager
{
    private int $consecutiveCompactionFailures = 0;

    /** @var array<string, int|float|string|bool> */
    private array $lastBudgetSnapshot = [];

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
     * Check context pressure before an LLM call and intervene if needed.
     *
     * Runs pruning and/or compaction when the token budget is above warning
     * thresholds. Includes a circuit breaker that disables auto-compaction
     * after 3 consecutive failures, falling back to trimOldest instead.
     *
     * @param  ConversationHistory  $history  The conversation to inspect and possibly mutate
     * @param  AgentMode  $mode  Current agent mode, used for prompt estimation
     * @param  AgentContext|null  $agentContext  Optional sub-agent context for prompt estimation
     * @return array{int, int} [tokens_in, tokens_out] consumed by any compaction LLM calls
     */
    public function preFlightCheck(ConversationHistory $history, AgentMode $mode = AgentMode::Edit, ?AgentContext $agentContext = null): array
    {
        // Nothing to do without a compactor or pruner
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

            // Try lightweight pruning first
            if ($this->pruner !== null) {
                $saved = $this->pruner->prune($history);
                if ($saved > 0) {
                    $this->log->info('Pre-flight micro-prune applied', ['tokens_saved' => $saved]);
                    // Re-check after pruning — it may have brought us below thresholds
                    $snapshot = $this->snapshot($history, $mode, $agentContext);
                }
            }

            if (! $snapshot['is_above_warning']) {
                $this->resetCircuitBreakerIfHealthy();

                return [0, 0];
            }

            // Circuit breaker: stop attempting compaction after repeated failures
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

            // Last resort: drop oldest messages when at the hard blocking limit
            if ($snapshot['is_at_blocking_limit']) {
                $history->trimOldest();
            }

            return [0, 0];
        } catch (KosmokratorException $e) {
            $this->log->warning('Pre-flight check failed', ['error' => $e->getMessage()]);

            return [0, 0];
        } catch (\Throwable $e) {
            $this->log->error('Pre-flight check failed unexpectedly', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return [0, 0];
        }
    }

    /**
     * Headless variant of preFlightCheck() — identical behaviour but named for
     * call-sites that run outside the interactive TUI loop.
     *
     * @param  ConversationHistory  $history  The conversation to inspect and possibly mutate
     * @param  AgentMode  $mode  Current agent mode
     * @param  AgentContext|null  $agentContext  Optional sub-agent context
     * @return array{int, int} [tokens_in, tokens_out] consumed by any compaction LLM calls
     */
    public function headlessPreFlightCheck(ConversationHistory $history, AgentMode $mode = AgentMode::Edit, ?AgentContext $agentContext = null): array
    {
        return $this->preFlightCheck($history, $mode, $agentContext);
    }

    /**
     * Compact the conversation history by summarising older messages via the LLM.
     *
     * Builds a compaction plan that preserves protected messages, applies it to
     * the history, stores the summary as a working memory, and extracts durable
     * memories from the summary for future sessions.
     *
     * @param  ConversationHistory  $history  The conversation to compact
     * @param  AgentMode  $mode  Current agent mode
     * @param  AgentContext|null  $agentContext  Optional sub-agent context
     * @return array{int, int} [tokens_in, tokens_out] consumed by compaction + memory extraction
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
            $cancellation = $agentContext?->cancellation;
            $plan = $this->compactor->buildPlan($history, $protectedMessages, cancellation: $cancellation);
            $tokensIn = $plan->tokensIn;
            $tokensOut = $plan->tokensOut;

            if ($plan->isEmpty()) {
                SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
                SafeDisplay::call(fn () => $this->ui->showNotice('Nothing to compact.'), $this->log);

                return [$tokensIn, $tokensOut];
            }

            // Persist the plan so a resumed session can replay compaction
            $this->sessionManager?->persistCompactionPlan($plan);
            $history->applyCompactionPlan($plan);

            // Store the summary as a short-lived working memory for context continuity
            if ($this->sessionManager !== null) {
                $title = mb_substr($plan->summary, 0, 80);
                $expiresAt = date('c', time() + (14 * 86400));
                $this->sessionManager->addMemory('compaction', $title, $plan->summary, 'working', false, $expiresAt);
            }

            // Extract durable memories (facts, decisions) from the compaction summary
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
                // Merge duplicate or overlapping memories after extraction
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
        } catch (KosmokratorException $e) {
            SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
            $this->consecutiveCompactionFailures++;
            $messagesBefore = count($history->messages());
            $history->trimOldest();
            $this->log->warning('Compaction failed with known exception', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'messages_before' => $messagesBefore,
                'messages_after' => count($history->messages()),
                'consecutive_failures' => $this->consecutiveCompactionFailures,
            ]);

            return [0, 0];
        } catch (\Throwable $e) {
            SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
            $this->consecutiveCompactionFailures++;
            $messagesBefore = count($history->messages());
            // Fallback: drop the oldest message to free up space
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

    /**
     * Rebuild and push the full system prompt to the LLM client.
     *
     * @param  AgentMode  $mode  Current agent mode
     * @param  ConversationHistory|null  $history  Used to derive the user query for memory recall
     * @param  AgentContext|null  $agentContext  Optional sub-agent context providing a parent brief
     */
    public function refreshSystemPrompt(AgentMode $mode, ?ConversationHistory $history = null, ?AgentContext $agentContext = null): void
    {
        $this->llm->setSystemPrompt($this->buildSystemPrompt($mode, $history, $agentContext));
    }

    /**
     * Assemble the complete system prompt from all context sources.
     *
     * Layers: base prompt → relevant memories → session recall → mode suffix →
     * parent brief → active tasks.
     *
     * @param  AgentMode  $mode  Current agent mode
     * @param  ConversationHistory|null  $history  Used to derive the user query for memory recall
     * @param  AgentContext|null  $agentContext  Optional sub-agent context providing a parent brief
     * @param  bool  $markSurfacedMemories  Whether to flag injected memories as surfaced (prevents re-injection)
     */
    public function buildSystemPrompt(
        AgentMode $mode,
        ?ConversationHistory $history = null,
        ?AgentContext $agentContext = null,
        bool $markSurfacedMemories = true,
    ): string {
        $query = $history?->latestUserContext();
        $prompt = $this->baseSystemPrompt;

        // Inject relevant memories and session recall when the feature is enabled
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
     * Check whether the conversation has grown past the auto-compaction threshold.
     *
     * @param  ConversationHistory  $history  The conversation to evaluate
     * @param  AgentMode  $mode  Current agent mode
     * @param  AgentContext|null  $agentContext  Optional sub-agent context
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

    /**
     * Produce a budget snapshot: token counts, thresholds, and boolean flags
     * indicating whether compaction or pruning should be triggered.
     *
     * @return array<string, int|float|string|bool>
     */
    private function snapshot(ConversationHistory $history, AgentMode $mode, ?AgentContext $agentContext): array
    {
        $estimated = $this->estimateContextTokens($history, $mode, $agentContext);
        $model = $this->getModelName();
        if ($this->budget !== null) {
            $snapshot = $this->budget->snapshot($estimated, $model);
        } else {
            // Fallback: derive thresholds from the compactor or 80% of the context window
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

    /** Estimate total token count for the system prompt plus all conversation messages. */
    private function estimateContextTokens(ConversationHistory $history, AgentMode $mode, ?AgentContext $agentContext): int
    {
        // Suppress memory surface-marking during estimation to avoid side effects
        $prompt = $this->buildSystemPrompt($mode, $history, $agentContext, false);

        return TokenEstimator::estimate($prompt) + TokenEstimator::estimateMessages($history->messages());
    }

    /** Reset the compaction circuit breaker when context pressure has returned to normal. */
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
