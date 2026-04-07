# 08 — Toast Notification System

> **Module**: `src/UI/Tui/Widget/ToastWidget.php`, `src/UI/Tui/Widget/ToastManager.php`
> **Dependencies**: Signal primitives (`01-reactive-state`), animation system, `TerminalNotification`
> **Replaces**: Nothing — new overlay capability
> **Relates**: `07-modal-dialog-system` (overlay rendering), `09-status-bar-widget` (feedback channel)

---

## 1. Problem Statement

KosmoKrator has no non-blocking, non-modal feedback mechanism for transient events:

| Issue | Detail |
|-------|--------|
| **No ephemeral feedback** | Events like "file saved", "permission denied", "copied to clipboard" are either printed inline (lost in scroll) or shown in the status bar (overwrites existing content) |
| **Terminal notifications are invisible** | `TerminalNotification` fires OSC 9/777/99 sequences to the *desktop*, but there's no in-terminal confirmation that anything happened |
| **Modal dialogs are too heavy** | `DialogWidget` blocks input and requires user action — overkill for "permission granted" or "mode switched" |
| **No stacking** | Multiple events can fire in quick succession (tool call completes, file saved, agent done) with no way to display them all |
| **No categorisation** | All feedback looks the same — no visual distinction between success, warning, error, and info |

**Goal:** A floating toast notification system that renders transient, auto-dismissing messages stacked in the bottom-right corner of the viewport, with type-based coloring, entrance/exit animations, and integration with the existing `TerminalNotification` desktop notification system.

---

## 2. Prior Art Research

### 2.1 VS Code Notifications
- **Stacking**: Bottom-right corner, newest at top, up to 3 visible
- **Auto-dismiss**: Info fades after 5s, errors persist until dismissed
- **Types**: Info (blue), Warning (yellow), Error (red)
- **Actions**: Optional inline buttons ("Undo", "Dismiss")
- **Key takeaway**: Auto-dismiss timing varies by severity; errors stay until manually dismissed

### 2.2 Textual (Python) Toast / Notification
- `Toast` widget appears at bottom of screen, auto-dismisses
- Uses CSS transitions for fade-in / fade-out
- Single line of text, icon prefix
- **Key takeaway**: Minimal API surface — `notify(message, severity)` is all you need

### 2.3 Hyper Terminal
- Bottom-center toast for "Update available", "Download complete"
- Single toast at a time, no stacking
- Click to dismiss, auto-dismiss after 4s
- **Key takeaway**: Click-to-dismiss is essential for terminal environments

### 2.4 Terminal Toast Libraries (node-terminal-toast, rich)
- Render ASCII-art boxes in the terminal
- Use ANSI color + bold for severity
- Support progress bars inside toasts (out of scope for us)
- **Key takeaway**: Color-coded borders + icon prefix are the standard visual pattern

### 2.5 Patterns Summary

| Feature | VS Code | Textual | Hyper | Terminal libs |
|---------|---------|---------|-------|--------------|
| Position | Bottom-right | Bottom | Bottom-center | Bottom-right |
| Stack | Yes (3 max) | No | No | Yes |
| Auto-dismiss | Severity-based | Fixed | Fixed (4s) | Fixed |
| Types | 3 levels | 3 levels | 1 level | 3-4 levels |
| Animation | Slide + fade | CSS fade | Fade | None/slide |
| Manual dismiss | Click/button | Click | Click | N/A |
| Actions | Inline buttons | No | No | No |

---

## 3. Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│ Terminal (viewport)                                              │
│                                                                  │
│  ┌─────────────────────────────┐                                 │
│  │ Message list / main content │                                 │
│  │                             │                                 │
│  │                             │               ┌─ Toast 3 ────┐ │
│  │                             │               │ ✓ File saved  │ │
│  │                             │               └───────────────┘ │
│  │                             │            ┌─ Toast 2 ────────┐│
│  │                             │            │ ⚠ Context 80%    ││
│  │                             │            └──────────────────┘│
│  │                             │         ┌─ Toast 1 ──────────┐│
│  │                             │         │ ✕ Permission denied ││
│  │                             │         └────────────────────┘│
│  ├─────────────────────────────┤                                 │
│  │ Status bar                  │                                 │
│  └─────────────────────────────┘                                 │
└──────────────────────────────────────────────────────────────────┘
```

Toasts float **above** all other content, positioned at the bottom-right of the viewport, above the status bar. They are non-modal — input continues to flow to the focused widget underneath.

### Class Hierarchy

```
ToastManager (singleton, owns the toast lifecycle)
├── ToastItem (value object: message, type, timers, animation state)
├── ToastOverlayWidget (renders the toast stack as a floating layer)
└── ToastType (enum: success, warning, error, info)

Relationship to existing systems:
  ToastManager
  ├── uses TerminalNotification  (desktop notification bridge)
  └── used by TuiCoreRenderer   (integration point)
```

### Interaction with Reactive State

```
ToastOverlayWidget
├── Signal<list<ToastItem>> $toasts     — current visible toast stack
├── Computed<int> $availableHeight      — viewport rows - status bar - margin
├── Effect → start auto-dismiss timers when toasts change
└── Effect → re-render when any toast's animation state changes

