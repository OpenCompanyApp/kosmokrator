# Mouse Support — Full Implementation Plan

> **Directory**: `src/UI/Tui/Mouse/` (new), extensions to existing `Input/`, `Terminal/`, `Focus/`, `Widget/`
> **Depends on**: PositionTracker (`Render/PositionTracker.php`), FocusManager (`Focus/FocusManager.php`), StdinBuffer (`Input/StdinBuffer.php`), WidgetRect (`Render/WidgetRect.php`)
> **Blocks**: Drag-to-resize splits, text selection, scrollbar dragging

---

## 1. Problem Statement

KosmoKrator's TUI is keyboard-only today. Users cannot click to focus a widget, scroll the conversation with a mouse wheel, or tap collapsible sections to toggle them. Every polished TUI (Lazygit, Helix, Warp, Textual apps) supports mouse interaction. Without it, KosmoKrator feels like a step backward from modern terminal applications.

The Symfony TUI foundation already has the building blocks — **StdinBuffer** parses SGR mouse sequences (`vendor/symfony/tui/.../Input/StdinBuffer.php:267`), **PositionTracker** records every widget's screen rect (`vendor/symfony/tui/.../Render/PositionTracker.php:28`), and **WidgetRect::contains()** does hit-testing (`vendor/symfony/tui/.../Render/WidgetRect.php:61`). The `RenderRequestorInterface` comment explicitly references a `MouseCoordinator` that doesn't exist yet (`vendor/symfony/tui/.../Render/RenderRequestorInterface.php:17`).

This plan designs the missing `MouseCoordinator`, the event model, and the integration path from raw terminal bytes to widget callbacks.

---

## 2. Research: Mouse Support in Polished TUIs

### 2.1 Bubble Tea v2 (Go) — `tea.MouseMsg`

Bubble Tea's mouse model:

- **Event types**: `MouseLeft`, `MouseRight`, `MouseMiddle`, `MouseRelease`, `MouseWheelUp`, `MouseWheelDown`, `MouseMotion`
- **SGR-1006 mode only** — Bubble Tea enables `\x1b[?1000h\x1b[?1002h\x1b[?1006h` and ignores X10 (9-byte) sequences
- **Motion tracking** (`1002h`) is used for drag detection — the framework synthesizes `MouseDrag` from motion events while a button is held
- **Coordinate system**: 0-indexed (col, row), matching terminal cell positions
- **No built-in hit-testing**: Each `tea.Model` checks coordinates manually via bounds comparison
- **Key insight**: Mouse events are just another `Msg` in the Elm architecture — they flow through the same `Update()` → `View()` cycle as key events

### 2.2 Ratatui + Crossterm (Rust)

Crossterm's approach:

- **Event enum**: `Event::Mouse(MouseEvent)` with `MouseEventKind` variants: `Down`, `Up`, `Drag`, `Moved`, `ScrollDown`, `ScrollUp`, `ScrollLeft`, `ScrollRight`
- **SGR-1006 parsing**: Crossterm exclusively parses `\x1b[<B;X;YM` (capital M for press/drag) and `\x1b[<B;X;Ym` (lowercase m for release)
- **Button encoding**: Bit 0 = left, bit 1 = middle, bit 2 = right; bit 5 = shift, bit 4 = meta, bit 3 = ctrl; bits 6+64 = scroll
- **Ratatui**: No built-in mouse dispatch — the application matches coordinates against widget areas from the `Layout::split()` call
- **Key insight**: The framework provides raw events; the application does its own dispatch

### 2.3 Textual (Python) — Rich mouse integration

Textual's approach is the most relevant for KosmoKrator because it also has a widget tree:

- **Mouse events as methods**: Each widget implements `on_click()`, `on_mouse_move()`, `on_mouse_down()`, `on_mouse_up()`, `on_scroll_up()`, `on_scroll_down()`
- **Hit-testing**: Textual dispatches mouse events by walking the widget tree and checking `widget.region.contains(pos)`
- **Capture and bubble**: Widgets can `capture_mouse()` to receive all subsequent events (for drag). Otherwise, events bubble from leaf to root
- **Focus-on-click**: Clicking any focusable widget automatically focuses it
- **Key insight**: Method-based dispatch with capture semantics is the right model for a widget tree

### 2.4 Lazygit — Pragmatic mouse support

Lazygit's approach:

