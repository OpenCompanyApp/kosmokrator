<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Modal;

use Kosmokrator\UI\Theme;
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
 * - Arbitrary content lines in the body
 * - Configurable button row with focus cycling
 * - Focus trap: Tab/Shift+Tab cycles between buttons; Escape dismisses
 * - Stack support: multiple dialogs can be open simultaneously
 * - Blocking await via Revolt Suspension
 *
 * Usage:
 *   $dialog = DialogWidget::create('Confirm Delete', ['Delete this file?'])
 *       ->addButton(ButtonWidget::cancel())
 *       ->addButton(ButtonWidget::danger('Delete'));
 *
 *   $overlay->open($dialog);
 *   $result = $dialog->await(); // blocks until user selects a button or dismisses
 */
final class DialogWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    // --- Border style constants ---

    public const BORDER_ROUNDED = 'rounded';
    public const BORDER_DOUBLE = 'double';
    public const BORDER_THICK = 'thick';
    public const BORDER_CUSTOM = 'custom';

    /** @var array<string, list<string>> Border character sets: [tl, tr, bl, br, h, v] */
    private const BORDER_CHARS = [
        self::BORDER_ROUNDED => ['╭', '╮', '╰', '╯', '─', '│'],
        self::BORDER_DOUBLE => ['╔', '╗', '╚', '╝', '═', '║'],
        self::BORDER_THICK => ['┏', '┓', '┗', '┛', '━', '┃'],
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

    /** Custom border chars: [tl, tr, bl, br, h, v] */
    private array $customBorderChars;

    /** Border ANSI color */
    private string $borderColor;

    /** Title ANSI color */
    private string $titleColor;

    /** Whether Escape dismisses the dialog */
    private bool $escapeDismisses;

    // --- Content ---

    /** @var list<string> Content lines (rendered body) */
    private array $contentLines;

    // --- Buttons ---

    /** @var list<ButtonWidget> */
    private array $buttons = [];

    /** Index of the currently focused button */
    private int $focusedButtonIndex = 0;

    // --- State ---

    /** @var Suspension|null Blocking suspension for await() */
    private ?Suspension $suspension = null;

    /** @var callable|null Callback invoked when dialog is dismissed without a button */
    private $onDismissCallback = null;

    // --- Factory methods ---

    /**
     * Create a dialog with a title and content lines.
     *
     * @param string $title        Dialog title (may include icon)
     * @param list<string> $contentLines Body content as ANSI-formatted lines
     */
    public static function create(string $title, array $contentLines = []): self
    {
        return new self($title, $contentLines);
    }

    /**
     * Create a simple confirmation dialog with Cancel/Confirm buttons.
     *
     * @param string $message Message to display
     * @param string $title   Dialog title
     */
    public static function confirm(string $message, string $title = 'Confirm'): self
    {
        return self::create($title, [$message])
            ->addButton(new ButtonWidget('Cancel', DialogResult::Cancelled->value))
            ->addButton(new ButtonWidget('Confirm', DialogResult::Confirmed->value, ButtonWidget::VARIANT_PRIMARY));
    }

    /**
     * Create a simple alert dialog with a single OK button.
     */
    public static function alert(string $message, string $title = 'Alert'): self
    {
        return self::create($title, [$message])
            ->addButton(new ButtonWidget('OK', DialogResult::Acknowledged->value, ButtonWidget::VARIANT_PRIMARY));
    }

    /**
     * Create a danger confirmation dialog (destructive action).
     *
     * @param string $message Message to display
     * @param string $title   Dialog title
     * @param string $dangerLabel Label for the danger button
     */
    public static function dangerConfirm(
        string $message,
        string $title = 'Warning',
        string $dangerLabel = 'Delete',
    ): self {
        return self::create($title, [$message])
            ->addButton(ButtonWidget::cancel())
            ->addButton(ButtonWidget::danger($dangerLabel, DialogResult::Danger->value));
    }

    // --- Constructor ---

    /**
     * @param string $title        Dialog title
     * @param list<string> $contentLines Body content lines
     */
    public function __construct(string $title, array $contentLines = [])
    {
        $this->title = $title;
        $this->icon = '';
        $this->contentLines = $contentLines;
        $this->maxWidth = 0; // auto
        $this->minWidth = 30;
        $this->borderStyle = self::BORDER_ROUNDED;
        $this->customBorderChars = [];
        $this->borderColor = Theme::borderAccent();
        $this->titleColor = Theme::accent();
        $this->escapeDismisses = true;
    }

    // --- Fluent configuration ---

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function setWidth(int $width): self
    {
        $this->maxWidth = $width;

        return $this;
    }

    public function setMinWidth(int $width): self
    {
        $this->minWidth = $width;

        return $this;
    }

    public function setBorderStyle(string $style): self
    {
        $this->borderStyle = $style;

        return $this;
    }

    public function setBorderColor(string $color): self
    {
        $this->borderColor = $color;

        return $this;
    }

    public function setTitleColor(string $color): self
    {
        $this->titleColor = $color;

        return $this;
    }

    /**
     * Set custom border characters.
     *
     * @param string $tl Top-left
     * @param string $tr Top-right
     * @param string $bl Bottom-left
     * @param string $br Bottom-right
     * @param string $h  Horizontal
     * @param string $v  Vertical
     */
    public function setCustomBorder(string $tl, string $tr, string $bl, string $br, string $h, string $v): self
    {
        $this->borderStyle = self::BORDER_CUSTOM;
        $this->customBorderChars = [$tl, $tr, $bl, $br, $h, $v];

        return $this;
    }

    /**
     * @param list<string> $lines Content lines
     */
    public function setContent(array $lines): self
    {
        $this->contentLines = $lines;

        return $this;
    }

    public function setEscapeDismisses(bool $dismisses): self
    {
        $this->escapeDismisses = $dismisses;

        return $this;
    }

    public function addButton(ButtonWidget $button): self
    {
        $this->buttons[] = $button;

        // Focus the last added button by default
        $this->focusedButtonIndex = max(0, count($this->buttons) - 1);

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
     * Returns the value of the clicked button, or DialogResult::Dismissed->value
     * if dismissed via Escape. Uses Revolt Suspension for async-safe blocking.
     *
     * @return string The button value or 'dismissed'
     */
    public function await(): string
    {
        $this->suspension = EventLoop::getSuspension();

        try {
            return $this->suspension->suspend();
        } finally {
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
        $this->close(DialogResult::Dismissed->value);
    }

    // --- Focus / Input ---

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        // Tab: cycle to next button
        if ($kb->matches($data, 'next')) {
            $count = max(1, count($this->buttons));
            $this->focusedButtonIndex = ($this->focusedButtonIndex + 1) % $count;
            $this->invalidate();

            return;
        }

        // Shift+Tab: cycle to previous button
        if ($kb->matches($data, 'prev')) {
            $count = max(1, count($this->buttons));
            $this->focusedButtonIndex = ($this->focusedButtonIndex - 1 + $count) % $count;
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

        // Escape / Ctrl+C: dismiss
        if ($kb->matches($data, 'cancel') && $this->escapeDismisses) {
            $this->dismiss();
        }
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'next' => [Key::TAB],
            'prev' => ["\033[Z"], // Shift+Tab
            'confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
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

        // Title bar: ╭─ Title ───────────╮
        $titleText = ($this->icon !== '' ? "{$this->icon} " : '') . $this->title;
        $titleVisible = mb_strwidth($titleText);
        $titlePadLeft = 1;
        $titlePadRight = max(0, $dialogInnerWidth - $titleVisible - $titlePadLeft);
        $lines[] = AnsiUtils::truncateToWidth(
            "{$border}{$chars[0]}{$chars[4]}{$accent}{$titleText}{$r}{$border}" . str_repeat($chars[4], $titlePadRight) . "{$chars[1]}{$r}",
            $viewportWidth,
        );

        // Content area
        foreach ($this->contentLines as $contentLine) {
            foreach ($this->wrapBlock($contentLine, $dialogInnerWidth - 2) as $wrapped) {
                $lines[] = $this->boxLine(
                    $wrapped,
                    $dialogInnerWidth,
                    $viewportWidth,
                    $chars[5],
                    $border,
                    $r,
                );
            }
        }

        // Button separator + button row (only if buttons exist)
        if ($this->buttons !== []) {
            $lines[] = AnsiUtils::truncateToWidth(
                "{$border}" . str_repeat($chars[4], $dialogInnerWidth + 2) . "{$r}",
                $viewportWidth,
            );

            $buttonRow = $this->renderButtonRow($dialogInnerWidth);
            $lines[] = $this->boxLine($buttonRow, $dialogInnerWidth, $viewportWidth, $chars[5], $border, $r);
        }

        // Bottom border: ╰──────────────────╯
        $lines[] = AnsiUtils::truncateToWidth(
            "{$border}{$chars[2]}" . str_repeat($chars[4], $dialogInnerWidth + 1) . "{$chars[3]}{$r}",
            $viewportWidth,
        );

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
        if ($this->borderStyle === self::BORDER_CUSTOM && $this->customBorderChars !== []) {
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

        // Also account for button row width
        if ($this->buttons !== []) {
            $buttonWidth = 0;
            foreach ($this->buttons as $button) {
                $buttonWidth += $button->getVisibleWidth();
            }
            $buttonWidth += 2 * max(0, count($this->buttons) - 1); // 2-space gaps
            $maxWidth = max($maxWidth, $buttonWidth);
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
     * Render a single boxed line with left/right borders and padding.
     */
    private function boxLine(
        string $content,
        int $innerWidth,
        int $viewportWidth,
        string $vChar,
        string $borderColor,
        string $reset,
    ): string {
        $visible = AnsiUtils::visibleWidth($content);
        $padding = max(0, $innerWidth - $visible - 2);

        return AnsiUtils::truncateToWidth(
            "{$borderColor}{$vChar}{$reset} {$content}{$reset}" . str_repeat(' ', $padding) . " {$borderColor}{$vChar}{$reset}",
            $viewportWidth,
        );
    }

    /**
     * Word-wrap a text line to fit within the given visible width.
     *
     * Handles plain text (no ANSI codes). For ANSI-colored content, lines
     * should already be split at appropriate boundaries.
     *
     * @return list<string>
     */
    private function wrapBlock(string $text, int $width): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [''];
        }

        $lines = [];
        foreach (preg_split('/\R/', $trimmed) ?: [] as $paragraph) {
            $current = '';
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];

            foreach ($words as $word) {
                $candidate = $current === '' ? $word : "{$current} {$word}";
                if (mb_strwidth($candidate) > $width && $current !== '') {
                    $lines[] = $current;
                    $current = $word;

                    continue;
                }

                if (mb_strwidth($candidate) > $width) {
                    $lines[] = mb_strimwidth($candidate, 0, $width, '…');
                    $current = '';

                    continue;
                }

                $current = $candidate;
            }

            $lines[] = $current === '' ? '' : $current;
        }

        return $lines;
    }
}