ToastItem (per-toast signals)
├── Signal<float> $opacity              — 0.0 → 1.0 (entrance), 1.0 → 0.0 (exit)
├── Signal<int> $slideOffset            — horizontal offset for slide-from-right
└── Signal<ToastPhase> $phase           — entering | visible | exiting | done
```

---

## 4. Toast Types

### 4.1 `ToastType` Enum

| Type | Icon | Border Color | Text Color | Auto-dismiss | Use Cases |
|------|------|-------------|-----------|-------------|-----------|
| `Success` | `✓` | Green `(80,220,100)` | Green `(120,240,140)` | 2s | File saved, permission granted, copy confirmed, agent completed |
| `Warning` | `⚠` | Yellow `(255,200,80)` | Yellow `(255,220,120)` | 3s | Context high, deprecated API, slow operation |
| `Error` | `✕` | Red `(255,80,60)` | Red `(255,120,100)` | 4s | Permission denied, tool failed, file not found |
| `Info` | `ℹ` | Blue `(100,160,255)` | Blue `(140,190,255)` | 2s | Mode switched, session resumed, settings changed |

### 4.2 Duration Rules

- **Info / Success**: 2000ms — brief confirmation, user saw the action
- **Warning**: 3000ms — needs a moment to register
- **Error**: 4000ms — may need to read the full message
- **Sticky**: Error toasts can optionally be made sticky (no auto-dismiss) for critical failures
- **Hover pause**: If mouse support is active and the cursor is over a toast, its timer pauses

---

## 5. Class Designs

### 5.1 `ToastType` (Enum)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Toast;

/**
 * Semantic toast notification type.
 *
 * Each type has a fixed icon, color scheme, and default auto-dismiss duration.
 */
enum ToastType: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Error   = 'error';
    case Info    = 'info';

    /**
     * Unicode icon prefix for this toast type.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Success => '✓',
            self::Warning => '⚠',
            self::Error   => '✕',
            self::Info    => 'ℹ',
        };
    }

    /**
     * Default auto-dismiss duration in milliseconds.
     */
    public function defaultDuration(): int
    {
        return match ($this) {
            self::Success => 2000,
            self::Warning => 3000,
            self::Error   => 4000,
            self::Info    => 2000,
        };
    }

    /**
     * ANSI foreground color for the toast icon and text.
     */
    public function foregroundColor(): string
    {
        return match ($this) {
            self::Success => "\033[38;2;120;240;140m",
            self::Warning => "\033[38;2;255;220;120m",
            self::Error   => "\033[38;2;255;120;100m",
            self::Info    => "\033[38;2;140;190;255m",
        };
    }

    /**
     * ANSI foreground color for the toast border and background tint.
     */
    public function borderColor(): string
    {
        return match ($this) {
            self::Success => "\033[38;2;80;220;100m",
            self::Warning => "\033[38;2;255;200;80m",
            self::Error   => "\033[38;2;255;80;60m",
            self::Info    => "\033[38;2;100;160;255m",
        };
    }

    /**
     * ANSI background color (subtle tint matching the type).
     */
    public function backgroundColor(): string
    {
        return match ($this) {
            self::Success => "\033[48;2;20;40;25m",
            self::Warning => "\033[48;2;40;35;15m",
            self::Error   => "\033[48;2;45;18;15m",
            self::Info    => "\033[48;2;18;25;45m",
        };
    }

    /**
     * Dark border character color (for the box outline).
     */
    public function borderDimColor(): string
    {
        return match ($this) {
            self::Success => "\033[38;2;50;130;60m",
            self::Warning => "\033[38;2;160;120;40m",
            self::Error   => "\033[38;2;160;50;35m",
            self::Info    => "\033[38;2;60;100;160m",
        };
    }
}
```

### 5.2 `ToastPhase` (Enum)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Toast;

/**
 * Lifecycle phase of a single toast notification.
 */
enum ToastPhase: string
{
    case Entering = 'entering';   // Slide-from-right + fade-in animation
    case Visible  = 'visible';   // Fully shown, auto-dismiss timer running
    case Exiting  = 'exiting';   // Fade-out animation
    case Done     = 'done';      // Animation complete, ready for removal
}
```

### 5.3 `ToastItem` (Value Object)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Toast;

use Kosmokrator\UI\Tui\Reactive\Signal;

/**
 * A single toast notification instance with reactive animation state.
 *
 * Each toast tracks its own lifecycle: entering → visible → exiting → done.
 * The ToastManager drives phase transitions; the ToastOverlayWidget reads
 * signals for rendering.
 */
final class ToastItem
{
    private static int $idCounter = 0;

    // --- Identity ---
    public readonly int $id;
    public readonly string $message;
    public readonly ToastType $type;
    public readonly int $durationMs;

    // --- Reactive state ---
    /** @var Signal<float> Opacity: 0.0 during entering, 1.0 when visible, fading to 0.0 during exiting */
    public readonly Signal $opacity;

    /** @var Signal<int> Horizontal slide offset (in columns). Starts at toast width, animates to 0. */
    public readonly Signal $slideOffset;

    /** @var Signal<ToastPhase> Current lifecycle phase */
    public readonly Signal $phase;

    // --- Timing ---
    public readonly float $createdAt;

    /**
     * @param string $message      Toast body text (plain text, no ANSI)
     * @param ToastType $type      Semantic type (determines color, icon, duration)
     * @param int $durationMs      Auto-dismiss duration in ms (0 = sticky)
     * @param float|null $createdAt Monotonic timestamp of creation (for ordering)
     */
    public function __construct(
        string $message,
        ToastType $type,
        int $durationMs = 0,
        ?float $createdAt = null,
    ) {
        $this->id = ++self::$idCounter;
        $this->message = $message;
        $this->type = $type;
        $this->durationMs = $durationMs > 0 ? $durationMs : $type->defaultDuration();
        $this->createdAt = $createdAt ?? microtime(true);

        // Initial animation state: invisible, fully off-screen to the right
        $this->opacity = new Signal(0.0);
        $this->slideOffset = new Signal(40); // will be recalculated on first render
        $this->phase = new Signal(ToastPhase::Entering);
    }

    /**
     * Convenience factory for common toast types.
     */
    public static function success(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Success, $durationMs);
    }

    public static function warning(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Warning, $durationMs);
    }

    public static function error(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Error, $durationMs);
    }

    public static function info(string $message, int $durationMs = 0): self
    {
        return new self($message, ToastType::Info, $durationMs);
    }

    /**
     * Whether this toast should auto-dismiss (non-sticky).
     */
    public function isAutoDismiss(): bool
    {
        return $this->durationMs > 0;
    }

    /**
     * Begin the exit animation.
     */
    public function dismiss(): void
    {
        if ($this->phase->get() !== ToastPhase::Done) {
            $this->phase->set(ToastPhase::Exiting);
        }
    }

    /**
     * Mark as fully done (ready for removal from the stack).
     */
    public function markDone(): void
    {
        $this->phase->set(ToastPhase::Done);
        $this->opacity->set(0.0);
    }
}
```

