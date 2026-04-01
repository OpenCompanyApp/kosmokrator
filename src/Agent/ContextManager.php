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
 * Manages context window health: pre-flight checks, LLM-based compaction,
 * and system prompt refresh.
 *
 * All methods that modify conversation history receive it as a parameter
 * and return token costs rather than mutating shared state.
 */
final class ContextManager
{
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
    ) {}

    /**
     * Check if context is approaching limits and compact/trim proactively.
     *
     * @return array{int, int} [tokensIn, tokensOut] consumed by compaction (zero if no action taken)
     */
    public function preFlightCheck(ConversationHistory $history): array
    {
        if ($this->compactor === null && $this->pruner === null) {
            return [0, 0];
        }

        try {
            $estimated = TokenEstimator::estimateMessages($history->messages());
            $modelName = $this->getModelName();

            // Use compactor's configurable threshold; fall back to 80% for pruner-only mode
            if ($this->compactor !== null) {
                $threshold = $this->compactor->getThresholdTokens($modelName);
            } else {
                $threshold = (int) ($this->getContextWindow() * 0.8);
            }

            if ($estimated < $threshold) {
                return [0, 0];
            }

            $this->log->info('Pre-flight context check: estimated tokens exceed threshold', [
                'estimated' => $estimated,
                'threshold' => $threshold,
            ]);

            if ($this->compactor !== null) {
                return $this->performCompaction($history);
            }

            $history->trimOldest();

            return [0, 0];
        } catch (\Throwable $e) {
            $this->log->error('Pre-flight check failed', ['error' => $e->getMessage()]);

            return [0, 0];
        }
    }

    /**
     * Simplified pre-flight check for headless mode — only trim, never compact.
     */
    public function headlessPreFlightCheck(ConversationHistory $history): void
    {
        if ($this->pruner === null) {
            return;
        }

        $estimated = TokenEstimator::estimateMessages($history->messages());
        $threshold = (int) ($this->getContextWindow() * 0.8);

        if ($estimated >= $threshold) {
            $messagesBefore = count($history->messages());
            $history->trimOldest();
            $this->log->info('Headless pre-flight trim', [
                'estimated_tokens' => $estimated,
                'threshold' => $threshold,
                'messages_before' => $messagesBefore,
                'messages_after' => count($history->messages()),
            ]);
        }
    }

    /**
     * Run LLM-based compaction, persist result, extract memories.
     *
     * @return array{int, int} [tokensIn, tokensOut] consumed by compaction + extraction
     */
    public function performCompaction(ConversationHistory $history): array
    {
        if ($this->compactor === null) {
            $this->log->warning('Compaction requested but no compactor configured');

            return [0, 0];
        }

        $this->log->info('Starting context compaction');
        SafeDisplay::call(fn () => $this->ui->showCompacting(), $this->log);

        try {
            $result = $this->compactor->compact($history);
            $summary = $result['summary'];

            $tokensIn = $result['tokens_in'];
            $tokensOut = $result['tokens_out'];

            if ($summary === '') {
                SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
                SafeDisplay::call(fn () => $this->ui->showNotice('Nothing to compact.'), $this->log);

                return [$tokensIn, $tokensOut];
            }

            // Persist compaction to database
            $this->sessionManager?->persistCompaction($summary);

            // In-memory: replace old messages with summary
            $history->compact($summary);

            // Save compaction summary as memory
            if ($this->sessionManager !== null) {
                $title = mb_substr($summary, 0, 80);
                $this->sessionManager->addMemory('compaction', $title, $summary);
            }

            // Extract durable memories from summary (best-effort)
            $extraction = $this->compactor->extractMemories($summary);

            $tokensIn += $extraction['tokens_in'];
            $tokensOut += $extraction['tokens_out'];

            if ($this->sessionManager !== null) {
                foreach ($extraction['memories'] as $item) {
                    $this->sessionManager->addMemory($item['type'], $item['title'], $item['content']);
                }
            }

            SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
            SafeDisplay::call(fn () => $this->ui->showNotice('Context compacted.'), $this->log);
            $this->log->info('Compaction complete', [
                'memories_extracted' => count($extraction['memories']),
                'messages_after' => count($history->messages()),
                'compaction_tokens_in' => $result['tokens_in'],
                'compaction_tokens_out' => $result['tokens_out'],
                'summary_length' => strlen($summary),
            ]);

            return [$tokensIn, $tokensOut];
        } catch (\Throwable $e) {
            SafeDisplay::call(fn () => $this->ui->clearCompacting(), $this->log);
            $messagesBefore = count($history->messages());
            $history->trimOldest();
            $this->log->error('Compaction failed, falling back to trimOldest', [
                'error' => $e->getMessage(),
                'messages_before' => $messagesBefore,
                'messages_after' => count($history->messages()),
            ]);

            return [0, 0];
        }
    }

    /**
     * Rebuild the system prompt with mode suffix and task tree.
     */
    public function refreshSystemPrompt(AgentMode $mode): void
    {
        $prompt = $this->baseSystemPrompt.$mode->systemPromptSuffix();

        if ($this->taskStore !== null && ! $this->taskStore->isEmpty()) {
            $prompt .= "\n\n## Current Tasks\n".$this->taskStore->renderTree();
        }

        $this->llm->setSystemPrompt($prompt);
    }

    /**
     * Get the combined provider/model name.
     */
    public function getModelName(): string
    {
        return $this->llm->getProvider().'/'.$this->llm->getModel();
    }

    /**
     * Get the context window size for the current model.
     */
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
}
