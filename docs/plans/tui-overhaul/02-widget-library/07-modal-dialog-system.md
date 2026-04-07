# 07 — Modal Dialog System

> **Module**: `src/UI/Tui/Widget\`
> **Dependencies**: Signal primitives (`01-reactive-state`), animation system (`08-animation`)
> **Replaces**: The ad-hoc overlay + suspension pattern in `TuiModalManager`
> **Blocks**: Refactoring `PermissionPromptWidget`, `PlanApprovalWidget`, `QuestionWidget`

## 1. Problem Statement

The current modal system in `TuiModalManager` works but has structural issues:

| Issue | Detail |
|-------|--------|
| **No modal abstraction** | Each modal (`askToolPermission`, `approvePlan`, `askChoice`) hand-rolls its own border rendering, button layout, focus management, and overlay lifecycle |
| **Single-modal limit** | `$activeModal` flag prevents stacking — you get a `LogicException` if a modal is already open |
| **No backdrop dimming** | The overlay container just appends widgets; there's no dimmed/darkened background to visually separate the modal from the content beneath |
| **No centering** | Widgets render at the top of the viewport and span full width — no centered dialog box |
| **Manual suspension management** | Each method creates its own `EventLoop::getSuspension()`, wires up callbacks, wraps in try/finally — massive duplication |
| **Mixed concerns** | `TuiModalManager` contains both generic modal mechanics AND domain-specific logic (permission preview building, settings submenus) |
| **No animation** | Modals appear and vanish instantly — no slide-in, fade, or transition |

The goal: a reusable **ModalDialog** system that any widget can use to present centered, bordered, backdrop-dimmed dialogs with configurable buttons, focus trapping, stack support, and animated entrance/exit.

## 2. Research: How Polished TUIs Handle Modals

### Lazygit (Go / gocui)
- **Confirmation dialogs**: Full-screen overlay with centered text and `[yes/no]` buttons at bottom
- **No backdrop dimming** — instead uses a distinct border color to separate the dialog
- **Fixed button layout**: `[enter] confirm / [esc] close` — always the same pattern
- **Simple stacking**: Error confirmations can appear on top of other panels
- **Key insight**: Minimalist — one border style, one layout, consistent across all dialogs

### Textual (Python — Textual Framework)
- `ModalScreen` is a full `Screen` subclass that overlays the existing screen
- Supports `result` return via `self.dismiss(value)` — the caller gets the value via `await`
- **Backdrop**: `ModalScreen` dims the background via CSS `background: $surface 60%`
- **Custom content**: Any widget tree can be the modal body
- **Stacking**: Multiple screens naturally stack; the topmost receives input
- **Key insight**: Modals are just screens — full composability, but heavy for simple confirm dialogs

### Ink/React (Node.js — React for CLI)
- `<Overlay>` component uses absolute positioning via `Yoga` flexbox
- Content renders at a specific (x, y) offset to center in viewport
- **No built-in dimming** — achieved by rendering a full-size `<Box>` with dim text behind the dialog
- **Focus trap**: Managed via `useFocus` hook — Tab/Shift+Tab cycles within the overlay's children
- **Key insight**: Modals are just positioned containers — the framework doesn't need special API

### Patterns Summary

| Feature | Lazygit | Textual | Ink/React |
|---------|---------|---------|-----------|
| Backdrop | None | Dim overlay | Manual dim box |
| Centering | Manual calc | Framework layout | Yoga flexbox |
| Content | Fixed text | Any widget | Any component |
| Buttons | Fixed pattern | Widget-based | Component-based |
| Stack | Simple | Screen stack | Z-index |
| Animation | None | CSS transitions | Manual |
| Focus trap | Implicit | Framework | useFocus hook |

## 3. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ Terminal (viewport)                                         │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │  ░░░░░░░░░░░░░░░░  Backdrop (dimmed)  ░░░░░░░░░░░░░░░░ │ │
│ │  ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ │ │
│ │  ░░░░░┌───────────────────────────────────────┐░░░░░░░ │ │
│ │  ░░░░░│ ┌─ Title Bar (icon + title) ─────────┐│░░░░░░░ │ │
│ │  ░░░░░│ │  Content Area (any widget)          ││░░░░░░░ │ │
│ │  ░░░░░│ │                                     ││░░░░░░░ │ │
│ │  ░░░░░│ ├─────────────────────────────────────┤│░░░░░░░ │ │
│ │  ░░░░░│ │ [Cancel]  [Confirm]  ← Button Row   ││░░░░░░░ │ │
│ │  ░░░░░│ └─────────────────────────────────────┘│░░░░░░░ │ │
│ │  ░░░░░└───────────────────────────────────────┘░░░░░░░ │ │
│ │  ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### Class Hierarchy

```
AbstractWidget
├── ModalOverlayWidget          (backdrop + centering + stack manager)
│   └── DialogWidget            (border + title + content + button row)
│       └── ButtonWidget        (individual button in the button row)
│
├── FocusableInterface
│   └── DialogWidget implements FocusableInterface
│       └── ButtonWidget implements FocusableInterface
```

### Interaction with Reactive State

```
ModalOverlayWidget
├── Signal<bool> $isOpen         — true when dialog is visible
├── Signal<float> $opacity       — 0.0 → 1.0 during entrance animation
├── Signal<int> $slideOffset     — vertical slide offset for entrance
├── Signal<int> $focusedButton   — which button has focus (for focus trap)
├── Computed<string> $backdropStyle — derived from $opacity
└── Effect → render when any signal changes
```

### Stack Model

```
ModalStack (static, lives on ModalOverlayWidget)
┌──────────────────────┐
│ Stack[0] = Dialog A  │  ← top of stack (receives input)
│ Stack[1] = Dialog B  │  ← dimmed, no input
│ Stack[2] = Dialog C  │  ← dimmed, no input
└──────────────────────┘
```

Only the topmost dialog receives keyboard input. Each stacked dialog gets progressively dimmer backdrops.

## 4. Class Designs

### 4.1 `ModalOverlayWidget`

Full-screen overlay widget that manages backdrop rendering and dialog centering. Holds a stack of `DialogWidget` instances.

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Reactive\Signal;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Full-viewport overlay that renders a dimmed backdrop and centers
 * one or more stacked DialogWidget instances.
 *
 * Usage:
 *   $overlay = new ModalOverlayWidget();
 *   $dialog = DialogWidget::create('Confirm', $contentWidget)
 *       ->addButton(ButtonWidget::confirm('Yes'))
 *       ->addButton(ButtonWidget::cancel('No'));
 *   $overlay->open($dialog);
 *   $result = $dialog->await(); // blocks via Suspension
 */
final class ModalOverlayWidget extends AbstractWidget
{
    /** @var list<DialogWidget> Stack of open dialogs, topmost last */
    private array $stack = [];

    /** @var Signal<bool> Whether any dialog is visible */
    private Signal $isOpen;

    /** @var Signal<float> Entrance animation progress 0.0–1.0 */
    private Signal $animProgress;

    public function __construct()
    {
        $this->isOpen = new Signal(false);
        $this->animProgress = new Signal(1.0); // 1.0 = fully shown
    }

    /**
     * Open a dialog and push it onto the stack.
     * Returns the dialog for method chaining.
     */
    public function open(DialogWidget $dialog): DialogWidget
    {
        $this->stack[] = $dialog;
        $this->isOpen->set(true);
        $this->animProgress->set(0.0);
        $this->invalidate();

        return $dialog;
    }

    /**
     * Close the topmost dialog and return it.
     */
    public function close(): ?DialogWidget
    {
        if ($this->stack === []) {
            return null;
        }

        $dialog = array_pop($this->stack);
        $this->isOpen->set($this->stack !== []);
        $this->invalidate();

        return $dialog;
    }

    /**
     * Close a specific dialog (by reference) from anywhere in the stack.
     */
    public function closeDialog(DialogWidget $dialog): void
    {
        $this->stack = array_values(array_filter(
            $this->stack,
            static fn(DialogWidget $d): bool => $d !== $dialog,
        ));
        $this->isOpen->set($this->stack !== []);
        $this->invalidate();
    }

    /**
     * Get the topmost (active) dialog, or null if stack is empty.
     */
    public function getActiveDialog(): ?DialogWidget
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /**
     * Check if any dialog is open.
     */
    public function hasOpenDialogs(): bool
    {
        return $this->stack !== [];
    }

    /**
     * Render the full-viewport backdrop with all stacked dialogs.
     *
     * For each dialog in the stack (bottom to top):
     * 1. Render a dimmed backdrop covering the viewport
     * 2. Calculate centered position for the dialog
     * 3. Render the dialog at that position
     *
     * The topmost dialog is rendered last (on top) and is fully opaque;
     * lower dialogs are progressively dimmed.
     */
    public function render(RenderContext $context): array
    {
        if ($this->stack === []) {
            return [];
        }

        $columns = $context->getColumns();
        $rows = $context->getRows();
        $lines = array_fill(0, $rows, '');

        $dim = "\033[38;2;60;60;65m";    // backdrop dim color
        $dimBg = "\033[48;2;20;20;25m";   // backdrop dim background
        $r = Theme::reset();

        // Render each dialog from bottom to top
        $stackDepth = count($this->stack);
        foreach ($this->stack as $index => $dialog) {
            $isTopmost = $index === $stackDepth - 1;

            // Render backdrop for this layer (dimmer for lower layers)
            $opacity = $isTopmost ? 0.85 : 0.4;
            $this->renderBackdrop($lines, $columns, $rows, $opacity);

            // Calculate dialog dimensions and centered position
            $dialogLines = $dialog->render($context);
            $dialogHeight = count($dialogLines);
            $dialogWidth = max(array_map(
                static fn(string $line): int => AnsiUtils::visibleWidth($line),
                $dialogLines,
            ));

            $startRow = (int) floor(($rows - $dialogHeight) / 2);
            $startCol = (int) floor(($columns - $dialogWidth) / 2);

            // Composite dialog onto the backdrop
            $this->composite($lines, $dialogLines, $startRow, $startCol, $columns, $rows);
        }

        return $lines;
    }

    // --- Private helpers ---

    /**
     * Render a semi-transparent backdrop over the entire viewport.
     *
     * Uses dark background color to create a dimming effect.
     * The $opacity parameter controls how dark (0.0 = transparent, 1.0 = opaque black).
     */
    private function renderBackdrop(array &$lines, int $columns, int $rows, float $opacity): void
    {
        $bg = $this->backdropColor($opacity);
        $r = Theme::reset();

        for ($row = 0; $row < $rows; $row++) {
            $lines[$row] = $bg . str_repeat(' ', $columns) . $r;
        }
    }

    /**
     * Calculate the ANSI background color for a given backdrop opacity.
     */
    private function backdropColor(float $opacity): string
    {
        $v = (int) round(12 * (1 - $opacity)); // 0→12 (darkest), 1→0 (transparent)
        return "\033[48;2;{$v};{$v};" . ($v + 3) . 'm';
    }

    /**
     * Composite source lines onto the target buffer at (row, col) offset.
     */
    private function composite(
        array &$target,
        array $source,
        int $startRow,
        int $startCol,
        int $columns,
        int $rows,
    ): void {
        foreach ($source as $offset => $line) {
            $targetRow = $startRow + $offset;
            if ($targetRow < 0 || $targetRow >= $rows) {
                continue;
            }

            // Place the dialog line at the horizontal offset
            // by moving the cursor to (targetRow, startCol)
            $target[$targetRow] = "\033[{$targetRow};" . ($startCol + 1) . "H" . $line;
        }
    }
}
```

