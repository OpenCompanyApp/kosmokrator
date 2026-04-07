<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Layout;

use Symfony\Component\Tui\Tui;

/**
 * Reads the current terminal size and provides a TerminalDimension value object.
 *
 * Acts as a factory: call `provide()` whenever dimensions are needed (each render
 * pass or on SIGWINCH). The Tui object already tracks terminal resizes, so this
 * provider always returns fresh dimensions.
 */
final class DimensionProvider
{
    private ?TerminalDimension $cached = null;

    private int $cachedCols = 0;

    private int $cachedRows = 0;

    /**
     * Create a provider that reads dimensions from a Symfony Tui instance.
     *
     * @param  Tui  $tui  The active TUI session (holds the terminal reference)
     */
    public function __construct(
        private readonly Tui $tui,
    ) {}

    /**
     * Return the current terminal dimensions.
     *
     * Reads columns/rows from the TUI terminal on every call so that
     * SIGWINCH resizes are always reflected. A trivial cache avoids
     * re-creating the value object when dimensions haven't changed.
     */
    public function provide(): TerminalDimension
    {
        $cols = $this->tui->getTerminal()->getColumns();
        $rows = $this->tui->getTerminal()->getRows();

        if ($this->cached !== null && $this->cachedCols === $cols && $this->cachedRows === $rows) {
            return $this->cached;
        }

        $this->cached = new TerminalDimension($cols, $rows);
        $this->cachedCols = $cols;
        $this->cachedRows = $rows;

        return $this->cached;
    }

    /**
     * Invalidate the cached dimension (e.g. after a SIGWINCH).
     */
    public function invalidate(): void
    {
        $this->cached = null;
    }

    /**
     * Create a TerminalDimension from raw column/row values.
     *
     * Useful for testing or when dimensions come from a source other than Tui.
     */
    public static function fromValues(int $columns, int $rows): TerminalDimension
    {
        return new TerminalDimension($columns, $rows);
    }
}
