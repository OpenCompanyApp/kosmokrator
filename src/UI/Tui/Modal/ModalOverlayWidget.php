<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Modal;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Full-viewport overlay that renders a dimmed backdrop and centers
 * one or more stacked DialogWidget instances.
 *
 * Usage:
 *   $overlay = new ModalOverlayWidget();
 *   $dialog = DialogWidget::create('Confirm', ['Are you sure?'])
 *       ->addButton(ButtonWidget::cancel())
 *       ->addButton(ButtonWidget::confirm());
 *   $overlay->open($dialog);
 *   $result = $dialog->await(); // blocks via Suspension
 *
 * Stack support:
 *   Multiple dialogs can be open simultaneously. The topmost dialog
 *   receives input; lower dialogs are progressively dimmed.
 *
 * Rendering:
 *   1. Full-viewport backdrop with dim background
 *   2. Each dialog is rendered and centered via ANSI cursor positioning
 *   3. Lower stack layers get progressively dimmer backdrops
 */
final class ModalOverlayWidget extends AbstractWidget
{
    /** @var list<DialogWidget> Stack of open dialogs, topmost last */
    private array $stack = [];

    /**
     * Open a dialog and push it onto the stack.
     *
     * Returns the dialog for method chaining.
     */
    public function open(DialogWidget $dialog): DialogWidget
    {
        $this->stack[] = $dialog;
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
     * Get the current stack depth.
     */
    public function getStackDepth(): int
    {
        return count($this->stack);
    }

    /**
     * Render the full-viewport backdrop with all stacked dialogs.
     *
     * For each dialog in the stack (bottom to top):
     * 1. Render a dimmed backdrop covering the viewport
     * 2. Calculate centered position for the dialog
     * 3. Render the dialog at that position
     *
     * The topmost dialog is rendered last (on top) and has the strongest
     * backdrop; lower dialogs are progressively dimmed.
     *
     * @return list<string> ANSI-formatted lines
     */
    public function render(RenderContext $context): array
    {
        if ($this->stack === []) {
            return [];
        }

        $columns = $context->getColumns();
        $rows = $context->getRows();

        // Start with an empty buffer
        $lines = array_fill(0, $rows, '');

        $stackDepth = count($this->stack);
        foreach ($this->stack as $index => $dialog) {
            $isTopmost = $index === $stackDepth - 1;

            // Render backdrop for this layer (dimmer for lower layers)
            $opacity = $isTopmost ? 0.85 : 0.4;
            $this->renderBackdrop($lines, $columns, $rows, $opacity);

            // Render the dialog into a temporary buffer to measure it
            $dialogLines = $dialog->render($context);
            $dialogHeight = count($dialogLines);
            $dialogWidth = 0;
            foreach ($dialogLines as $line) {
                $dialogWidth = max($dialogWidth, AnsiUtils::visibleWidth($line));
            }

            // Calculate centered position
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
     *
     * Formula: component = round(12 * (1 - opacity))
     * opacity 0.0 → rgb(12, 12, 15) (barely visible)
     * opacity 0.85 → rgb(2, 2, 3)   (near-black)
     * opacity 1.0 → rgb(0, 0, 0)    (pure black)
     */
    private function backdropColor(float $opacity): string
    {
        $v = (int) round(12 * (1 - $opacity));

        return "\033[48;2;{$v};{$v};" . ($v + 3) . 'm';
    }

    /**
     * Composite source lines onto the target buffer at (row, col) offset.
     *
     * Uses ANSI cursor positioning sequences to place the dialog at the
     * calculated centered position within the viewport.
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

            // Place the dialog line at the horizontal offset using cursor positioning
            $target[$targetRow] = "\033[{$targetRow};" . ($startCol + 1) . 'H' . $line;
        }
    }
}
