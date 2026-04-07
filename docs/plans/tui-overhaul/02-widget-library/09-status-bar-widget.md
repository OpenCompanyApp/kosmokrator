# Plan: StatusBarWidget — Adaptive Mode / Context / Info Bar

> **Module**: `src/UI/Tui/Widget/StatusBarWidget.php`
> **Dependencies**: `Theme`, `AnsiUtils`, `AbstractWidget` (from `symfony/tui`), future `KosmokratorStyleSheet`
> **Replaces**: The current `ProgressBarWidget` misuse in `TuiCoreRenderer` (lines 197–204, 770–778)

---

## 1. Problem Statement

The current status bar is a **repurposed `ProgressBarWidget`** that was never designed for rich segmented rendering:

```php
// TuiCoreRenderer.php:197-204 — current hack
$this->statusBar = new ProgressBarWidget(200_000, '%message%  %bar%');
$this->statusBar->setBarCharacter('━');
$this->statusBar->setEmptyBarCharacter('─');
$this->statusBar->setBarWidth(20);
```

**Issues:**
1. **No layout structure** — everything is a flat `%message%` string with manual `·` separators concatenated in `refreshStatusBar()`.
2. **No adaptive width** — bar width is hardcoded to 20 regardless of terminal width.
3. **No segment alignment** — left/center/right content is just left-to-right text with no anchoring.
4. **Mode background** — the bar has no mode-colored background strip; only foreground colors exist.
5. **Duplicated state** — `$currentModeLabel`, `$currentModeColor`, `$currentPermissionLabel`, `$currentPermissionColor`, `$statusDetail`, `$lastStatusTokensIn`, etc. are all raw properties on `TuiCoreRenderer`.
6. **No responsive breakpoints** — same output on an 80-col terminal as on a 200-col one.

**Goal:** A purpose-built `StatusBarWidget` with three anchored segments (left / center / right), mode-aware background, adaptive truncation, and responsive breakpoints.

---

## 2. Prior Art Research

### 2.1 Vim Status Bar
- **Segments:** `[mode] | [filename] | [row:col] [percent]`
- Left = mode (`-- INSERT --`, `-- VISUAL --`), center = filename, right = position + percentage.
- Mode color inverts the background (`StatusLine` vs `StatusLineNC`).
- Fully customizable via `statusline` option with `%<` truncation markers.

**Takeaway:** Mode indicator with inverted background is the gold standard. Truncation point markers are elegant.

### 2.2 Helix Status Line
```
[EDIT] main.rs  L23:C5  [warnings: 2]  utf-8  rust  45%
```
- Left: mode (color-coded), filename, cursor position.
- Right: diagnostics count, encoding, language, position percentage.
- Mode background fills the left segment entirely.

**Takeaway:** Color-coded mode pill with solid background is visually distinctive. Diagnostics on the right give contextual info.

### 2.3 Lazygit Bottom Bar
```
<esc> cancel  ^n/^p scroll  x range select
```
- Single line of **keybinding hints**.
- `<key>` notation for key descriptions, plain text for actions.
- Changes contextually based on focused panel.

**Takeaway:** Status bar content should adapt to context. Keybinding hints are a useful future extension.

### 2.4 Claude Code StatusLine
- Mode pill (left), model name + cost (center), token usage gauge (right).
- Token gauge uses gradient coloring (green → yellow → red).
- Compact in narrow terminals, full detail in wide ones.

**Takeaway:** Three-segment layout with adaptive detail is the right pattern for KosmoKrator.

---

## 3. Design

### 3.1 Segmented Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│ EDIT ┃ Guardian ◈ ┃  12.4k/200k ━━━━━━━━━━━━━━━━━──░░░░  6%  $0.04 │
└──────────────────────────────────────────────────────────────────────┘
  LEFT          LEFT          CENTER (gauge)                    RIGHT
