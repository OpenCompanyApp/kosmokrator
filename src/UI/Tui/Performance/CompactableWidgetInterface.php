<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Performance;

/**
 * Interface for widgets that support compaction.
 *
 * Widgets implementing this interface can be compacted (content freed, cached
 * render lines retained) and evicted (replaced with a lightweight placeholder).
 */
interface CompactableWidgetInterface
{
    /**
     * Compact the widget: capture rendered output and free content properties.
     */
    public function compact(): void;

    /**
     * Whether this widget has been compacted.
     */
    public function isCompacted(): bool;

    /**
     * A one-line summary for the evicted placeholder display.
     */
    public function getSummaryLine(): string;

    /**
     * Estimated rendered height in terminal lines.
     */
    public function getEstimatedHeight(): int;
}