### 4.2 `DialogWidget`

The core dialog box: title bar, content area, button row, border styles, focus trapping.

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Reactive\Signal;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Centered dialog box with title bar, content area, and button row.
 *
 * Supports:
 * - Configurable border styles (rounded, double, thick, custom)
 * - Title bar with icon and label
 * - Arbitrary content widget in the body
 * - Configurable button row with focus cycling
 * - Focus trap: Tab/Shift+Tab cycles between buttons; Escape dismisses
 * - Stack support: multiple dialogs can be open simultaneously
 * - Animated entrance via opacity/slide signals
 *
 * Usage:
 *   $dialog = DialogWidget::create('⚠ Confirm Delete', $content)
 *       ->setWidth(60)
 *       ->setBorderStyle(BorderStyle::Rounded)
 *       ->addButton(new ButtonWidget('Cancel', 'cancel'))
 *       ->addButton(new ButtonWidget('Delete', 'confirm', ButtonVariant::Danger));
 *
 *   $overlay->open($dialog);
 *   $result = $dialog->await(); // returns the clicked button's value
 */
final class DialogWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    // --- Border style enum ---

    /** Border character sets for different styles */
    public const BORDER_ROUNDED = 'rounded';
    public const BORDER_DOUBLE  = 'double';
    public const BORDER_THICK   = 'thick';
    public const BORDER_CUSTOM  = 'custom';

    private const BORDER_CHARS = [
        self::BORDER_ROUNDED => ['╭', '╮', '╰', '╯', '─', '│'],
        self::BORDER_DOUBLE  => ['╔', '╗', '╚', '╝', '═', '║'],
        self::BORDER_THICK   => ['┏', '┓', '┗', '┛', '━', '┃'],
    ];

    // --- Configuration ---

    /** Dialog title (rendered in the title bar with optional icon) */
    private string $title;

    /** Optional icon prefix for the title bar */
    private string $icon;

    /** Maximum dialog width in columns (0 = auto-size to content) */
    private int $maxWidth;

    /** Minimum dialog width in columns */
    private int $minWidth;

    /** Border style */
    private string $borderStyle;

    /** Custom border chars: [topLeft, topRight, bottomLeft, bottomRight, horizontal, vertical] */
    private array $customBorderChars;

    /** Border ANSI color */
    private string $borderColor;

    /** Title ANSI color */
    private string $titleColor;

    /** Whether Escape dismisses the dialog */
    private bool $escapeDismisses;

    // --- Content ---

    /** Content lines (rendered body). Can also be a widget reference for future integration. */
    private array $contentLines = [];

    // --- Buttons ---

    /** @var list<ButtonWidget> */
    private array $buttons = [];

    /** Index of the currently focused button */
    private int $focusedButtonIndex = 0;

    // --- State ---

    /** @var Signal<bool> Whether this dialog is currently open/visible */
    private Signal $visible;

    /** @var Signal<int> Vertical slide offset for entrance animation */
    private Signal $slideOffset;

    /** @var Suspension|null Blocking suspension for await() */
    private ?Suspension $suspension = null;

    /** @var callable|null Callback invoked when dialog is dismissed without a button */
    private $onDismissCallback = null;

    // --- Factory methods ---

    /**
     * Create a dialog with a title and content lines.
     *
     * @param  string  $title  Dialog title (may include icon)
     * @param  list<string>  $contentLines  Body content as ANSI-formatted lines
     */
    public static function create(string $title, array $contentLines = []): self
    {
        return new self($title, $contentLines);
    }

    /**
     * Create a simple confirmation dialog with OK/Cancel buttons.
     *
     * @param  string  $message  Message to display
     * @param  string  $title  Dialog title
     * @return self
     */
    public static function confirm(string $message, string $title = 'Confirm'): self
    {
        return self::create($title, [$message])
            ->addButton(new ButtonWidget('Cancel', 'cancel'))
            ->addButton(new ButtonWidget('OK', 'confirm', ButtonWidget::VARIANT_PRIMARY));
    }

    /**
     * Create a simple alert dialog with a single OK button.
     */
    public static function alert(string $message, string $title = 'Alert'): self
    {
        return self::create($title, [$message])
            ->addButton(new ButtonWidget('OK', 'ok', ButtonWidget::VARIANT_PRIMARY));
    }

    // --- Constructor ---

    /**
     * @param  string  $title  Dialog title
     * @param  list<string>  $contentLines  Body content lines
     */
    public function __construct(string $title, array $contentLines = [])
    {
        $this->title = $title;
        $this->icon = '';
        $this->contentLines = $contentLines;
        $this->maxWidth = 0;    // auto
        $this->minWidth = 30;
        $this->borderStyle = self::BORDER_ROUNDED;
        $this->customBorderChars = [];
        $this->borderColor = Theme::borderAccent();
        $this->titleColor = Theme::accent();
        $this->escapeDismisses = true;
        $this->visible = new Signal(false);
        $this->slideOffset = new Signal(2); // start 2 rows below final position
    }

    // --- Fluent configuration ---

    public function setIcon(string $icon): self { $this->icon = $icon; return $this; }
    public function setWidth(int $width): self { $this->maxWidth = $width; return $this; }
    public function setMinWidth(int $width): self { $this->minWidth = $width; return $this; }
    public function setBorderStyle(string $style): self { $this->borderStyle = $style; return $this; }
    public function setBorderColor(string $color): self { $this->borderColor = $color; return $this; }
    public function setTitleColor(string $color): self { $this->titleColor = $color; return $this; }

    public function setCustomBorder(string $tl, string $tr, string $bl, string $br, string $h, string $v): self
    {
        $this->borderStyle = self::BORDER_CUSTOM;
        $this->customBorderChars = [$tl, $tr, $bl, $br, $h, $v];
        return $this;
    }

    public function setContent(array $lines): self { $this->contentLines = $lines; return $this; }

    public function setEscapeDismisses(bool $dismisses): self { $this->escapeDismisses = $dismisses; return $this; }

    public function addButton(ButtonWidget $button): self
    {
        $this->buttons[] = $button;
        return $this;
    }

    public function onDismiss(callable $callback): self
    {
        $this->onDismissCallback = $callback;
        return $this;
    }

    // --- Public API ---

    /**
     * Block until the user selects a button or dismisses the dialog.
     *
     * Returns the value of the clicked button, or null if dismissed.
     * Uses Revolt Suspension for async-safe blocking.
     */
    public function await(): ?string
    {
        $this->visible->set(true);
        $this->suspension = EventLoop::getSuspension();

        try {
            return $this->suspension->suspend();
        } finally {
            $this->visible->set(false);
            $this->suspension = null;
        }
    }

    /**
     * Programmatically close the dialog with a result value.
     */
    public function close(string $result): void
    {
        if ($this->suspension !== null) {
            $this->suspension->resume($result);
        }
    }

    /**
     * Programmatically dismiss the dialog (equivalent to Escape).
     */
    public function dismiss(): void
    {
        if ($this->onDismissCallback !== null) {
            ($this->onDismissCallback)();
        }
        $this->close(null);
    }

    // --- Focus / Input ---

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        // Tab: cycle to next button
        if ($kb->matches($data, 'next')) {
            $this->focusedButtonIndex = ($this->focusedButtonIndex + 1) % max(1, count($this->buttons));
            $this->invalidate();
            return;
        }

        // Shift+Tab: cycle to previous button
        if ($kb->matches($data, 'prev')) {
            $this->focusedButtonIndex = ($this->focusedButtonIndex - 1 + count($this->buttons)) % max(1, count($this->buttons));
            $this->invalidate();
            return;
        }

        // Enter: activate focused button
        if ($kb->matches($data, 'confirm')) {
            if ($this->buttons !== []) {
                $button = $this->buttons[$this->focusedButtonIndex];
                $this->close($button->getValue());
            }
            return;
        }

        // Escape: dismiss
        if ($kb->matches($data, 'cancel') && $this->escapeDismisses) {
            $this->dismiss();
        }
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'next'   => [Key::TAB],
            'prev'   => ["\033[Z"], // Shift+Tab
            'confirm' => [Key::ENTER],
            'cancel'  => [Key::ESCAPE, 'ctrl+c'],
        ];
    }

    // --- Rendering ---

    /**
     * Render the dialog: border, title bar, content, separator, button row.
     *
     * The returned lines represent the dialog only (no backdrop).
     * The parent ModalOverlayWidget handles positioning and compositing.
     *
     * @return list<string> ANSI-formatted lines
     */
    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $border = $this->borderColor;
        $accent = $this->titleColor;
        $chars = $this->getBorderChars();
        // [0]=tl, [1]=tr, [2]=bl, [3]=br, [4]=h, [5]=v

        // Calculate dialog width
        $viewportWidth = $context->getColumns();
        $contentWidth = $this->calculateContentWidth();
        $dialogInnerWidth = $this->maxWidth > 0
            ? min($this->maxWidth - 4, $viewportWidth - 4)
            : min(max($contentWidth, $this->minWidth), $viewportWidth - 4);
        $dialogInnerWidth = max(20, $dialogInnerWidth);

        $lines = [];

        // Title bar
        $titleText = ($this->icon !== '' ? "{$this->icon} " : '') . $this->title;
        $titleVisible = mb_strwidth($titleText);
        $titlePadLeft = 1;
        $titlePadRight = max(0, $dialogInnerWidth - $titleVisible - $titlePadLeft);
        $lines[] = $border . $chars[0] . $chars[4]
            . $accent . $titleText . $r
            . $border . str_repeat($chars[4], $titlePadRight) . $chars[1] . $r;

        // Content area
        foreach ($this->contentLines as $contentLine) {
            // Word-wrap long lines
            foreach ($this->wrapLine($contentLine, $dialogInnerWidth - 2) as $wrapped) {
                $lines[] = $this->boxLine(
                    $wrapped,
                    $dialogInnerWidth,
                    $chars[5],
                    $border,
                    $r,
                );
            }
        }

        // Button separator
        if ($this->buttons !== []) {
            $lines[] = $border . str_repeat($chars[4], $dialogInnerWidth + 2) . $r;

            // Button row
            $buttonRow = $this->renderButtonRow($dialogInnerWidth);
            $lines[] = $this->boxLine($buttonRow, $dialogInnerWidth, $chars[5], $border, $r);
        }

        // Bottom border
        $lines[] = $border . $chars[2] . str_repeat($chars[4], $dialogInnerWidth + 1) . $chars[3] . $r;

        return $lines;
    }

    // --- Private helpers ---

    /**
     * Get the border character set for the current style.
     *
     * @return list<string> [topLeft, topRight, bottomLeft, bottomRight, horizontal, vertical]
     */
    private function getBorderChars(): array
    {
        if ($this->borderStyle === self::BORDER_CUSTOM) {
            return $this->customBorderChars;
        }

        return self::BORDER_CHARS[$this->borderStyle] ?? self::BORDER_CHARS[self::BORDER_ROUNDED];
    }

    /**
     * Calculate the maximum visible width of the content lines.
     */
    private function calculateContentWidth(): int
    {
        $maxWidth = 0;
        foreach ($this->contentLines as $line) {
            $maxWidth = max($maxWidth, AnsiUtils::visibleWidth($line));
        }
        return $maxWidth;
    }

    /**
     * Render the button row as a single ANSI-formatted string.
     */
    private function renderButtonRow(int $innerWidth): string
    {
        if ($this->buttons === []) {
            return '';
        }

        $r = Theme::reset();
        $parts = [];
        $totalVisibleWidth = 0;

        foreach ($this->buttons as $index => $button) {
            $isFocused = $index === $this->focusedButtonIndex;
            $parts[] = $button->renderInline($isFocused);
            $totalVisibleWidth += $button->getVisibleWidth();

            // Add spacing between buttons
            if ($index < count($this->buttons) - 1) {
                $parts[] = '  '; // 2-space gap
                $totalVisibleWidth += 2;
            }
        }

        // Right-align the button row (common pattern for modal dialogs)
        $padding = max(0, $innerWidth - 2 - $totalVisibleWidth);
        return str_repeat(' ', $padding) . implode('', $parts);
    }

    /**
     * Render a single boxed line with left/right borders.
     */
    private function boxLine(string $content, int $innerWidth, string $vChar, string $borderColor, string $reset): string
    {
        $visible = AnsiUtils::visibleWidth($content);
        $padding = max(0, $innerWidth - $visible - 2);

        return $borderColor . $vChar . $reset . ' ' . $content . $reset
            . str_repeat(' ', $padding) . ' ' . $borderColor . $vChar . $reset;
    }

    /**
     * Word-wrap a line to fit within the given visible width.
     *
     * @return list<string>
     */
    private function wrapLine(string $line, int $width): array
    {
        $visible = AnsiUtils::visibleWidth($line);
        if ($visible <= $width) {
            return [$line];
        }

        // For ANSI-colored lines, we wrap at the visible width boundary.
        // This is a simplified version; a full implementation would need
        // to properly handle ANSI escape sequences during wrapping.
        return AnsiUtils::wrapToWidth($line, $width);
    }
}
```

### 4.3 `ButtonWidget`

Individual button in a dialog's button row. Supports variants (primary, danger, default) and renders with focus highlighting.

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A single button within a DialogWidget's button row.
 *
 * Renders as `[ Label ]` with focus highlighting:
 * - Unfocused: `[ Label ]` in dim colors
 * - Focused:   `[▸ Label ]` with highlighted border and text
 *
 * Supports semantic variants:
 * - DEFAULT: standard button
 * - PRIMARY: highlighted (confirm action)
 * - DANGER:  red-highlighted (destructive action)
 */
final class ButtonWidget
{
    public const VARIANT_DEFAULT = 'default';
    public const VARIANT_PRIMARY = 'primary';
    public const VARIANT_DANGER  = 'danger';

    /** Button label text */
    private string $label;

    /** Value returned when this button is clicked */
    private string $value;

    /** Visual variant */
    private string $variant;

    /**
     * @param  string  $label  Display text
     * @param  string  $value  Return value when activated
     * @param  string  $variant  One of VARIANT_* constants
     */
    public function __construct(
        string $label,
        string $value,
        string $variant = self::VARIANT_DEFAULT,
    ) {
        $this->label = $label;
        $this->value = $value;
        $this->variant = $variant;
    }

    // --- Convenience factories ---

    public static function confirm(string $label = 'Confirm'): self
    {
        return new self($label, 'confirm', self::VARIANT_PRIMARY);
    }

    public static function cancel(string $label = 'Cancel'): self
    {
        return new self($label, 'cancel', self::VARIANT_DEFAULT);
    }

    public static function danger(string $label, string $value = 'danger'): self
    {
        return new self($label, $value, self::VARIANT_DANGER);
    }

    // --- Accessors ---

    public function getValue(): string { return $this->value; }
    public function getLabel(): string { return $this->label; }
    public function getVariant(): string { return $this->variant; }

    /**
     * Get the visible width of this button when rendered (including brackets and spacing).
     */
    public function getVisibleWidth(): int
    {
        return mb_strwidth($this->label) + 4; // '[ ' + label + ' ]'
    }

    // --- Rendering ---

    /**
     * Render this button as an inline ANSI string (for embedding in a button row).
     *
     * @param  bool  $focused  Whether this button has focus
     * @return string ANSI-formatted button string
     */
    public function renderInline(bool $focused): string
    {
        $r = Theme::reset();

        if ($focused) {
            return match ($this->variant) {
                self::VARIANT_PRIMARY => $this->renderFocused(Theme::accent(), '▸'),
                self::VARIANT_DANGER  => $this->renderFocused(Theme::error(), '▸'),
                default               => $this->renderFocused(Theme::white(), '▸'),
            };
        }

        return match ($this->variant) {
            self::VARIANT_PRIMARY => "\033[38;2;180;140;50m[ {$this->label} ]{$r}",
            self::VARIANT_DANGER  => "\033[38;2;160;60;50m[ {$this->label} ]{$r}",
            default               => Theme::dim() . "[ {$this->label} ]{$r}",
        };
    }

    /**
     * Render a focused button with the given highlight color.
     */
    private function renderFocused(string $color, string $cursor): string
    {
        $r = Theme::reset();
        $white = Theme::white();
        return "{$color}[{$r} {$cursor}{$white} {$this->label} {$color}]{$r}";
    }
}
```