- **Three behaviors**: Click to focus panel, scroll to navigate, click on specific items to select
- **Minimal event model**: Only `MOUSE_LEFT`, `MOUSE_RIGHT`, `MOUSE_RELEASE`, `MOUSE_WHEEL_UP`, `MOUSE_WHEEL_DOWN`
- **Panel-based dispatch**: The main controller checks which panel rectangle contains the click point
- **Scroll delegation**: Mouse wheel events are sent to whichever panel the cursor is over (not just the focused one)
- **Key insight**: Scroll-to-whatever-is-under-cursor (not just the focused widget) is the expected UX

### 2.5 Synthesis — Design Principles for KosmoKrator

| Principle | Source |
|-----------|--------|
| SGR-1006 only, no X10 fallback | Bubble Tea, Crossterm |
| Event types: Down, Up, Drag, Move, ScrollUp, ScrollDown | Crossterm, Textual |
| Hit-test against widget tree using PositionTracker data | Textual |
| Capture mouse for drag operations | Textual |
| Click-to-focus integration with FocusManager | Textual, Lazygit |
| Scroll targets the widget under cursor, not just focused widget | Lazygit |
| Synthesize drag from motion + button-held state | Bubble Tea |

---

## 3. Terminal Mouse Protocols

### 3.1 X10 Mouse Mode (mode 9)

```
\x1b[?9h   — Enable (button press only, no release/motion)
Format: ESC [ M Cb Cx Cy   (6 bytes, coordinates offset by 32)
```

**Not used.** Limited to button press, no release or motion. Incompatible with drag.

### 3.2 Normal Tracking Mode (mode 1000)

```
\x1b[?1000h — Enable button press/release
\x1b[?1000l — Disable
```

Reports press and release. Old-style encoding uses `ESC [ M Cb Cx Cy` with coordinates offset by 32 (breaks for columns > 223).

### 3.3 Button Event Tracking (mode 1002)

```
\x1b[?1002h — Enable button press/release + motion while button held (drag)
\x1b[?1002l — Disable
```

Extends 1000 with motion events while a button is pressed. Essential for drag support.

### 3.4 SGR Extended Mode (mode 1006) — **Required**

```
\x1b[?1006h — Enable SGR mouse encoding
\x1b[?1006l — Disable
```

Changes encoding to `\x1b[<B;X;YM` (press/motion) or `\x1b[<B;X;Ym` (release).

| Field | Meaning |
|-------|---------|
| `B` | Button code (bit 0: left, bit 1: middle, bit 2: right, bit 4: shift, bit 5: meta, bit 6: ctrl, bits 64/65: scroll) |
| `X` | Column (1-indexed) |
| `Y` | Row (1-indexed) |
| `M` (uppercase) | Press or motion event |
| `m` (lowercase) | Release event |

**SGR is required** because it supports coordinates > 223 and uses decimal encoding.

### 3.5 Enable Sequence

```
\x1b[?1000h\x1b[?1002h\x1b[?1006h
```

This enables: basic tracking (1000) + drag tracking (1002) + SGR encoding (1006).

### 3.6 Disable Sequence (must be called on exit)

```
\x1b[?1006l\x1b[?1002l\x1b[?1000l
```

---

## 4. Architecture

### 4.1 Event Flow Diagram

```
Terminal (stdin bytes)
    │
    ▼
StdinBuffer::process($data)
    │  Extracts complete escape sequences
    │  Already handles SGR mouse: ESC[<B;X;YM/m  (line 267)
    │
    ▼
Tui::handleInput($sequence)                          ← existing, Tui.php:459
    │
    ├─── InputEvent dispatched (existing)
    │
    ▼
MouseParser::parse($sequence)                        ← NEW
    │  Returns MouseEvent or null
    │
    ▼
MouseCoordinator::dispatch($event)                   ← NEW
    │
    ├─── 1. Query PositionTracker for widget rects
    │       Renderer::getPositionTracker() → PositionTracker
    │
    ├─── 2. Hit-test: find deepest widget containing (col, row)
    │       Walk widget tree, check WidgetRect::contains()
    │
    ├─── 3. If captured: route to capture target
    │       (used for drag)
    │
    ├─── 4. Dispatch to target widget
    │       MouseHandlerInterface::handleMouse($event, $widgetRect)
    │
    ├─── 5. If click on focusable widget:
    │       FocusManager::setFocus($widget)
    │
    └─── 6. Bubble if not consumed
            Parent widget gets a chance to handle
```

### 4.2 Component Diagram

