<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget\Tree;

/**
 * A single node in the tree hierarchy.
 *
 * Each node has a unique identifier, a display label, optional icon and detail
 * text, per-node styling, and an optional callback for lazy child loading.
 *
 * Instances should be treated as value objects after construction.
 */
final class TreeNode
{
    /**
     * @param string $id Unique identifier for selection/expand tracking.
     *                    Must be unique within the tree (not just among siblings).
     * @param string $label Display text for the node.
     * @param string|null $icon Optional single-character icon shown before the label.
     * @param string|null $detail Optional secondary text shown after the label (e.g. "3 tools", "128 lines").
     * @param string|null $iconColor ANSI color for the icon (e.g. Theme::success()).
     * @param string|null $labelStyle ANSI style for the label (e.g. Theme::dim()).
     * @param string|null $detailStyle ANSI style for the detail text.
     * @param list<TreeNode> $children Pre-populated child nodes.
     * @param (\Closure(): list<TreeNode>)|null $loadChildren Callback invoked on first expand.
     *                  Returns child nodes. Set to null if children are pre-populated.
     * @param bool $expanded Whether the node starts expanded (default: false).
     * @param array<string, mixed> $metadata Arbitrary data attached to the node (e.g. file path, agent type).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $icon = null,
        public readonly ?string $detail = null,
        public readonly ?string $iconColor = null,
        public readonly ?string $labelStyle = null,
        public readonly ?string $detailStyle = null,
        public readonly array $children = [],
        public readonly ?\Closure $loadChildren = null,
        public readonly bool $expanded = false,
        public readonly array $metadata = [],
    ) {}

    /**
     * Whether this node can have children (either pre-populated or lazy-loadable).
     */
    public function hasChildren(): bool
    {
        return $this->children !== [] || $this->loadChildren !== null;
    }

    /**
     * Whether this node's children have been loaded (either pre-populated or lazy-loaded).
     */
    public function isChildrenLoaded(): bool
    {
        return $this->children !== [] || $this->loadChildren === null;
    }

    /**
     * Create a copy with different children (used after lazy loading).
     *
     * Clears the loadChildren callback (since children are now loaded) and
     * sets expanded to true so the node auto-expands after loading.
     *
     * @param list<TreeNode> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            icon: $this->icon,
            detail: $this->detail,
            iconColor: $this->iconColor,
            labelStyle: $this->labelStyle,
            detailStyle: $this->detailStyle,
            children: $children,
            loadChildren: null,
            expanded: true,
            metadata: $this->metadata,
        );
    }

    /**
     * Create a copy with a replaced child node (by ID).
     *
     * Used internally during lazy loading to rebuild the immutable tree.
     *
     * @return self A new TreeNode with the target child replaced.
     */
    public function withChildReplaced(string $targetId, TreeNode $replacement): self
    {
        $newChildren = [];
        foreach ($this->children as $child) {
            if ($child->id === $targetId) {
                $newChildren[] = $replacement;
            } else {
                $newChildren[] = $child->withChildReplaced($targetId, $replacement);
            }
        }

        return new self(
            id: $this->id,
            label: $this->label,
            icon: $this->icon,
            detail: $this->detail,
            iconColor: $this->iconColor,
            labelStyle: $this->labelStyle,
            detailStyle: $this->detailStyle,
            children: $newChildren,
            loadChildren: $this->loadChildren,
            expanded: $this->expanded,
            metadata: $this->metadata,
        );
    }
}
