<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget;

use KosmoKrator\UI\Theme;
use KosmoKrator\UI\Tui\Widget\Tree\TreeNode;
use KosmoKrator\UI\Tui\Widget\Tree\TreeState;
use KosmoKrator\UI\Tui\Widget\Tree\VisibleItem;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Interactive hierarchical tree widget with expand/collapse, keyboard
 * navigation, lazy loading, and per-node styling.
 *
 * ## Rendering
 *
 * The tree is rendered as a list of terminal lines, one per visible node.
 * Each line consists of:
 *
 *   [connectors][expand-indicator] [icon] [label] [detail]
 *
 * Connectors use Unicode box-drawing characters:
 *   ├─  child (not last sibling)
 *   └─  child (last sibling)
 *   │   continuation line (parent has more siblings below)
 *
 * Expand indicators:
 *   ▸   collapsed, has children
 *   ▾   expanded, has children
 *       (none) leaf node
 *
 * ## Keyboard Navigation
 *
 *   ↑/↓          Move selection up/down (through visible items)
 *   ←            Collapse current node (if expanded) or move to parent
 *   →/Enter      Expand current node (if collapsed, loads children if lazy)
 *   Home/g       Move to first item
 *   End/G        Move to last item
 *   PgUp         Page up
 *   PgDn         Page down
 *   Space        Toggle expand/collapse
 *   Escape       Cancel/dismiss
 *
 * ## Lazy Loading
 *
 * When a node has a `loadChildren` callback and the user expands it:
 * 1. The callback is invoked: `($node->loadChildren)()`
 * 2. Returns `list<TreeNode>`
 * 3. The node in the tree is replaced via `TreeNode::withChildReplaced()`
 * 4. The tree is rebuilt and the node is auto-expanded
 *
 * ## Scroll
 *
 * When visible items exceed the allocated height, only a viewport-sized
 * window is rendered. The scroll offset is adjusted to keep the selected
 * item visible (same algorithm as SelectListWidget).
 */