```
┌─────────────────────────────────────────────────────────┐
│                        Tui                              │
│  ┌──────────┐  ┌───────────┐  ┌─────────────────────┐  │
│  │ Terminal  │  │ Renderer  │  │   FocusManager      │  │
│  │           │  │           │  │                     │  │
│  │ write()   │  │ position  │  │ setFocus(widget)    │  │
│  │ enable()  │  │ Tracker() │  │ getFocus()          │  │
│  └─────┬─────┘  └─────┬─────┘  └──────────┬──────────┘  │
│        │              │                    │             │
│        ▼              ▼                    ▼             │
│  ┌──────────────────────────────────────────────────┐   │
│  │              MouseCoordinator                    │   │
│  │                                                  │   │
│  │  mouseParser: MouseParser                        │   │
│  │  positionTracker: PositionTracker                │   │
│  │  focusManager: FocusManager                      │   │
│  │  captureTarget: ?AbstractWidget                  │   │
│  │                                                  │   │
│  │  dispatch(MouseEvent): void                      │   │
│  │  captureMouse(AbstractWidget): void              │   │
│  │  releaseMouse(): void                            │   │
│  └──────────────────────────────────────────────────┘   │
│        │                                                 │
│        ▼                                                 │
│  ┌──────────────────────────────────────────────────┐   │
│  │           Widget Tree (hit-test)                  │   │
│  │                                                  │   │
│  │  Widget A (WidgetRect: row=0,col=0,w=80,h=5)    │   │
│  │  ├── Widget B (WidgetRect: row=0,col=0,w=40,h=5)│   │
│  │  └── Widget C (WidgetRect: row=0,col=40,w=40,h=5│   │
│  │      └── Widget D (WidgetRect: row=2,col=40,...) │   │
│  └──────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

---

## 5. New Classes

### 5.1 `Mouse\MouseEvent` — Value Object

```php
namespace Symfony\Component\Tui\Mouse;

final class MouseEvent
{
    public function __construct(
        private readonly MouseAction $action,
        private readonly MouseButton $button,
        private readonly int $col,      // 0-indexed
        private readonly int $row,      // 0-indexed
        private readonly bool $shift,
        private readonly bool $alt,
        private readonly bool $ctrl,
    ) {}

    public function getAction(): MouseAction { return $this->action; }
    public function getButton(): MouseButton { return $this->button; }
    public function getCol(): int { return $this->col; }
    public function getRow(): int { return $this->row; }
    public function isShift(): bool { return $this->shift; }
    public function isAlt(): bool { return $this->alt; }
    public function isCtrl(): bool { return $this->ctrl; }
}
```

### 5.2 `Mouse\MouseAction` — Enum

```php
namespace Symfony\Component\Tui\Mouse;

enum MouseAction: string
{
    case Down = 'down';         // Button pressed
    case Up = 'up';             // Button released
    case Drag = 'drag';         // Motion while button held
    case Move = 'move';         // Motion without button (mode 1003, future)
    case ScrollUp = 'scroll_up';
    case ScrollDown = 'scroll_down';
}
```

### 5.3 `Mouse\MouseButton` — Enum

```php
namespace Symfony\Component\Tui\Mouse;

enum MouseButton: int
{
    case None = 0;      // Release event, scroll events
    case Left = 1;
    case Middle = 2;
    case Right = 3;
}
```

### 5.4 `Mouse\MouseParser` — Stateful Sequence Parser

```php
namespace Symfony\Component\Tui\Mouse;

/**
 * Parses SGR-1006 mouse sequences into MouseEvent objects.
 *
 * Handles both old-style (ESC [ M Cb Cx Cy) and SGR (ESC [ < B ; X ; Y M/m).
 * Old-style sequences are converted for compatibility with terminals that
 * don't support SGR mode (rare in practice).
 */
