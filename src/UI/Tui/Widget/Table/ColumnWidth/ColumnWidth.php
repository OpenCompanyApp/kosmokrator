<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table\ColumnWidth;

/**
 * Column width strategies for TableWidget.
 *
 * Each variant is a final class under the ColumnWidth namespace.
 * Resolved during rendering by TableWidget::resolveColumnWidths().
 */
interface ColumnWidth
{
    /**
     * Given the available remaining width and the column's intrinsic (natural) width,
     * return the resolved character width for this column.
     */
    public function resolve(int $availableWidth, int $naturalWidth): int;
}
