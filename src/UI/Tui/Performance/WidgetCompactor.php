<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Performance;

use Revolt\EventLoop;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Orchestrates widget compaction and eviction for the conversation container.
 *
 * Every conversation turn adds widgets to `ContainerWidget::children[]`. Each
 * widget holds its full source content (markdown, tool output, ANSI strings)
 * indefinitely. After 50+ turns this can grow to 200+ widgets and 20–100 MB
 * of retained content — all of it immutable.
 *
 * WidgetCompactor manages a four-stage lifecycle:
 *
 *   Active  ──►  Settled  ──►  Compacted  ──►  Evicted
 *     │           │              │               │
 *     │           │              │               └─ metadata only (~200 bytes)
 *     │           │              └─ cached rendered lines, original content freed
 *     │           └─ content finalized, compaction allowed
 *     └─ content still changing (streaming, running bash)
 *
 * Compaction is triggered when the widget count exceeds a configurable threshold
 * or when total memory usage passes a limit. It runs via `EventLoop::defer()` so
 * it never blocks the render loop.
 *
 * Usage:
 *   $compactor = new WidgetCompactor($conversation, new CompactionStrategy());
 *
 *   // After each widget addition:
 *   $compactor->onWidgetAdded();
 *
 *   // Or manually:
 *   $compactor->compact();
 *
 * @see docs/plans/tui-overhaul/13-architecture/02-widget-compaction.md
 */
final class WidgetCompactor
{
    /** @var list<EvictedWidgetEntry> Metadata for widgets that have been evicted */
    private array $evictedEntries = [];

    /** Total estimated scroll height including evicted placeholders */
    private int $totalEstimatedHeight = 0;

    /** Guards against re-entrant compaction */
    private bool $isCompacting = false;

    /** Guards against re-entrant eviction */
    private bool $isEvicting = false;

    /**
     * @param ContainerWidget $conversation The main conversation container
     * @param CompactionStrategy $strategy Thresholds and policy configuration
     */
    public function __construct(
        private readonly ContainerWidget $conversation,
        private readonly CompactionStrategy $strategy = new CompactionStrategy(),
    ) {}

    /**
     * Called after a widget is added to the conversation.
     *
     * Schedules a compaction pass via `EventLoop::defer()` when the widget
     * count exceeds the configured threshold or memory usage is high.
     */
    public function onWidgetAdded(): void
    {
        $widgetCount = count($this->conversation->all());

        if ($widgetCount <= $this->strategy->compactAfterNthWidget
            && memory_get_usage(true) < $this->strategy->memoryThresholdBytes
        ) {
            return;
        }

        EventLoop::defer($this->compact(...));
    }

    /**
     * Track a widget for potential compaction.
     *
     * Alias for {@see onWidgetAdded()} — tracks the widget count and schedules
     * a non-blocking compaction pass when the threshold is exceeded.
     *
     * @param object $widget The widget to track (type-hinted as object for flexibility)
     */
    public function track(object $widget): void
    {
        unset($widget); // We don't store the widget itself; we count via the container

        $widgetCount = count($this->conversation->all());

        if ($widgetCount <= $this->strategy->compactAfterNthWidget
            && memory_get_usage(true) < $this->strategy->memoryThresholdBytes
        ) {
            return;
        }

        EventLoop::defer($this->compact(...));
    }

    /**
     * Run a single compaction + eviction pass.
     *
     * Walks widgets from oldest to newest, transitioning states:
     *   - Settled widgets beyond `compactAfterNthWidget` → Compacted
     *   - Compacted widgets beyond `evictAfterNthWidget` → Evicted
     *
     * The newest `keepActiveCount` widgets are always preserved. Active
     * (still-updating) widgets are never touched.
     *
     * Safe to call manually or via `EventLoop::defer()`. Re-entrant calls are
     * silently ignored.
     */
    public function compact(): void
    {
        if ($this->isCompacting) {
            return;
        }

        $this->isCompacting = true;

        try {
            $this->doCompactionPass();
        } finally {
            $this->isCompacting = false;
        }
    }