final class MouseParser
{
    /**
     * Parse a raw escape sequence into a MouseEvent, or null if not a mouse sequence.
     */
    public function parse(string $sequence): ?MouseEvent
    {
        // SGR mouse: \x1b[<B;X;YM  or  \x1b[<B;X;Ym
        if (preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])$/', $sequence, $m)) {
            return $this->parseSgr((int) $m[1], (int) $m[2], (int) $m[3], $m[4]);
        }

        // Old-style mouse: \x1b[M + 3 bytes (already extracted by StdinBuffer as 6-byte sequence)
        if (6 === strlen($sequence) && "\x1b[M" === substr($sequence, 0, 3)) {
            return $this->parseX10(
                ord($sequence[3]),
                ord($sequence[4]),
                ord($sequence[5])
            );
        }

        return null;
    }

    private function parseSgr(int $buttonCode, int $col, int $row, string $action): MouseEvent
    {
        // SGR coordinates are 1-indexed; convert to 0-indexed
        $col -= 1;
        $row -= 1;

        $shift = (bool) ($buttonCode & 0x04);
        $alt   = (bool) ($buttonCode & 0x08);
        $ctrl  = (bool) ($buttonCode & 0x10);

        $lowButton = $buttonCode & 0x03;
        $isMotion  = (bool) ($buttonCode & 0x20);
        $isScroll  = $buttonCode >= 64;

        if ($isScroll) {
            return new MouseEvent(
                ($buttonCode & 0x01) ? MouseAction::ScrollDown : MouseAction::ScrollUp,
                MouseButton::None,
                $col, $row, $shift, $alt, $ctrl,
            );
        }

        if ('m' === $action) {
            // Release event
            return new MouseEvent(
                MouseAction::Up,
                MouseButton::None,
                $col, $row, $shift, $alt, $ctrl,
            );
        }

        // Press or drag
        $mouseButton = match ($lowButton) {
            0 => MouseButton::Left,
            1 => MouseButton::Middle,
            2 => MouseButton::Right,
            default => MouseButton::None,
        };

        return new MouseEvent(
            $isMotion ? MouseAction::Drag : MouseAction::Down,
            $mouseButton,
            $col, $row, $shift, $alt, $ctrl,
        );
    }

    private function parseX10(int $cb, int $cx, int $cy): MouseEvent
    {
        // X10 coordinates are offset by 32
        $col = $cx - 32;
        $row = $cy - 32;

        $lowButton = $cb & 0x03;

        $mouseButton = match ($lowButton) {
            0 => MouseButton::Left,
            1 => MouseButton::Middle,
            2 => MouseButton::Right,
            default => MouseButton::None,
        };

        return new MouseEvent(
            MouseAction::Down, // X10 only reports press
            $mouseButton,
            max(0, $col - 1),
            max(0, $row - 1),
            (bool) ($cb & 0x04),
            (bool) ($cb & 0x08),
            (bool) ($cb & 0x10),
        );
    }
}
```

### 5.5 `Mouse\MouseCoordinator` — Dispatch Engine

```php
namespace Symfony\Component\Tui\Mouse;

use Symfony\Component\Tui\Focus\FocusManager;
use Symfony\Component\Tui\Render\PositionTracker;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\RenderRequestorInterface;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MouseHandlerInterface;

/**
 * Dispatches mouse events to the appropriate widget.
 *
 * Uses PositionTracker data to hit-test which widget is under the cursor,
 * supports mouse capture for drag operations, and integrates with
 * FocusManager for click-to-focus.
 */
final class MouseCoordinator
{
    private MouseParser $parser;
    private ?AbstractWidget $captureTarget = null;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly FocusManager $focusManager,
        private readonly RenderRequestorInterface $renderRequestor,
    ) {
        $this->parser = new MouseParser();
    }

    /**
     * Try to handle an input sequence as a mouse event.
     *
     * @return bool True if the sequence was a mouse event (consumed or not)
     */
    public function handleInput(string $sequence): bool
    {
        $event = $this->parser->parse($sequence);
        if (null === $event) {
            return false;
        }

        $this->dispatch($event);
        return true;
    }

    /**
     * Capture all subsequent mouse events to the given widget.
     *
     * Used by widgets that need to track drag operations even when
     * the cursor moves outside their bounds.
     */
    public function captureMouse(AbstractWidget $widget): void
    {
        $this->captureTarget = $widget;
    }

    /**
     * Release a previous mouse capture.
     */
    public function releaseMouse(): void
    {
        $this->captureTarget = null;
    }

    private function dispatch(MouseEvent $event): void
    {
        $positionTracker = $this->renderer->getPositionTracker();

        // If a widget has captured the mouse, send directly to it
        if (null !== $this->captureTarget) {
            $widgetRect = $positionTracker->getWidgetRect($this->captureTarget);
            if (null !== $widgetRect && $this->captureTarget instanceof MouseHandlerInterface) {
                $this->captureTarget->handleMouse($event, $widgetRect);
            }

            // Release capture on mouse up
            if (MouseAction::Up === $event->getAction()) {
                $this->captureTarget = null;
            }

            return;
        }

        // Hit-test: find the deepest widget at (col, row)
        $target = $this->hitTest($positionTracker, $event->getCol(), $event->getRow());

        if (null === $target) {
            return;
        }

        $widgetRect = $positionTracker->getWidgetRect($target);

        // Dispatch to target widget
        if ($target instanceof MouseHandlerInterface) {
            $target->handleMouse($event, $widgetRect);
        }

        // Click-to-focus: left click on a focusable widget focuses it
        if (MouseAction::Down === $event->getAction()
            && MouseButton::Left === $event->getButton()
            && $target instanceof FocusableInterface
        ) {
            $this->focusManager->setFocus($target);
        }

        $this->renderRequestor->requestRender();
    }

    /**
     * Find the deepest (most specific) widget at the given coordinates.
     */
    private function hitTest(PositionTracker $tracker, int $col, int $row): ?AbstractWidget
    {
        // Iterate all tracked widgets, find the deepest one containing the point.
        // "Deepest" = smallest area (most specific child).
        $best = null;
        $bestArea = PHP_INT_MAX;

        foreach ($tracker->getAllWidgetRects() as $widget => $rect) {
            if ($rect->contains($row, $col)) {
                $area = $rect->getRows() * $rect->getColumns();
                if ($area < $bestArea) {
                    $bestArea = $area;
                    $best = $widget;
                }
            }
        }

        return $best;
    }
}
```

### 5.6 `Widget\MouseHandlerInterface` — Widget Contract

```php
namespace Symfony\Component\Tui\Widget;

