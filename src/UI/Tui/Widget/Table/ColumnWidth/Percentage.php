<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table\ColumnWidth;

/**
 * Percentage column. Takes the given percentage of the total available width.
 * Clamped to [naturalWidth, availableWidth].
 */
final class Percentage implements ColumnWidth
{
    public function __construct(
        public readonly int $percent, // 1–100
    ) {
    }

    public function resolve(int $availableWidth, int $naturalWidth): int
    {
        $resolved = (int) round($this->percent / 100 * $availableWidth);

        return max($naturalWidth, min($resolved, $availableWidth));
    }
}