### 5.4 `ToastManager` (Lifecycle Controller)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Toast;

use Kosmokrator\UI\TerminalNotification;
use Kosmokrator\UI\Tui\Reactive\Signal;
use Revolt\EventLoop;

/**
 * Manages the lifecycle of toast notifications.
 *
 * Responsibilities:
 * 1. Add toasts to the visible stack (max 5)
 * 2. Drive entrance/exit animations via timer callbacks
 * 3. Auto-dismiss toasts after their configured duration
 * 4. Remove completed toasts from the stack
 * 5. Bridge to TerminalNotification for desktop notifications
 * 6. Provide a simple static API for callers
 *
 * Usage:
 *   ToastManager::show('File saved', ToastType::Success);
 *   ToastManager::error('Permission denied');
 *   ToastManager::info('Mode: Edit');
 */
final class ToastManager
{
    private const MAX_VISIBLE = 5;
    private const ENTRANCE_DURATION_MS = 150;
    private const EXIT_DURATION_MS = 200;
    private const ANIMATION_FRAME_MS = 16; // ~60fps

    /** @var Signal<list<ToastItem>> The current toast stack (newest first) */
    public readonly Signal $toasts;

    /** @var array<int, string> Active timer IDs for cleanup */
    private array $timers = [];

    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var bool Whether to also fire TerminalNotification (desktop) for errors */
    private bool $desktopNotifyOnError = true;

    private function __construct()
    {
        $this->toasts = new Signal([]);
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // --- Static convenience API ---

    public static function show(string $message, ToastType $type, int $durationMs = 0): ToastItem
    {
        return self::getInstance()->addToast(new ToastItem($message, $type, $durationMs));
    }

    public static function success(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Success, $durationMs);
    }

