<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Renders a compact bar-chart sparkline using Unicode block characters.
 *
 * Renders an array of numeric values as a single-line (or multi-line) bar chart.
 * Each value maps to one column; the block character height is determined by
 * normalizing the value against the data range.
 *
 * Supports three color modes:
 *   - single:    all bars share one color
 *   - gradient:  color interpolates from low to high based on value
 *   - threshold: color determined by configurable thresholds
 *
 * ## Stylesheet Elements
 *
 *   SparklineWidget::class            — base style (padding, etc.)
 *   SparklineWidget::class.'::bar'    — bar color (single mode default)
 *
 * ## Usage
 *
 *   // Single-color sparkline
 *   $sparkline = new SparklineWidget([3, 7, 2, 9, 5, 8, 4, 6]);
 *
 *   // Gradient sparkline
 *   $sparkline = (new SparklineWidget($tokenHistory))
 *       ->setColorMode(SparklineWidget::COLOR_GRADIENT)
 *       ->setGradientColors(Color::hex('#50c878'), Color::hex('#ff503c'));
 *
 *   // Update data live
 *   $sparkline->push(42);         // appends, auto-trims to maxItems
 *   $sparkline->setData([...]);   // full replacement
 */
class SparklineWidget extends AbstractWidget
{
    /** Color mode constants */
    public const COLOR_SINGLE = 'single';
    public const COLOR_GRADIENT = 'gradient';
    public const COLOR_THRESHOLD = 'threshold';

    /** Unicode block characters for 8 discrete levels (▁ through █). */
    private const BLOCK_CHARS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /** Default maximum number of data points to display. */
    private const DEFAULT_MAX_ITEMS = 40;

    /** @var list<int|float> The data values to render */
    private array $data = [];

    /** @var int<1, 4> Number of terminal rows the sparkline occupies */
    private int $height = 1;

    /** @var string One of COLOR_* constants */
    private string $colorMode = self::COLOR_SINGLE;

    /** @var int<1, max> Maximum data points to keep (sliding window) */
    private int $maxItems = self::DEFAULT_MAX_ITEMS;

    /** @var Color|null Explicit bar color (single mode). Null = use stylesheet. */
    private ?Color $barColor = null;

    /** @var Color|null Gradient start color */
    private ?Color $gradientStart = null;

    /** @var Color|null Gradient end color */
    private ?Color $gradientEnd = null;

    /**
     * @var list<array{threshold: float, color: Color}> Threshold definitions
     * Sorted ascending by threshold. The first threshold >= the normalized value wins.
     */
    private array $thresholds = [];

    /** @var float|null Explicit data maximum for normalization. Null = auto from data. */
    private ?float $dataMax = null;

    /** @var float|null Explicit data minimum for normalization. Null = 0 (floor). */
    private ?float $dataMin = null;

    /**
     * @param list<int|float>|null $data Initial data values
     */
    public function __construct(?array $data = null)
    {
        if ($data !== null) {
            $this->data = $data;
        }
    }

    // ── Configuration ─────────────────────────────────────────────────

    /** Set the full data array. Replaces all existing data. */
    public function setData(array $data): static
    {
        $this->data = array_values($data);
        $this->invalidate();

        return $this;
    }

    /**
     * Append a value to the data. If the data exceeds maxItems, the oldest
     * value is dropped (sliding window behavior).
     */
    public function push(int|float $value): static
    {
        $this->data[] = $value;
        if (count($this->data) > $this->maxItems) {
            array_shift($this->data);
        }
        $this->invalidate();

        return $this;
    }

    /** Set the number of terminal rows (1–4). Default: 1. */
    public function setHeight(int $height): static
    {
        $this->height = max(1, min(4, $height));
        $this->invalidate();

        return $this;
    }

    /** Set the maximum number of data points (sliding window size). */
    public function setMaxItems(int $maxItems): static
    {
        $this->maxItems = max(1, $maxItems);
        // Trim existing data if needed
        if (count($this->data) > $this->maxItems) {
            $this->data = array_slice($this->data, -$this->maxItems);
            $this->invalidate();
        }

        return $this;
    }

    /** Set the color mode (COLOR_SINGLE, COLOR_GRADIENT, COLOR_THRESHOLD). */
    public function setColorMode(string $mode): static
    {
        $this->colorMode = $mode;
        $this->invalidate();

        return $this;
    }

