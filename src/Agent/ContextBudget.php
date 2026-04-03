<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\ModelCatalog;

/**
 * Calculates context-window budget thresholds for conversation compaction.
 *
 * Uses ModelCatalog to determine the raw context window size, then derives
 * warning, auto-compact, and blocking thresholds based on configured token
 * buffers. Consumed by the agent loop to decide when to compact history.
 *
 * @see ModelCatalog For raw context-window sizes per model
 * @see CompactionPlan The plan produced when a threshold is crossed
 */
final class ContextBudget
{
    /**
     * @param  ModelCatalog|null  $models  Catalog of model capabilities; falls back to 200k when null
     * @param  int  $reserveOutputTokens  Tokens reserved for the LLM's response generation
     * @param  int  $warningBufferTokens  Buffer subtracted from effective window for the warning threshold
     * @param  int  $autoCompactBufferTokens  Buffer subtracted from effective window for the auto-compact threshold
     * @param  int  $blockingBufferTokens  Buffer subtracted from effective window for the hard blocking threshold
     */
    public function __construct(
        private readonly ?ModelCatalog $models,
        private readonly int $reserveOutputTokens = 0,
        private readonly int $warningBufferTokens = 0,
        private readonly int $autoCompactBufferTokens = 0,
        private readonly int $blockingBufferTokens = 0,
    ) {}

    /**
     * Get the raw context-window size for the given model.
     *
     * @param  string  $model  Model identifier used to look up capabilities
     * @return int Context window in tokens (defaults to 200 000)
     */
    public function contextWindow(string $model): int
    {
        return $this->models?->contextWindow($model) ?? 200_000;
    }

    /**
     * Context window minus the output-reservation tokens.
     *
     * @param  string  $model  Model identifier
     * @return int Usable input token budget (always ≥ 1)
     */
    public function effectiveContextWindow(string $model): int
    {
        return max(1, $this->contextWindow($model) - max(0, $this->reserveOutputTokens));
    }

    /**
     * Token count at which a low-context warning should be emitted.
     *
     * @param  string  $model  Model identifier
     * @return int Warning threshold in tokens
     */
    public function warningThreshold(string $model): int
    {
        return max(1, $this->effectiveContextWindow($model) - max(0, $this->warningBufferTokens));
    }

    /**
     * Token count at which automatic compaction should be triggered.
     *
     * @param  string  $model  Model identifier
     * @return int Auto-compact threshold in tokens
     */
    public function autoCompactThreshold(string $model): int
    {
        return max(1, $this->effectiveContextWindow($model) - max(0, $this->autoCompactBufferTokens));
    }

    /**
     * Token count at which further turns are blocked until compaction completes.
     *
     * @param  string  $model  Model identifier
     * @return int Hard blocking threshold in tokens
     */
    public function blockingThreshold(string $model): int
    {
        return max(1, $this->effectiveContextWindow($model) - max(0, $this->blockingBufferTokens));
    }

    /**
     * Build a point-in-time snapshot of all budget metrics for the given token usage.
     *
     * @param  int  $estimatedTokens  Current estimated token usage from TokenEstimator
     * @param  string  $model  Model identifier
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
        // Clamp to 0 so negative usage never produces a negative percentage
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