    public static function warning(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Warning, $durationMs);
    }

    public static function error(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Error, $durationMs);
    }

    public static function info(string $message, int $durationMs = 0): ToastItem
    {
        return self::show($message, ToastType::Info, $durationMs);
    }

    /**
     * Dismiss all active toasts immediately (with exit animation).
     */
    public static function dismissAll(): void
    {
        self::getInstance()->dismissAllToasts();
    }

    // --- Instance API ---

    /**
     * Add a toast item to the stack and begin its lifecycle.
     */
    public function addToast(ToastItem $toast): ToastItem
    {
        $stack = $this->toasts->get();

        // Enforce max visible: dismiss oldest if stack is full
        if (count($stack) >= self::MAX_VISIBLE) {
            $oldest = $stack[array_key_last($stack)];
            $this->dismissToast($oldest);
            $stack = array_filter($stack, fn(ToastItem $t) => $t->id !== $oldest->id);
        }

        // Prepend (newest first = rendered at top)
        array_unshift($stack, $toast);
        $this->toasts->set(array_values($stack));

        // Start entrance animation
        $this->startEntranceAnimation($toast);

        // Bridge to desktop notification for errors
        if ($this->desktopNotifyOnError && $toast->type === ToastType::Error) {
            TerminalNotification::notify();
        }

        return $toast;
    }

    /**
     * Dismiss a specific toast (starts exit animation).
     */
    public function dismissToast(ToastItem $toast): void
    {
        if ($toast->phase->get() === ToastPhase::Exiting
            || $toast->phase->get() === ToastPhase::Done
        ) {
            return;
        }

        $toast->dismiss();
        $this->cancelTimers($toast->id);
        $this->startExitAnimation($toast);
    }

    /**
     * Dismiss all toasts with exit animation.
     */
    public function dismissAllToasts(): void
    {
        foreach ($this->toasts->get() as $toast) {
            $this->dismissToast($toast);
        }
    }

    /**
     * Remove a toast from the stack (called after exit animation completes).
     */
    public function removeToast(ToastItem $toast): void
    {
        $stack = array_values(array_filter(
            $this->toasts->get(),
            fn(ToastItem $t) => $t->id !== $toast->id,
        ));
        $this->toasts->set($stack);
        $this->cancelTimers($toast->id);
    }

    /**
     * Find a toast by its screen coordinates (for mouse click dismissal).
     *
     * @param int $row Screen row (1-based)
     * @param int $col Screen column (1-based)
     * @param int $viewportRows Total viewport rows
     * @param int $viewportCols Total viewport columns
     * @param int $statusBarRows Height of the status bar area
     * @return ToastItem|null The toast at those coordinates, or null
     */
    public function getToastAt(
        int $row,
        int $col,
        int $viewportRows,
        int $viewportCols,
        int $statusBarRows = 1,
    ): ?ToastItem {
        // Delegate position calculation to ToastOverlayWidget's layout logic
        // (see section 6.2 for the layout algorithm)
        $marginRight = 2;
        $marginBottom = $statusBarRows + 1;
        $toastMaxWidth = min(50, $viewportCols - $marginRight - 4);

        $baseRow = $viewportRows - $marginBottom;
        $currentRow = $baseRow;

        foreach ($this->toasts->get() as $toast) {
            if ($toast->phase->get() === ToastPhase::Done) {
                continue;
            }

            $visibleLines = $this->calculateToastHeight($toast->message, $toastMaxWidth - 4);
            $toastTop = $currentRow - $visibleLines + 1;
            $toastLeft = $viewportCols - $marginRight - $toastMaxWidth;
            $toastRight = $viewportCols - $marginRight;

            if ($row >= $toastTop && $row <= $currentRow
                && $col >= $toastLeft && $col <= $toastRight
            ) {
                return $toast;
            }

            $currentRow = $toastTop - 1; // 1-row gap between toasts
        }

        return null;
    }

    /**
     * Enable/disable desktop notification bridging for error toasts.
     */
    public function setDesktopNotifyOnError(bool $enabled): void
    {
        $this->desktopNotifyOnError = $enabled;
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->dismissAllToasts();
            self::$instance = null;
        }
    }

    // --- Private: animation lifecycle ---

    /**
     * Animate a toast's entrance: slide from right + fade in.
     */
    private function startEntranceAnimation(ToastItem $toast): void
    {
        $frames = (int) ceil(self::ENTRANCE_DURATION_MS / self::ANIMATION_FRAME_MS);
        $slideStart = 30; // columns off-screen to the right
        $frameDuration = self::ENTRANCE_DURATION_MS / $frames;

        $toast->slideOffset->set($slideStart);
        $toast->opacity->set(0.0);

        $currentFrame = 0;
        $timerId = EventLoop::addPeriodicTimer(
            $frameDuration / 1000,
            function () use ($toast, &$currentFrame, $frames, $slideStart) {
                $currentFrame++;
                $progress = min(1.0, $currentFrame / $frames);

                // Ease-out curve for smooth deceleration
                $eased = 1.0 - (1.0 - $progress) ** 2;

                $toast->slideOffset->set((int) round($slideStart * (1.0 - $eased)));
                $toast->opacity->set($eased);

                if ($progress >= 1.0) {
                    $toast->phase->set(ToastPhase::Visible);
                    $this->cancelTimers($toast->id);
                    $this->scheduleAutoDismiss($toast);
                }
            },
        );

        $this->timers[$toast->id . '_entrance'] = $timerId;
    }

    /**
     * Schedule auto-dismissal after the toast's configured duration.
     */
    private function scheduleAutoDismiss(ToastItem $toast): void
    {
        if (!$toast->isAutoDismiss()) {
            return; // Sticky toast — no auto-dismiss
        }

        $timerId = EventLoop::addTimer(
            $toast->durationMs / 1000,
            function () use ($toast): void {
                if ($toast->phase->get() === ToastPhase::Visible) {
                    $this->dismissToast($toast);
                }
            },
        );

        $this->timers[$toast->id . '_auto'] = $timerId;
    }

    /**
     * Animate a toast's exit: fade out.
     */
    private function startExitAnimation(ToastItem $toast): void
    {
        $frames = (int) ceil(self::EXIT_DURATION_MS / self::ANIMATION_FRAME_MS);
        $frameDuration = self::EXIT_DURATION_MS / $frames;

        $currentFrame = 0;
        $timerId = EventLoop::addPeriodicTimer(
            $frameDuration / 1000,
            function () use ($toast, &$currentFrame, $frames) {
                $currentFrame++;
                $progress = min(1.0, $currentFrame / $frames);

                // Ease-in for fade-out
                $eased = $progress ** 2;
                $toast->opacity->set(1.0 - $eased);

                if ($progress >= 1.0) {
                    $toast->markDone();
                    $this->cancelTimers($toast->id);
                    $this->removeToast($toast);
                }
            },
        );

        $this->timers[$toast->id . '_exit'] = $timerId;
    }

    /**
     * Cancel all timers for a given toast ID.
     */
    private function cancelTimers(int $toastId): void
    {
        foreach (['_entrance', '_auto', '_exit'] as $suffix) {
            $key = $toastId . $suffix;
            if (isset($this->timers[$key])) {
                EventLoop::cancelTimer($this->timers[$key]);
                unset($this->timers[$key]);
            }
        }
    }

    /**
     * Calculate the rendered height (in terminal rows) of a toast message.
     */
    private function calculateToastHeight(string $message, int $innerWidth): int
    {
        // 1 line top border + N content lines + 1 line bottom border
        $lines = 1; // top border
        $wrapped = $this->wrapText($message, $innerWidth);
        $lines += count($wrapped);
        $lines += 1; // bottom border
        return $lines;
    }

    /**
     * Simple word-wrap to fit within a visible character width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        if (mb_strwidth($text) <= $width) {
            return [$text];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strwidth($test) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }
}
```

### 5.5 `ToastOverlayWidget` (Renderer)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Toast;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Reactive\Signal;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Floating overlay widget that renders the toast notification stack.
 *
 * Renders in the bottom-right corner of the viewport, above the status bar.
 * Each toast is a bordered box with icon, message, and type-colored styling.
 * Handles entrance/exit animations via per-toast opacity/slideOffset signals.
 *
 * This widget is non-modal: it renders on top of other content but does not
 * capture input. Dismissal is handled by ToastManager (via Escape key or click).
 *
 * Usage (in TuiCoreRenderer):
 *   $this->toastOverlay = new ToastOverlayWidget(ToastManager::getInstance()->toasts);
 *   // Add to overlay container at z-index above other content
 */