```

| Section | Content | Alignment | Responsive Priority |
|---------|---------|-----------|-------------------|
| **Left** | Mode pill + Permission mode | Left-aligned | Always visible (priority 1) |
| **Center** | Token usage gauge + labels | Center / flex | Hidden below 60 cols (priority 2) |
| **Right** | Model name + cost | Right-aligned | Model hidden below 80 cols (priority 3) |

### 3.2 Segment Classes

```
StatusBarWidget
├── StatusBarSegment (abstract)
│   ├── ModeSegment       — mode pill with colored background
│   ├── PermissionSegment — permission mode label
│   ├── GaugeSegment      — token usage gauge bar
│   ├── ModelSegment      — model name (dim white)
│   └── CostSegment       — session cost
└── StatusBarLayout       — measures, distributes space, renders separators
```

### 3.3 Responsive Breakpoints

| Terminal Width | Left | Center | Right |
|---------------|------|--------|-------|
| ≥ 100 cols | `EDIT ┃ Guardian ◈` | `12.4k/200k ━━━━━━━━━━━━━░░ 6%` | `claude-3.5-sonnet $0.04` |
| 80–99 cols | `EDIT ┃ Guardian ◈` | `12.4k/200k ━━━━━━━━━░░ 6%` | `$0.04` |
| 60–79 cols | `EDIT ┃ Guardian ◈` | `12.4k/200k ━━━━░ 6%` | — |
| < 60 cols | `EDIT` | `12.4k/200k ━░ 6%` | — |

**Rules:**
- Priority 3 (model name) drops at < 80 cols.
- Priority 2 (gauge) shrinks its bar width at < 100 cols, drops below 60.
- Priority 1 (mode + permission) always visible; permission drops below 60.
- Gauge bar width = `available - text_overhead`, minimum 4 chars.

### 3.4 Separator Characters

```
┃  (U+2503, BOX DRAWINGS HEAVY VERTICAL)   — between major segments
│  (U+2502, BOX DRAWINGS LIGHT VERTICAL)   — between sub-segments within a section
```

Example with mixed separators:
```
 EDIT ┃ Guardian ◈ │  12.4k/200k ━━━━━━━━━━━━━━━━━──░░░░  6%  │  claude-3.5-sonnet  $0.04
```

### 3.5 Mode Colors (Background + Foreground)

Each mode gets a **dark background** and a **contrasting bright foreground**:

| Mode | Background | Foreground | Usage |
|------|-----------|-----------|-------|
| Edit | `bgRgb(20, 80, 40)` | `rgb(80, 220, 100)` | General agent |
| Plan | `bgRgb(50, 30, 100)` | `rgb(160, 120, 255)` | Plan agent |
| Ask | `bgRgb(80, 60, 20)` | `rgb(255, 200, 80)` | Ask/explore agent |
| Idle | `bgRgb(30, 30, 35)` | `rgb(140, 140, 150)` | Waiting for input |

The entire status bar background matches the current mode, but subtly (dark variant). Only the mode pill gets the stronger background.

### 3.6 Gauge Gradient

The token usage gauge uses a **continuous gradient** rather than the current 3-step color:

```
0% → 50%: green (80,220,100) → yellow (255,200,80)
50% → 80%: yellow (255,200,80) → orange (255,140,60)
80% → 100%: orange (255,140,60) → red (255,60,40)
```

This replaces the current `Theme::contextColor()` three-band approach with interpolated RGB for a smoother visual.

### 3.7 Styling via KosmokratorStyleSheet (Future)

The widget will support style tokens that map to a future `KosmokratorStyleSheet`:

```yaml
status_bar:
  background: "mode-dark"        # auto-varies with mode
  separator_major: "┃"
  separator_minor: "│"
  mode_pill:
    padding: [0, 1]              # left/right padding
    bold: true
  gauge:
    filled_char: "━"
    empty_char: "─"
    min_width: 4
  breakpoints:
    wide: 100
    medium: 80
    narrow: 60
