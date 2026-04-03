<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use Psr\Log\LoggerInterface;

/**
 * Creates all context-management components (budget, compactor, pruner,
 * deduplicator, truncator, protected context builder) from config and session settings.
 */
final class ContextPipelineFactory
{
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly ModelCatalog $models,
        private readonly TaskStore $taskStore,
        private readonly LoggerInterface $log,
        private readonly array $config,
    ) {}

    /**
     * Build the full context pipeline from config and persisted session settings.
     */
    public function create(LlmClientInterface $llm): ContextPipeline
    {
        $contextBudget = new ContextBudget(
            models: $this->models,
            reserveOutputTokens: $this->setting('context_reserve_output_tokens',
                $this->config['context']['reserve_output_tokens'] ?? 16_000),
            warningBufferTokens: $this->setting('context_warning_buffer_tokens',
                $this->config['context']['warning_buffer_tokens'] ?? 24_000),
            autoCompactBufferTokens: $this->setting('context_auto_compact_buffer_tokens',
                $this->config['context']['auto_compact_buffer_tokens'] ?? 12_000),
            blockingBufferTokens: $this->setting('context_blocking_buffer_tokens',
                $this->config['context']['blocking_buffer_tokens'] ?? 3_000),
        );

        $autoCompactEnabled = ($this->sessionManager->getSetting('auto_compact') ?? 'on') !== 'off';
        $compactThreshold = (int) ($this->sessionManager->getSetting('compact_threshold')
            ?? $this->config['context']['compact_threshold'] ?? 60);
        $compactor = $autoCompactEnabled
            ? new ContextCompactor($llm, $this->models, $this->log, $compactThreshold, $contextBudget)
            : null;

        $truncator = new OutputTruncator(
            maxLines: (int) ($this->config['context']['max_output_lines'] ?? 2000),
            maxBytes: (int) ($this->config['context']['max_output_bytes'] ?? 50_000),
        );

        $pruneProtect = (int) ($this->sessionManager->getSetting('prune_protect')
            ?? $this->config['context']['prune_protect'] ?? 40_000);
        $pruneMinSavings = (int) ($this->sessionManager->getSetting('prune_min_savings')
            ?? $this->config['context']['prune_min_savings'] ?? 20_000);
        $pruner = new ContextPruner($pruneProtect, $pruneMinSavings);

        $deduplicator = new ToolResultDeduplicator;
        $protectedContextBuilder = new ProtectedContextBuilder($this->taskStore);

        return new ContextPipeline(
            budget: $contextBudget,
            compactor: $compactor,
            pruner: $pruner,
            deduplicator: $deduplicator,
            truncator: $truncator,
            protectedContextBuilder: $protectedContextBuilder,
        );
    }

    /**
     * Resolve an integer setting from session storage with a config fallback.
     */
    private function setting(string $key, int|string $default): int
    {
        return (int) ($this->sessionManager->getSetting($key) ?? $default);
    }
}