final class ToastOverlayWidget extends AbstractWidget
{
    // ── Layout constants ────────────────────────────────────────────────
    private const MAX_TOAST_WIDTH = 50;
    private const MIN_TOAST_WIDTH = 20;
    private const MARGIN_RIGHT = 2;
    private const MARGIN_BOTTOM = 2; // above status bar
    private const GAP_BETWEEN_TOASTS = 1;

    // ── Border characters (rounded) ─────────────────────────────────────
    private const BORDER_TL = '╭';
    private const BORDER_TR = '╮';
    private const BORDER_BL = '╰';
    private const BORDER_BR = '╯';
    private const BORDER_H  = '─';
    private const BORDER_V  = '│';

    // ── State ───────────────────────────────────────────────────────────

    /** @var Signal<list<ToastItem>> Reactive toast stack from ToastManager */
    private readonly Signal $toastsSignal;

    /** @var int Height of the status bar (in rows), to offset positioning */
    private int $statusBarHeight = 1;

    // ── Constructor ─────────────────────────────────────────────────────

    /**
     * @param Signal<list<ToastItem>> $toasts Reactive toast stack signal
     */
    public function __construct(Signal $toasts)
    {
        $this->toastsSignal = $toasts;
    }

    /**
     * Set the status bar height for bottom positioning offset.
     */
    public function setStatusBarHeight(int $rows): void
    {
        $this->statusBarHeight = $rows;
    }

    // ── Rendering ───────────────────────────────────────────────────────

    /**
     * Render the toast overlay as ANSI-formatted lines with cursor positioning.
     *
     * Each toast is rendered at its calculated position using absolute
     * cursor placement (\033[row;colH). This avoids interfering with the
     * main content render.
     *
     * @return list<string> ANSI lines with absolute positioning
     */
    public function render(RenderContext $context): array
    {
        $toasts = $this->toastsSignal->get();

        // Filter out done toasts
        $visibleToasts = array_filter(
            $toasts,
            fn(ToastItem $t) => $t->phase->get() !== ToastPhase::Done,
        );

        if ($visibleToasts === []) {
            return [];
        }

        $cols = $context->getColumns();
        $rows = $context->getRows();
        $output = [];

        // Calculate toast dimensions
        $toastWidth = min(self::MAX_TOAST_WIDTH, $cols - self::MARGIN_RIGHT - 4);
        $toastWidth = max(self::MIN_TOAST_WIDTH, $toastWidth);
        $innerWidth = $toastWidth - 4; // border + padding

        // Render each toast from bottom to top
        $baseRow = $rows - $this->statusBarHeight - self::MARGIN_BOTTOM;
        $currentBottomRow = $baseRow;

        foreach ($visibleToasts as $toast) {
            $opacity = $toast->opacity->get();
            $slideOffset = $toast->slideOffset->get();

            // Skip fully transparent toasts
            if ($opacity <= 0.01) {
                continue;
            }

            $toastLines = $this->renderSingleToast($toast, $toastWidth, $innerWidth, $opacity);
            $toastHeight = count($toastLines);

            $topRow = $currentBottomRow - $toastHeight + 1;
            $leftCol = $cols - self::MARGIN_RIGHT - $toastWidth + $slideOffset;

            // Place each line of the toast at its absolute position
            foreach ($toastLines as $lineOffset => $line) {
                $row = $topRow + $lineOffset;
                if ($row < 0 || $row >= $rows) {
                    continue;
                }
                // Use cursor positioning to place the toast
                $output[] = "\033[{$row};" . ($leftCol + 1) . "H" . $line;
            }

            $currentBottomRow = $topRow - self::GAP_BETWEEN_TOASTS;
        }

        return $output;
    }

    /**
     * Render a single toast box with border, icon, and message.
     *
     * @return list<string> ANSI-formatted lines (no cursor positioning)
     */
    private function renderSingleToast(
        ToastItem $toast,
        int $toastWidth,
        int $innerWidth,
        float $opacity,
    ): array {
        $r = Theme::reset();
        $type = $toast->type;

        // Opacity-aware colors: interpolate toward black as opacity decreases
        $border = $this->applyOpacity($type->borderDimColor(), $opacity);
        $bg = $this->applyOpacity($type->backgroundColor(), $opacity);
        $fg = $this->applyOpacity($type->foregroundColor(), $opacity);
        $dim = $this->applyOpacity(Theme::dim(), $opacity);

        // Wrap message text to fit inner width
        $wrappedLines = $this->wrapText($toast->message, $innerWidth - 3); // "icon space " prefix

        $lines = [];

        // Top border
        $lines[] = $border . self::BORDER_TL . str_repeat(self::BORDER_H, $toastWidth - 2) . self::BORDER_TR . $r;

        // Content lines (first line gets icon prefix)
        foreach ($wrappedLines as $index => $line) {
            if ($index === 0) {
                // First line: icon + space + message
                $content = $fg . $type->icon() . $r . ' ' . $fg . $this->truncateToWidth($line, $innerWidth - 3) . $r;
            } else {
                // Continuation lines: indent to align with message text
                $content = '  ' . $fg . $this->truncateToWidth($line, $innerWidth - 2) . $r;
            }

            $lines[] = $border . self::BORDER_V . $r
                . $bg . ' ' . $content . $r
                . $bg . str_repeat(' ', max(0, $innerWidth - $this->visibleWidth($content))) . ' ' . $r
                . $border . self::BORDER_V . $r;
        }

        // Bottom border
        $lines[] = $border . self::BORDER_BL . str_repeat(self::BORDER_H, $toastWidth - 2) . self::BORDER_BR . $r;

        return $lines;
    }