## 5. Integration with `TuiModalManager`

The refactored `TuiModalManager` becomes a thin facade over `ModalOverlayWidget` + `DialogWidget`:

```php
// BEFORE (current — 60+ lines per modal)
public function askToolPermission(string $toolName, array $args): string
{
    if ($this->activeModal) { throw new \LogicException('A modal is already active'); }
    $this->activeModal = true;
    $preview = (new PermissionPreviewBuilder)->build($toolName, $args);
    $widget = new PermissionPromptWidget($toolName, $preview);
    $widget->setId('permission-prompt');
    $this->overlay->add($widget);
    $this->tui->setFocus($widget);
    $this->flushRender();
    $suspension = EventLoop::getSuspension();
    $widget->onConfirm(function (string $decision) use ($suspension) { $suspension->resume($decision); });
    $widget->onDismiss(function () use ($suspension) { $suspension->resume('deny'); });
    try { $decision = $suspension->suspend(); }
    finally { $this->activeModal = false; }
    $this->overlay->remove($widget);
    $this->tui->setFocus($this->input);
    $this->forceRender();
    return $decision;
}

// AFTER (refactored — 10 lines)
public function askToolPermission(string $toolName, array $args): string
{
    $preview = (new PermissionPreviewBuilder)->build($toolName, $args);
    $content = $this->formatPermissionContent($preview);

    $dialog = DialogWidget::create("{$preview['title']}", $content)
        ->setIcon(Theme::toolIcon($toolName))
        ->setBorderColor(Theme::borderAccent())
        ->addButton(new ButtonWidget('Allow once', 'allow'))
        ->addButton(new ButtonWidget('Always allow', 'always', ButtonWidget::VARIANT_PRIMARY))
        ->addButton(new ButtonWidget('Deny', 'deny', ButtonWidget::VARIANT_DANGER));

    $this->modalOverlay->open($dialog);
    $this->tui->setFocus($dialog);
    $this->flushRender();

    return $dialog->await() ?? 'deny';
}
```

