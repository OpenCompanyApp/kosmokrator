# ScrollbarWidget — Implementation Plan

> **File**: `src/UI/Tui/Widget/ScrollbarWidget.php`
> **Depends on**: Reactive state (Signal/Computed from `01-reactive-state`), virtual scrolling (`03-virtual-scrolling`)
> **Blocks**: ContainerWidget scrollbar integration, mouse scroll support (`05-mouse-support`)

---

## 1. Problem Statement

KosmoKrator's conversation can grow to thousands of lines. Scrolling currently uses a raw `scrollOffset` integer in `TuiCoreRenderer` (`src/UI/Tui/TuiCoreRenderer.php:108`) applied via `ScreenWriter::setScrollOffset()` which slices the rendered line buffer. The user has **no visual indicator** of:

- How far they are from the top of the conversation
- How much total content exists
- Where the current viewport sits relative to the whole

A scrollbar widget provides this spatial awareness at a glance.

## 2. Research: Existing Scrollbar Implementations

### 2.1 Ratatui (Rust) — `ratatui-widgets/src/scrollbar.rs`

Key design decisions from Ratatui:

- **Separate `ScrollbarState`** holding `(content_length, viewport_content_length, position)` — decoupled from the widget itself
- **Configurable symbols**: `Scrollbar::new()` accepts custom `Set<Symbol>` for track (`│`, `┃`) and thumb (`█`, `▓`) characters
- **Rendering algorithm**: Computes `thumb_start` and `thumb_end` as proportional slices of the viewport height
- **Minimal width**: Always 1 cell wide (or 2 for double-track variants)
- **No interactivity**: Pure display — scrolling is handled by the parent component

**Lesson for KosmoKrator**: Decouple scroll *state* from the *widget*. The widget should accept state values and render them.

### 2.2 php-tui — `ScrollbarWidget.php`

php-tui's implementation:

- Static helper approach: `ScrollbarWidget::scroll(int $totalLines, int $visibleLines, int $scrollOffset, int $height): array`
- Returns an array of single-character strings, one per line
- Uses Unicode block characters for the thumb (`█` full, `▓` dark shade, `▒` medium shade)
- Track is drawn with lighter characters (`░` or `│`)
- No object-oriented widget — more of a utility function

**Lesson**: The proportional math is simple: `thumbPosition = (scrollOffset / (totalLines - visibleLines)) * (height - thumbLength)`.

### 2.3 Bubble Tea (Go) — viewport scroll indicators

Bubble Tea takes a different approach:

- **No dedicated scrollbar widget** — instead, the viewport component shows scroll hints
- `Viewport.GotoTop()` / `Viewport.GotoBottom()` markers shown as `↑` / `↓` at edges
- Some community wrappers add proportional scrollbars via the `lipgloss` library's `Place()` function
- Scroll percentage shown as text: `42%`

**Lesson**: Textual percentage indicators are a useful fallback when the viewport is too short for a proportional thumb.

## 3. Current Scrolling Architecture

### How it works today:

```
User presses PgUp
  → TuiCoreRenderer::scrollHistoryUp()              (line 796)
    → $this->scrollOffset += historyScrollStep()     (line 798)
    → $this->tui->setScrollOffset($offset)           (line 821 → Tui.php:408)
      → ScreenWriter::setScrollOffset($offset)       (ScreenWriter.php:80)
        → On next writeLines(), slice the full line buffer:  (ScreenWriter.php:106)
            $startLine = $totalLines - $rows - $effectiveOffset
            $lines = array_slice($lines, $startLine, $rows)
```

Key observations:
- `$scrollOffset` is stored on `TuiCoreRenderer` (line 108) — not on any widget
- The `ScreenWriter` applies the offset by slicing the *entire rendered output* (all widgets)
- There is no per-widget scroll concept — it's a global viewport offset
- `HistoryStatusWidget` shows "Browsing history" text but no position indicator

### What needs to change for a scrollbar:

The scrollbar needs to know:
1. **Total content height** — the sum of all rendered conversation lines
2. **Viewport height** — terminal rows minus chrome (status bar, input, etc.)
3. **Current scroll position** — the existing `$scrollOffset`