use Symfony\Component\Tui\Mouse\MouseEvent;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * Widgets that respond to mouse events implement this interface.
 */
interface MouseHandlerInterface
{
    /**
     * Handle a mouse event within this widget's bounds.
     *
     * @param WidgetRect $widgetRect This widget's absolute position on screen
     */
    public function handleMouse(MouseEvent $event, WidgetRect $widgetRect): void;
}
```

---

## 6. Integration Points

### 6.1 Terminal — Enable/Disable Mouse Tracking

**File**: `vendor/symfony/tui/.../Terminal/Terminal.php`

Add to `Terminal::start()` (after bracketed paste mode, line 76):

```php
// Enable SGR mouse tracking (1000 = basic, 1002 = drag, 1006 = SGR encoding)
$this->write("\x1b[?1000h\x1b[?1002h\x1b[?1006h");
```

Add to `Terminal::stop()` (before stty restore, around line 131):

```php
// Disable mouse tracking
$this->write("\x1b[?1006l\x1b[?1002l\x1b[?1000l");
```

Add to `TerminalInterface`:

```php
public function isMouseSupported(): bool;
```

The `Terminal` implementation returns `true` when stty is available and `TERM` is not `dumb`. The `VirtualTerminal` returns `false`.

**Environment detection** (SSH, CI, dumb terminals):

```php
public function isMouseSupported(): bool
{
    if (!$this->hasSttyAvailable()) {
        return false;
    }

    // Disable mouse in CI environments
    if (getenv('CI') || getenv('CONTINUOUS_INTEGRATION')) {
        return false;
    }

    // Disable for dumb terminals
    $term = getenv('TERM');
    if (false === $term || 'dumb' === $term) {
        return false;
    }

    return true;
}
```

Mouse sequences are only emitted when `isMouseSupported()` returns `true`.

### 6.2 Tui — Route Mouse Events Through MouseCoordinator

**File**: `vendor/symfony/tui/.../Tui.php`

Modify `Tui::handleInput()` (line 459):

```php
public function handleInput(string $data): void
{
    $event = $this->eventDispatcher->dispatch(new InputEvent($data));
    if ($event->isPropagationStopped()) {
        return;
    }

    // NEW: Try mouse event first (mouse coordinator returns true if consumed)
    if ($this->mouseCoordinator->handleInput($data)) {
        return;
    }

    if ($this->focusManager->handleInput($data)) {
        return;
    }

    $focused = $this->focusManager->getFocus();
    if ($focused instanceof FocusableInterface) {
        $revisionBeforeInput = $this->root->getRenderRevision();
        $focused->handleInput($data);
        if ($this->root->getRenderRevision() !== $revisionBeforeInput) {
            $this->requestRender();
        }
    }
}
```

Add MouseCoordinator as a constructor dependency:

```php
// In Tui::__construct():
$this->mouseCoordinator = new MouseCoordinator(
    $this->renderer,
    $this->focusManager,
    $this, // RenderRequestorInterface
);
```

### 6.3 PositionTracker — Add Iteration Method

**File**: `vendor/symfony/tui/.../Render/PositionTracker.php`

The `MouseCoordinator::hitTest()` needs to iterate all tracked widgets. Add:

```php
/**
 * Get all tracked widget positions.
 *
 * @return \WeakMap<AbstractWidget, WidgetRect>
 */
