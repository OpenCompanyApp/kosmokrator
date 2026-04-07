<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table;

/**
 * A single row in a TableWidget.
 *
 * Data is stored as an associative array keyed by column keys.
 * This decouples row data from column order — columns can be reordered
 * without touching rows.
 */
final class Row
{
    /**
     * @param array<string, mixed> $cells  Column key → cell value. Raw values; formatted
     *                                      by the Column's formatter during rendering.
     * @param string|null $id               Optional stable row identifier (for selection events,
     *                                      multi-select, etc.). If null, the row's index is used.
     * @param string[] $styleClasses        CSS-like class names for per-row styling.
     *                                      Resolved via KosmokratorStyleSheet.
     */
    public function __construct(
        public readonly array $cells,
        public readonly ?string $id = null,
        public readonly array $styleClasses = [],
    ) {
    }

    /**
     * Get the cell value for a given column key.
     */
    public function get(string $columnKey): mixed
    {
        return $this->cells[$columnKey] ?? null;
    }

    /**
     * Create a row from positional values, mapped to column keys.
     *
     * @param list<mixed> $values
     * @param list<string> $columnKeys
     */
    public static function fromValues(array $values, array $columnKeys, ?string $id = null): self
    {
        $cells = [];
        foreach ($values as $i => $value) {
            if (isset($columnKeys[$i])) {
                $cells[$columnKeys[$i]] = $value;
            }
        }

        return new self($cells, $id);
    }
}