```

Until the style sheet system exists, defaults are hardcoded as class constants.

---

## 4. Class Sketch

```php
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

    // ── State: mode ─────────────────────────────────────────────────
    private string $modeLabel = 'Edit';
    private string $modeFg = "\033[38;2;80;200;120m";
    private string $modeBg = "\033[48;2;20;80;40m";

    // ── State: permission ───────────────────────────────────────────
    private string $permissionLabel = 'Guardian ◈';
    private string $permissionColor = "\033[38;2;180;180;200m";

    // ── State: token usage ──────────────────────────────────────────
    private int $tokensIn = 0;
    private int $maxContext = 200_000;

    // ── State: model / cost ─────────────────────────────────────────
    private string $modelName = '';
    private float $cost = 0.0;

    // ── State: phase ────────────────────────────────────────────────
    private bool $idle = true;

    // ── Mode presets ────────────────────────────────────────────────
    private const MODE_PRESETS = [
        'Edit'    => ['fg' => [80, 220, 100], 'bg' => [20, 80, 40]],
        'Plan'    => ['fg' => [160, 120, 255], 'bg' => [50, 30, 100]],
        'Ask'     => ['fg' => [255, 200, 80],  'bg' => [80, 60, 20]],
        'Explore' => ['fg' => [100, 200, 220], 'bg' => [20, 60, 70]],
    ];

    private const IDLE_FG = [140, 140, 150];
    private const IDLE_BG = [30, 30, 35];

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Set the current agent mode (Edit, Plan, Ask, Explore).
     * Automatically resolves foreground and background colors from presets.
     */
    public function setMode(string $label, ?string $fgColor = null): void
    {
        $this->modeLabel = $label;
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
     */
    public function setPermission(string $label, string $color): void
    {
        $this->permissionLabel = $label;
        $this->permissionColor = $color;
        $this->invalidate();
    }

    /**
     * Update token usage for the gauge segment.
     */
    public function setTokenUsage(int $tokensIn, int $maxContext): void
    {
        $this->tokensIn = $tokensIn;
        $this->maxContext = max(1, $maxContext);
        $this->invalidate();
    }

    /**
     * Set model name and session cost.
     */
    public function setModelAndCost(string $model, float $cost): void
    {
        $this->modelName = $model;
        $this->cost = $cost;
        $this->invalidate();
    }

    /**
     * Set idle state (affects mode pill styling).
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
     * @param RenderContext $context Terminal dimensions
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

    // ── Internal ────────────────────────────────────────────────────

    private function buildLine(int $cols): string
    {
        $r = Theme::reset();
        $sep = self::SEP_MAJOR;
        $sepLen = AnsiUtils::visibleWidth($sep);

        // 1. Build the mode pill (left segment)
        $left = $this->renderLeftSegment($cols);

        // 2. Determine what else fits
        $leftLen = AnsiUtils::visibleWidth($left);
        $remaining = $cols - $leftLen;

        // 3. Build center gauge if space allows
        $center = '';
        $right = '';
        $showGauge = $cols >= self::BREAKPOINT_NARROW;
        $showModel = $cols >= self::BREAKPOINT_MEDIUM;

        if ($showGauge) {
            $center = $this->renderGaugeSegment($remaining - $sepLen);
        }

        if ($showModel) {
            $right = $this->renderRightSegment($showGauge);
        }

        // 4. Assemble with separators
        $result = $left;
        if ($center !== '') {
            $result .= $sep . $center;
        }
        if ($right !== '') {
            $result .= $sep . $right;
        }

        return $result;
    }

    /**
     * Render the left segment: mode pill + optional permission label.
     *
     * Examples:
     *   Wide:   " EDIT ┃ Guardian ◈"
     *   Narrow: " EDIT"
     */
    private function renderLeftSegment(int $cols): string
    {
        $r = Theme::reset();
        $fg = $this->idle ? Theme::rgb(...self::IDLE_FG) : $this->modeFg;
        $bg = $this->idle ? Theme::bgRgb(...self::IDLE_BG) : $this->modeBg;

        // Mode pill with background
        $pill = "{$bg}{$fg} {$this->modeLabel} {$r}";

        // Permission label (hide below narrow breakpoint)
        if ($cols >= self::BREAKPOINT_NARROW) {
            $minorSep = self::SEP_MINOR;
            $pill .= "{$minorSep}{$this->permissionColor}{$this->permissionLabel}{$r}";
        }

        return $pill;
    }

    /**
     * Render the center gauge segment: token usage bar + labels.
     *
     * Example: "12.4k/200k ━━━━━━━━━━━━━━━━━──░░░░  6%"
     *
     * @param int $availableWidth Character width available for the gauge segment
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

        // Calculate bar width: available - label - percentage - spaces
        $textOverhead = AnsiUtils::visibleWidth($label) + AnsiUtils::visibleWidth($pctStr) + 4; // spaces
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

    /**
     * Render the right segment: model name + cost.
     *
     * @param bool $gaugeVisible Whether the gauge is shown (affects layout)
     */
    private function renderRightSegment(bool $gaugeVisible): string
    {
        $r = Theme::reset();
        $dimWhite = Theme::dimWhite();

        $parts = [];

        if ($this->modelName !== '') {
            // Shorten model name if too long
            $maxModelLen = $gaugeVisible ? 25 : 40;
            $model = $this->modelName;
            if (strlen($model) > $maxModelLen) {
                $model = substr($model, 0, $maxModelLen - 1) . '…';
            }
            $parts[] = "{$dimWhite}{$model}{$r}";
        }

        if ($this->cost > 0.0) {
            $costStr = Theme::formatCost($this->cost);
            $parts[] = "{$dimWhite}{$costStr}{$r}";
        }

        return implode(self::SEP_MINOR, $parts);
    }

    /**
     * Compute a smooth gradient color for a given ratio.
     *
     * 0.0–0.5: green → yellow
     * 0.5–0.8: yellow → orange
     * 0.8–1.0: orange → red
     */
    private function gradientColor(float $ratio): string
    {
        $ratio = max(0.0, min(1.0, $ratio));

        if ($ratio < 0.5) {
            $t = $ratio / 0.5;
            return Theme::rgb(
                (int) round(80 + (255 - 80) * $t),
                (int) round(220 + (200 - 220) * $t),
                (int) round(100 + (80 - 100) * $t),
            );
        }

        if ($ratio < 0.8) {
            $t = ($ratio - 0.5) / 0.3;
            return Theme::rgb(
                255,
                (int) round(200 + (140 - 200) * $t),
                (int) round(80 + (60 - 80) * $t),
            );
        }

        $t = ($ratio - 0.8) / 0.2;
        return Theme::rgb(
            255,
            (int) round(140 + (60 - 140) * $t),
            (int) round(60 + (40 - 60) * $t),
        );
    }
}
```

---

## 5. Integration with TuiCoreRenderer

### 5.1 Current State Extraction

The following properties in `TuiCoreRenderer` become inputs to `StatusBarWidget`:

| TuiCoreRenderer Property | StatusBarWidget Method | Current Location |
|--------------------------|----------------------|-----------------|
| `$currentModeLabel` | `setMode($label)` | Line 74 |
| `$currentModeColor` | `setMode($label, $color)` | Line 76 |
| `$currentPermissionLabel` | `setPermission($label, $color)` | Line 80 |
| `$currentPermissionColor` | `setPermission($label, $color)` | Line 82 |
| `$lastStatusTokensIn` | `setTokenUsage($in, $max)` | Line 84 |
| `$lastStatusMaxContext` | `setTokenUsage($in, $max)` | Line 90 |
| `$lastStatusCost` | `setModelAndCost($model, $cost)` | Line 88 |
| `$statusDetail` (derived) | Replaced by widget's internal rendering | Line 78 |

### 5.2 Migration Steps

1. **Add `StatusBarWidget`** as a new class alongside existing widgets.
2. **Replace `ProgressBarWidget` instantiation** in `TuiCoreRenderer::initTui()` (line 197):
   ```php
   // Before
   $this->statusBar = new ProgressBarWidget(200_000, '%message%  %bar%');

   // After
   $this->statusBar = new StatusBarWidget();
   ```
   Update the type hint on the property (line 51) from `ProgressBarWidget` to `StatusBarWidget`.
3. **Replace `refreshStatusBar()`** (line 770) — instead of building a formatted string, call individual setters:
   ```php
   private function refreshStatusBar(): void
   {
       $this->statusBar->setMode($this->currentModeLabel, $this->currentModeColor);
       $this->statusBar->setPermission($this->currentPermissionLabel, $this->currentPermissionColor);
       $this->statusBar->setTokenUsage(
           $this->lastStatusTokensIn ?? 0,
           $this->lastStatusMaxContext ?? 200_000,
       );
       $this->statusBar->setModelAndCost($this->modelName ?? '', $this->lastStatusCost ?? 0.0);
       $this->statusBar->setIdle($this->currentPhase === AgentPhase::Idle);
   }
   ```
4. **Simplify `showStatus()`** (line 525) — remove manual string building, delegate to widget.
5. **Simplify `refreshRuntimeSelection()`** (line 550) — same pattern.

### 5.3 Properties to Remove from TuiCoreRenderer

After migration, `$statusDetail` can be removed entirely — the widget handles its own rendering. The other properties (`$currentModeLabel`, etc.) remain as state but no longer need string formatting.

---

## 6. Segment Architecture — Detailed

### 6.1 Segment Interface (Future Extensibility)

For v1, segments are inline methods on `StatusBarWidget`. For v2, extract into a segment system:

```php
interface StatusBarSegmentInterface
{
    /** Minimum terminal width for this segment to be visible. */
    public function getMinWidth(): int;