    /**
     * Apply opacity to an ANSI color sequence by interpolating toward the
     * terminal's default background (assumed dark: ~rgb(18,18,25)).
     *
     * In practice for TUI environments, we modify the *background* component
     * of background colors and leave foreground colors mostly intact — true
     * transparency isn't possible in terminals. Instead, we blend toward the
     * terminal background color.
     */
    private function applyOpacity(string $ansiSequence, float $opacity): string
    {
        // For simplicity in the initial implementation, opacity is handled by
        // either using the color at full strength (opacity >= 0.5) or switching
        // to a dimmed variant (opacity < 0.5).
        //
        // A more sophisticated version would parse the RGB values from the ANSI
        // sequence and interpolate them toward the background color.
        if ($opacity >= 0.5) {
            return $ansiSequence;
        }

        // Blend toward dark background by replacing with dimmer version
        return Theme::dim();
    }

    /**
     * Measure the visible (non-ANSI) width of a string.
     */
    private function visibleWidth(string $text): int
    {
        // Strip ANSI escape sequences and measure visible width
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $text);
        return mb_strwidth($stripped);
    }

    /**
     * Truncate text to a maximum visible width.
     */
    private function truncateToWidth(string $text, int $maxWidth): string
    {
        if (mb_strwidth($text) <= $maxWidth) {
            return $text;
        }

        // Truncate and add ellipsis
        while (mb_strwidth($text) > $maxWidth - 1 && $text !== '') {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }

    /**
     * Word-wrap text to fit within a given visible width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        if (mb_strwidth($text) <= $width) {
            return [$text];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strwidth($test) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }
}
```

---

## 6. Feature Deep-Dives

### 6.1 Stacking Algorithm

Toasts are stacked bottom-to-top in the bottom-right corner:

```
Viewport bottom-right:

                        ┌─────────────────────┐
                        │ ℹ Mode: Plan        │  ← newest (index 0)
                        └─────────────────────┘
                        ┌─────────────────────┐
                        │ ✓ File saved        │  ← second
                        └─────────────────────┘
                        ┌─────────────────────┐
                        │ ✕ Permission denied │  ← oldest (rendered lowest)
                        └─────────────────────┘
            ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─
            │ Status bar                      │
```

Layout calculation:
1. `baseRow = viewportRows - statusBarHeight - marginBottom`
2. For each toast (newest first):
   - Calculate toast height = `1 (top border) + messageLines + 1 (bottom border)`
   - Render from `baseRow` upward
   - Decrement `baseRow` by `toastHeight + gapBetweenToasts`
3. If a toast would render above row 0, skip it (stack overflow)

### 6.2 Mouse Click Dismissal

When mouse support (`05-mouse-support`) is available:

```php
// In TuiCoreRenderer's mouse event handler:
public function handleMouseEvent(int $row, int $col, string $action): void
{
    if ($action === 'click') {
        $toast = ToastManager::getInstance()->getToastAt(
            $row, $col,
            $this->viewportRows,
            $this->viewportCols,
            $this->statusBarHeight,
        );
        if ($toast !== null) {
            ToastManager::getInstance()->dismissToast($toast);
        }
    }
}
```

The hit-test uses the same positioning algorithm as the overlay renderer, checking if `(row, col)` falls within any toast's bounding box.

### 6.3 Escape Key Dismissal

Escape dismisses the **topmost** (newest) toast:

```php
// In TuiCoreRenderer's key handler, before modal/focus logic:
public function handleKey(string $key): void
{
    if ($key === "\033" /* Escape */) {
        $toasts = ToastManager::getInstance()->toasts->get();
        if ($toasts !== []) {
            ToastManager::getInstance()->dismissToast($toasts[0]);
            return; // consumed
        }
    }
    // ... normal key handling
}
```

Escape only dismisses one toast per press. A user can rapidly press Escape to clear the stack. If no toasts are visible, Escape falls through to normal handling (modal dismissal, etc.).

### 6.4 Entrance Animation (Slide from Right)

```
Frame 0:    offset=30, opacity=0.0   ← fully off-screen right
Frame 1:    offset=22, opacity=0.3
Frame 2:    offset=14, opacity=0.6
Frame 3:    offset=7,  opacity=0.85
Frame 4:    offset=3,  opacity=0.95
Frame 5:    offset=0,  opacity=1.0   ← final position
```

The animation uses an **ease-out quadratic** curve: `eased = 1 - (1 - t)²`. This gives a natural deceleration as the toast settles into its final position.

Duration: 150ms (approximately 9 frames at 60fps, or 5 frames at 16ms intervals).

The `slideOffset` signal shifts the toast's `leftCol` rightward during rendering. Content that would render outside the viewport is naturally clipped (terminal doesn't render beyond the last column).

### 6.5 Exit Animation (Fade Out)

```
Frame 0:    opacity=1.0   ← fully visible
Frame 1:    opacity=0.7
Frame 2:    opacity=0.4
Frame 3:    opacity=0.1
Frame 4:    opacity=0.0   ← invisible, marked Done
```

The exit animation uses an **ease-in quadratic** curve: `eased = t²`. This creates a gentle fade-out that accelerates slightly at the end.

Duration: 200ms.

In terminals, true alpha transparency isn't possible. The "fade" is implemented by:
1. For `opacity >= 0.5`: render with full colors (toast is "visible")
2. For `opacity < 0.5`: render with dimmed/neutral colors (toast is "fading")
3. For `opacity <= 0.01`: skip rendering entirely

A more sophisticated approach (v2) could blend the ANSI RGB values toward the background color.

### 6.6 TerminalNotification Bridge

The `ToastManager` bridges to the existing `TerminalNotification` system:

```php
// In ToastManager::addToast():
if ($this->desktopNotifyOnError && $toast->type === ToastType::Error) {
    TerminalNotification::notify();
}
```

This ensures that:
- **Error toasts** also fire a desktop notification (via OSC 9 for iTerm2, OSC 777 for Ghostty, OSC 99 for Kitty)
- The user sees the error both in the terminal (toast) and in their desktop notification center
- The desktop notification fires only for errors, not for routine success/info toasts (to avoid spam)

The bridge is configurable:
```php
ToastManager::getInstance()->setDesktopNotifyOnError(false); // disable if user prefers
```

### 6.7 Auto-Dismiss Timer Management

Each toast has two possible timer sources:

| Timer | Triggered By | Duration | Action |
|-------|-------------|----------|--------|
| `entrance` | `addToast()` | 150ms | Drives slide+fade entrance animation |
| `auto` | `entrance` completion | `type.defaultDuration()` | Starts exit animation |
| `exit` | `dismissToast()` or auto-timer | 200ms | Drives fade-out, then removes from stack |

Timer cleanup:
- When a toast is manually dismissed, its `auto` timer is cancelled and replaced with the `exit` timer
- When a toast is removed, all its timers are cancelled
- `ToastManager::reset()` dismisses all toasts and cancels all timers

### 6.8 Integration with TuiCoreRenderer

The toast system integrates into `TuiCoreRenderer` at three points:

**1. Initialization (in `initTui()`):**
```php
// After creating other widgets
$this->toastOverlay = new ToastOverlayWidget(ToastManager::getInstance()->toasts);
$this->toastOverlay->setStatusBarHeight(1);
// Add to the overlay container (same z-layer as modal overlay)
$this->overlay->add($this->toastOverlay);
```

**2. Rendering (in the render cycle):**
```php
// The ToastOverlayWidget is a persistent child of the overlay container.
// It renders on top of other content automatically because it uses
// absolute cursor positioning (\033[row;colH) for each toast.
// No special render-order logic needed.
```

**3. Key/mouse handling:**
```php
// In handleKey():
if ($key === Key::ESCAPE) {
    $toasts = ToastManager::getInstance()->toasts->get();
    if ($toasts !== [] && $toasts[0]->phase->get() !== ToastPhase::Done) {
        ToastManager::getInstance()->dismissToast($toasts[0]);
        return; // consumed — don't pass to modal/focus system
    }
}