## 6. Feature Deep-Dives

### 6.1 Centering in Viewport

The `ModalOverlayWidget::render()` method:

1. Gets viewport dimensions from `RenderContext::getColumns()` / `getRows()`
2. Renders the dialog first into a temporary buffer to measure its actual height/width
3. Calculates `(row, col)` offset: `floor((viewport - dialog) / 2)`
4. Composites the dialog buffer onto the backdrop at the computed offset using ANSI cursor positioning (`\033[row;colH`)

This approach (measure-then-place) avoids the need for a layout engine and works with any dialog size.

### 6.2 Backdrop Dimming

The backdrop is rendered as a full-viewport layer with a dark background color:

```
Opacity 0.0 → no backdrop (transparent)
Opacity 0.5 → \033[48;2;6;6;9m    (very dark blue-gray)
Opacity 0.85 → \033[48;2;2;2;3m   (near-black)
Opacity 1.0 → \033[48;2;0;0;0m    (pure black)
```

The backdrop color formula: `component = round(12 * (1 - opacity))`.

For stacked modals, each layer gets its own backdrop with decreasing opacity. The topmost dialog has the strongest backdrop; lower dialogs are progressively dimmed.

### 6.3 Border Styles

```php
// Rounded (default) — for standard dialogs
$dialog->setBorderStyle(DialogWidget::BORDER_ROUNDED);
// ╭─ Title ──────────────╮
// │ Content               │
// ╰──────────────────────╯

// Double — for important warnings
$dialog->setBorderStyle(DialogWidget::BORDER_DOUBLE);
// ╔═ Title ══════════════╗
// ║ Content               ║
// ╚══════════════════════╝

// Thick — for errors
$dialog->setBorderStyle(DialogWidget::BORDER_THICK);
// ┏━ Title ━━━━━━━━━━━━━━┓
// ┃ Content               ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━┛

// Custom — for themed dialogs
$dialog->setCustomBorder('┌', '┐', '└', '┘', '─', '│');
```

