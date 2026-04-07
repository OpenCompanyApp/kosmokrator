<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Toast;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Signal\Signal;
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

        // Wrap message text to fit inner width (accounting for "icon + space" prefix on first line)
        $wrappedLines = $this->wrapText($toast->message, $innerWidth - 3);

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