final class TreeWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    // ── Unicode box-drawing characters ────────────────────────────────────

    private const CONNECTOR_BRANCH = '├─';
    private const CONNECTOR_LAST = '└─';
    private const CONNECTOR_VERTICAL = '│ ';
    private const CONNECTOR_BLANK = '  ';
    private const INDICATOR_COLLAPSED = '▸ ';
    private const INDICATOR_EXPANDED = '▾ ';
    private const INDICATOR_LEAF = '  ';

    private TreeState $state;

    /** @var (callable(TreeNode): void)|null Callback when a node is selected (Enter on leaf or any node) */
    private $onSelectCallback = null;

    /** @var (callable(TreeNode): void)|null Callback when expand/collapse changes */
    private $onToggleCallback = null;

    /** @var (callable(): void)|null Callback when Escape is pressed */
    private $onCancelCallback = null;

    private bool $showScrollIndicator = true;

    /** @var int Cached viewport height from the last render() call */
    private int $lastViewportHeight = 20;

    // ── Constructor ───────────────────────────────────────────────────────

    /**
     * @param list<TreeNode> $nodes Top-level tree nodes.
     *                              Wrapped in a virtual root internally.
     */
    public function __construct(
        array $nodes = [],
    ) {
        $root = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: $nodes,
        );
        $this->state = new TreeState($root);
    }

    // ── Configuration ─────────────────────────────────────────────────────

    /**
     * Set the top-level nodes (replaces the entire tree).
     * Preserves selection and expanded state where possible.
     *
     * @param list<TreeNode> $nodes
     */
    public function setNodes(array $nodes): static
    {
        $root = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: $nodes,
        );
        $this->state->setRoot($root);
        $this->invalidate();

        return $this;
    }

    /**
     * Get the current tree state (for external observation).
     */
    public function getState(): TreeState
    {
        return $this->state;
    }

    /**
     * Get the currently selected node, if any.
     */
    public function getSelectedNode(): ?TreeNode
    {
        return $this->state->getSelectedNode();
    }

    /**
     * Set whether to show a scroll indicator (e.g. "5/20") when content overflows.
     */
    public function setShowScrollIndicator(bool $show): static
    {
        $this->showScrollIndicator = $show;
        $this->invalidate();

        return $this;
    }

    // ── Callbacks ─────────────────────────────────────────────────────────

    /**
     * Register a callback for when the user presses Enter on a leaf node
     * or confirms selection on any node.
     *
     * @param callable(TreeNode): void $callback
     */
    public function onSelect(callable $callback): static
    {
        $this->onSelectCallback = $callback;

        return $this;
    }

    /**
     * Register a callback for when a node is expanded or collapsed.
     *
     * @param callable(TreeNode): void $callback
     */
    public function onToggle(callable $callback): static
    {
        $this->onToggleCallback = $callback;

        return $this;
    }

    /**
     * Register a callback for when Escape is pressed.
     *
     * @param callable(): void $callback
     */
    public function onCancel(callable $callback): static
    {
        $this->onCancelCallback = $callback;

        return $this;
    }

    // ── Keyboard Input ────────────────────────────────────────────────────

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'up')) {
            if ($this->state->moveUp()) {
                $this->state->ensureSelectedVisible($this->lastViewportHeight);
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'down')) {
            if ($this->state->moveDown()) {
                $this->state->ensureSelectedVisible($this->lastViewportHeight);
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'left')) {
            $this->handleLeft();

            return;
        }

        if ($kb->matches($data, 'right') || $kb->matches($data, 'confirm')) {
            $this->handleRightOrConfirm();

            return;
        }

        if ($kb->matches($data, 'toggle')) {
            $this->handleToggle();

            return;
        }

        if ($kb->matches($data, 'home')) {
            if ($this->state->moveToFirst()) {
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'end')) {
            if ($this->state->moveToLast()) {
                $this->state->ensureSelectedVisible($this->lastViewportHeight);
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'page_up')) {
            if ($this->state->pageUp($this->lastViewportHeight)) {
                $this->state->ensureSelectedVisible($this->lastViewportHeight);
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'page_down')) {
            if ($this->state->pageDown($this->lastViewportHeight)) {
                $this->state->ensureSelectedVisible($this->lastViewportHeight);
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'cancel')) {
            if ($this->onCancelCallback !== null) {
                ($this->onCancelCallback)();
            }

            return;
        }
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP],
            'down' => [Key::DOWN],
            'left' => [Key::LEFT],
            'right' => [Key::RIGHT],
            'confirm' => [Key::ENTER],
            'toggle' => [Key::SPACE],
            'home' => [Key::HOME, 'g'],
            'end' => [Key::END, 'G'],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'cancel' => [Key::ESCAPE],
        ];
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    /**
     * Render the visible tree into terminal lines.
     *
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $visibleItems = $this->state->getVisibleItems();
        if ($visibleItems === []) {
            return [];
        }

        $height = $context->getRows();
        $width = $context->getColumns();

        // Cache viewport height for handleInput() calls between renders
        $this->lastViewportHeight = $height;

        // Compute scroll window
        $this->state->ensureSelectedVisible($height);
        $offset = $this->state->getScrollOffset();
        $visibleCount = count($visibleItems);
        $maxOffset = max(0, $visibleCount - $height);
        $offset = min($offset, $maxOffset);
        $this->state->setScrollOffset($offset);

        $windowSize = min($height, $visibleCount - $offset);
        $window = array_slice($visibleItems, $offset, $windowSize);

        $reset = Theme::reset();
        $dim = Theme::dim();
        $selectedBg = Theme::bgRgb(40, 40, 60);

        $lines = [];
        foreach ($window as $item) {
            $isSelected = $item->node->id === $this->state->getSelectedId();
            $lines[] = $this->renderItem($item, $isSelected, $width, $reset, $dim, $selectedBg);
        }

        // Pad to allocated height
        while (count($lines) < $height) {
            $lines[] = '';
        }

        // Add scroll indicator in the last line if content overflows
        if ($this->showScrollIndicator && $visibleCount > $height) {
            $pos = $offset + 1;
            $end = min($offset + $windowSize, $visibleCount);
            $indicator = "{$dim}({$pos}-{$end}/{$visibleCount}){$reset}";
            $lines[$height - 1] = $indicator;
        }

        return $lines;
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function handleLeft(): void
    {
        $node = $this->state->getSelectedNode();
        if ($node === null) {
            return;
        }

        // If expanded, collapse
        if ($node->hasChildren() && $this->state->isExpanded($node->id)) {
            $this->state->toggleExpanded($node->id);
            $this->invalidate();

            return;
        }

        // Otherwise, move to parent
        if ($this->state->moveToParent()) {
            $this->state->ensureSelectedVisible($this->lastViewportHeight);
            $this->invalidate();
        }
    }

    private function handleRightOrConfirm(): void
    {
        $node = $this->state->getSelectedNode();
        if ($node === null) {
            return;
        }

        // If collapsed and has children, expand (lazy-load if needed)
        if ($node->hasChildren() && !$this->state->isExpanded($node->id)) {
            $this->loadChildrenIfNeeded($node);
            $this->state->setExpanded($node->id, true);
            $this->invalidate();

            if ($this->onToggleCallback !== null) {
                ($this->onToggleCallback)($node);
            }

            return;
        }

        // If already expanded or leaf, fire select callback
        if ($this->onSelectCallback !== null) {
            ($this->onSelectCallback)($node);
        }
    }

    private function handleToggle(): void
    {
        $node = $this->state->getSelectedNode();
        if ($node === null || !$node->hasChildren()) {
            return;
        }

        if (!$this->state->isExpanded($node->id)) {
            $this->loadChildrenIfNeeded($node);
        }

        $this->state->toggleExpanded($node->id);
        $this->invalidate();

        if ($this->onToggleCallback !== null) {
            ($this->onToggleCallback)($node);
        }
    }

    /**
     * If the node has a loadChildren callback, invoke it and replace
     * the node in the tree with children populated.
     */
    private function loadChildrenIfNeeded(TreeNode $node): void
    {
        if ($node->loadChildren === null) {
            return;
        }

        $children = ($node->loadChildren)();
        if ($children === []) {
            return;
        }

        $replacement = $node->withChildren($children);
        $newRoot = $this->state->getRoot()->withChildReplaced($node->id, $replacement);
        $this->state->setRoot($newRoot);

        // Mark the node as expanded in state
        $this->state->setExpanded($node->id, true);
    }

    /**
     * Render a single visible item into a styled terminal line.
     *
     * Format: [connector-prefix][node-connector][expand-indicator][icon][label][detail]
     */
    private function renderItem(
        VisibleItem $item,
        bool $isSelected,
        int $maxWidth,
        string $reset,
        string $dim,
        string $selectedBg,
    ): string {
        $node = $item->node;

        // Build connector prefix (ancestor continuation lines)
        $prefix = '';
        for ($level = 0; $level < $item->depth; $level++) {
            $hasMore = $item->ancestorHasMore[$level] ?? false;
            $prefix .= $hasMore ? self::CONNECTOR_VERTICAL : self::CONNECTOR_BLANK;
        }

        // Node connector (├─ or └─)
        if ($item->depth > 0) {
            $connector = $item->hasMoreSiblings ? self::CONNECTOR_BRANCH : self::CONNECTOR_LAST;
        } else {
            // Top-level items: no connector prefix
            $connector = '';
        }

        // Expand/collapse indicator
        if ($node->hasChildren()) {
            $indicator = $item->isExpanded ? self::INDICATOR_EXPANDED : self::INDICATOR_COLLAPSED;
        } else {
            $indicator = self::INDICATOR_LEAF;
        }

        // Icon
        $iconPart = '';
        if ($node->icon !== null) {
            $iconColor = $node->iconColor ?? $dim;
            $iconPart = "{$iconColor}{$node->icon}{$reset} ";
        }

        // Label
        $labelStyle = $node->labelStyle ?? '';
        $label = "{$labelStyle}{$node->label}{$reset}";

        // Detail
        $detailPart = '';
        if ($node->detail !== null) {
            $detailStyle = $node->detailStyle ?? $dim;
            $detailPart = " {$detailStyle}{$node->detail}{$reset}";
        }

        $content = "{$dim}{$prefix}{$connector}{$reset}{$indicator}{$iconPart}{$label}{$detailPart}";

        // Truncate to maxWidth (accounting for ANSI codes)
        $content = $this->truncateToWidth($content, $maxWidth, $reset);

        // Apply selection highlight (only when focused)
        if ($isSelected && $this->isFocused()) {
            $content = "{$selectedBg}{$content}{$reset}";
        }

        return $content;
    }

    /**
     * Truncate a string to a visual width, preserving ANSI escape sequences.
     *
     * Counts printable characters (those not inside escape sequences) and
     * stops when the visual width reaches maxWidth.
     */
    private function truncateToWidth(string $text, int $maxWidth, string $reset): string
    {
        $visualWidth = 0;
        $inEscape = false;
        $result = '';

        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];

            if ($char === "\033") {
                $inEscape = true;
                $result .= $char;
                continue;
            }

            if ($inEscape) {
                $result .= $char;
                if ($char === 'm') {
                    $inEscape = false;
                }
                continue;
            }

            $visualWidth++;
            if ($visualWidth > $maxWidth) {
                return $result . $reset;
            }
            $result .= $char;
        }

        return $result;
    }
}
