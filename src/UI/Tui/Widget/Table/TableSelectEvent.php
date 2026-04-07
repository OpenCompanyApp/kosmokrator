<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table;

use Symfony\Component\Tui\Event\AbstractEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Event dispatched when a row is selected in a TableWidget (Enter pressed).
 */
final class TableSelectEvent extends AbstractEvent
{
    public function __construct(
        AbstractWidget $target,
        private readonly string $rowId,
        private readonly ?Row $row,
    ) {
        parent::__construct($target);
    }

    /**
     * Get the selected row's ID (or stringified index if no ID was set).
     */
    public function getRowId(): string
    {
        return $this->rowId;
    }

    /**
     * Get the selected row, or null if rows were cleared between dispatch and handling.
     */
    public function getRow(): ?Row
    {
        return $this->row;
    }
}
