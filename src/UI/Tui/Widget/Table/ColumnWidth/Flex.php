<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table\ColumnWidth;

/**
 * Flex-grow column. After fixed columns are satisfied, flex columns
 * share the remaining space proportionally based on their weight.
 *
 * - flex(1) = equal share (default)
 * - flex(2) = twice as wide as flex(1)
 *
 * If the column's natural width exceeds its flex share, it uses the natural width
 * (flex is a minimum grow weight, not a maximum cap).
 */
final class Flex implements ColumnWidth
{
    public function __construct(
        public readonly int $weight = 1,
    ) {
    }

    public function resolve(int $availableWidth, int $naturalWidth): int
    {
        return max($naturalWidth, $availableWidth);
    }
}