public function getAllWidgetRects(): \WeakMap
{
    return $this->widgetPositions;
}
```

This is a minor addition — the WeakMap is already there (`PositionTracker.php:31`).

### 6.4 Renderer — Expose PositionTracker

**File**: `vendor/symfony/tui/.../Render/Renderer.php`

Add a public accessor (the tracker already exists as a private field, line 48):

```php
public function getPositionTracker(): PositionTracker
{
    return $this->positionTracker;
}
```

---

## 7. Widget Integration Examples

### 7.1 SelectListWidget — Click to Select

```php
class SelectListWidget extends AbstractWidget implements FocusableInterface, MouseHandlerInterface
{
    public function handleMouse(MouseEvent $event, WidgetRect $widgetRect): void
    {
        if (MouseAction::Down === $event->getAction() && MouseButton::Left === $event->getButton()) {
            $relative = $widgetRect->toRelative($event->getRow(), $event->getCol());
            $itemIndex = $relative['row']; // Each item is one row
            if (isset($this->filteredItems[$itemIndex])) {
                $this->setSelectedIndex($itemIndex);
                $this->toggle(); // Select/confirm the item
            }
        } elseif (MouseAction::ScrollUp === $event->getAction()) {
            $this->navigateUp();
        } elseif (MouseAction::ScrollDown === $event->getAction()) {
            $this->navigateDown();
        }
    }
}
```

### 7.2 CollapsibleWidget — Click Header to Toggle

```php
class CollapsibleWidget extends AbstractWidget implements MouseHandlerInterface
{
    public function handleMouse(MouseEvent $event, WidgetRect $widgetRect): void
    {
        if (MouseAction::Down === $event->getAction() && MouseButton::Left === $event->getButton()) {
            $relative = $widgetRect->toRelative($event->getRow(), $event->getCol());
            // Header is always the first row
            if (0 === $relative['row']) {
                $this->toggle();
            }
        }
    }
}
```

### 7.3 Conversation/MessageList — Scroll Wheel

```php
// In TuiCoreRenderer or the conversation widget:
public function handleMouse(MouseEvent $event, WidgetRect $widgetRect): void
{
    if (MouseAction::ScrollUp === $event->getAction()) {
        $this->scrollHistoryUp();
    } elseif (MouseAction::ScrollDown === $event->getAction()) {
        $this->scrollHistoryDown();
    }
}
```

### 7.4 ScrollbarWidget — Drag to Scroll (Future)

```php
class ScrollbarWidget extends AbstractWidget implements MouseHandlerInterface
{
    public function handleMouse(MouseEvent $event, WidgetRect $widgetRect): void
    {
        $relative = $widgetRect->toRelative($event->getRow(), $event->getCol());

        if (MouseAction::Down === $event->getAction() && MouseButton::Left === $event->getButton()) {
            // Check if click is on the thumb
            if ($this->isOnThumb($relative['row'])) {
                $this->dragStartRow = $relative['row'];
                $this->dragStartScrollOffset = $this->scrollOffset;
                // Capture mouse for drag
                $this->context->getMouseCoordinator()->captureMouse($this);
            } else {
                // Click on track: jump to position
                $this->jumpToPosition($relative['row']);
            }
        } elseif (MouseAction::Drag === $event->getAction()) {
            $delta = $relative['row'] - $this->dragStartRow;
            $this->scrollToOffset($this->dragStartScrollOffset + $delta * $this->pixelsPerRow());
        } elseif (MouseAction::Up === $event->getAction()) {
            $this->dragStartRow = null;
        }
    }
}
```

### 7.5 EditorWidget — Cursor Positioning

```php
class EditorWidget extends AbstractWidget implements FocusableInterface, MouseHandlerInterface
{
    public function handleMouse(MouseEvent $event, WidgetRect $widgetRect): void
    {
        if (MouseAction::Down === $event->getAction() && MouseButton::Left === $event->getButton()) {
            $relative = $widgetRect->toRelative($event->getRow(), $event->getCol());
            $this->document->moveCursorToPosition(
                $this->viewport->rowToLine($relative['row']),
                $relative['col']
            );
        } elseif (MouseAction::ScrollUp === $event->getAction()) {
            $this->viewport->scroll(-3);
        } elseif (MouseAction::ScrollDown === $event->getAction()) {
            $this->viewport->scroll(3);
        }
    }
}
```

---

## 8. Edge Cases and Robustness

### 8.1 Terminal Without Mouse Support

- **Detection**: `Terminal::isMouseSupported()` returns false in CI, SSH without `$TERM`, dumb terminals
- **Behavior**: Mouse sequences are never emitted; `MouseParser::parse()` simply won't match any sequences
- **No degradation**: Everything works identically via keyboard

### 8.2 tmux / Screen

- tmux may or may not forward mouse events depending on `set -g mouse on`
- When mouse is off in tmux, the outer terminal intercepts mouse for selection/copy — this is expected
- When mouse is on in tmux, SGR sequences are forwarded correctly with tmux's coordinate adjustment
- **No special handling needed** — SGR-1006 works through tmux transparently

### 8.3 Coordinate Overflow

- SGR-1006 uses decimal coordinates, so no overflow for any reasonable terminal size
- Old-style X10 is limited to 223 columns, but we only use it as a fallback for terminals that don't support SGR
- All coordinates are clamped to valid range in `WidgetRect::contains()`

### 8.4 Mouse Capture Leak

- If a widget captures the mouse and then gets removed from the tree, the WeakMap-based PositionTracker will lose its rect
- `MouseCoordinator::dispatch()` checks for null `$widgetRect` and releases capture
- Additionally, any `Up` event releases capture regardless

### 8.5 Scroll Direction on macOS

- macOS with "Natural scrolling" inverts scroll direction at the OS level
- The terminal always sends the physical direction (ScrollUp = scroll wheel up = content moves down)
- **No inversion needed** in the TUI — the OS handles it

### 8.6 Double/Triple Click

- Most terminals don't report double/triple click via SGR mouse (they select text instead)
- If needed in the future, double-click can be synthesized from two `Down` events within 300ms at the same position
- **Not in scope** for initial implementation

---

## 9. Testing Strategy

### 9.1 Unit Tests — MouseParser

```php
class MouseParserTest extends TestCase
{
    public function testParseSgrLeftClick(): void
    {
        $parser = new MouseParser();
        $event = $parser->parse("\x1b[<0;10;5M");
        $this->assertNotNull($event);
        $this->assertEquals(MouseAction::Down, $event->getAction());
        $this->assertEquals(MouseButton::Left, $event->getButton());
        $this->assertEquals(9, $event->getCol());   // 0-indexed
        $this->assertEquals(4, $event->getRow());   // 0-indexed
    }

