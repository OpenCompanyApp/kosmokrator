<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Tree;

/**
 * Mutable interaction state for a TreeWidget.
 *
 * Tracks which node is selected, which nodes are expanded, and the scroll
 * offset. This is decoupled from TreeNode data so that state survives
 * data refreshes (e.g. when agent statuses update but selection persists).
 *
 * The flattened visible-items list is recomputed on every state change.
 */
final class TreeState
{
    /** @var string|null ID of the currently selected node */
    private ?string $selectedId = null;

    /** @var array<string, bool> Map of node ID => expanded state */
    private array $expanded = [];

    /** @var int Scroll offset in the visible-items list */
    private int $scrollOffset = 0;

    /** @var list<VisibleItem>|null Cached flattened visible items (invalidated on change) */
    private ?array $visibleItemsCache = null;

    /** @var array<string, TreeNode> Node lookup by ID (rebuilt from root) */
    private array $nodeIndex = [];

    /**
     * @param TreeNode $root The root node (or a virtual root wrapping top-level children).
     *                       The root node itself is not rendered.
     */
    public function __construct(
        private TreeNode $root,
    ) {
        $this->rebuildIndex();
        $this->applyInitialExpanded();
    }

    /**
     * Replace the root node (e.g. after data refresh). Preserves selection
     * and expanded states where possible.
     */
    public function setRoot(TreeNode $root): void
    {
        $this->root = $root;
        $this->visibleItemsCache = null;
        $this->rebuildIndex();

        // If selected node no longer exists, reset to first visible
        if ($this->selectedId !== null && !isset($this->nodeIndex[$this->selectedId])) {
            $this->selectedId = null;
            $this->scrollOffset = 0;
        }
    }

    public function getRoot(): TreeNode
    {
        return $this->root;
    }

    // ── Selection ─────────────────────────────────────────────────────────

    public function getSelectedId(): ?string
    {
        return $this->selectedId;
    }

    public function getSelectedNode(): ?TreeNode
    {
        return $this->selectedId !== null ? ($this->nodeIndex[$this->selectedId] ?? null) : null;
    }

    /**
     * Set selection by node ID. Does NOT adjust scroll offset.
     */
    public function setSelectedId(?string $id): void
    {
        $this->selectedId = $id;
    }

    // ── Expansion ─────────────────────────────────────────────────────────

    public function isExpanded(string $nodeId): bool
    {
        return $this->expanded[$nodeId] ?? false;
    }

    public function setExpanded(string $nodeId, bool $expanded): void
    {
        $this->expanded[$nodeId] = $expanded;
        $this->visibleItemsCache = null;
    }

    public function toggleExpanded(string $nodeId): void
    {
        $this->setExpanded($nodeId, !$this->isExpanded($nodeId));
    }

    // ── Scroll ────────────────────────────────────────────────────────────

    public function getScrollOffset(): int
    {
        return $this->scrollOffset;
    }

    public function setScrollOffset(int $offset): void
    {
        $this->scrollOffset = max(0, $offset);
    }

    /**
     * Ensure the selected item is visible within the given viewport height.
     * Adjusts scrollOffset if necessary.
     */
    public function ensureSelectedVisible(int $viewportHeight): void
    {
        $visible = $this->getVisibleItems();
        if ($visible === [] || $this->selectedId === null) {
            return;
        }

        // Find selected index in visible list
        $selectedIndex = null;
        foreach ($visible as $i => $item) {
            if ($item->node->id === $this->selectedId) {
                $selectedIndex = $i;
                break;
            }
        }

        if ($selectedIndex === null) {
            return;
        }

        // Clamp scroll to valid range
        $maxOffset = max(0, count($visible) - $viewportHeight);
        $this->scrollOffset = min($this->scrollOffset, $maxOffset);

        // If selected is above the viewport, scroll up
        if ($selectedIndex < $this->scrollOffset) {
            $this->scrollOffset = $selectedIndex;
        }

        // If selected is below the viewport, scroll down
        if ($selectedIndex >= $this->scrollOffset + $viewportHeight) {
            $this->scrollOffset = $selectedIndex - $viewportHeight + 1;
        }
    }

    // ── Visible Items ─────────────────────────────────────────────────────

    /**
     * Get the flattened list of visible items (respecting expand/collapse).
     *
     * Cached until the next state change.
     *
     * @return list<VisibleItem>
     */
    public function getVisibleItems(): array
    {
        if ($this->visibleItemsCache !== null) {
            return $this->visibleItemsCache;
        }

        $items = [];
        $childCount = count($this->root->children);
        foreach ($this->root->children as $i => $child) {
            $this->flattenNode(
                node: $child,
                depth: 0,
                items: $items,
                ancestorHasMore: [],
                hasMoreSiblings: $i < $childCount - 1,
            );
        }

        $this->visibleItemsCache = $items;

        // Auto-select first item if nothing is selected
        if ($this->selectedId === null && $items !== []) {
            $this->selectedId = $items[0]->node->id;
        }

        return $items;
    }

    /**
     * Get the total number of visible items.
     */
    public function getVisibleCount(): int
    {
        return count($this->getVisibleItems());
    }

    // ── Navigation ────────────────────────────────────────────────────────

    /**
     * Move selection up by one visible item. Returns true if selection changed.
     */
    public function moveUp(): bool
    {
        $visible = $this->getVisibleItems();
        if ($visible === [] || $this->selectedId === null) {
            return false;
        }

        $selectedIndex = $this->findVisibleIndex($this->selectedId);
        if ($selectedIndex === null || $selectedIndex === 0) {
            return false;
        }

        $this->selectedId = $visible[$selectedIndex - 1]->node->id;

        return true;
    }

