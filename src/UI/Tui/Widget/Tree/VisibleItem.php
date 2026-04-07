<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Tree;

/**
 * A node in the flattened visible-items list.
 *
 * Pre-computed for rendering — carries the node, its visible depth,
 * whether it's currently expanded (for connector rendering), and
 * ancestor sibling information needed to draw proper tree connectors.
 */
final class VisibleItem
{
    /**
     * @param TreeNode $node The tree node this item represents.
     * @param int $depth Depth in the tree (0 = top-level children of the virtual root).
     * @param bool $isExpanded Whether this node is currently expanded in the tree state.
     * @param list<bool> $ancestorHasMore For each depth level 0..depth-1, true if ancestor
     *                has more siblings below it (for drawing vertical continuation lines).
     * @param bool $hasMoreSiblings Whether this node has more siblings below it
     *                (for drawing ├ vs └ connectors).
     */
    public function __construct(
        public readonly TreeNode $node,
        public readonly int $depth,
        public readonly bool $isExpanded,
        public readonly array $ancestorHasMore = [],
        public readonly bool $hasMoreSiblings = false,
    ) {}
}