    /** Set the bar color for single-color mode. */
    public function setBarColor(Color $color): static
    {
        $this->barColor = $color;
        $this->invalidate();

        return $this;
    }

    /** Set the gradient colors for gradient mode. */
    public function setGradientColors(Color $start, Color $end): static
    {
        $this->gradientStart = $start;
        $this->gradientEnd = $end;
        $this->invalidate();

        return $this;
    }

    /**
     * Set threshold definitions for threshold color mode.
     *
     * @param array<float|int, Color> $thresholds Map of [0.0–1.0 threshold => Color]
     *   Example: [0.5 => $green, 0.8 => $gold, 1.0 => $red]
     *   Values below the first threshold use the first threshold's color.
     *   Note: PHP truncates float array keys to int, so thresholds are stored as tuples internally.
     */
    public function setThresholds(array $thresholds): static
    {
        $this->thresholds = [];
        foreach ($thresholds as $threshold => $color) {
            $this->thresholds[] = ['threshold' => (float) $threshold, 'color' => $color];
        }
        usort($this->thresholds, fn (array $a, array $b): int => $a['threshold'] <=> $b['threshold']);
        $this->invalidate();

        return $this;
    }

    /** Set an explicit data maximum for normalization. Null = auto-detect. */
    public function setDataMax(?float $max): static
    {
        $this->dataMax = $max;
        $this->invalidate();

        return $this;
    }

    /** Set an explicit data minimum for normalization. Null = 0. */
    public function setDataMin(?float $min): static
    {
        $this->dataMin = $min;
        $this->invalidate();

        return $this;
    }

    // ── Rendering ─────────────────────────────────────────────────────

