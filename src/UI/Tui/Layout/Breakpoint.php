<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Layout;

/**
 * Terminal width breakpoint enum for responsive TUI layout.
 *
 * Each case maps to a column-width range that drives layout and widget adaptation.
 *
 * Thresholds (in columns):
 *
 *   Tiny      < 60   — unsupported, show warning
 *   Narrow    60–79  — compact single-column
 *   Medium    80–119 — default layout
 *   Wide      120–159 — expanded views
 *   UltraWide ≥ 160  — multi-column layouts
 */
enum Breakpoint: string
{
    case Tiny = 'tiny';
    case Narrow = 'narrow';
    case Medium = 'medium';
    case Wide = 'wide';
    case UltraWide = 'ultra';

    /**
     * Resolve a breakpoint from a terminal column width.
     */
    public static function fromWidth(int $columns): self
    {
        return match (true) {
            $columns < 60 => self::Tiny,
            $columns < 80 => self::Narrow,
            $columns < 120 => self::Medium,
            $columns < 160 => self::Wide,
            default => self::UltraWide,
        };
    }

    /**
     * Return the minimum column width for this breakpoint.
     */
    public function minWidth(): int
    {
        return match ($this) {
            self::Tiny => 0,
            self::Narrow => 60,
            self::Medium => 80,
            self::Wide => 120,
            self::UltraWide => 160,
        };
    }

    /**
     * Return the maximum column width (exclusive) for this breakpoint.
     *
     * Returns PHP_INT_MAX for UltraWide since it has no upper bound.
     */
    public function maxWidth(): int
    {
        return match ($this) {
            self::Tiny => 60,
            self::Narrow => 80,
            self::Medium => 120,
            self::Wide => 160,
            self::UltraWide => PHP_INT_MAX,
        };
    }
}