    /** Render the segment content (ANSI-formatted string). */
    public function render(int $availableWidth): string;

    /** Priority for space allocation (lower = higher priority). */
    public function getPriority(): int;
}
```

This allows third-party segments (e.g., a Git branch segment, a timer segment) to be plugged in.

### 6.2 Layout Algorithm

```
1. Measure terminal width = cols
2. Collect visible segments (where cols >= segment.minWidth)
3. Sort by priority (ascending)
4. For each segment:
   a. Reserve space: segment.renderWidth(remaining)
   b. Subtract from remaining
5. Assemble left-to-right with separator insertion
6. Pad right side to fill cols
```

### 6.3 Space Distribution

The center gauge is **flexible** — it grows/shrinks to fill available space. Left and right segments are **fixed-width** based on their content.

```
total = cols
left_width  = visible_width(left_content)    // fixed
right_width = visible_width(right_content)   // fixed
sep_width   = separator_count * 3            // " ┃ " = 3 chars
center_width = total - left_width - right_width - sep_width
gauge_bar_width = center_width - text_overhead
```

---

## 7. Styling Integration

### 7.1 Current Theme Methods Used

| Method | Used For |
|--------|----------|
| `Theme::rgb()` | Foreground colors |
| `Theme::bgRgb()` | Background colors (mode pill) |
| `Theme::reset()` | Reset sequences |
| `Theme::dim()` | Dimmed separator |
| `Theme::dimmer()` | Empty gauge portion |
| `Theme::dimWhite()` | Model name, cost |
| `Theme::formatTokenCount()` | Token labels |
| `Theme::formatCost()` | Cost label |

### 7.2 New Theme Methods Needed

| Method | Purpose |
|--------|---------|
| `Theme::modeBackground(string $mode)` | Returns the dark background color for a mode preset |
| `Theme::modeForeground(string $mode)` | Returns the bright foreground color for a mode preset |

These replace the hardcoded `MODE_PRESETS` constant in the widget.

### 7.3 Future KosmokratorStyleSheet Tokens

```yaml
# kosmokrator-theme.yaml
status_bar:
  background:
    edit:    "rgb(20, 80, 40)"
    plan:    "rgb(50, 30, 100)"
    ask:     "rgb(80, 60, 20)"
    explore: "rgb(20, 60, 70)"
    idle:    "rgb(30, 30, 35)"
  foreground:
    edit:    "rgb(80, 220, 100)"
    plan:    "rgb(160, 120, 255)"
    ask:     "rgb(255, 200, 80)"
    explore: "rgb(100, 200, 220)"
    idle:    "rgb(140, 140, 150)"
  gauge:
    gradient_stops:
      - { at: 0.0, color: "rgb(80, 220, 100)" }
      - { at: 0.5, color: "rgb(255, 200, 80)" }
      - { at: 0.8, color: "rgb(255, 140, 60)" }
      - { at: 1.0, color: "rgb(255, 60, 40)" }
  separators:
    major: " ┃ "
    minor: " │ "
  breakpoints:
    wide: 100
    medium: 80
    narrow: 60