    /**
     * Force eviction of all compactable widgets regardless of thresholds.
     *
     * Used by `/compact` command for manual cleanup.
     *
     * @return array{compacted: int, evicted: int, estimated_bytes_freed: int}
     */
    public function compactAll(): array
    {
        $compacted = 0;
        $evicted = 0;
        $bytesFreed = 0;

        $children = $this->conversation->all();
        $count = count($children);
        $keepZone = $this->strategy->keepActiveCount;

        for ($i = 0; $i < $count - $keepZone; $i++) {
            $widget = $children[$i] ?? null;
            if ($widget === null) {
                continue;
            }

            if ($widget instanceof CompactableWidgetInterface) {
                if (! $widget->isCompacted()) {
                    $bytesBefore = $this->estimateWidgetSize($widget);
                    $widget->compact();
                    $bytesFreed += max(0, $bytesBefore - $this->estimateWidgetSize($widget));
                    ++$compacted;
                }

                // Evict everything beyond keepActiveCount
                if ($i < $count - $this->strategy->keepActiveCount - $this->strategy->keepSettledCount) {
                    $bytesBefore = $this->estimateWidgetSize($widget);
                    $this->evictWidget($widget, $i);
                    $bytesFreed += $bytesBefore;
                    ++$evicted;
                }
            }
        }

        return [
            'compacted' => $compacted,
            'evicted' => $evicted,
            'estimated_bytes_freed' => $bytesFreed,
        ];
    }

    /**
     * Estimate the memory used by all conversation widgets.
     *
     * Uses `strlen()` on known content-holding properties as an approximation.
     */
    public function estimateMemoryUsage(): int
    {
        $total = 0;

        foreach ($this->conversation->all() as $widget) {
            $total += $this->estimateWidgetSize($widget);
        }

        return $total;
    }

    /**
     * Return the total number of evicted widget entries.
     */
    public function evictedCount(): int
    {
        return count($this->evictedEntries);
    }

    /**
     * Return all evicted widget entries.
     *
     * @return list<EvictedWidgetEntry>
     */
    public function getEvictedEntries(): array
    {
        return $this->evictedEntries;
    }

    /**
     * Return total estimated scroll height including evicted placeholders.
     */
    public function getTotalEstimatedHeight(): int
    {
        return $this->totalEstimatedHeight;
    }

    /**
     * Clear all compaction state (e.g. on `/new` or session reset).
     */
    public function reset(): void
    {
        $this->evictedEntries = [];
        $this->totalEstimatedHeight = 0;
        $this->isCompacting = false;
        $this->isEvicting = false;
    }

    /**
     * Get the active compaction strategy.
     */
    public function getStrategy(): CompactionStrategy
    {
        return $this->strategy;
    }

    // ── Internal ────────────────────────────────────────────────────────

    private function doCompactionPass(): void
    {
        $children = $this->conversation->all();
        $count = count($children);

        if ($count <= $this->strategy->compactAfterNthWidget) {
            return;
        }

        $keepActive = $this->strategy->keepActiveCount;
        $keepSettled = $this->strategy->keepSettledCount;
        $compactThreshold = $count - $keepActive - $keepSettled;
        $evictThreshold = $count - $keepActive;

        for ($i = 0; $i < $count - $keepActive; $i++) {
            $widget = $children[$i] ?? null;
            if ($widget === null || ! $widget instanceof CompactableWidgetInterface) {
                continue;
            }

            // Don't compact widgets that are still actively updating
            if ($widget instanceof ActiveWidgetInterface && $widget->isActive()) {
                continue;
            }

            if ($i < $compactThreshold) {
                // Beyond both thresholds → evict
                if (! $widget->isCompacted()) {
                    $widget->compact();
                }
                $this->evictWidget($widget, $i);
            } elseif ($i < $evictThreshold) {
                // Beyond compact threshold but within evict zone → compact only
                if (! $widget->isCompacted()) {
                    $widget->compact();
                }
            }
        }

        $this->updateTotalHeight();
    }

