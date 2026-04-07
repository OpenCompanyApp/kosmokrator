<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Adaptive status bar with three-segment layout: Left (mode + permission),
 * Center (token gauge), Right (model + cost).
 *
 * Renders a single ANSI line that fills the full terminal width.
 * Responsive breakpoints control segment visibility based on available columns.
 *
 * Layout:
 *   ┌──────────────────────────────────────────────────────────────────────┐
 *   │ EDIT ┃ Guardian ◈ │  12.4k/200k ━━━━━━━━━━━━━━━━━──░░░░  6% │ $0.04 │
 *   └──────────────────────────────────────────────────────────────────────┘
 *     LEFT              CENTER (gauge)                                 RIGHT
 */
final class StatusBarWidget extends AbstractWidget
{
    // ── Responsive breakpoints (columns) ────────────────────────────
    private const BREAKPOINT_WIDE = 100;
    private const BREAKPOINT_MEDIUM = 80;
    private const BREAKPOINT_NARROW = 60;

    // ── Gauge ───────────────────────────────────────────────────────
    private const GAUGE_MIN_WIDTH = 4;
    private const GAUGE_MAX_WIDTH = 24;
    private const GAUGE_FILLED = '━';
    private const GAUGE_EMPTY = '─';

    // ── Separators ──────────────────────────────────────────────────
    private const SEP_MAJOR = ' ┃ ';
    private const SEP_MINOR = ' │ ';

    // ── Mode presets: foreground [R,G,B] and background [R,G,B] ─────
    private const MODE_PRESETS = [
        'Edit' => ['fg' => [80, 220, 100], 'bg' => [20, 80, 40]],
        'Plan' => ['fg' => [160, 120, 255], 'bg' => [50, 30, 100]],
        'Ask' => ['fg' => [255, 200, 80], 'bg' => [80, 60, 20]],
        'Explore' => ['fg' => [100, 200, 220], 'bg' => [20, 60, 70]],
    ];

    private const IDLE_FG = [140, 140, 150];
    private const IDLE_BG = [30, 30, 35];

    // ── State: mode ─────────────────────────────────────────────────
    private string $modeLabel = 'Edit';
    private string $modeFg;
    private string $modeBg;

    // ── State: permission ───────────────────────────────────────────
    private string $permissionLabel = '';
    private string $permissionColor;

    // ── State: token usage ──────────────────────────────────────────
    private int $tokensIn = 0;
    private int $maxContext = 200_000;

    // ── State: model / cost ─────────────────────────────────────────
    private string $modelName = '';
    private float $cost = 0.0;

    // ── State: idle ─────────────────────────────────────────────────
    private bool $idle = true;

    public function __construct()
    {
        $this->modeFg = Theme::rgb(...self::MODE_PRESETS['Edit']['fg']);
        $this->modeBg = Theme::bgRgb(...self::MODE_PRESETS['Edit']['bg']);
        $this->permissionColor = Theme::dimWhite();
    }

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Set the current agent mode (Edit, Plan, Ask, Explore).
     * Automatically resolves foreground and background colors from presets.
     *
     * @param string      $label   Mode label (e.g. 'Edit', 'Plan')
     * @param string|null $fgColor Optional explicit foreground ANSI escape; null uses preset
     */
    public function setMode(string $label, ?string $fgColor = null): void
    {
        $this->modeLabel = $label;
        $this->idle = false;

        $preset = self::MODE_PRESETS[$label] ?? null;

        if ($fgColor !== null) {
            $this->modeFg = $fgColor;
        } elseif ($preset !== null) {
            $this->modeFg = Theme::rgb(...$preset['fg']);
        }

        if ($preset !== null) {
            $this->modeBg = Theme::bgRgb(...$preset['bg']);
        }

        $this->invalidate();
    }

    /**
     * Set the permission mode label and color.
     *
     * @param string $label Permission label (e.g. 'Guardian ◈', 'Auto ✓')
     * @param string $color ANSI foreground escape sequence
     */
    public function setPermission(string $label, string $color): void
    {
        $this->permissionLabel = $label;
        $this->permissionColor = $color;
        $this->invalidate();
    }

    /**
     * Update token usage for the gauge segment.
     *
     * @param int $tokensIn    Tokens currently consumed
     * @param int $maxContext  Maximum context window size
     */
    public function setTokenUsage(int $tokensIn, int $maxContext): void
    {
        $this->tokensIn = $tokensIn;
        $this->maxContext = max(1, $maxContext);
        $this->invalidate();
    }

