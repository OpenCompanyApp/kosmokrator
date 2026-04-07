<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget;

/**
 * Immutable value object carrying the three metrics needed for scrollbar rendering.
 *
 * Decoupled from the widget itself so that the parent container or a reactive
 * computed signal can build the state from scroll offset and content metrics.
 *
 * @see ScrollbarWidget
 */
final class ScrollbarState
{
    /**
     * @param int $contentLength  Total lines of scrollable content
     * @param int $viewportLength Visible lines in the viewport
     * @param int $position       Scroll offset from the top (0 = at top)
     */
    public function __construct(
        public readonly int $contentLength,
        public readonly int $viewportLength,
        public readonly int $position,
    ) {}

    /**
     * Whether content exceeds the viewport and scrolling is possible.
     */
    public function isScrollable(): bool
    {
        return $this->contentLength > $this->viewportLength;
    }

    /**
     * Scroll fraction from 0.0 (top) to 1.0 (bottom).
     *
     * Returns 0.0 when content fits the viewport or max scroll is zero.
     */
    public function scrollFraction(): float
    {
        if ($this->contentLength <= $this->viewportLength) {
            return 0.0;
        }

        $maxScroll = $this->contentLength - $this->viewportLength;

        return $this->position / $maxScroll;
    }

    /**
     * Thumb size in rows, proportional to viewport/content ratio.
     *
     * Always returns at least 1 row so the thumb remains visible even for
     * very long content.
     *
     * @param int $trackHeight Height of the scrollbar track in rows
     */
    public function thumbSize(int $trackHeight): int
    {
        if ($this->contentLength <= 0) {
            return $trackHeight;
        }

        return max(1, (int) round($trackHeight * $this->viewportLength / $this->contentLength));
    }

    /**
     * Thumb start row (0-indexed within the track).
     *
     * @param int $trackHeight Height of the scrollbar track in rows
     */
    public function thumbStart(int $trackHeight): int
    {
        $thumb = $this->thumbSize($trackHeight);
        $maxPos = $trackHeight - $thumb;

        return (int) round($maxPos * $this->scrollFraction());
    }

    /**
     * Create a new state with a different position.
     */
    public function withPosition(int $position): self
    {
        return new self($this->contentLength, $this->viewportLength, $position);
    }
}
