<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Layout;

/**
 * Immutable value object representing current terminal dimensions.
 *
 * Encapsulates breakpoint detection, content-width helpers, and minimum-size
 * validation. All width-dependent layout decisions should flow through this
 * object rather than hardcoded magic numbers.
 */
final readonly class TerminalDimension
{
    public function __construct(
        public int $columns,
        public int $rows,
    ) {}

    /**
     * Determine the current breakpoint from column count.
     */
    public function breakpoint(): Breakpoint
    {
        return Breakpoint::fromWidth($this->columns);
    }

    /**
     * Whether the terminal meets or exceeds the given breakpoint.
     */
    public function isAtLeast(Breakpoint $breakpoint): bool
    {
        return $this->columns >= $breakpoint->minWidth();
    }

    public function isTiny(): bool
    {
        return $this->columns < 60;
    }

    public function isNarrow(): bool
    {
        return $this->columns >= 60 && $this->columns < 80;
    }

    public function isMedium(): bool
    {
        return $this->columns >= 80 && $this->columns < 120;
    }

    public function isWide(): bool
    {
        return $this->columns >= 120 && $this->columns < 160;
    }

    public function isUltraWide(): bool
    {
        return $this->columns >= 160;
    }

    /**
     * Content width after subtracting standard padding (2 per side).
     */
    public function contentWidth(): int
    {
        return max(40, $this->columns - 4);
    }

    /**
     * Max width for tool call labels and collapsible widgets.
     *
     * Scales with breakpoint: on narrow/tiny terminals it uses the full
     * content width; on wider terminals it caps at a readable maximum.
     */
    public function toolCallWidth(): int
    {
        return min($this->contentWidth(), match ($this->breakpoint()) {
            Breakpoint::Tiny, Breakpoint::Narrow => $this->contentWidth(),
            Breakpoint::Medium => 120,
            Breakpoint::Wide => 140,
            Breakpoint::UltraWide => 160,
        });
    }

    /**
     * Max columns for markdown rendering.
     *
     * Keeps line lengths readable; wider terminals get more columns.
     */
    public function markdownColumns(): int
    {
        return min($this->contentWidth(), match ($this->breakpoint()) {
            Breakpoint::Tiny, Breakpoint::Narrow => $this->contentWidth(),
            Breakpoint::Medium => 100,
            Breakpoint::Wide => 120,
            Breakpoint::UltraWide => 140,
        });
    }

    /**
     * Preview truncation length for tool-executing indicator.
     */
    public function previewLength(): int
    {
        return match ($this->breakpoint()) {
            Breakpoint::Tiny => 40,
            Breakpoint::Narrow => 60,
            Breakpoint::Medium => 100,
            Breakpoint::Wide => 120,
            Breakpoint::UltraWide => 140,
        };
    }

    /**
     * Whether the terminal meets the minimum supported size (60×20).
     */
    public function isSupported(): bool
    {
        return $this->columns >= 60 && $this->rows >= 20;
    }

    /**
     * Return a warning message if the terminal is smaller than supported.
     */
    public function sizeWarning(): ?string
    {
        if ($this->columns < 60 && $this->rows < 20) {
            return "Terminal too small ({$this->columns}×{$this->rows}). Minimum: 60×20. Some UI may be clipped.";
        }

        if ($this->columns < 60) {
            return "Terminal too narrow ({$this->columns} cols). Minimum: 60 columns.";
        }

        if ($this->rows < 20) {
            return "Terminal too short ({$this->rows} rows). Minimum: 20 rows.";
        }

        return null;
    }

    /**
     * Discovery bash label truncation length.
     *
     * Derived from toolCallWidth minus space for prefix/decoration.
     */
    public function discoveryLabelLength(): int
    {
        return max(30, $this->toolCallWidth() - 30);
    }
}