    /**
     * Set model name and session cost.
     *
     * @param string $model Model identifier (e.g. 'claude-sonnet-4-20250514')
     * @param float  $cost  Session cost in USD
     */
    public function setModelAndCost(string $model, float $cost): void
    {
        $this->modelName = $model;
        $this->cost = $cost;
        $this->invalidate();
    }

    /**
     * Set idle state — overrides mode styling with muted gray.
     *
     * @param bool $idle True when waiting for user input
     */
    public function setIdle(bool $idle): void
    {
        $this->idle = $idle;
        $this->invalidate();
    }

    // ── Rendering ───────────────────────────────────────────────────

    /**
     * Render the status bar as a single ANSI-formatted line.
     *
     * Returns a single-element array containing the full-width status line,
     * padded to fill exactly the terminal width with no trailing artifacts.
     *
     * @param RenderContext $context Terminal dimensions
     *
     * @return list<string> Single-element array with the full-width status line
     */
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $line = $this->buildLine($cols);

        // Ensure the line fills the full width (no trailing artifacts)
        $visibleLen = AnsiUtils::visibleWidth($line);
        $rightFill = str_repeat(' ', max(0, $cols - $visibleLen));

        return [$line . $rightFill];
    }

    // ── Internal: layout assembly ───────────────────────────────────

    /**
     * Assemble the full status line from segments based on available width.
     */
    private function buildLine(int $cols): string
    {
        $r = Theme::reset();
        $sepMajor = self::SEP_MAJOR;
        $sepMinor = self::SEP_MINOR;
        $sepMajorLen = AnsiUtils::visibleWidth($sepMajor);
        $sepMinorLen = AnsiUtils::visibleWidth($sepMinor);

        // 1. Build left segment (always visible)
        $left = $this->renderLeftSegment($cols);

        // 2. Determine responsive visibility
        $showGauge = $cols >= self::BREAKPOINT_NARROW;
        $showModel = $cols >= self::BREAKPOINT_MEDIUM;
        $showCost = $cols >= self::BREAKPOINT_NARROW;

        // 3. Build right segment first to know its width for gauge calculation
        $rightParts = [];
        $rightVisibleWidth = 0;

        if ($showModel && $this->modelName !== '') {
            $maxModelLen = $cols >= self::BREAKPOINT_WIDE ? 25 : 18;
            $model = $this->modelName;
            if (mb_strlen($model) > $maxModelLen) {
                $model = mb_substr($model, 0, $maxModelLen - 1) . '…';
            }
            $dimWhite = Theme::dimWhite();
            $modelPart = "{$dimWhite}{$model}{$r}";
            $rightParts[] = $modelPart;
            $rightVisibleWidth += AnsiUtils::visibleWidth($modelPart);
        }

        if ($showCost && $this->cost > 0.0) {
            $costStr = Theme::formatCost($this->cost);
            $dimWhite = Theme::dimWhite();
            $costPart = "{$dimWhite}{$costStr}{$r}";
            $rightParts[] = $costPart;
            $rightVisibleWidth += AnsiUtils::visibleWidth($costPart);
        }

        // Separator width between right parts
        if (\count($rightParts) > 1) {
            $rightVisibleWidth += $sepMinorLen;
        }

        $right = implode($sepMinor, $rightParts);

        // 4. Build center gauge
        $center = '';
        $leftLen = AnsiUtils::visibleWidth($left);
        $rightLen = AnsiUtils::visibleWidth($right);

        // Count separators that will be used
        $separatorCount = 0;
        if ($showGauge && $this->tokensIn > 0) {
            ++$separatorCount; // left | center
        }
        if ($right !== '') {
            ++$separatorCount; // center | right or left | right
        }
        $totalSepWidth = $separatorCount * $sepMajorLen;

        if ($showGauge && $this->tokensIn > 0) {
            $gaugeAvailable = $cols - $leftLen - $rightLen - $totalSepWidth;
            $center = $this->renderGaugeSegment($gaugeAvailable);
        }

        // 5. Assemble with separators
        $result = $left;

        if ($center !== '') {
            $result .= $sepMajor . $center;
        }

        if ($right !== '') {
            $result .= $sepMajor . $right;
        }

        return $result;
    }

    // ── Internal: left segment ──────────────────────────────────────

    /**
     * Render the left segment: mode pill + optional permission label.
     *
     * Examples (wide):  " EDIT ┃ Guardian ◈"
     *          (narrow): " EDIT"
     */
    private function renderLeftSegment(int $cols): string
    {
        $r = Theme::reset();

        $fg = $this->idle ? Theme::rgb(...self::IDLE_FG) : $this->modeFg;
        $bg = $this->idle ? Theme::bgRgb(...self::IDLE_BG) : $this->modeBg;
        $bold = Theme::bold();

        // Mode pill with background and bold foreground
        $pill = "{$bg}{$bold}{$fg} {$this->modeLabel} {$r}";

        // Permission label — only show above narrow breakpoint and when set
        if ($cols >= self::BREAKPOINT_NARROW && $this->permissionLabel !== '') {
            $pill .= self::SEP_MINOR . $this->permissionColor . $this->permissionLabel . $r;
        }

        return $pill;
    }

    // ── Internal: center gauge ──────────────────────────────────────

    /**
     * Render the center gauge segment: token usage bar + labels.
     *
     * Example: "12.4k/200k ━━━━━━━━━━━━━━━━━──░░░░  6%"
     *
     * @param int $availableWidth Character width available for the entire gauge segment
     */
    private function renderGaugeSegment(int $availableWidth): string
    {
        $r = Theme::reset();
        $ratio = min(1.0, $this->tokensIn / $this->maxContext);
        $pct = (int) round($ratio * 100);

        $inLabel = Theme::formatTokenCount($this->tokensIn);
        $maxLabel = Theme::formatTokenCount($this->maxContext);
        $label = "{$inLabel}/{$maxLabel}";
        $pctStr = "{$pct}%";

        // Calculate bar width: available - label - percentage - surrounding spaces
        $textOverhead = AnsiUtils::visibleWidth($label) + AnsiUtils::visibleWidth($pctStr) + 4;
        $barWidth = min(self::GAUGE_MAX_WIDTH, max(self::GAUGE_MIN_WIDTH, $availableWidth - $textOverhead));

        // If not enough room for even the minimum bar, just show the label
        if ($availableWidth < $textOverhead + self::GAUGE_MIN_WIDTH) {
            $ctxColor = $this->gradientColor($ratio);

            return "{$ctxColor}{$label}{$r}";
        }

        $filled = (int) round($ratio * $barWidth);
        $empty = $barWidth - $filled;

        $barColor = $this->gradientColor($ratio);
        $dimColor = Theme::dimmer();

        $bar = $barColor . str_repeat(self::GAUGE_FILLED, $filled)
             . $dimColor . str_repeat(self::GAUGE_EMPTY, $empty) . $r;

        $ctxColor = $this->gradientColor($ratio);

        return "{$ctxColor}{$label}{$r} {$bar} {$ctxColor}{$pctStr}{$r}";
    }

    // ── Internal: gradient ──────────────────────────────────────────

    /**
     * Compute a smooth gradient color for a given context usage ratio.
     *
     * Gradient stops:
     *   0.0 – 0.5: green  (80,220,100) → yellow (255,200,80)
     *   0.5 – 0.8: yellow (255,200,80) → orange (255,140,60)
     *   0.8 – 1.0: orange (255,140,60) → red    (255,60,40)
     *
     * @param float $ratio Usage ratio 0.0–1.0 (clamped)
     */
    private function gradientColor(float $ratio): string
    {
        $ratio = max(0.0, min(1.0, $ratio));

        if ($ratio < 0.5) {
            // Green → Yellow
            $t = $ratio / 0.5;

            return Theme::rgb(
                (int) round(80 + (255 - 80) * $t),
                (int) round(220 + (200 - 220) * $t),
                (int) round(100 + (80 - 100) * $t),
            );
        }

        if ($ratio < 0.8) {
            // Yellow → Orange
            $t = ($ratio - 0.5) / 0.3;

            return Theme::rgb(
                255,
                (int) round(200 + (140 - 200) * $t),
                (int) round(80 + (60 - 80) * $t),
            );
        }

        // Orange → Red
        $t = ($ratio - 0.8) / 0.2;

        return Theme::rgb(
            255,
            (int) round(140 + (60 - 140) * $t),
            (int) round(60 + (40 - 60) * $t),
        );
    }
}
