<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\ModelCatalog;

/**
 * Calculates context window thresholds: warning, auto-compact, and blocking limits.
 *
 * Each threshold is derived from the model's context window minus a configurable buffer.
 * Used by ContextManager to decide when to prune or compact. The snapshot() method
 * returns a single array with all threshold flags for a given estimated token count.
 */
final class ContextBudget
{
    public function __construct(
        private readonly ?ModelCatalog $models,
        private readonly int $reserveOutputTokens = 0,
        private readonly int $warningBufferTokens = 0,
        private readonly int $autoCompactBufferTokens = 0,
        private readonly int $blockingBufferTokens = 0,
    ) {}

    /** Raw model context window size (tokens). */
    public function contextWindow(string $model): int
    {
        return $this->models?->contextWindow($model) ?? 200_000;
    }

    /** Context window minus reserved output tokens — the usable input budget. */
    public function effectiveContextWindow(string $model): int
    {
        return max(1, $this->contextWindow($model) - max(0, $this->reserveOutputTokens));
    }

    /**
     * Token count at which a warning should be logged.
     */
    public function warningThreshold(string $model): int
    {
        return max(1, $this->effectiveContextWindow($model) - max(0, $this->warningBufferTokens));
    }

    /**
     * Token count at which auto-compaction should trigger.
     */
    public function autoCompactThreshold(string $model): int
    {
        return max(1, $this->effectiveContextWindow($model) - max(0, $this->autoCompactBufferTokens));
    }

    /**
     * Token count at which the context is considered full and action is mandatory.
     */
    public function blockingThreshold(string $model): int
    {
        return max(1, $this->effectiveContextWindow($model) - max(0, $this->blockingBufferTokens));
    }

    /**
     * Compute a budget snapshot with all thresholds and boolean flags for the given token estimate.
     *
     * @param  int  $estimatedTokens  Current estimated token usage
     * @param  string  $model  Model identifier for context-window lookup
     * @return array{
     *   estimated_tokens:int,
     *   context_window:int,
     *   effective_window:int,
     *   warning_threshold:int,
     *   auto_compact_threshold:int,
     *   blocking_threshold:int,
     *   percent_left:int,
     *   is_above_warning:bool,
     *   is_above_auto_compact:bool,
     *   is_at_blocking_limit:bool
     * }
     */
    public function snapshot(int $estimatedTokens, string $model): array
    {
        $effective = $this->effectiveContextWindow($model);
        $warning = $this->warningThreshold($model);
        $autoCompact = $this->autoCompactThreshold($model);
        $blocking = $this->blockingThreshold($model);
        $percentLeft = max(0, (int) round((($effective - $estimatedTokens) / $effective) * 100));

        return [
            'estimated_tokens' => $estimatedTokens,
            'context_window' => $this->contextWindow($model),
            'effective_window' => $effective,
            'warning_threshold' => $warning,
            'auto_compact_threshold' => $autoCompact,
            'blocking_threshold' => $blocking,
            'percent_left' => $percentLeft,
            'is_above_warning' => $estimatedTokens >= $warning,
            'is_above_auto_compact' => $estimatedTokens >= $autoCompact,
            'is_at_blocking_limit' => $estimatedTokens >= $blocking,
        ];
    }
}