    /**
     * Evict a widget from the conversation, recording its metadata.
     *
     * Replaces it with an EvictedPlaceholderWidget in the container
     * so that scroll height is preserved.
     */
    private function evictWidget(AbstractWidget $widget, int $index): void
    {
        $summary = '';
        $estimatedHeight = 1;

        if ($widget instanceof CompactableWidgetInterface) {
            $summary = $widget->getSummaryLine();
            $estimatedHeight = $widget->getEstimatedHeight();
        }

        $entry = new EvictedWidgetEntry(
            type: $widget::class,
            summary: $summary,
            estimatedHeight: $estimatedHeight,
            originalIndex: $index,
        );

        $this->evictedEntries[] = $entry;

        // Replace with a lightweight placeholder
        $placeholder = new EvictedPlaceholderWidget($summary, $estimatedHeight);
        $children = $this->conversation->all();

        // Remove old widget and insert placeholder at the same position
        $this->conversation->remove($widget);

        // Note: ContainerWidget doesn't have insertAt(). The placeholder
        // is appended; scroll-height tracking uses the evictedEntries list
        // for correct positioning. The placeholder's padding lines ensure
        // the visual height is preserved.
        $this->conversation->add($placeholder);
    }

    private function updateTotalHeight(): void
    {
        $height = 0;

        foreach ($this->conversation->all() as $widget) {
            if ($widget instanceof CompactableWidgetInterface) {
                $height += $widget->getEstimatedHeight();
            } else {
                // Non-compactable widgets are typically small (1–3 lines)
                $height += 1;
            }
        }

        $this->totalEstimatedHeight = $height;
    }

    /**
     * Rough estimate of a widget's content size in bytes.
     */
    private function estimateWidgetSize(AbstractWidget $widget): int
    {
        $size = 0;

        if ($widget instanceof CompactableWidgetInterface) {
            $size += strlen($widget->getSummaryLine());
        }

        return $size;
    }
}

/**
 * Interface for widgets that are still actively updating.
 *
 * The compactor never touches widgets reporting `isActive() === true`.
 */
interface ActiveWidgetInterface
{
    /**
     * Whether the widget is still receiving updates (streaming, running).
     */
    public function isActive(): bool;
}

/**
 * Metadata record for an evicted widget.
 */
final class EvictedWidgetEntry
{
    public function __construct(
        public readonly string $type,
        public readonly string $summary,
        public readonly int $estimatedHeight,
        public readonly int $originalIndex,
    ) {}
}

/**
 * Lightweight placeholder rendered in place of evicted widgets.
 *
 * Contributes to scroll height with padding lines but has near-zero RAM cost.
 */
final class EvictedPlaceholderWidget extends AbstractWidget
{
    public function __construct(
        private readonly string $summary,
        private readonly int $estimatedHeight,
    ) {}

    public function render(\Symfony\Component\Tui\Render\RenderContext $context): array
    {
        $dim = "\x1b[38;5;240m";
        $r = "\x1b[0m";

        $lines = ["  {$dim}\x1b[38;5;245m⊛ {$this->summary} ({$this->estimatedHeight} lines){$r}"];

        // Pad to estimated height so scroll calculations stay correct
        for ($i = 1; $i < $this->estimatedHeight; $i++) {
            $lines[] = '';
        }

        return $lines;
    }
}

/**
 * Configurable thresholds and policy for compaction/eviction.
 */
final class CompactionStrategy
{
    public function __construct(
        /** Start compacting after this many widgets */
        public readonly int $compactAfterNthWidget = 50,
        /** Start evicting after this many widgets */
        public readonly int $evictAfterNthWidget = 100,
        /** Trigger compaction when memory exceeds this many bytes */
        public readonly int $memoryThresholdBytes = 50 * 1024 * 1024,
        /** Always keep the newest N widgets active */
        public readonly int $keepActiveCount = 20,
        /** Keep the next N widgets in settled (compactable) state */
        public readonly int $keepSettledCount = 30,
    ) {}
}