Border characters are stored as a 6-element array: `[topLeft, topRight, bottomLeft, bottomRight, horizontal, vertical]`.

### 6.4 Title Bar with Icon

The title bar is rendered as the first line of the dialog border:

```
╭─ ⚡ Bash: rm -rf /tmp/test ───────────────────╮
│                                                │
```

The icon is set via `->setIcon('⚡')` and prepended to the title with a space. The icon should be a single-width Unicode character (not a wide emoji) to maintain alignment.

Convention: use `Theme::toolIcon()` for tool-related dialogs, and thematic icons for generic dialogs:
- `⚠` for warnings
- `✓` for success confirmations
- `✕` for errors
- `?` for questions
- `⚙` for settings

### 6.5 Button Row

Buttons are rendered right-aligned in the button row (following standard dialog UX):

```
│                            [ Cancel ]  [ Confirm ] │
```

Layout rules:
- Buttons are separated by 2 spaces
- The button row is right-aligned within the dialog inner width
- The focused button gets a `▸` cursor prefix and highlighted bracket color
- The last button added is the default (focused first)

### 6.6 Focus Trap

When a dialog is open:
- **Tab** cycles focus to the next button (wrapping)
- **Shift+Tab** cycles to the previous button (wrapping)
- **Enter** activates the focused button
- **Escape** dismisses the dialog (if `escapeDismisses` is true)
- **No input escapes** the dialog — no arrow keys reach the content beneath

