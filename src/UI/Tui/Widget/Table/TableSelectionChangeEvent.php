<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table;

use Symfony\Component\Tui\Event\AbstractEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Event dispatched when the highlighted row changes in a TableWidget.
 *
 * This fires when the user moves the cursor (arrow keys, page up/down, home/end),
 * not when they confirm a selection (that's {@see TableSelectEvent}).
 */
final class TableSelectionChangeEvent extends AbstractEvent
{
    public function __construct(
        AbstractWidget $target,
        private readonly string $rowId,
        private readonly ?Row $row,
    ) {
        parent::__construct($target);
    }

    /**
     * Get the highlighted row's ID (or stringified index if no ID was set).
     */
    public function getRowId(): string
    {
        return $this->rowId;
    }

    /**
     * Get the highlighted row, or null if rows were cleared between dispatch and handling.
     */
    public function getRow(): ?Row
    {
        return $this->row;
    }
}