Currently, total content height is only known *after* rendering (it's the line count returned by the Renderer). For a reactive scrollbar, we need to expose these values as reactive state.

## 4. Design

### 4.1 ScrollbarState (value object, not a widget)

A lightweight data object carrying the three values needed for rendering:

```php
namespace Kosmokrator\UI\Tui\Widget;

final class ScrollbarState
{
    public function __construct(
        public readonly int $contentLength,   // total lines of content
        public readonly int $viewportLength,  // visible lines in the viewport
        public readonly int $position,        // scroll offset from the top (0 = at top)
    ) {}

    public function isScrollable(): bool
    {
        return $this->contentLength > $this->viewportLength;
    }

    /**
     * Scroll fraction from 0.0 (top) to 1.0 (bottom).
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
     */
    public function thumbStart(int $trackHeight): int
    {
        $thumb = $this->thumbSize($trackHeight);
        $maxPos = $trackHeight - $thumb;
        return (int) round($maxPos * $this->scrollFraction());
    }
}
```

### 4.2 ScrollbarWidget

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Vertical scrollbar indicator for scrollable content.
 *
 * Renders a narrow (1-column) track with a proportional thumb showing
 * the current viewport position relative to total content height.
 *
 * ## Rendering algorithm
 *
 * Given track height H, content length C, viewport length V, and position P:
 *
 *   thumbSize  = max(1, round(H * V / C))
 *   maxScroll  = C - V
 *   fraction   = P / maxScroll         (0.0 = top, 1.0 = bottom)
 *   thumbStart = round((H - thumbSize) * fraction)
 *
 * The track is filled with `trackChar`, rows [thumbStart, thumbStart+thumbSize)
 * are overwritten with `thumbChar`.
 *
 * ## Styling
 *
 * - `ScrollbarWidget::class` → base style (can set color for the track)
 * - `ScrollbarWidget::class . '::thumb'` → thumb style (color/attributes)
 * - `ScrollbarWidget::class . '::track'` → track style
 *
 * ## Integration
 *
 * The widget receives a ScrollbarState on each render cycle. The parent
 * container is responsible for computing state from scroll offset and
 * content metrics, either manually or via the reactive signal system.
 *
 * ## Future: Virtual Scrolling
 *
 * When VirtualMessageList is implemented, ScrollbarWidget will bind to
 * computed signals:
 *
 *   $state = new ScrollbarState(
 *       contentLength: $totalHeightSignal->get(),
 *       viewportLength: $viewportHeightSignal->get(),
 *       position: $scrollOffsetSignal->get(),
 *   );
 *
 * This avoids manual state plumbing — the widget re-renders automatically
 * when any signal changes.
 */
final class ScrollbarWidget extends AbstractWidget
{
    // ── Unicode block characters (default symbol set) ──────────────────────
    public const SYMBOLS_DEFAULT = [
        'track' => '░',  // light shade
        'thumb' => '█',  // full block
    ];

    public const SYMBOLS_MODERN = [
        'track' => '│',  // light vertical
        'thumb' => '┃',  // heavy vertical
    ];

    public const SYMBOLS_DOTS = [
        'track' => '┊',  // dotted vertical
        'thumb' => '╎',  // dotted vertical stroke (repeated for thumb rows)
    ];

    /** @var ScrollbarState|null Current scroll state; null = not scrollable */
    private ?ScrollbarState $state = null;

    /** @var array{track: string, thumb: string} Symbol set */
    private array $symbols = self::SYMBOLS_DEFAULT;

    // ── Configuration ─────────────────────────────────────────────────────

    /**
     * Set the scrollbar state (content/viewport/position metrics).
     */
    public function setState(?ScrollbarState $state): static
    {
        $this->state = $state;
        $this->invalidate();

        return $this;
    }

    /**
     * Set the symbol characters for track and thumb.
     *
     * @param array{track: string, thumb: string} $symbols
     */
    public function setSymbols(array $symbols): static
    {
        $this->symbols = $symbols;
        $this->invalidate();

        return $this;
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    /**
     * Render the scrollbar into terminal lines.
     *
     * Returns one line per row (each is a single ANSI-styled character).
     * Returns an empty array when no ScrollbarState is set or content fits
     * the viewport.
     *
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        // No state or content fits viewport → nothing to render
        if ($this->state === null || !$this->state->isScrollable()) {
            return [];
        }

        $height = $context->getRows();
        if ($height <= 0) {
            return [];
        }

        $thumbStart = $this->state->thumbStart($height);
        $thumbSize = $this->state->thumbSize($height);

        // Resolve sub-element styles via the stylesheet
        $trackStyled = $this->applyElement('track', $this->symbols['track']);
        $thumbStyled = $this->applyElement('thumb', $this->symbols['thumb']);

        $lines = [];
        for ($row = 0; $row < $height; $row++) {
            $isThumb = $row >= $thumbStart && $row < $thumbStart + $thumbSize;
            $lines[] = $isThumb ? $thumbStyled : $trackStyled;
        }

        return $lines;
    }
}
```

### 4.3 Stylesheet Entries

Add to `KosmokratorStyleSheet::create()`:

```php
// Scrollbar track (background gutter)
ScrollbarWidget::class => new Style(
    color: Color::hex('#303030'),
),

// Scrollbar thumb (current position indicator)
ScrollbarWidget::class . '::thumb' => new Style(
    color: Color::hex('#606060'),
),

// Scrollbar track
ScrollbarWidget::class . '::track' => new Style(
    color: Color::hex('#303030'),
),
```

When the user scrolls (focus state), the thumb could brighten:
```php
ScrollbarWidget::class . '::thumb:scrolling' => new Style(
    color: Color::hex('#ffc850'),
),
```

### 4.4 Integration with TuiCoreRenderer

#### Phase 1: Manual plumbing (before reactive state)

In `TuiCoreRenderer::initialize()`:

```php
$this->scrollbar = new ScrollbarWidget();
$this->scrollbar->setId('conversation-scrollbar');

// Add scrollbar to the session layout alongside the conversation
$scrollbarContainer = new ContainerWidget();
$scrollbarContainer->setId('scrollbar-area');
$scrollbarContainer->add($this->scrollbar);
```

The session layout changes from vertical-only to a **horizontal split** for the conversation area:

```
┌──────────────────────────────────┬─┐
│                                  │░│
│  Conversation content            │█│  ← scrollbar thumb
│  (MarkdownWidget, tool calls)    │█│
│                                  │░│
│                                  │░│
├──────────────────────────────────┴─┤
│ [status bar]                        │
├────────────────────────────────────┤
│ > user input                        │
└────────────────────────────────────┘
```

This requires wrapping the conversation + scrollbar in a horizontal `ContainerWidget`:

```php
$conversationPane = new ContainerWidget();
$conversationPane->setStyle(new Style(direction: Direction::Horizontal));
$conversationPane->setId('conversation-pane');
$conversationPane->expandVertically(true);

$conversationPane->add($this->conversation);    // flex: 1
$conversationPane->add($this->scrollbar);        // intrinsic width (1 col)
```

In `TuiCoreRenderer::applyScrollOffset()` and anywhere scroll offset changes:

```php
private function updateScrollbar(): void
{
    // After rendering, the Renderer knows total line count.
    // For Phase 1, approximate from conversation child count.
    // This will be replaced by reactive signals in Phase 2.

    $contentHeight = $this->estimateContentHeight();
    $viewportHeight = $this->getViewportHeight();
    $position = $contentHeight - $viewportHeight - $this->scrollOffset;
    $position = max(0, min($position, $contentHeight - $viewportHeight));

    $this->scrollbar->setState(new ScrollbarState(
        contentLength: $contentHeight,
        viewportLength: $viewportHeight,
        position: $position,
    ));
}
```

#### Phase 2: Reactive signal binding (after `01-reactive-state`)

```php
// In the reactive state store:
$scrollState = new Computed(function () use ($contentHeight, $viewportHeight, $scrollOffset) {
    return new ScrollbarState(
        contentLength: $contentHeight->get(),
        viewportLength: $viewportHeight->get(),
        position: max(0, $contentHeight->get() - $viewportHeight->get() - $scrollOffset->get()),
    );
});

// In the widget (or an effect that feeds the widget):
new Effect(function () use ($scrollState, $scrollbar) {
    $scrollbar->setState($scrollState->get());
});
```

### 4.5 Integration with Virtual Scrolling (`03-virtual-scrolling`)

When `VirtualMessageList` is implemented, it will expose:

```php
$virtualList = new VirtualMessageList($messages);
$virtualList->getTotalHeightSignal();    // Signal<int> — sum of all row heights
$virtualList->getViewportHeightSignal(); // Signal<int> — visible rows
$virtualList->getScrollPositionSignal(); // Signal<int> — current offset
```

The scrollbar binds directly to these signals — no manual plumbing in `TuiCoreRenderer`.

## 5. Rendering Algorithm — Detailed

```
Input:
  trackHeight = 20 (rows allocated to scrollbar)
  contentLength = 500 (total lines)
  viewportLength = 20 (visible lines)
  position = 150 (lines scrolled from top)

Compute:
  isScrollable = 500 > 20 → true
  maxScroll = 500 - 20 = 480
  fraction = 150 / 480 ≈ 0.3125
  thumbSize = max(1, round(20 * 20 / 500)) = max(1, round(0.8)) = 1
  thumbStart = round((20 - 1) * 0.3125) = round(5.94) = 6

Output (20 rows):
  Row  0: ░  (track)
  Row  1: ░
  Row  2: ░
  Row  3: ░
  Row  4: ░
  Row  5: ░
  Row  6: █  ← thumb (1 row)
  Row  7: ░
  ...
  Row 19: ░
```

Edge case — large viewport, small content:
```
  contentLength = 15, viewportLength = 20
  isScrollable = false → return [] (nothing rendered)
```

Edge case — very long content, small thumb:
```
  trackHeight = 20, contentLength = 10000, viewportLength = 20
  thumbSize = max(1, round(20 * 20 / 10000)) = 1
  → Thumb is always at least 1 row
```

## 6. File Structure

```
src/UI/Tui/Widget/
├── ScrollbarWidget.php        # The widget (render logic)
└── ScrollbarState.php         # Value object for scroll metrics

src/UI/Tui/KosmokratorStyleSheet.php    # Add ::thumb and ::track style rules
src/UI/Tui/TuiCoreRenderer.php          # Wire up scrollbar state updates

tests/Unit/UI/Tui/Widget/
├── ScrollbarStateTest.php     # Unit tests for proportional math
└── ScrollbarWidgetTest.php    # Render output assertions
```

## 7. Test Plan

### 7.1 `ScrollbarStateTest`

| Test | Input | Expected |
|------|-------|----------|
| Content fits viewport | `content=10, viewport=20, pos=0` | `isScrollable() = false`, `fraction() = 0.0` |
| At top | `content=100, viewport=20, pos=0` | `fraction() = 0.0`, `thumbStart(20) = 0` |
| At bottom | `content=100, viewport=20, pos=80` | `fraction() = 1.0`, `thumbStart(20) = 20 - thumbSize` |
| Mid-scroll | `content=200, viewport=50, pos=75` | `fraction() = 0.5`, `thumbStart(30) ≈ 14` |
| Huge content | `content=10000, viewport=20, pos=5000` | `thumbSize(20) = 1` (minimum) |
| Zero content | `content=0, viewport=20, pos=0` | `isScrollable() = false` |
| Equal content/viewport | `content=20, viewport=20, pos=0` | `isScrollable() = false` |

### 7.2 `ScrollbarWidgetTest`

| Test | Assertion |
|------|-----------|
| No state → empty output | `render() = []` |
| Content fits → empty output | `render() = []` |
| Correct thumb placement | Exactly `thumbSize` rows contain thumb char |
| Track fills non-thumb rows | All other rows contain track char |
| Output height matches context | `count(render()) = context->rows` |
| ANSI escape sequences present | Thumb rows contain color codes from stylesheet |

## 8. Accessibility Considerations

- **Minimum thumb size**: Always ≥ 1 row, even for very long content
- **Color contrast**: Track at `#303030` vs thumb at `#606060` — distinguishable but subtle. When scrolling, thumb brightens to `#ffc850`
- **Future: screen reader**: The `scrollFraction()` method enables a text-based percentage announcement

## 9. Future Enhancements (out of scope for initial implementation)

1. **Horizontal scrollbar** — same widget with `Orientation::Horizontal` parameter
2. **Mouse click-to-scroll** — clicking on the track jumps the viewport. Depends on `05-mouse-support`
3. **Mouse drag** — dragging the thumb scrolls proportionally. Depends on `05-mouse-support`
4. **Scroll wheel integration** — wheel events update ScrollbarState. Depends on `05-mouse-support`
5. **Custom thumb shape** — arrows at top/bottom of thumb (`▴▾`) to indicate direction
6. **Scroll-to-top / scroll-to-bottom buttons** — small `↑` / `↓` indicators at track ends
7. **Animation** — smooth thumb transition on scroll via `08-animation` spring physics
8. **Percentage label** — optional small text overlay showing "42%" when actively scrolling