Implementation:
```php
// In DialogWidget::handleInput()
'next'    => [Key::TAB],
'prev'    => ["\033[Z"],  // Shift+Tab
'confirm' => [Key::ENTER],
'cancel'  => [Key::ESCAPE, 'ctrl+c'],
```

The `FocusableInterface` implementation on `DialogWidget` ensures the TUI's focus manager routes all input to the dialog when it's focused.

### 6.7 Escape / Dismiss

`Escape` and `Ctrl+C` both dismiss the dialog. The dialog returns `null` from `await()`.

Some dialogs may want to prevent dismissal (e.g., "You have unsaved changes"):
```php
$dialog->setEscapeDismisses(false);
// Now the user MUST click a button; Escape is ignored
```

The `onDismiss` callback fires before the suspension is resumed, allowing cleanup:
```php
$dialog->onDismiss(function () {
    // Log dismissal, reset state, etc.
});
```

### 6.8 Stack Support (Modal on Modal)

The `ModalOverlayWidget` maintains a stack of `DialogWidget` instances:

```php
// Open a confirmation dialog on top of a settings dialog
$settingsDialog = DialogWidget::create('Settings', $settingsContent);
$modalOverlay->open($settingsDialog);

// User clicks "Reset" → open a confirmation on top
$confirmDialog = DialogWidget::confirm('Reset all settings?', 'Are you sure?');
$modalOverlay->open($confirmDialog);

// Only the topmost (confirmDialog) receives input
// When it closes, settingsDialog becomes active again
```

