<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table;

/**
 * Describes the current sort state of the table.
 */
final class SortState
{
    public function __construct(
        public readonly string $columnKey,
        public readonly SortDirection $direction = SortDirection::Ascending,
    ) {
    }

    public function toggle(): self
    {
        return new self(
            $this->columnKey,
            $this->direction === SortDirection::Ascending
                ? SortDirection::Descending
                : SortDirection::Ascending,
        );
    }

    /**
     * Create a new sort state for a given column.
     *
     * If the column is the same as the current one, toggle direction.
     * Otherwise, start ascending.
     */
    public function withColumn(string $columnKey): self
    {
        if ($this->columnKey === $columnKey) {
            return $this->toggle();
        }

        return new self($columnKey, SortDirection::Ascending);
    }
}
