# 05 — SparklineWidget & Enhanced GaugeWidget

> Plan for implementing compact data-visualization widgets: a multi-height sparkline bar chart and an enhanced gauge/progress bar with gradient fill, animation, and inline labels.

## Table of Contents

- [Motivation](#motivation)
- [Prior Art](#prior-art)
- [Use Cases](#use-cases)
- [Architecture](#architecture)
- [SparklineWidget](#sparklinewidget)
- [GaugeWidget](#gaugewidget)
- [Shared Infrastructure](#shared-infrastructure)
- [Stylesheet Integration](#stylesheet-integration)
- [Testing Strategy](#testing-strategy)
- [Migration Path](#migration-path)
- [File Layout](#file-layout)

---

## Motivation

KosmoKrator's status bar and swarm dashboard need compact, glanceable data visualization. Currently:

- **Token usage over time** — has no visualization; only a static label.
- **Context window fill** — uses Symfony's `ProgressBarWidget`, which is functional but limited to a single color per element and cannot show gradient fills or inline labels.
- **Swarm agent progress** — hand-rolls a simple `█░` bar in `SwarmDashboardWidget.php:104–111` with hardcoded ANSI escapes.

We need two reusable primitives:

1. **Sparkline** — render a sliding window of numeric values as a single-line (or multi-line) bar chart using Unicode block characters. Think `▁▂▃▄▅▆▇█`.
2. **Gauge** — a percentage bar with gradient fill, animated indeterminate state, and an inline label.

Both must follow existing conventions: extend `AbstractWidget`, return `list<string>` from `render()`, use `$this->invalidate()` for dirty tracking, and integrate with `KosmokratorStyleSheet` via `::` pseudo-element selectors.

---

## Prior Art

### Ratatui (Rust)

- **`Sparkline`** — takes a `Vec<u64>` of data, renders as bar chart using `▁▂▃▄▅▆▇█` (8 levels). Configurable `direction` (left-to-right or right-to-left), `style` for bar color. Height is the number of terminal rows allocated to the widget. Data values are auto-scaled to the allocated height using `max(data) / height`.
- **`Gauge`** — renders a filled rectangle showing `ratio` (0.0–1.0). Supports `label` rendered centered inside the bar. Has `gauge_style` (filled region color) and `background_style` (unfilled region color). A `LineGauge` variant uses Unicode line-drawing characters for a thinner bar.

### Bubble Tea (Go)

- **`progress` package** — renders `█`-based progress bar. Supports gradient fill via `progress.WithGradientGradient(startColor, endColor)`. Full/empty characters customizable. Animate by calling `Incr()` in a `tea.Tick` loop. Width auto-fits to container.

### Lip Gloss (Go)

- **Progress bar styling** — composable via `lipgloss.NewStyle()`. Supports `Foreground`, `Background`, `Width`. Full character, empty character, and indeterminate animation configurable. The `Width` method auto-pads.

### Key Takeaways

| Feature | Sparkline | Gauge |
|---------|-----------|-------|
| Block characters | `▁▂▃▄▅▆▇█` (8 heights) | `█░▓▒` or custom |
| Height | 1–4 terminal rows | Always 1 row |
| Color modes | Single color or gradient | Single, gradient, or threshold-based |
| Data | Array of numeric values | Percentage (0.0–1.0) |
| Label | N/A | Centered inside bar |
| Animation | Slide new data in from right | Animated indeterminate fill |

---

## Use Cases

### 1. Token Usage Sparkline (Status Bar)

```
▃▄▆▇█▅▃▂▄▆  12.4k/200k tokens
```

A rolling window of the last N API calls' token counts, rendered as a 1-line sparkline in the status bar. Color shifts from green → yellow → red as the rolling average approaches the context limit.

### 2. Context Window Gauge (Status Bar)

```
[████████████░░░░░░░░░░░░] 62% — 124k/200k
```

Gradient-filled gauge showing context window utilization. Threshold coloring: green < 70%, yellow 70–90%, red > 90%.

### 3. Swarm Progress Gauge (Swarm Dashboard)

```
████████████████░░░░░░░░  8/12 agents done (66.7%)
```

Replaces the hand-rolled bar in `SwarmDashboardWidget.php:104–111`.

### 4. Cost Tracking Sparkline

```
▂▃▅▆▇█▇▅▃▂  $0.42 session · $0.08/min
```

Rolling per-minute cost visualization in the session summary.

---

## Architecture

### Design Principles

1. **Follow existing patterns** — extend `AbstractWidget`, use `render(): array`, call `invalidate()`.
2. **Pure rendering** — widgets hold state and render it. No timers, no side effects. Animation tick is handled by the owner (e.g., `TuiCoreRenderer`) calling `advance()` or `push()`.
3. **Stylesheet-driven** — all color/character defaults come from `KosmokratorStyleSheet`. The PHP API accepts overrides for ad-hoc usage.
4. **Width-aware** — use `AnsiUtils::visibleWidth()` and `AnsiUtils::truncateToWidth()` consistently.
5. **Composable** — these are leaf widgets. They can be embedded in any parent (status bar, dashboard, modal).

### Component Diagram

```
┌─────────────────────────────────────────────┐
│               KosmokratorStyleSheet          │
│  SparklineWidget::class => Style(...)       │
│  SparklineWidget::class.'::bar' => Style    │
│  GaugeWidget::class => Style(...)           │
│  GaugeWidget::class.'::fill' => Style       │
│  GaugeWidget::class.'::empty' => Style      │
│  GaugeWidget::class.'::label' => Style      │
└──────────┬───────────────────┬──────────────┘
           │                   │
     ┌─────▼─────┐      ┌─────▼─────┐
     │ Sparkline  │      │   Gauge   │
     │  Widget    │      │  Widget   │
     └─────┬─────┘      └─────┬─────┘
           │                   │
     ┌─────▼───────────────────▼─────┐
     │      GradientHelper           │
     │  (shared color interpolation) │
     └───────────────────────────────┘
           │
     ┌─────▼──────────────────────────┐
     │  Symfony\Component\Tui\Style\  │
     │  Color, Style, AnsiUtils       │
     └────────────────────────────────┘
```

---

## SparklineWidget

### Overview

Renders an array of numeric values as a compact bar chart using Unicode block characters. Each value maps to one column; the character height is determined by normalizing the value against the data range.

### Unicode Block Characters

```
Index  Character  Height fraction
0      ▁          1/8
1      ▂          2/8
2      ▃          3/8
3      ▄          4/8
4      ▅          5/8
5      ▆          6/8
6      ▇          7/8
7      █          8/8 (full block)
```

For multi-line rendering (height > 1), the widget uses the **upper half block** `▀` and **lower half block** `▄` to achieve 2× resolution per row, plus the full block `█`. With 4 rows this gives 8 discrete levels per row × 4 rows = 32 possible levels.

In practice, for simplicity, the primary implementation uses the 8 single-line characters for height=1, and stacks full blocks + partial blocks for height > 1:

| Height | Technique | Levels |
|--------|-----------|--------|
| 1 | 8 block chars `▁▂▃▄▅▆▇█` | 8 |
| 2 | Row 0: upper halves, Row 1: lower halves using `█▀▄` | 16 |
| 3–4 | Full-block stacking + top partial | 8 × height |

**Recommendation**: Start with height=1 only (8 levels). Multi-line support is a later enhancement tracked in the class API but implemented as `height ≥ 1` using the simpler per-row approach.

### Color Modes

| Mode | Behavior |
|------|----------|
| `single` | All bars use one color (from stylesheet or constructor arg) |
| `gradient` | Color interpolates from `colorStart` to `colorEnd` based on each bar's normalized value |
| `threshold` | Color determined by mapping ranges to colors (e.g., green < 50%, yellow 50–80%, red > 80%) |

### Class Sketch

```php
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
 *   - single:   all bars share one color
 *   - gradient: color interpolates from low to high based on value
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
 *       ->colorMode(SparklineWidget::COLOR_GRADIENT)
 *       ->gradientColors(Color::hex('#50c878'), Color::hex('#ff503c'));
 *
 *   // Update data live
 *   $sparkline->push(42);      // appends, auto-trims to maxItems
 *   $sparkline->setData([...]); // full replacement
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
     * @var array<float, Color> Threshold definitions: [threshold => color]
     * Sorted ascending. The first threshold >= the normalized value wins.
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
            $this->data = array_values($data);
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
     * @param array<float, Color> $thresholds Map of [0.0–1.0 threshold => Color]
     *   Example: [0.5 => Color::hex('#50c878'), 0.8 => Color::hex('#ffc850'), 1.0 => Color::hex('#ff503c')]
     *   Values below the first threshold use the first threshold's color.
     */
    public function setThresholds(array $thresholds): static
    {
        $this->thresholds = $thresholds;
        ksort($this->thresholds, SORT_NUMERIC);
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
            fn (array $chars) => AnsiUtils::truncateToWidth(implode('', $chars), $columns),
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
        if ($style->getForegroundColor() !== null) {
            return $this->colorToAnsi($style->getForegroundColor());
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

    /** Resolve threshold color by finding the first threshold ≥ normalized value. */
    private function resolveThresholdColor(float $normalized): string
    {
        if (empty($this->thresholds)) {
            return $this->resolveSingleColor();
        }

        foreach ($this->thresholds as $threshold => $color) {
            if ($normalized <= $threshold) {
                return $this->colorToAnsi($color);
            }
        }

        // Above all thresholds — use the last one
        return $this->colorToAnsi(end($this->thresholds) ?: Color::hex('#ff503c'));
    }

    // ── Color Utilities ───────────────────────────────────────────────

    /** Convert a Color object to an ANSI 24-bit foreground escape sequence. */
    private function colorToAnsi(Color $color): string
    {
        $rgb = $color->toRgb();

        return "\033[38;2;{$rgb[0]};{$rgb[1]};{$rgb[2]}m";
    }

    /**
     * Linearly interpolate between two colors.
     *
     * @return Color The interpolated color
     */
    public static function interpolateColor(Color $start, Color $end, float $t): Color
    {
        $s = $start->toRgb();
        $e = $end->toRgb();

        $r = (int) round($s[0] + ($e[0] - $s[0]) * $t);
        $g = (int) round($s[1] + ($e[1] - $s[1]) * $t);
        $b = (int) round($s[2] + ($e[2] - $s[2]) * $t);

        return Color::rgb(
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b)),
        );
    }
}
```

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **`push()` method** | Enables sliding-window usage: just push new values, old ones auto-drop. Ideal for real-time token usage. |
| **Auto-trim to `maxItems`** | Prevents unbounded memory growth. Default 40 = fits comfortably in a 40–80 col status bar. |
| **8-level granularity** | Matches the 8 available block characters. Sufficient for sparklines; more detail requires multi-line mode. |
| **Color modes as enum-like strings** | Extensible without subclassing. PHP 8.4 enums could work but strings are more flexible for stylesheet-driven config. |
| **`dataMax`/`dataMin` overrides** | Essential for stable sparklines where the data range is known (e.g., token counts 0–200k). Without this, a single spike distorts the entire chart. |
| **`renderMultiLine` stacks bottom-to-top** | Matches how terminal rows are addressed. Row 0 = bottom of the bar. |
| **Static `interpolateColor()`** | Shared with `GaugeWidget` and potentially other widgets. Could be extracted to a `GradientHelper` utility later. |

---

## GaugeWidget

### Overview

A percentage-fill bar with gradient support, inline labels, animated indeterminate state, and customizable characters. Replaces both the Symfony `ProgressBarWidget` for status-bar usage and the hand-rolled bars in `SwarmDashboardWidget`.

### Feature Matrix

| Feature | Description |
|---------|-------------|
| **Gradient fill** | Color transitions from `fillStart` to `fillEnd` across the filled width |
| **Threshold fill** | Color changes based on percentage thresholds (e.g., red when > 90%) |
| **Inline label** | Text rendered centered inside the bar (over the fill and empty regions) |
| **Percentage display** | Optional auto-generated "XX.X%" label |
| **Custom characters** | Configurable fill char (default `█`), empty char (default `░`), tip char (default `▓`) |
| **Indeterminate animation** | Oscillating fill for unknown progress, driven by `advanceAnimation()` |
| **Brackets** | Optional left/right bracket characters (default `[`/`]`) |
| **Width modes** | Explicit width, or auto-fit to `RenderContext::getColumns()` |

### Class Sketch

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Renders a percentage-fill gauge bar with optional gradient, labels, and animation.
 *
 * The gauge renders a single line of terminal output:
 *
 *   [████████████░░░░░░░░░░░░] 62% — 124k/200k
 *
 * ## Features
 *
 *   - Gradient fill: color interpolates from fillStart to fillEnd
 *   - Threshold fill: color changes at configurable percentage breakpoints
 *   - Inline label: text centered over the bar
 *   - Percentage display: auto-generated or custom format
 *   - Custom characters: fill, empty, tip, brackets
 *   - Indeterminate animation: oscillating bar for unknown progress
 *
 * ## Stylesheet Elements
 *
 *   GaugeWidget::class             — base style
 *   GaugeWidget::class.'::fill'    — fill color (single mode)
 *   GaugeWidget::class.'::empty'   — empty region color
 *   GaugeWidget::class.'::label'   — label text color
 *   GaugeWidget::class.'::bracket' — bracket color
 *
 * ## Usage
 *
 *   // Simple gauge
 *   $gauge = (new GaugeWidget(0.62))
 *       ->setLabel('124k/200k')
 *       ->setShowPercentage(true);
 *
 *   // Gradient gauge
 *   $gauge = (new GaugeWidget($pct))
 *       ->setColorMode(GaugeWidget::COLOR_GRADIENT)
 *       ->setGradientColors(Color::hex('#50c878'), Color::hex('#ff503c'));
 *
 *   // Indeterminate (animated)
 *   $gauge = (new GaugeWidget())->setIndeterminate(true);
 *   // In tick callback: $gauge->advanceAnimation();
 */
class GaugeWidget extends AbstractWidget
{
    /** Color mode constants */
    public const COLOR_SINGLE = 'single';
    public const COLOR_GRADIENT = 'gradient';
    public const COLOR_THRESHOLD = 'threshold';

    /** Default characters */
    private const DEFAULT_FILL_CHAR = '█';
    private const DEFAULT_EMPTY_CHAR = '░';
    private const DEFAULT_TIP_CHAR = '▓';
    private const DEFAULT_LEFT_BRACKET = '';
    private const DEFAULT_RIGHT_BRACKET = '';

    /** @var float Current ratio (0.0–1.0). NaN = indeterminate. */
    private float $ratio;

    /** @var string One of COLOR_* constants */
    private string $colorMode = self::COLOR_SINGLE;

    /** @var string|null Inline label text. Null = no label. */
    private ?string $label = null;

    /** @var bool Whether to show "XX.X%" after the bar */
    private bool $showPercentage = false;

    /** @var string Custom percentage format string. %s = formatted number. */
    private string $percentageFormat = '%s%%';

    /** @var int|null Number of decimals in percentage display */
    private int $percentageDecimals = 1;

    /** @var string Fill character */
    private string $fillChar = self::DEFAULT_FILL_CHAR;

    /** @var string Empty character */
    private string $emptyChar = self::DEFAULT_EMPTY_CHAR;

    /** @var string|null Tip character (at fill/empty boundary). Null = use fillChar. */
    private ?string $tipChar = null;

    /** @var string Left bracket character */
    private string $leftBracket = self::DEFAULT_LEFT_BRACKET;

    /** @var string Right bracket character */
    private string $rightBracket = self::DEFAULT_RIGHT_BRACKET;

    /** @var int|null Explicit width in columns. Null = auto-fit to terminal. */
    private ?int $width = null;

    /** @var bool Whether the gauge is in indeterminate (animated) mode */
    private bool $indeterminate = false;

    /** @var float Animation phase (0.0–1.0), advanced by advanceAnimation() */
    private float $animPhase = 0.0;

    /** @var float Animation speed (full cycles per second at 4 Hz tick rate) */
    private float $animSpeed = 0.04;

    /** @var float Animation bar width as fraction of total (for indeterminate) */
    private float $animBarWidth = 0.3;

    /** @var Color|null Fill color for single mode */
    private ?Color $fillColor = null;

    /** @var Color|null Empty region color */
    private ?Color $emptyColor = null;

    /** @var Color|null Label text color */
    private ?Color $labelColor = null;

    /** @var Color|null Bracket color */
    private ?Color $bracketColor = null;

    /** @var Color|null Gradient start color */
    private ?Color $gradientStart = null;

    /** @var Color|null Gradient end color */
    private ?Color $gradientEnd = null;

    /**
     * @var array<float, Color> Threshold definitions
     */
    private array $thresholds = [];

    /**
     * @param float $ratio Initial ratio (0.0–1.0). Use NAN for indeterminate.
     */
    public function __construct(float $ratio = 0.0)
    {
        $this->ratio = $ratio;
    }

    // ── Configuration ─────────────────────────────────────────────────

    /** Set the current ratio (0.0–1.0). */
    public function setRatio(float $ratio): static
    {
        $this->ratio = max(0.0, min(1.0, $ratio));
        $this->invalidate();

        return $this;
    }

    /** Set the color mode. */
    public function setColorMode(string $mode): static
    {
        $this->colorMode = $mode;
        $this->invalidate();

        return $this;
    }

    /** Set the fill color (single mode). */
    public function setFillColor(Color $color): static
    {
        $this->fillColor = $color;
        $this->invalidate();

        return $this;
    }

    /** Set the empty region color. */
    public function setEmptyColor(Color $color): static
    {
        $this->emptyColor = $color;
        $this->invalidate();

        return $this;
    }

    /** Set the label text rendered inside the bar. */
    public function setLabel(?string $label): static
    {
        $this->label = $label;
        $this->invalidate();

        return $this;
    }

    /** Enable/disable percentage display after the bar. */
    public function setShowPercentage(bool $show = true): static
    {
        $this->showPercentage = $show;
        $this->invalidate();

        return $this;
    }

    /** Set the percentage format string. %s = the formatted number. */
    public function setPercentageFormat(string $format, int $decimals = 1): static
    {
        $this->percentageFormat = $format;
        $this->percentageDecimals = $decimals;
        $this->invalidate();

        return $this;
    }

    /** Set the fill character. */
    public function setFillChar(string $char): static
    {
        $this->fillChar = $char;
        $this->invalidate();

        return $this;
    }

    /** Set the empty character. */
    public function setEmptyChar(string $char): static
    {
        $this->emptyChar = $char;
        $this->invalidate();

        return $this;
    }

    /** Set the tip character (at fill/empty boundary). Null = use fillChar. */
    public function setTipChar(?string $char): static
    {
        $this->tipChar = $char;
        $this->invalidate();

        return $this;
    }

    /** Set bracket characters. Empty string = no bracket. */
    public function setBrackets(string $left = '[', string $right = ']'): static
    {
        $this->leftBracket = $left;
        $this->rightBracket = $right;
        $this->invalidate();

        return $this;
    }

    /** Set explicit width in columns. Null = auto-fit. */
    public function setWidth(?int $width): static
    {
        $this->width = $width;
        $this->invalidate();

        return $this;
    }

    /** Enable/disable indeterminate animation mode. */
    public function setIndeterminate(bool $indeterminate = true): static
    {
        $this->indeterminate = $indeterminate;
        $this->invalidate();

        return $this;
    }

    /** Advance the indeterminate animation by one tick. */
    public function advanceAnimation(): static
    {
        $this->animPhase += $this->animSpeed;
        if ($this->animPhase > 1.0 + $this->animBarWidth) {
            $this->animPhase = -$this->animBarWidth;
        }
        $this->invalidate();

        return $this;
    }

    /** Set gradient colors for gradient mode. */
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
     * @param array<float, Color> $thresholds Map of [0.0–1.0 threshold => Color]
     */
    public function setThresholds(array $thresholds): static
    {
        $this->thresholds = $thresholds;
        ksort($this->thresholds, SORT_NUMERIC);
        $this->invalidate();

        return $this;
    }

    // ── Rendering ─────────────────────────────────────────────────────

    /**
     * Render the gauge as a single ANSI-formatted line.
     *
     * @return list<string> Single-element array containing the formatted line
     */
    public function render(RenderContext $context): array
    {
        $reset = "\033[0m";
        $columns = $this->width ?? $context->getColumns();

        if ($this->indeterminate) {
            return [$this->renderIndeterminate($columns, $reset)];
        }

        return [$this->renderDeterminate($columns, $reset)];
    }

    // ── Determinate Rendering ─────────────────────────────────────────

    private function renderDeterminate(int $columns, string $reset): string
    {
        // Calculate bar width (excluding brackets and percentage suffix)
        $pctStr = '';
        $pctWidth = 0;
        if ($this->showPercentage) {
            $pctStr = ' ' . sprintf($this->percentageFormat, number_format($this->ratio * 100, $this->percentageDecimals));
            $pctWidth = AnsiUtils::visibleWidth($pctStr);
        }

        $bracketWidth = mb_strlen($this->leftBracket) + mb_strlen($this->rightBracket);
        $barWidth = max(1, $columns - $bracketWidth - $pctWidth);

        $filled = (int) round($this->ratio * $barWidth);
        $empty = $barWidth - $filled;

        // Build bar characters
        $bar = '';

        if ($this->colorMode === self::COLOR_GRADIENT) {
            // Per-character gradient
            $start = $this->gradientStart ?? Color::hex('#50c878');
            $end = $this->gradientEnd ?? Color::hex('#ff503c');

            for ($i = 0; $i < $filled; $i++) {
                $t = $barWidth > 1 ? $i / ($barWidth - 1) : 0.0;
                $color = SparklineWidget::interpolateColor($start, $end, $t);
                $seq = $this->colorToAnsi($color);
                $char = ($this->tipChar !== null && $i === $filled - 1 && $empty > 0)
                    ? $this->tipChar
                    : $this->fillChar;
                $bar .= $seq . $char . $reset;
            }
        } else {
            $fillSeq = $this->resolveFillColor();
            $tipSeq = $this->tipChar !== null && $empty > 0
                ? $this->resolveFillColor() // Same color, different char
                : null;

            if ($filled > 0) {
                $innerFill = $tipSeq !== null ? max(0, $filled - 1) : $filled;
                $bar .= $fillSeq . str_repeat($this->fillChar, $innerFill) . $reset;
                if ($tipSeq !== null && $filled > 0) {
                    $bar .= $tipSeq . $this->tipChar . $reset;
                }
            }
        }

        // Empty region
        $emptySeq = $this->resolveEmptyColor();
        $bar .= $emptySeq . str_repeat($this->emptyChar, $empty) . $reset;

        // Overlay label if present
        if ($this->label !== null) {
            $bar = $this->overlayLabel($bar, $this->label, $barWidth, $reset);
        }

        // Assemble
        $result = '';
        if ($this->leftBracket !== '') {
            $result .= $this->resolveBracketColor() . $this->leftBracket . $reset;
        }
        $result .= $bar;
        if ($this->rightBracket !== '') {
            $result .= $this->resolveBracketColor() . $this->rightBracket . $reset;
        }
        $result .= $pctStr;

        return AnsiUtils::truncateToWidth($result, $columns);
    }

    // ── Indeterminate Rendering ───────────────────────────────────────

    private function renderIndeterminate(int $columns, string $reset): string
    {
        $bracketWidth = mb_strlen($this->leftBracket) + mb_strlen($this->rightBracket);
        $barWidth = max(1, $columns - $bracketWidth);

        $animBarCols = (int) round($this->animBarWidth * $barWidth);
        $offset = (int) round($this->animPhase * $barWidth);

        // Build the animated bar
        $fillSeq = $this->resolveFillColor();
        $emptySeq = $this->resolveEmptyColor();

        $bar = '';
        for ($i = 0; $i < $barWidth; $i++) {
            $dist = $i - $offset;
            if ($dist >= 0 && $dist < $animBarCols) {
                // Fade: stronger at center, weaker at edges
                $centerDist = abs($dist - $animBarCols / 2) / ($animBarCols / 2);
                if ($centerDist < 0.8) {
                    $bar .= $fillSeq . $this->fillChar . $reset;
                } else {
                    $bar .= $fillSeq . $this->fillChar . $reset;
                }
            } else {
                $bar .= $emptySeq . $this->emptyChar . $reset;
            }
        }

        $result = '';
        if ($this->leftBracket !== '') {
            $result .= $this->resolveBracketColor() . $this->leftBracket . $reset;
        }
        $result .= $bar;
        if ($this->rightBracket !== '') {
            $result .= $this->resolveBracketColor() . $this->rightBracket . $reset;
        }

        return AnsiUtils::truncateToWidth($result, $columns);
    }

    // ── Label Overlay ─────────────────────────────────────────────────

    /**
     * Overlay a label centered on the bar, splitting it into filled/empty regions.
     *
     * The label is rendered in the label color, overwriting the bar characters
     * at the centered position.
     */
    private function overlayLabel(string $bar, string $label, int $barWidth, string $reset): string
    {
        // Strip ANSI to find visible positions
        $plain = AnsiUtils::stripAnsiCodes($bar);
        $labelVisible = AnsiUtils::visibleWidth($label);
        $startPos = (int) floor(($barWidth - $labelVisible) / 2);
        $startPos = max(0, $startPos);

        // Build label with label color
        $labelSeq = $this->resolveLabelColor();
        $labeled = $labelSeq . $label . $reset;

        // Splice into the plain bar at the right position
        // For simplicity, rebuild: prefix + label + suffix
        $prefix = mb_substr($plain, 0, $startPos);
        $suffix = mb_substr($plain, $startPos + $labelVisible);

        // Re-colorize prefix and suffix by extracting ANSI chunks
        // Simple approach: build new string from scratch
        $result = '';
        $result .= AnsiUtils::truncateToWidth($bar, $startPos);
        $result .= $labeled;

        // Get suffix portion
        $fullVisible = AnsiUtils::visibleWidth(AnsiUtils::stripAnsiCodes($bar));
        if ($startPos + $labelVisible < $fullVisible) {
            // We need the portion after the label
            // Use stripAnsiCodes + colorize approach
            $suffixPlain = mb_substr($plain, $startPos + $labelVisible);
            // Re-colorize: determine if we're in fill or empty region
            $filled = (int) round($this->ratio * $barWidth);
            if ($startPos + $labelVisible >= $filled) {
                $result .= $this->resolveEmptyColor() . $suffixPlain . $reset;
            } else {
                // Mixed — just use the empty color for remaining
                $result .= $this->resolveFillColor() . $suffixPlain . $reset;
            }
        }

        return $result;
    }

    // ── Color Resolution ──────────────────────────────────────────────

    private function resolveFillColor(): string
    {
        if ($this->fillColor !== null) {
            return $this->colorToAnsi($this->fillColor);
        }

        $style = $this->resolveElement('fill');
        if ($style->getForegroundColor() !== null) {
            return $this->colorToAnsi($style->getForegroundColor());
        }

        return "\033[38;2;80;200;120m"; // Default green
    }

    private function resolveEmptyColor(): string
    {
        if ($this->emptyColor !== null) {
            return $this->colorToAnsi($this->emptyColor);
        }

        $style = $this->resolveElement('empty');
        if ($style->getForegroundColor() !== null) {
            return $this->colorToAnsi($style->getForegroundColor());
        }

        return "\033[38;5;240m"; // Default dim gray
    }

    private function resolveLabelColor(): string
    {
        if ($this->labelColor !== null) {
            return $this->colorToAnsi($this->labelColor);
        }

        $style = $this->resolveElement('label');
        if ($style->getForegroundColor() !== null) {
            return $this->colorToAnsi($style->getForegroundColor());
        }

        return "\033[1;37m"; // Default bold white
    }

    private function resolveBracketColor(): string
    {
        if ($this->bracketColor !== null) {
            return $this->colorToAnsi($this->bracketColor);
        }

        $style = $this->resolveElement('bracket');
        if ($style->getForegroundColor() !== null) {
            return $this->colorToAnsi($style->getForegroundColor());
        }

        return "\033[38;5;240m"; // Default dim gray
    }

    private function colorToAnsi(Color $color): string
    {
        $rgb = $color->toRgb();

        return "\033[38;2;{$rgb[0]};{$rgb[1]};{$rgb[2]}m";
    }
}
```

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Always returns `list<string>` with 1 element** | Consistent with `render(): array` contract. Gauge is always 1 row. |
| **Animation driven by owner** | No `ScheduledTickTrait` — the gauge is a pure render widget. The caller (e.g., `TuiCoreRenderer`) advances the animation on its tick. Keeps widget stateless re: timers. |
| **Gradient is per-character** | Each column gets its own color, interpolated across the bar width. Visually smoother than 2-color blocks. |
| **Label overlay approach** | Labels are rendered by splicing into the bar at the center position. The bar's fill/empty regions are preserved visually; the label overwrites the characters. |
| **Brackets optional (default: none)** | Status bar use case wants a clean bar without brackets. Dashboard use case can add `[`/`]`. |
| **Shares `interpolateColor()` with SparklineWidget** | Both widgets need the same gradient math. Static method on `SparklineWidget` for now; extract to utility if more widgets need it. |

---

## Shared Infrastructure

### `SparklineWidget::interpolateColor()`

The `interpolateColor(Color $start, Color $end, float $t): Color` method is defined as `public static` on `SparklineWidget` and reused by `GaugeWidget`. Both widgets import it directly.

**Future extraction**: If more widgets need gradient support (e.g., a `HeatmapWidget`), extract to a dedicated utility class:

```
src/UI/Tui/Helper/GradientHelper.php
```

With methods:
- `interpolateColor(Color $start, Color $end, float $t): Color`
- `interpolateColorRgb(array $start, array $end, float $t): array`
- `multiStopGradient(array $stops, float $t): Color`

For now, the static method approach keeps the dependency graph simple.

---

## Stylesheet Integration

Add the following rules to `KosmokratorStyleSheet.php`:

```php
use Kosmokrator\UI\Tui\Widget\SparklineWidget;
use Kosmokrator\UI\Tui\Widget\GaugeWidget;

// In the style rules array:

// Sparkline
SparklineWidget::class => new Style(
    padding: new Padding(0, 1, 0, 0),
),
SparklineWidget::class.'::bar' => new Style(
    color: Color::hex('#909090'),  // Neutral gray default
),

// Gauge
GaugeWidget::class => new Style(
    padding: new Padding(0, 1, 0, 0),
),
GaugeWidget::class.'::fill' => new Style(
    color: Color::hex('#50c878'),  // Green
),
GaugeWidget::class.'::empty' => new Style(
    color: Color::hex('#404040'),  // Dark gray
),
GaugeWidget::class.'::label' => new Style(
    color: Color::hex('#ffffff'),
    bold: true,
),
GaugeWidget::class.'::bracket' => new Style(
    color: Color::hex('#606060'),
),
```

### Semantic Override Classes

For specific use cases, add style-class overrides:

```php
// Token usage sparkline (green → red gradient by default)
'.sparkline-tokens' => new Style(
    color: Color::hex('#50c878'),
),

// Context gauge (threshold-colored)
'.gauge-context' => new Style(
    color: Color::hex('#50c878'),
),

// Swarm progress gauge
'.gauge-swarm' => new Style(
    color: Color::hex('#ffc850'),
),
```

Usage:
```php
$sparkline = (new SparklineWidget($data))->addStyleClass('sparkline-tokens');
$gauge = (new GaugeWidget(0.62))->addStyleClass('gauge-context');
```

---

## Testing Strategy

### Unit Tests

#### `SparklineWidgetTest`

| Test | Description |
|------|-------------|
| `testRenderEmptyData` | Returns `['']` for empty data |
| `testRenderSingleValue` | One data point → one full block `█` |
| `testRenderEightLevels` | 8 data points evenly spaced → all 8 block chars appear |
| `testRenderSlidingWindow` | `push()` beyond `maxItems` drops oldest values |
| `testRenderWidthCapping` | Output truncated to `$context->getColumns()` |
| `testSingleColorMode` | All bars use the same color sequence |
| `testGradientMode` | Low values use start color, high values use end color, middle is interpolated |
| `testThresholdMode` | Values below threshold get first color, above get second |
| `testExplicitDataMax` | Custom `dataMax` normalizes correctly even with outliers |
| `testMultiLineHeight2` | Returns 2 lines; bottom row has blocks, top row has blocks/spaces |
| `testMultiLineHeight4` | Returns 4 lines |
| `testInvalidateCalledOnSetData` | Verify `invalidate()` is triggered |
| `testInvalidateCalledOnPush` | Verify `invalidate()` is triggered |

#### `GaugeWidgetTest`

| Test | Description |
|------|-------------|
| `testRenderZeroPercent` | 0.0 ratio → all empty chars |
| `testRenderHundredPercent` | 1.0 ratio → all fill chars |
| `testRenderFiftyPercent` | 0.5 ratio → half fill, half empty |
| `testRenderWithBrackets` | Brackets appear at start and end |
| `testRenderWithPercentage` | Percentage string appended |
| `testRenderWithLabel` | Label text centered in bar |
| `testRenderWithGradient` | Fill region has per-character gradient colors |
| `testRenderWithThresholds` | Color changes at threshold boundaries |
| `testRenderIndeterminate` | Animating bar appears, advances on `advanceAnimation()` |
| `testRenderCustomCharacters` | Custom fill/empty/tip chars are used |
| `testRenderExplicitWidth` | Bar fits to explicit width, not terminal columns |
| `testClampRatio` | Values below 0 clamped to 0, above 1 clamped to 1 |
| `testWidthCapping` | Output truncated to column count |

### Visual/Integration Tests

- Render both widgets with realistic data and snapshot the ANSI output
- Test embedding in a status-bar-like container
- Test embedding in SwarmDashboardWidget replacing the inline bar

---

## Migration Path

### Phase 1: Implement Widgets (Self-Contained)

1. Create `src/UI/Tui/Widget/SparklineWidget.php`
2. Create `src/UI/Tui/Widget/GaugeWidget.php`
3. Add stylesheet rules to `KosmokratorStyleSheet.php`
4. Write unit tests for both widgets
5. **No changes to existing code** — widgets are new additions

### Phase 2: Integrate Sparkline into Status Bar

1. In `TuiCoreRenderer`, create a `SparklineWidget` instance for token usage
2. Wire `push()` calls into the existing token tracking flow
3. Render the sparkline in the status bar line, replacing or augmenting the static token count

### Phase 3: Replace Swarm Dashboard Inline Bar

1. Replace `SwarmDashboardWidget.php:104–111` inline bar with `GaugeWidget`:

```php
// Before:
$barWidth = 38;
$filled = (int) round($pct * $barWidth);
$empty = $barWidth - $filled;
$barColor = $pct < 0.5 ? $green : $gold;
$pctStr = number_format($pct * 100, 1).'%';
$lines[] = $pad("{$barColor}".str_repeat('█', $filled)."{$dim}".str_repeat('░', $empty)."{$r}  {$white}{$pctStr}{$r}");

// After:
$gauge = (new GaugeWidget($pct))
    ->setWidth(38)
    ->setShowPercentage(true)
    ->setFillChar('█')
    ->setEmptyChar('░')
    ->setThresholds([
        0.5 => Color::hex('#50dc64'),  // green
        0.8 => Color::hex('#ffc850'),  // gold
        1.0 => Color::hex('#ff503c'),  // red
    ]);
$gaugeRender = $gauge->render($context);
$lines[] = $pad($gaugeRender[0]);
```

### Phase 4: Replace/Augment Symfony ProgressBarWidget

1. Evaluate whether `GaugeWidget` can fully replace `ProgressBarWidget` in the status bar
2. `ProgressBarWidget` has features `GaugeWidget` doesn't (format strings, elapsed time, indeterminate animation via `ScheduledTickTrait`)
3. **Decision**: Keep `ProgressBarWidget` for complex progress scenarios (file operations, long-running tasks). Use `GaugeWidget` for simple percentage displays (context window, swarm progress)
4. Both coexist; `GaugeWidget` fills the "compact status gauge" niche

---

## File Layout

```
src/UI/Tui/
├── Widget/
│   ├── SparklineWidget.php          ← NEW
│   ├── GaugeWidget.php              ← NEW
│   ├── SwarmDashboardWidget.php     ← MODIFIED (Phase 3: use GaugeWidget)
│   └── ...existing widgets...
├── KosmokratorStyleSheet.php        ← MODIFIED (add SparklineWidget/GaugeWidget rules)
├── TuiCoreRenderer.php              ← MODIFIED (Phase 2: sparkline in status bar)
└── ...

tests/Unit/UI/Tui/Widget/
├── SparklineWidgetTest.php          ← NEW
├── GaugeWidgetTest.php              ← NEW
└── ...

docs/plans/tui-overhaul/02-widget-library/
└── 05-sparkline-gauge.md            ← THIS FILE
```

---

## Open Questions

| # | Question | Recommendation |
|---|----------|---------------|
| 1 | Should `Color::toRgb(): array` exist on the Symfony `Color` class? | Check vendor API. If not, use `Color::hex()` construction from RGB components or access internal properties. |
| 2 | Multi-line sparkline rendering quality | Start with height=1 only. Multi-line is a stretch goal; the stacking approach in the sketch may need refinement for visual quality. |
| 3 | Label overlay in GaugeWidget | The current approach (rebuild from stripped + recolorize) is simple but may break with complex gradient fills. Consider using a dedicated label rendering pass that builds ANSI from scratch. |
| 4 | Should we add `Color::rgb()` static factory? | The Symfony `Color` class likely has `Color::hex()` and possibly `Color::rgb()`. Verify and use what's available. If `Color::rgb(int,int,int)` doesn't exist, use `Color::hex()` with computed hex string. |
| 5 | Thread safety of `push()` | Not a concern in PHP's single-threaded model, but the sliding window (`array_shift`) is O(n). For `maxItems=40` this is negligible. |