    public function testParseSgrRelease(): void
    {
        $parser = new MouseParser();
        $event = $parser->parse("\x1b[<0;10;5m");  // lowercase m = release
        $this->assertEquals(MouseAction::Up, $event->getAction());
    }

    public function testParseSgrScrollUp(): void
    {
        $parser = new MouseParser();
        $event = $parser->parse("\x1b[<64;10;5M");  // bit 6 set, bit 0 clear
        $this->assertEquals(MouseAction::ScrollUp, $event->getAction());
    }

    public function testParseSgrScrollDown(): void
    {
        $parser = new MouseParser();
        $event = $parser->parse("\x1b[<65;10;5M");  // bit 6 set, bit 0 set
        $this->assertEquals(MouseAction::ScrollDown, $event->getAction());
    }

    public function testParseSgrDrag(): void
    {
        $parser = new MouseParser();
        $event = $parser->parse("\x1b[<32;20;10M");  // bit 5 = motion, button 0 = left
        $this->assertEquals(MouseAction::Drag, $event->getAction());
        $this->assertEquals(MouseButton::Left, $event->getButton());
    }

    public function testParseSgrModifiers(): void
    {
        $parser = new MouseParser();
        // shift (bit 2) + ctrl (bit 4) + left button (bits 0-1 = 0)
        $event = $parser->parse("\x1b[<20;5;3M");  // 0x14 = 00010100
        $this->assertTrue($event->isShift());
        $this->assertTrue($event->isCtrl());
        $this->assertFalse($event->isAlt());
    }