Rendering:
1. Each stacked dialog gets its own backdrop (with decreasing opacity for lower layers)
2. The topmost dialog is rendered last (on top)
3. Only the topmost dialog's `handleInput()` is called

### 6.9 Animated Entrance

Entrance animation is signal-driven:

```php
// Signals on DialogWidget
private Signal $slideOffset;  // vertical offset: starts at 2, animates to 0
private Signal $opacity;      // 0.0 → 1.0 fade-in

// Animation timeline (driven by the animation system from 08-animation)
// Frame 0:    slideOffset=2, opacity=0.0   (invisible, 2 rows below center)
// Frame 1:    slideOffset=1, opacity=0.5   (sliding up, fading in)
// Frame 2:    slideOffset=0, opacity=1.0   (final position, fully visible)
```

Animation configuration:
```php
$dialog->setEntranceAnimation(
    new SlideInAnimation(direction: 'up', distance: 2, duration: 150), // ms
);
```

If the animation system (`08-animation`) is not yet available, the dialog renders at full opacity immediately (the `Signal` defaults to `slideOffset=0, opacity=1.0`).

### 6.10 Signal-Based State Integration

All mutable state on `DialogWidget` and `ModalOverlayWidget` uses `Signal`:

| Signal | Type | Purpose |
|--------|------|---------|
| `DialogWidget::$visible` | `bool` | Whether dialog is shown |
| `DialogWidget::$slideOffset` | `int` | Entrance animation offset |
| `ModalOverlayWidget::$isOpen` | `bool` | Whether any dialog is open |
| `ModalOverlayWidget::$animProgress` | `float` | Global animation progress |

Effects automatically trigger re-renders:
```php
// From 03-effect-runner: when $visible changes, a render is scheduled
// No manual flushRender() needed
Effect::create(function () use ($dialog) {
    if ($dialog->visible->get()) {
        // Re-render the overlay
    }
});
```

## 7. Migration Plan

### Phase 1: Core Infrastructure (non-breaking)

1. **Create `ButtonWidget`** — pure data class, no dependencies
2. **Create `DialogWidget`** — standalone widget, used alongside existing widgets
3. **Create `ModalOverlayWidget`** — backdrop + centering
4. **Write tests** for each new class (render snapshots, focus cycling, stack behavior)

### Phase 2: Wire into TuiModalManager (backwards-compatible)

