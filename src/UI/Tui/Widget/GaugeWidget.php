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
 *       ->setInlineLabel('124k/200k')
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

    /** @var float Current ratio (0.0–1.0) */
    private float $ratio;

    /** @var string One of COLOR_* constants */
    private string $colorMode = self::COLOR_SINGLE;

    /** @var string|null Inline label text. Null = no label. */
    private ?string $label = null;

    /** @var bool Whether to show "XX.X%" after the bar */
    private bool $showPercentage = false;

    /** @var string Custom percentage format string. %s = formatted number. */
    private string $percentageFormat = '%s%%';

    /** @var int Number of decimals in percentage display */
    private int $percentageDecimals = 1;

    /** @var string Fill character */
    private string $fillChar = self::DEFAULT_FILL_CHAR;

    /** @var string Empty character */
    private string $emptyChar = self::DEFAULT_EMPTY_CHAR;

    /** @var string|null Tip character (at fill/empty boundary). Null = no tip. */
    private ?string $tipChar = null;

    /** @var string Left bracket character */
    private string $leftBracket = '';

    /** @var string Right bracket character */
    private string $rightBracket = '';

    /** @var int|null Explicit width in columns. Null = auto-fit to terminal. */
    private ?int $width = null;

    /** @var bool Whether the gauge is in indeterminate (animated) mode */
    private bool $indeterminate = false;

    /** @var float Animation phase (0.0–1.0+), advanced by advanceAnimation() */
    private float $animPhase = 0.0;

    /** @var float Animation speed (phase increment per tick) */
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
     * @var list<array{threshold: float, color: Color}> Threshold definitions
     * Sorted ascending by threshold.
     */
    private array $thresholds = [];

    /**
     * @param float $ratio Initial ratio (0.0–1.0). Use setIndeterminate(true) for animated mode.
     */
    public function __construct(float $ratio = 0.0)
    {
        $this->ratio = $ratio;
    }

    // ── Configuration ─────────────────────────────────────────────────

    /** Set the current ratio (0.0–1.0). Values are clamped. */
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

    /** Set the inline label text color. */
    public function setLabelColor(Color $color): static
    {
        $this->labelColor = $color;
        $this->invalidate();

        return $this;
    }

    /** Set the bracket color. */
    public function setBracketColor(Color $color): static
    {
        $this->bracketColor = $color;
        $this->invalidate();

        return $this;
    }

    /**
     * Set the inline label text rendered centered inside the bar.
     *
     * This is distinct from the widget metadata label (AbstractWidget::setLabel)
     * which is used by parent containers like TabsWidget.
     */
    public function setInlineLabel(?string $label): static
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

    /** Set the tip character (at fill/empty boundary). Null = no tip. */
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

    /** Set the animation speed (phase increment per tick). Default: 0.04. */
    public function setAnimSpeed(float $speed): static
    {
        $this->animSpeed = $speed;

        return $this;
    }

    /** Set the animation bar width as a fraction of total width (0.0–1.0). Default: 0.3. */
    public function setAnimBarWidth(float $width): static
    {
        $this->animBarWidth = max(0.05, min(0.9, $width));

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
     * @param array<float|int, Color> $thresholds Map of [0.0–1.0 threshold => Color]
     *   Example: [0.7 => $green, 0.9 => $gold, 1.0 => $red]
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

    // ── Rendering ─────────────────────────────────────────────────────

    /**
     * Render the gauge as a single ANSI-formatted line.
     *
     * @return list<string> Single-element array containing the formatted line
     */
    public function render(RenderContext $context): array
    {
        $columns = $this->width ?? $context->getColumns();

        if ($this->indeterminate) {
            return [$this->renderIndeterminate($columns)];
        }

        return [$this->renderDeterminate($columns)];
    }

    // ── Determinate Rendering ─────────────────────────────────────────

    private function renderDeterminate(int $columns): string
    {
        $reset = "\033[0m";

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
        $bar = $this->buildFilledBar($filled, $empty, $barWidth, $reset);

        // Append empty region
        $emptySeq = $this->resolveEmptyColor();
        $bar .= $emptySeq . str_repeat($this->emptyChar, $empty) . $reset;

        // Overlay label if present
        if ($this->label !== null) {
            $bar = $this->overlayLabel($bar, $this->label, $barWidth, $reset);
        }

        // Assemble full result
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

    /**
     * Build the filled portion of the bar.
     */
    private function buildFilledBar(int $filled, int $empty, int $barWidth, string $reset): string
    {
        if ($filled <= 0) {
            return '';
        }

        if ($this->colorMode === self::COLOR_GRADIENT) {
            return $this->buildGradientFill($filled, $empty, $barWidth, $reset);
        }

        if ($this->colorMode === self::COLOR_THRESHOLD) {
            return $this->buildThresholdFill($filled, $empty, $reset);
        }

        return $this->buildSingleFill($filled, $empty, $reset);
    }

    /**
     * Build a single-color fill region.
     */
    private function buildSingleFill(int $filled, int $empty, string $reset): string
    {
        $fillSeq = $this->resolveFillColor();

        // Check if we should use a tip character at the boundary
        $useTip = $this->tipChar !== null && $empty > 0;

        if ($useTip) {
            $innerFill = max(0, $filled - 1);
            $bar = '';
            if ($innerFill > 0) {
                $bar .= $fillSeq . str_repeat($this->fillChar, $innerFill) . $reset;
            }
            $bar .= $fillSeq . $this->tipChar . $reset;

            return $bar;
        }

        return $fillSeq . str_repeat($this->fillChar, $filled) . $reset;
    }

    /**
     * Build a gradient fill region — each column gets its own interpolated color.
     */
    private function buildGradientFill(int $filled, int $empty, int $barWidth, string $reset): string
    {
        $start = $this->gradientStart ?? Color::hex('#50c878');
        $end = $this->gradientEnd ?? Color::hex('#ff503c');

        $bar = '';
        for ($i = 0; $i < $filled; $i++) {
            $t = $barWidth > 1 ? $i / ($barWidth - 1) : 0.0;
            $color = SparklineWidget::interpolateColor($start, $end, $t);
            $seq = $color->toForegroundCode();
            $char = ($this->tipChar !== null && $i === $filled - 1 && $empty > 0)
                ? $this->tipChar
                : $this->fillChar;
            $bar .= $seq . $char . $reset;
        }

        return $bar;
    }

    /**
     * Build a threshold-based fill region.
     */
    private function buildThresholdFill(int $filled, int $empty, string $reset): string
    {
        if (empty($this->thresholds)) {
            return $this->buildSingleFill($filled, $empty, $reset);
        }

        // Determine color based on ratio
        $fillColor = $this->resolveThresholdColorForRatio($this->ratio);
        $fillSeq = $fillColor->toForegroundCode();

        $useTip = $this->tipChar !== null && $empty > 0;

        if ($useTip) {
            $innerFill = max(0, $filled - 1);
            $bar = '';
            if ($innerFill > 0) {
                $bar .= $fillSeq . str_repeat($this->fillChar, $innerFill) . $reset;
            }
            $bar .= $fillSeq . $this->tipChar . $reset;

            return $bar;
        }

        return $fillSeq . str_repeat($this->fillChar, $filled) . $reset;
    }

    // ── Indeterminate Rendering ───────────────────────────────────────

    private function renderIndeterminate(int $columns): string
    {
        $reset = "\033[0m";
        $bracketWidth = mb_strlen($this->leftBracket) + mb_strlen($this->rightBracket);
        $barWidth = max(1, $columns - $bracketWidth);

        $animBarCols = (int) round($this->animBarWidth * $barWidth);
        $offset = (int) round($this->animPhase * $barWidth);

        $fillSeq = $this->resolveFillColor();
        $emptySeq = $this->resolveEmptyColor();

        $bar = '';
        for ($i = 0; $i < $barWidth; $i++) {
            $dist = $i - $offset;
            if ($dist >= 0 && $dist < $animBarCols) {
                $bar .= $fillSeq . $this->fillChar . $reset;
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
     * Overlay a label centered on the bar.
     *
     * The label is rendered in the label color, overwriting characters at
     * the centered position. The bar's fill/empty coloring is preserved
     * around the label.
     */
    private function overlayLabel(string $bar, string $label, int $barWidth, string $reset): string
    {
        $labelVisible = AnsiUtils::visibleWidth($label);
        $startPos = (int) floor(($barWidth - $labelVisible) / 2);
        $startPos = max(0, $startPos);

        $labelSeq = $this->resolveLabelColor();

        // Build: [prefix up to startPos] + [label] + [suffix after label]
        $prefix = AnsiUtils::sliceByColumn($bar, 0, $startPos);

        $labelEnd = $startPos + $labelVisible;
        $suffix = '';
        $barVisible = AnsiUtils::visibleWidth(AnsiUtils::stripAnsiCodes($bar));
        if ($labelEnd < $barVisible) {
            // Extract the suffix portion from the original bar
            $suffixSlice = AnsiUtils::sliceByColumn($bar, $labelEnd, $barVisible - $labelEnd);
            // Re-colorize: determine if we're in fill or empty region
            $filled = (int) round($this->ratio * $barWidth);
            if ($labelEnd >= $filled) {
                $suffix = $this->resolveEmptyColor() . AnsiUtils::stripAnsiCodes($suffixSlice) . $reset;
            } else {
                $suffix = $this->resolveFillColor() . AnsiUtils::stripAnsiCodes($suffixSlice) . $reset;
            }
        }

        return $prefix . $labelSeq . $label . $reset . $suffix;
    }

    // ── Color Resolution ──────────────────────────────────────────────

    private function resolveFillColor(): string
    {
        if ($this->fillColor !== null) {
            return $this->fillColor->toForegroundCode();
        }

        $style = $this->resolveElement('fill');
        $fgColor = $style->getColor();
        if ($fgColor !== null) {
            return $fgColor->toForegroundCode();
        }

        return "\033[38;2;80;200;120m"; // Default green
    }

    private function resolveEmptyColor(): string
    {
        if ($this->emptyColor !== null) {
            return $this->emptyColor->toForegroundCode();
        }

        $style = $this->resolveElement('empty');
        $fgColor = $style->getColor();
        if ($fgColor !== null) {
            return $fgColor->toForegroundCode();
        }

        return "\033[38;5;240m"; // Default dim gray
    }

    private function resolveLabelColor(): string
    {
        if ($this->labelColor !== null) {
            return $this->labelColor->toForegroundCode();
        }

        $style = $this->resolveElement('label');
        $fgColor = $style->getColor();
        if ($fgColor !== null) {
            return $fgColor->toForegroundCode();
        }

        return "\033[1;37m"; // Default bold white
    }

    private function resolveBracketColor(): string
    {
        if ($this->bracketColor !== null) {
            return $this->bracketColor->toForegroundCode();
        }

        $style = $this->resolveElement('bracket');
        $fgColor = $style->getColor();
        if ($fgColor !== null) {
            return $fgColor->toForegroundCode();
        }

        return "\033[38;5;240m"; // Default dim gray
    }

    /**
     * Resolve the fill Color object for a given ratio using threshold definitions.
     */
    private function resolveThresholdColorForRatio(float $ratio): Color
    {
        if ($this->thresholds === []) {
            return Color::hex('#50c878');
        }

        foreach ($this->thresholds as $entry) {
            if ($ratio <= $entry['threshold']) {
                return $entry['color'];
            }
        }

        $lastEntry = end($this->thresholds);
        return $lastEntry !== false ? $lastEntry['color'] : Color::hex('#ff503c');
    }
}