    public function testNonMouseSequenceReturnsNull(): void
    {
        $parser = new MouseParser();
        $this->assertNull($parser->parse("\x1b[A"));  // Up arrow
        $this->assertNull($parser->parse("a"));
    }
}
```

### 9.2 Unit Tests — MouseCoordinator

Using `VirtualTerminal` with manually set widget positions:

```php
class MouseCoordinatorTest extends TestCase
{
    public function testClickFocusesWidget(): void { /* ... */ }
    public function testScrollDispatchesToWidgetUnderCursor(): void { /* ... */ }
    public function testCaptureRoutesDragToCapturingWidget(): void { /* ... */ }
    public function testReleaseClearsCapture(): void { /* ... */ }
    public function testNoWidgetAtPositionIsNoOp(): void { /* ... */ }
}
```

### 9.3 Integration Test — Full Event Flow

```php
class MouseIntegrationTest extends TestCase
{
    public function testSgrSequenceFlowsFromStdinBufferToWidget(): void
    {
        // Set up Tui with VirtualTerminal
        // Register a mock widget with known position
        // Feed SGR mouse sequence into StdinBuffer
        // Assert widget received the correct MouseEvent
    }
}
```

---

## 10. Implementation Phases

### Phase 1: Foundation (Day 1-2)

1. Create `Mouse/` directory with `MouseEvent`, `MouseAction`, `MouseButton`, `MouseParser`
2. Add `MouseParserTest` — full coverage of SGR and X10 parsing
3. Add `PositionTracker::getAllWidgetRects()` and `Renderer::getPositionTracker()`
4. Add `Widget\MouseHandlerInterface`

**Deliverable**: Mouse events can be parsed from raw escape sequences.

### Phase 2: Dispatch Engine (Day 3-4)

5. Create `Mouse\MouseCoordinator` with hit-testing and capture support
6. Integrate into `Tui::handleInput()` — mouse events parsed and dispatched before keyboard
7. Add `MouseCoordinatorTest` — widget tree hit-testing, capture/release cycle
8. Enable/disable mouse tracking in `Terminal::start()`/`stop()` with environment detection

**Deliverable**: Mouse events flow from terminal to widgets. Click-to-focus works.

### Phase 3: Widget Adoption (Day 5-7)

9. Add `MouseHandlerInterface` to `SelectListWidget` — click to select, scroll to navigate
10. Add `MouseHandlerInterface` to `CollapsibleWidget` — click header to toggle
11. Add mouse scroll to conversation/message list
12. Add `MouseHandlerInterface` to `EditorWidget` — click to position cursor, scroll
13. Add `MouseHandlerInterface` to `ScrollbarWidget` — drag to scroll (if scrollbar exists)

**Deliverable**: All interactive widgets respond to mouse input.

### Phase 4: Polish (Day 8)

14. Integration tests for full event flow
15. Test in tmux, SSH, macOS Terminal, iTerm2, Windows Terminal
16. Add `$TERM` sniffing for known-broken terminals (fallback to no mouse)
17. Performance: verify hit-test doesn't regress render time (< 0.1ms for 50 widgets)

**Deliverable**: Production-ready mouse support.

---

## 11. Future Extensions (Out of Scope)

| Feature | Notes |
|---------|-------|
| **Text selection** | Requires `MouseAction::Drag` + shift-click + clipboard integration |
| **Split resize via drag** | Needs a `SplitHandle` widget that captures mouse during drag |
| **Right-click context menus** | `MouseButton::Right` → dispatch to widget → open a `PopupWidget` |
| **Double/triple click** | Synthesized from timing heuristic; most terminals handle natively |
| **Mouse motion tracking** (mode 1003) | Enable `\x1b[?1003h` for hover effects; high event volume — use sparingly |
| **Touch support** | Most touch terminals map to mouse events automatically |
| **URI hover** | OSC 8 hyperlinks + mouse motion for underline-on-hover |

---

## 12. File Manifest

### New Files

| File | Purpose |
|------|---------|
| `src/UI/Tui/Mouse/MouseEvent.php` | Mouse event value object |
| `src/UI/Tui/Mouse/MouseAction.php` | Mouse action enum |
| `src/UI/Tui/Mouse/MouseButton.php` | Mouse button enum |
| `src/UI/Tui/Mouse/MouseParser.php` | SGR/X10 sequence parser |
| `src/UI/Tui/Mouse/MouseCoordinator.php` | Hit-testing, capture, dispatch |
| `src/UI/Tui/Widget/MouseHandlerInterface.php` | Widget contract for mouse events |
| `tests/UI/Tui/Mouse/MouseParserTest.php` | Parser unit tests |
| `tests/UI/Tui/Mouse/MouseCoordinatorTest.php` | Coordinator unit tests |

### Modified Files

| File | Change |
|------|--------|
| `vendor/symfony/tui/.../Terminal/Terminal.php` | Add `\x1b[?1000h\x1b[?1002h\x1b[?1006h` in `start()`, disable in `stop()`, add `isMouseSupported()` |
| `vendor/symfony/tui/.../Terminal/TerminalInterface.php` | Add `isMouseSupported(): bool` |
| `vendor/symfony/tui/.../Terminal/VirtualTerminal.php` | Implement `isMouseSupported()` returning `false` |
| `vendor/symfony/tui/.../Tui/Tui.php` | Add `MouseCoordinator` field, route input in `handleInput()` |
| `vendor/symfony/tui/.../Render/PositionTracker.php` | Add `getAllWidgetRects(): WeakMap` |
| `vendor/symfony/tui/.../Render/Renderer.php` | Add `getPositionTracker(): PositionTracker` |

### Widget Files (Phase 3)

| File | Change |
|------|--------|
| `SelectListWidget.php` | Implement `MouseHandlerInterface` |
| `CollapsibleWidget.php` | Implement `MouseHandlerInterface` |
| `EditorWidget.php` | Implement `MouseHandlerInterface` |
| `ScrollbarWidget.php` | Implement `MouseHandlerInterface` (drag-to-scroll) |
| Conversation widget | Add scroll wheel handler |
