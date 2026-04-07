<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Table\ColumnWidth;

/**
 * Fixed-width column. Always renders at exactly N characters.
 *
 * Use for: status icons (2ch), booleans (3ch), short codes (8ch).
 */
final class Fixed implements ColumnWidth
{
    public function __construct(
        public readonly int $chars,
    ) {
    }

    public function resolve(int $availableWidth, int $naturalWidth): int
    {
        return $this->chars;
    }
}