5. **Add `ModalOverlayWidget`** as a persistent child of the existing overlay `ContainerWidget`
6. **Add `showDialog()` helper** to `TuiModalManager` — thin wrapper around the new system
7. **Migrate `askChoice()`** — simplest modal, good proof of concept
8. **Test** that existing modals still work

### Phase 3: Migrate Existing Modals

9. **Migrate `askToolPermission()`** — rebuild `PermissionPromptWidget` content using `DialogWidget`
10. **Migrate `approvePlan()`** — rebuild `PlanApprovalWidget` with custom content + toggle buttons
11. **Migrate `askUser()`** — simplest migration (just a question + input)
12. **Migrate `pickSession()`** — SelectListWidget embedded in dialog
13. **Remove old `$activeModal` flag** — replaced by stack depth check

### Phase 4: Polish

14. **Add animation** — wire `SlideInAnimation` to the entrance signals
15. **Add backdrop click** — if mouse support (`05-mouse-support`) is available, click on backdrop to dismiss
16. **Extract domain helpers** — `PermissionDialog`, `PlanApprovalDialog`, `QuestionDialog` as convenience factories

## 8. Test Plan

### Unit Tests

| Test | What it verifies |
|------|-----------------|
| `ButtonWidgetTest` | Label rendering, focus state, variant colors, visible width calculation |
| `DialogWidgetTest` | Border rendering for all styles, title bar, content wrapping, button row layout |
| `DialogWidgetFocusTest` | Tab cycling wraps, Shift+Tab wraps backwards, Enter activates button, Escape dismisses |
| `ModalOverlayWidgetTest` | Stack push/pop, backdrop rendering, dialog centering, compositing |
| `DialogStackTest` | Multiple dialogs open, only topmost receives input, close restores previous |

### Snapshot Tests

| Snapshot | Description |
|----------|-------------|
| `dialog-rounded` | Rounded border with title, content, and 2 buttons |
| `dialog-double` | Double border variant |
| `dialog-danger` | Dialog with a danger button focused |
| `dialog-stacked` | Two dialogs visible, topmost active |
| `dialog-backdrop` | Full viewport with dimmed backdrop and centered dialog |

### Integration Tests

| Test | What it verifies |
|------|-----------------|
| `ModalManagerShowDialogTest` | `showDialog()` opens, awaits, and returns result |
| `ModalManagerStackTest` | Opening a second modal while first is open works correctly |
| `PermissionDialogIntegration` | Full permission prompt flow using new DialogWidget |
| `EscapeDismissTest` | Escape key properly closes dialog and resumes suspension |

## 9. File Map

```
src/UI/Tui/Widget/
├── ModalOverlayWidget.php     NEW — backdrop + centering + stack
├── DialogWidget.php           NEW — bordered dialog with title/content/buttons
├── ButtonWidget.php           NEW — individual button with variants
└── PermissionPromptWidget.php REFACTORED → content provider for DialogWidget

src/UI/Tui/
└── TuiModalManager.php        REFACTORED — uses new dialog system

tests/UI/Tui/Widget/
├── ButtonWidgetTest.php       NEW
├── DialogWidgetTest.php       NEW
├── DialogWidgetFocusTest.php  NEW
├── ModalOverlayWidgetTest.php NEW
└── DialogStackTest.php        NEW
```

## 10. API Quick Reference

```php
// Simple confirm
$result = Modal::confirm('Delete this file?')->await();
// $result === 'confirm' or null

// Custom dialog
$dialog = DialogWidget::create('⚠ Warning', ['This action cannot be undone.'])
    ->setIcon('⚠')
    ->setWidth(50)
    ->setBorderStyle(DialogWidget::BORDER_DOUBLE)
    ->setBorderColor(Theme::error())
    ->addButton(ButtonWidget::cancel())
    ->addButton(ButtonWidget::danger('Delete', 'delete'));

$modalOverlay->open($dialog);
$result = $dialog->await();

// Permission prompt (refactored)
$dialog = PermissionDialog::forTool('bash', ['command' => 'rm -rf /'])
    ->withPreview($preview);
$modalOverlay->open($dialog);
$decision = $dialog->await();
```

## 11. Open Questions

| # | Question | Resolution |
|---|----------|-----------|
| 1 | Should the backdrop render as a true ANSI background or as dimmed foreground text overlaid on existing content? | **Recommendation**: Background color (`\033[48;...m`) is simpler and more reliable than trying to re-render dimmed content beneath |
| 2 | How does the dialog handle content that exceeds the viewport height? | **Recommendation**: Cap dialog height at `viewport_rows - 4` and add a scroll indicator (`↑↓`) for overflow content |
| 3 | Should `ButtonWidget` be a full `AbstractWidget` or a plain value object? | **Recommendation**: Plain value object for now — it's only ever rendered inline within `DialogWidget`. If buttons need independent mouse click handling in the future, promote to widget |
| 4 | Can we reuse the existing `ContainerWidget` for the overlay, or does `ModalOverlayWidget` need its own compositing logic? | **Recommendation**: Own compositing — `ContainerWidget` does vertical/horizontal layout; modals need absolute positioning |
| 5 | Animation timing — should we use `EventLoop::repeat()` with a frame counter or the planned spring physics system? | **Recommendation**: Start with `EventLoop::repeat()` at 60fps with linear interpolation; migrate to spring physics when `08-animation` lands |