```

---

## 8. Edge Cases

| Case | Handling |
|------|----------|
| **Zero tokens** | Gauge shows empty bar, label "0/200k", color = green |
| **Context exceeded** | Ratio clamped to 1.0, full red bar |
| **No model set** | Right segment omits model, shows only cost (or nothing) |
| **Very long model name** | Truncated with `…` to max 25 chars |
| **Terminal resize** | Widget re-renders on next `render()` call (already reactive via `RenderContext`) |
| **Mode change** | `setMode()` calls `invalidate()`, triggers re-render |
| **ANSI escape width** | All width calculations use `AnsiUtils::visibleWidth()` to exclude escapes |

---

## 9. Testing Strategy

### 9.1 Unit Tests

| Test | What it verifies |
|------|-----------------|
| `testRenderWideTerminal` | Full layout with all segments at 120 cols |
| `testRenderMediumTerminal` | Model name hidden at 90 cols |
| `testRenderNarrowTerminal` | Gauge hidden at 70 cols |
| `testRenderVeryNarrow` | Only mode pill at 50 cols |
| `testModePillBackground` | Mode pill has correct bg/fg ANSI codes |
| `testGaugeGradient` | Gradient colors at 0%, 25%, 50%, 75%, 90%, 100% |
| `testGaugeWidthBounds` | Gauge bar respects min/max width constraints |
| `testFullWidthFill` | Output fills exactly `cols` visible characters |
| `testZeroTokens` | Empty gauge renders correctly |
| `testLongModelName` | Truncation with ellipsis |
| `testModePresets` | Each preset resolves to correct colors |
| `testIdleMode` | Idle styling overrides mode colors |

### 9.2 Visual Snapshot Tests

Capture rendered output at 120, 100, 80, 60, 40 columns for visual regression.

---

## 10. File Structure

```
src/UI/Tui/Widget/
├── StatusBarWidget.php           ← New (this plan)
├── StatusBar/                    ← Future v2 segment system
│   ├── SegmentInterface.php
│   ├── ModeSegment.php
│   ├── PermissionSegment.php
│   ├── GaugeSegment.php
│   ├── ModelSegment.php
│   └── CostSegment.php
```

---

## 11. Future Enhancements (Out of Scope for V1)

1. **Keybinding hints** — show contextual keybindings in the right segment (a la Lazygit).
2. **Duration timer** — show elapsed time for the current session or thinking phase.
3. **Git branch segment** — show current branch in the status bar.
4. **Diagnostic count** — show error/warning counts from tool results.
5. **Animated gauge** — subtle pulse animation when approaching context limit.
6. **Clickable segments** — mouse support for clicking mode to open command palette.
7. **Custom segment registration** — allow plugins to add segments.
8. **Segment reordering** — user-configurable segment positions.