// In handleMouse() (when 05-mouse-support is implemented):
if ($action === 'left_click') {
    $toast = ToastManager::getInstance()->getToastAt(...);
    if ($toast !== null) {
        ToastManager::getInstance()->dismissToast($toast);
        return;
    }
}
```

---

## 7. Use Cases

### 7.1 Tool Permission Granted

```php
// In TuiModalManager, after user grants permission:
ToastManager::success("Permission granted: {$toolName}");
```

### 7.2 Tool Permission Denied

```php
// In TuiModalManager, after user denies permission:
ToastManager::error("Permission denied: {$toolName}");
```

### 7.3 File Saved

```php
// In the file-write tool handler, after successful write:
ToastManager::success("Saved: " . basename($filePath));
```

### 7.4 Agent Completed

```php
// In TuiCoreRenderer, when the agent finishes responding:
ToastManager::success('Agent completed');
// Desktop notification fires separately via existing TerminalNotification::notify()
```

### 7.5 Mode Switched

```php
// In TuiCoreRenderer::switchMode():
ToastManager::info("Mode: {$newMode}");
```

### 7.6 Copy Confirmation

```php
// After copying text to clipboard:
ToastManager::success('Copied to clipboard');
```

### 7.7 Context Limit Warning

```php
// In token usage monitoring, when approaching limit:
if ($ratio > 0.8) {
    ToastManager::warning('Context usage at ' . (int)($ratio * 100) . '%');
}
```

### 7.8 Tool Error

```php
// In tool execution error handler:
ToastManager::error("Tool failed: {$toolName} — " . $errorMessage);
```

### 7.9 Sticky Error (Critical Failure)

```php
// For errors that must be manually dismissed:
ToastManager::show('Critical: API key invalid', ToastType::Error, durationMs: 0);
// durationMs: 0 = sticky (no auto-dismiss, must click/Escape)
```

---

## 8. Migration Plan

### Phase 1: Core Infrastructure (non-breaking)

1. **Create `ToastType`** enum — pure value type, no dependencies
2. **Create `ToastPhase`** enum — lifecycle states
3. **Create `ToastItem`** — value object with reactive signals
4. **Write tests** for `ToastType` (colors, durations, icons) and `ToastItem` (factory methods, lifecycle)
5. **Create `ToastManager`** — lifecycle controller with timer-based animations
6. **Write tests** for `ToastManager` (add, dismiss, auto-dismiss, stack overflow)

### Phase 2: Renderer

7. **Create `ToastOverlayWidget`** — renders toast stack with absolute positioning
8. **Write snapshot tests** for single toast rendering, stacked rendering, truncated text
9. **Write visual tests** at different viewport widths (40, 80, 120 cols)

### Phase 3: Integration

10. **Add `ToastOverlayWidget`** to `TuiCoreRenderer::initTui()` as a persistent overlay child
11. **Add Escape key handler** for toast dismissal (before modal/focus handling)
12. **Wire `ToastManager::error()`** to `TerminalNotification::notify()` bridge
13. **Test** that toasts don't interfere with modal dialogs or input focus

### Phase 4: Domain Integration

14. **Add toast calls** to `TuiModalManager` — permission granted/denied
15. **Add toast calls** to tool execution handlers — file saved, tool error
16. **Add toast calls** to mode switching logic
17. **Add toast call** for copy-to-clipboard confirmation

---

## 9. Test Plan

### Unit Tests

| Test | What it verifies |
|------|-----------------|
| `ToastTypeTest::testIcons` | Each type returns correct icon character |
| `ToastTypeTest::testDurations` | Default durations match spec (2s/3s/4s/2s) |
| `ToastTypeTest::testColors` | Foreground, border, background ANSI codes are correct |
| `ToastItemTest::testFactoryMethods` | `success()`, `warning()`, `error()`, `info()` create correct types |
| `ToastItemTest::testInitialPhase` | New toast starts in `Entering` phase |
| `ToastItemTest::testDismiss` | `dismiss()` transitions to `Exiting` |
| `ToastItemTest::testMarkDone` | `markDone()` sets `Done` phase and 0 opacity |
| `ToastManagerTest::testAddToast` | Toast appears in stack signal |
| `ToastManagerTest::testMaxVisible` | 6th toast dismisses oldest |
| `ToastManagerTest::testDismissToast` | Dismissed toast enters exit phase |
| `ToastManagerTest::testDismissAll` | All toasts enter exit phase |
| `ToastManagerTest::testAutoDismiss` | Toast auto-dismisses after configured duration |
| `ToastManagerTest::testStickyToast` | `durationMs: 0` toast never auto-dismisses |
| `ToastManagerTest::testDesktopBridge` | Error toast triggers `TerminalNotification::notify()` |

### Rendering Tests

| Test | What it verifies |
|------|-----------------|
| `ToastOverlayWidgetTest::testEmptyStack` | No toasts → empty output |
| `ToastOverlayWidgetTest::testSingleToast` | One info toast renders correctly with border |
| `ToastOverlayWidgetTest::testStackedToasts` | Three toasts stack vertically with gap |
| `ToastOverlayWidgetTest::testOpacityFade` | Fading toast uses dim colors |
| `ToastOverlayWidgetTest::testSlideOffset` | Entering toast is shifted right |
| `ToastOverlayWidgetTest::testViewportClipping` | Toasts above row 0 are skipped |
| `ToastOverlayWidgetTest::testNarrowViewport` | Toast width clamps to MIN_TOAST_WIDTH |
| `ToastOverlayWidgetTest::testLongMessage` | Message wraps to multiple lines |
| `ToastOverlayWidgetTest::testTruncation` | Very long words are truncated with ellipsis |

### Snapshot Tests

| Snapshot | Description |
|----------|-------------|
| `toast-success` | Single success toast with green border and ✓ icon |
| `toast-error` | Single error toast with red border and ✕ icon |
| `toast-warning` | Single warning toast with yellow border and ⚠ icon |
| `toast-info` | Single info toast with blue border and ℹ icon |
| `toast-stack-3` | Three stacked toasts of different types |
| `toast-long-message` | Toast with multi-line wrapped message |
| `toast-narrow-40col` | Toast in a 40-column viewport |

---

## 10. File Structure

```
src/UI/Tui/Widget/Toast/
├── ToastType.php              ← Enum: success, warning, error, info
├── ToastPhase.php             ← Enum: entering, visible, exiting, done
├── ToastItem.php              ← Value object with reactive signals
├── ToastManager.php           ← Lifecycle controller (singleton)
└── ToastOverlayWidget.php     ← Renderer (floating overlay)

tests/Unit/UI/Tui/Widget/Toast/
├── ToastTypeTest.php
├── ToastPhaseTest.php
├── ToastItemTest.php
├── ToastManagerTest.php
└── ToastOverlayWidgetTest.php
```

---

## 11. Future Enhancements (Out of Scope for V1)

1. **Action buttons** — inline dismiss/undo buttons inside toasts (requires mouse support)
2. **Progress toasts** — toast with a progress bar for long-running operations
3. **Toast queue** — when stack is full, queue pending toasts and show when space opens
4. **Persistent log** — `ToastHistory` that records all toasts for review in a log panel
5. **Sound feedback** — optional BEL on error toasts
6. **Toast grouping** — merge repeated toasts ("3 files saved") instead of stacking
7. **Hover pause** — pause auto-dismiss timer when mouse cursor is over a toast
8. **Custom positioning** — allow top-left, top-right, bottom-left corners
9. **Rich content** — allow ANSI-formatted messages (currently plain text only)
10. **Opacity blending** — true RGB interpolation toward background color for smooth fade