    /**
     * Move selection down by one visible item. Returns true if selection changed.
     */
    public function moveDown(): bool
    {
        $visible = $this->getVisibleItems();
        if ($visible === [] || $this->selectedId === null) {
            return false;
        }

        $selectedIndex = $this->findVisibleIndex($this->selectedId);
        if ($selectedIndex === null || $selectedIndex === count($visible) - 1) {
            return false;
        }

        $this->selectedId = $visible[$selectedIndex + 1]->node->id;

        return true;
    }

    /**
     * Move to the first visible item.
     */
    public function moveToFirst(): bool
    {
        $visible = $this->getVisibleItems();
        if ($visible === [] || $this->selectedId === $visible[0]->node->id) {
            return false;
        }
        $this->selectedId = $visible[0]->node->id;
        $this->scrollOffset = 0;

        return true;
    }

    /**
     * Move to the last visible item.
     */
    public function moveToLast(): bool
    {
        $visible = $this->getVisibleItems();
        if ($visible === []) {
            return false;
        }
        $last = $visible[count($visible) - 1];
        if ($this->selectedId === $last->node->id) {
            return false;
        }
        $this->selectedId = $last->node->id;

        return true;
    }

    /**
     * Page up by viewport height.
     */
    public function pageUp(int $viewportHeight): bool
    {
        $visible = $this->getVisibleItems();
        if ($visible === [] || $this->selectedId === null) {
            return false;
        }

        $selectedIndex = $this->findVisibleIndex($this->selectedId);
        if ($selectedIndex === null) {
            return false;
        }

        $newIndex = max(0, $selectedIndex - max(1, $viewportHeight - 1));
        if ($newIndex === $selectedIndex) {
            return false;
        }
        $this->selectedId = $visible[$newIndex]->node->id;

        return true;
    }

    /**
     * Page down by viewport height.
     */
    public function pageDown(int $viewportHeight): bool
    {
        $visible = $this->getVisibleItems();
        $count = count($visible);
        if ($count === 0 || $this->selectedId === null) {
            return false;
        }

        $selectedIndex = $this->findVisibleIndex($this->selectedId);
        if ($selectedIndex === null) {
            return false;
        }

        $newIndex = min($count - 1, $selectedIndex + max(1, $viewportHeight - 1));
        if ($newIndex === $selectedIndex) {
            return false;
        }
        $this->selectedId = $visible[$newIndex]->node->id;

        return true;
    }

    /**
     * Move selection to the parent of the currently selected node.
     *
     * Walks backwards through the visible items list to find the nearest
     * item at a lower depth level.
     *
     * @return bool True if selection moved to parent.
     */
    public function moveToParent(): bool
    {
        $visible = $this->getVisibleItems();
        if ($visible === [] || $this->selectedId === null) {
            return false;
        }

        $selectedIdx = $this->findVisibleIndex($this->selectedId);
        if ($selectedIdx === null || $selectedIdx === 0) {
            return false;
        }

        $targetDepth = $visible[$selectedIdx]->depth - 1;
        if ($targetDepth < 0) {
            return false;
        }

        for ($i = $selectedIdx - 1; $i >= 0; $i--) {
            if ($visible[$i]->depth === $targetDepth) {
                $this->selectedId = $visible[$i]->node->id;

                return true;
            }
        }

        return false;
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function rebuildIndex(): void
    {
        $this->nodeIndex = [];
        $this->indexNode($this->root);
    }

    private function indexNode(TreeNode $node): void
    {
        $this->nodeIndex[$node->id] = $node;
        foreach ($node->children as $child) {
            $this->indexNode($child);
        }
    }

    private function applyInitialExpanded(): void
    {
        $this->applyInitialExpandedNode($this->root);
    }

    private function applyInitialExpandedNode(TreeNode $node): void
    {
        if ($node->expanded) {
            $this->expanded[$node->id] = true;
        }
        foreach ($node->children as $child) {
            $this->applyInitialExpandedNode($child);
        }
    }

    /**
     * Recursively flatten a node and its visible children.
     *
     * Tracks ancestor sibling information for proper connector rendering:
     *   - ancestorHasMore: for each ancestor depth, whether that ancestor has more siblings below
     *   - hasMoreSiblings: whether this node has more siblings below (├ vs └)
     *
     * @param list<VisibleItem> $items
     * @param list<bool> $ancestorHasMore
     */
    private function flattenNode(
        TreeNode $node,
        int $depth,
        array &$items,
        array $ancestorHasMore = [],
        bool $hasMoreSiblings = false,
    ): void {
        $items[] = new VisibleItem(
            node: $node,
            depth: $depth,
            isExpanded: $this->isExpanded($node->id),
            ancestorHasMore: $ancestorHasMore,
            hasMoreSiblings: $hasMoreSiblings,
        );

        if ($this->isExpanded($node->id) && $node->children !== []) {
            $childCount = count($node->children);
            $childAncestorHasMore = [...$ancestorHasMore, $hasMoreSiblings];
            foreach ($node->children as $i => $child) {
                $this->flattenNode(
                    node: $child,
                    depth: $depth + 1,
                    items: $items,
                    ancestorHasMore: $childAncestorHasMore,
                    hasMoreSiblings: $i < $childCount - 1,
                );
            }
        }
    }

    private function findVisibleIndex(string $id): ?int
    {
        foreach ($this->getVisibleItems() as $i => $item) {
            if ($item->node->id === $id) {
                return $i;
            }
        }

        return null;
    }
}