    /**
     * Render the sparkline as one or more ANSI-formatted lines.
     *
     * For height=1: returns a single string of block characters.
     * For height>1: returns multiple strings (bottom to top), where each
     * row uses full blocks + partial blocks to represent the data.
     *
     * @return list<string> ANSI-formatted lines (one per terminal row)
     */
    public function render(RenderContext $context): array
    {
        if (empty($this->data)) {
            return array_fill(0, $this->height, '');
        }

        $columns = $context->getColumns();

        // Determine how many data points we can fit
        $visibleCount = min(count($this->data), $columns);
        $data = array_slice($this->data, -$visibleCount);

        // Compute normalization bounds
        $min = $this->dataMin ?? 0.0;
        $max = $this->dataMax ?? (float) max($data);
        if ($max <= $min) {
            $max = $min + 1.0; // Prevent division by zero
        }
        $range = $max - $min;

        if ($this->height === 1) {
            return [$this->renderSingleLine($data, $min, $range, $columns)];
        }

        return $this->renderMultiLine($data, $min, $range, $columns);
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * Render a single-line sparkline (height = 1).
     *
     * Each data point maps to one block character from ▁ through █.
     */
    private function renderSingleLine(array $data, float $min, float $range, int $columns): string
    {
        $reset = "\033[0m";
        $parts = [];

        foreach ($data as $value) {
            $normalized = ($value - $min) / $range;  // 0.0–1.0
            $level = (int) round($normalized * 7.0);  // 0–7
            $level = max(0, min(7, $level));
            $char = self::BLOCK_CHARS[$level];
            $colorSeq = $this->resolveColor($normalized);
            $parts[] = $colorSeq . $char . $reset;
        }

        $line = implode('', $parts);

        return AnsiUtils::truncateToWidth($line, $columns);
    }

    /**
     * Render a multi-line sparkline (height > 1).
     *
     * Uses a stacking approach: for each data point, compute the total
     * number of half-rows needed. Fill full rows with █, and use ▀ or ▄
     * for the partial row. Build output lines from bottom to top.
     *
     * For height N, we get N × 8 discrete levels.
     */
    private function renderMultiLine(array $data, float $min, float $range, int $columns): array
    {
        $reset = "\033[0m";
        $totalLevels = $this->height * 8;

        // Pre-compute levels for each data point
        $levels = [];
        foreach ($data as $value) {
            $normalized = ($value - $min) / $range;
            $level = (int) round($normalized * ($totalLevels - 1));
            $levels[] = max(0, min($totalLevels - 1, $level));
        }

        // Build rows from bottom (row 0) to top (row height-1)
        $rows = array_fill(0, $this->height, []);

        foreach ($data as $i => $value) {
            $level = $levels[$i];
            $normalized = ($value - $min) / $range;
            $colorSeq = $this->resolveColor($normalized);

            for ($row = 0; $row < $this->height; $row++) {
                $rowLevelStart = $row * 8;
                $rowLevelEnd = $rowLevelStart + 8;

                if ($level >= $rowLevelEnd) {
                    // Full block in this row
                    $rows[$row][] = $colorSeq . '█' . $reset;
                } elseif ($level > $rowLevelStart) {
                    // Partial block
                    if ($row === 0) {
                        // Bottom row — use lower fractions: ▁▂▃▄▅▆▇
                        $frac = $level - $rowLevelStart; // 1–7
                        $rows[$row][] = $colorSeq . self::BLOCK_CHARS[$frac] . $reset;
                    } else {
                        // Upper rows — use ▀ (upper half)
                        $rows[$row][] = $colorSeq . '▀' . $reset;
                    }
                } else {
                    // Empty in this row
                    $rows[$row][] = ' ';
                }
            }
        }

        // Reverse so that index 0 = bottom, index height-1 = top
        // (terminal renders top-to-bottom, so we reverse for display)
        $rows = array_reverse($rows);

        return array_map(
            fn (array $chars): string => AnsiUtils::truncateToWidth(implode('', $chars), $columns),
            $rows,
        );
    }

    /**
     * Resolve the ANSI color sequence for a normalized value (0.0–1.0).
     */
    private function resolveColor(float $normalized): string
    {
        return match ($this->colorMode) {
            self::COLOR_SINGLE => $this->resolveSingleColor(),
            self::COLOR_GRADIENT => $this->resolveGradientColor($normalized),
            self::COLOR_THRESHOLD => $this->resolveThresholdColor($normalized),
            default => $this->resolveSingleColor(),
        };
    }

    /** Resolve single-color mode. Falls back to stylesheet, then to dim gray. */
    private function resolveSingleColor(): string
    {
        if ($this->barColor !== null) {
            return $this->colorToAnsi($this->barColor);
        }

        // Try stylesheet element
        $style = $this->resolveElement('bar');
        $fgColor = $style->getColor();
        if ($fgColor !== null) {
            return $this->colorToAnsi($fgColor);
        }

        // Fallback: dim gray
        return "\033[38;5;240m";
    }

    /** Resolve gradient color by interpolating between start and end. */
    private function resolveGradientColor(float $t): string
    {
        $start = $this->gradientStart ?? Color::hex('#50c878');
        $end = $this->gradientEnd ?? Color::hex('#ff503c');

        $color = self::interpolateColor($start, $end, $t);

        return $this->colorToAnsi($color);
    }

    /** Resolve threshold color by finding the first threshold >= normalized value. */
    private function resolveThresholdColor(float $normalized): string
    {
        if ($this->thresholds === []) {
            return $this->resolveSingleColor();
        }

        foreach ($this->thresholds as $entry) {
            if ($normalized <= $entry['threshold']) {
                return $this->colorToAnsi($entry['color']);
            }
        }

        // Above all thresholds — use the last one
        $lastEntry = end($this->thresholds);
        $lastColor = $lastEntry !== false ? $lastEntry['color'] : Color::hex('#ff503c');

        return $this->colorToAnsi($lastColor);
    }

    // ── Color Utilities ───────────────────────────────────────────────

    /** Convert a Color object to an ANSI 24-bit foreground escape sequence. */
    private function colorToAnsi(Color $color): string
    {
        return $color->toForegroundCode();
    }

    /**
     * Linearly interpolate between two colors.
     *
     * Shared with GaugeWidget for gradient rendering.
     *
     * @return Color The interpolated color
     */
    public static function interpolateColor(Color $start, Color $end, float $t): Color
    {
        $s = $start->toRgb();
        $e = $end->toRgb();

        $r = (int) round($s['r'] + ($e['r'] - $s['r']) * $t);
        $g = (int) round($s['g'] + ($e['g'] - $s['g']) * $t);
        $b = (int) round($s['b'] + ($e['b'] - $s['b']) * $t);

        return Color::rgb(
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b)),
        );
    }
}
