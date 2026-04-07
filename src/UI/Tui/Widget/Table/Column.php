<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table;

use KosmoKrator\UI\Tui\Widget\Table\ColumnWidth\Flex;
use KosmoKrator\UI\Tui\Widget\Table\ColumnWidth\ColumnWidth;
use Symfony\Component\Tui\Style\TextAlign;

/**
 * Defines a single column in a TableWidget.
 */
final class Column
{
    /**
     * @param string $key       Stable identifier used for sorting and data access.
     *                           Does not change with reorder. Analogous to Textual's column key.
     * @param string $label     Display text shown in the header row.
     * @param ColumnWidth $width Width constraint for this column.
     * @param TextAlign $align  Text alignment within the column (default: Left).
     * @param bool $sortable    Whether this column can be sorted (default: true).
     * @param \Closure(mixed): string|null $formatter Optional cell formatter. Receives the raw cell
     *                           value and returns a display string. If null, (string) cast is used.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ColumnWidth $width = new Flex(),
        public readonly TextAlign $align = TextAlign::Left,
        public readonly bool $sortable = true,
        public readonly \Closure|null $formatter = null,
    ) {
    }
}
