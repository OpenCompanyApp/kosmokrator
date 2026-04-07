# 04 — TreeWidget

> **File**: `src/UI/Tui/Widget/TreeWidget.php`
> **Depends on**: AbstractWidget, FocusableInterface, KeybindingsTrait, Theme (`src/UI/Theme.php`)
> **Blocks**: Subagent dashboard (`14-subagent-display`), file tree view, diff file list, settings category nav

---

## 1. Problem Statement

KosmoKrator currently renders tree structures as plain ANSI text via `SubagentDisplayManager::renderTreeNodes()` (`src/UI/Tui/SubagentDisplayManager.php:464`). This is:

- **Non-interactive** — no keyboard navigation, no expand/collapse, no selection
- **Monolithic** — tree rendering logic is embedded in a manager class, not reusable
- **Not scrollable** — when the tree exceeds the viewport, the entire conversation scrolls rather than the tree itself

A `TreeWidget` provides a reusable, interactive, scrollable tree component that multiple features need:

| Use Case | Current State | With TreeWidget |
|----------|---------------|-----------------|
| Agent tree | Static TextWidget (`SubagentDisplayManager.php:148`) | Interactive, expandable per-agent subtrees |
| File tree | Not implemented | Collapsible directory tree with lazy loading |
| Diff file list | Not implemented | Expandable per-file hunks |
| Task tree | Not implemented | Hierarchical task breakdown |
| Settings nav | `SettingsWorkspaceWidget` flat list | Category tree with subcategories |

---

## 2. Research: Existing Tree Implementations

### 2.1 Ratatui (Rust) — `tui-tree-widget` crate

The de facto Rust tree widget. Key design:

- **`TreeItem`** — flat items with an `identifier` (for selection tracking) and `text` (display label)
- **`TreeState`** — holds `selected`, `offset`, and a set of opened `identifier`s. Completely decoupled from rendering.
- **Rendering** — iterates visible items (skipping collapsed children), renders indentation + Unicode connectors (`├──`, `└──`, `│`) per depth level
- **Scroll** — `offset` is adjusted to keep `selected` visible; no virtualization needed since only visible items are iterated
- **No lazy loading** — all items must be pre-populated

**Lessons**:
- Decouple `TreeState` (selection, scroll, expanded set) from `TreeItem` (data)
- A "flatten visible items" pass before rendering simplifies both scroll and navigation
- State should be serializable (just a set of expanded IDs + selected ID + scroll offset)

### 2.2 Lazygit (Go) — file tree panel

Lazygit's file tree in the commits panel:

- **Lazy loading** — directory contents loaded on first expand via filesystem read
- **Toggle** — pressing Enter on a directory toggles expand/collapse
- **Inline status** — each file shows a staged/unstaged/modified indicator (icon + color)
- **Single selection** — only one node selected at a time; selection moves across depth levels
- **Scroll lock** — selected item stays visible when the list is longer than the panel

**Lessons**:
- Lazy loading via callback (`onExpand`) is essential for file trees
- Per-node icons and status indicators must be customizable
- Selection movement across depth boundaries (up/down traverses the flattened visible list)

### 2.3 broot (Rust) — tree view

broot is a dedicated tree-view file browser:

- **Virtual listing** — only expanded nodes exist in memory; collapsing frees children
- **Git status integration** — colored icons per file status
- **Sort modes** — by name, size, date — toggled live
- **Search filtering** — typing filters the visible tree in real-time
- **Multi-column** — tree + size + date in columns (like a table)

**Lessons**:
- Sort and filter are advanced features; not needed for v1 but the data model should allow them
- Columnar detail alongside labels is useful (e.g., elapsed time for agents, line count for files)

### 2.4 SelectListWidget (KosmoKrator's closest equivalent)

Located at `vendor/symfony/tui/src/Symfony/Component/Tui/Widget/SelectListWidget.php`:

- Flat items with `value`, `label`, `description`
- Built-in scroll via `maxVisible` window + `startIndex` calculation
- `FocusableInterface` + `KeybindingsTrait` for keyboard nav
- Events dispatched via `$this->dispatch(new SelectEvent(...))`
- Pseudo-element styling: `::selected`, `::label`, `::description`

**Lessons for TreeWidget**:
- Follow the same `FocusableInterface` + `KeybindingsTrait` pattern
- Use the same scroll window approach (`startIndex` / `endIndex` around selected)
- Dispatch events for selection and expansion changes
- Use pseudo-element styling (`::selected`, `::connector`, `::icon`)

---

## 3. Architecture

### 3.1 Component Overview

```
TreeNode (data model)          TreeState (interaction state)       TreeWidget (rendering)
┌──────────────────┐           ┌──────────────────┐               ┌──────────────────┐
│ id: string       │           │ selectedId: ?str  │               │ render(): array  │
│ label: string    │           │ expandedIds: set  │               │ handleInput()    │
│ icon: ?string    │◄──────────│ scrollOffset: int │◄──────────────│                  │
│ detail: ?string  │  flatten  │ rootNode: TreeNode│   user input  │ theme/styling    │
│ style: ?NodeStyle│  visible  │ visibleItems: []  │               │ connector chars  │
│ children: []     │──────────►│                   │──────────────►│ scroll window    │
│ loadChildren: ?cb│           └──────────────────┘               └──────────────────┘
└──────────────────┘
```

### 3.2 Visible Items Flatten

Before rendering, the tree is flattened into a list of visible items. This is the same approach Ratatui uses:

```
Root
├── Agents (expanded)
│   ├── agent-1 (selected)        ← depth 2, visible
│   │   ├── sub-agent-a           ← depth 3, visible (parent expanded)
│   │   └── sub-agent-b           ← depth 3, visible
│   └── agent-2 (collapsed)      ← depth 2, visible
│       └── sub-agent-c           ← depth 3, NOT visible (parent collapsed)
├── Tasks (collapsed)
│   └── task-1                    ← depth 2, NOT visible (parent collapsed)
└── Settings                      ← depth 1, visible

Flattened visible items:
[0] Agents        depth=1  expanded  hasChildren
[1] agent-1       depth=2  selected  hasChildren
[2] sub-agent-a   depth=3            leaf
[3] sub-agent-b   depth=3            leaf
[4] agent-2       depth=2  collapsed hasChildren
[5] Settings      depth=1            leaf
```

Navigation moves through indices 0–5. Expanding "agent-2" inserts new items at index 5 and shifts "Settings" down.

### 3.3 Scroll Window

Same approach as `SelectListWidget`:

```
Visible items count: 20
Viewport height:     8 rows
Selected index:      15

Scroll window: items[11..18] → renders 8 rows, selected row at position 4
```

The scroll window adjusts so the selected item is always visible, with context lines above and below.

---

## 4. Class Designs

### 4.1 `TreeNode` — Data Model

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Tree;

/**
 * A single node in the tree hierarchy.
 *
 * Each node has a unique identifier, a display label, optional icon and detail
 * text, per-node styling, and an optional callback for lazy child loading.
 *
 * @immutable Instances should be treated as value objects after construction.
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
     * @param (callable(): list<TreeNode>)|null $loadChildren Callback invoked on first expand.
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
            loadChildren: null, // Clear: loaded
            expanded: true,     // Auto-expand after loading
            metadata: $this->metadata,
        );
    }
}
```

### 4.2 `TreeState` — Interaction State

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Tree;

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
        foreach ($this->root->children as $child) {
            $this->flattenNode($child, 0, $items);
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
     * @param list<VisibleItem> $items
     */
    private function flattenNode(TreeNode $node, int $depth, array &$items): void
    {
        $items[] = new VisibleItem(
            node: $node,
            depth: $depth,
            isExpanded: $this->isExpanded($node->id),
        );

        if ($this->isExpanded($node->id) && $node->children !== []) {
            $childDepth = $depth + 1;
            foreach ($node->children as $child) {
                $this->flattenNode($child, $childDepth, $items);
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
```

### 4.3 `VisibleItem` — Flattened View Entry

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Tree;

/**
 * A node in the flattened visible-items list.
 *
 * Pre-computed for rendering — carries the node, its visible depth,
 * and whether it's currently expanded (for connector rendering).
 */
final class VisibleItem
{
    public function __construct(
        public readonly TreeNode $node,
        public readonly int $depth,
        public readonly bool $isExpanded,
    ) {}
}
```

### 4.4 `TreeWidget` — The Widget

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\Tree\TreeNode;
use Kosmokrator\UI\Tui\Widget\Tree\TreeState;
use Kosmokrator\UI\Tui\Widget\Tree\VisibleItem;
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
 *   [indent][connectors][expand-indicator] [icon] [label] [detail]
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
 * ## Styling
 *
 * Per-node styling is controlled via TreeNode properties (iconColor, labelStyle,
 * detailStyle). Global styling uses pseudo-elements in the stylesheet:
 *
 *   TreeWidget::class                → base style
 *   TreeWidget::class.'::selected'   → selected row highlight
 *   TreeWidget::class.'::connector'  → tree line characters
 *   TreeWidget::class.'::expand-ind' → expand/collapse indicator
 *
 * ## Lazy Loading
 *
 * When a node has a `loadChildren` callback and the user expands it:
 * 1. The callback is invoked: `($node->loadChildren)()`
 * 2. Returns `list<TreeNode>`
 * 3. The node in the tree is replaced via `TreeNode::withChildren()`
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

    private const CONNECTORS = [
        'branch'     => '├─',    // child with siblings below
        'last'       => '└─',    // last child (no siblings below)
        'vertical'   => '│ ',    // continuation from parent
        'blank'      => '  ',    // empty indentation
        'collapsed'  => '▸ ',    // expand indicator (has hidden children)
        'expanded'   => '▾ ',    // collapse indicator (children visible)
        'leaf'       => '  ',    // no children indicator
    ];

    private TreeState $state;

    /** @var (callable(TreeNode): void)|null Callback when a node is selected (Enter on leaf) */
    private $onSelectCallback = null;

    /** @var (callable(TreeNode): void)|null Callback when expand/collapse changes */
    private $onToggleCallback = null;

    /** @var (callable(): void)|null Callback when Escape is pressed */
    private $onCancelCallback = null;

    private bool $showScrollIndicator = true;

    // ── Constructor ───────────────────────────────────────────────────────

    /**
     * @param list<TreeNode> $nodes Top-level tree nodes.
     *                              Wrapped in a virtual root internally.
     */
    public function __construct(
        array $nodes = [],
        private readonly int $indentWidth = 2,
    ) {
        $root = new TreeNode(
            id: '__root__',
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
            id: '__root__',
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
     * Set whether to show a scroll indicator (e.g., "5/20") when content overflows.
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
                $this->state->ensureSelectedVisible($this->getViewportHeight());
                $this->invalidate();
            }
            return;
        }

        if ($kb->matches($data, 'down')) {
            if ($this->state->moveDown()) {
                $this->state->ensureSelectedVisible($this->getViewportHeight());
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
                $this->state->ensureSelectedVisible($this->getViewportHeight());
                $this->invalidate();
            }
            return;
        }

        if ($kb->matches($data, 'page_up')) {
            if ($this->state->pageUp($this->getViewportHeight())) {
                $this->state->ensureSelectedVisible($this->getViewportHeight());
                $this->invalidate();
            }
            return;
        }

        if ($kb->matches($data, 'page_down')) {
            if ($this->state->pageDown($this->getViewportHeight())) {
                $this->state->ensureSelectedVisible($this->getViewportHeight());
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
            'up'      => [Key::UP],
            'down'    => [Key::DOWN],
            'left'    => [Key::LEFT],
            'right'   => [Key::RIGHT],
            'confirm' => [Key::ENTER],
            'toggle'  => ['space'],
            'home'    => [Key::HOME, 'g'],
            'end'     => [Key::END, 'G'],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'cancel'  => [Key::ESCAPE],
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
        $selectedBg = Theme::rgb(40, 40, 60);

        $lines = [];
        foreach ($window as $i => $item) {
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
            $indicator = "{$dim}({$pos}-" . min($offset + $windowSize, $visibleCount) . "/{$visibleCount}){$reset}";
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

        // Otherwise, move to parent (find parent of selected in visible items)
        $visible = $this->state->getVisibleItems();
        $selectedIdx = null;
        foreach ($visible as $i => $item) {
            if ($item->node->id === $node->id) {
                $selectedIdx = $i;
                break;
            }
        }
        if ($selectedIdx === null || $selectedIdx === 0) {
            return;
        }

        // Walk backwards to find the nearest item with lower depth (parent)
        $targetDepth = $visible[$selectedIdx]->depth - 1;
        for ($i = $selectedIdx - 1; $i >= 0; $i--) {
            if ($visible[$i]->depth === $targetDepth) {
                $this->state->setSelectedId($visible[$i]->node->id);
                $this->state->ensureSelectedVisible($this->getViewportHeight());
                $this->invalidate();
                return;
            }
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
        if ($children !== []) {
            // Replace the node in the tree root's children
            $this->replaceNode($this->state->getRoot(), $node->id, $node->withChildren($children));
        }
    }

    /**
     * Recursively find and replace a node in the tree.
     */
    private function replaceNode(TreeNode $parent, string $targetId, TreeNode $replacement): bool
    {
        foreach ($parent->children as $i => $child) {
            if ($child->id === $targetId) {
                // PHP arrays on readonly properties: we need to rebuild.
                // Since TreeNode is immutable, we rebuild the parent.
                // This is handled at the TreeState level.
                return true;
            }
            if ($this->replaceNode($child, $targetId, $replacement)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render a single visible item into a styled terminal line.
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
        $depth = $item->depth;

        // Build indentation and connectors
        $indent = '';
        for ($d = 0; $d < $depth; $d++) {
            $indent .= str_repeat(self::CONNECTORS['blank'], 1);
        }

        // Expand/collapse indicator
        if ($node->hasChildren()) {
            $indicator = $item->isExpanded ? self::CONNECTORS['expanded'] : self::CONNECTORS['collapsed'];
        } else {
            $indicator = self::CONNECTORS['leaf'];
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

        $content = "{$dim}{$indent}{$reset}{$indicator}{$iconPart}{$label}{$detailPart}";

        // Truncate to maxWidth (accounting for ANSI codes)
        $content = $this->truncateToWidth($content, $maxWidth, $reset);

        // Apply selection highlight
        if ($isSelected && $this->isFocused()) {
            $content = "{$selectedBg}{$content}{$reset}";
        }

        return $content;
    }

    /**
     * Truncate a string to a visual width, preserving ANSI escape sequences.
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

    /**
     * Estimate viewport height from the last render context.
     * Falls back to 20 if no context is available.
     */
    private function getViewportHeight(): int
    {
        // During render(), we have the context. Between renders, use a default.
        // This is a simplification; with reactive state, this would be a signal.
        return 20;
    }
}
```

---

## 5. Stylesheet Entries

Add to `src/UI/Tui/KosmokratorStyleSheet.php`:

```php
// TreeWidget base
TreeWidget::class => new Style(
    color: Color::hex('#c0c0c0'),
),

// Selected row (focused)
TreeWidget::class . '::selected' => new Style(
    background: Color::hex('#282840'),
),

// Tree connectors (├ └ │)
TreeWidget::class . '::connector' => new Style(
    color: Color::hex('#555555'),
),

// Expand/collapse indicator (▸ ▾)
TreeWidget::class . '::expand-ind' => new Style(
    color: Color::hex('#888888'),
),
```

---

## 6. Connector Rendering — Detailed

The tree uses two types of visual elements per line:

### 6.1 Indentation Prefix

Each depth level contributes 2 characters of indentation. For a node at depth 3:

```
│     ├─  node-label
│     │
│     └─  depth=0 (continuation from grandparent)
│
└─ depth=1 (continuation from parent)
```

The exact algorithm for building the prefix for each visible item:

```
For each visible item at depth D:
  prefix = ""
  for level 0 to D-1:
    if ancestor at this level has siblings below it:
      prefix += "│ "  (vertical continuation)
    else:
      prefix += "  "  (blank)

  if node has siblings below:
    prefix += "├─"  (branch)
  else:
    prefix += "└─"  (last child)
```

### 6.2 Rendering Example

Given this tree:

```
Agents (expanded)
  agent-1 (expanded)
    sub-a (leaf)
    sub-b (leaf)
  agent-2 (collapsed)
Settings (leaf)
```

Rendered output:

```
▾ Agents
│ ▾ agent-1
│ │ ▸ sub-a
│ └ ▸ sub-b
│ ▸ agent-2
▸ Settings
```

With the full approach tracking ancestor-sibling status, the prefix computation needs to know whether each ancestor has more siblings below it in the visible list. This is computed during the flatten pass.

**Updated `VisibleItem` to carry sibling info:**

```php
final class VisibleItem
{
    public function __construct(
        public readonly TreeNode $node,
        public readonly int $depth,
        public readonly bool $isExpanded,
        /** @var list<bool> For each depth level 0..depth-1, true if ancestor has more siblings */
        public readonly array $ancestorHasMore = [],
        /** Whether this node has more siblings below it in the visible list */
        public readonly bool $hasMoreSiblings = false,
    ) {}
}
```

The flatten method in `TreeState` populates `ancestorHasMore` and `hasMoreSiblings`:

```php
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
```

Updated rendering in `renderItem`:

```php
private function renderItem(VisibleItem $item, bool $isSelected, int $maxWidth, ...): string
{
    $node = $item->node;
    $dim = Theme::dim();
    $reset = Theme::reset();

    // Build connector prefix
    $prefix = '';
    for ($level = 0; $level < $item->depth; $level++) {
        $hasMore = $item->ancestorHasMore[$level] ?? false;
        $prefix .= $hasMore ? '│ ' : '  ';
    }

    // Node connector (├─ or └─)
    $connector = $item->hasMoreSiblings ? '├─' : '└─';

    // Expand indicator
    if ($node->hasChildren()) {
        $indicator = $item->isExpanded ? '▾ ' : '▸ ';
    } else {
        $indicator = '  ';
    }

    // ... rest of label/icon/detail rendering
    $line = "{$dim}{$prefix}{$connector}{$reset}{$indicator}{$iconPart}{$label}{$detailPart}";

    // ... selection highlight and truncation
}
```

---

## 7. Lazy Loading Flow

```
User presses → on collapsed node with loadChildren callback:

1. TreeWidget::handleRightOrConfirm()
   ├── Check: node has loadChildren?
   │   └── Yes → call ($node->loadChildren)()
   │       └── Returns list<TreeNode>
   │           └── Rebuild tree root with new children
   ├── TreeState::setExpanded(nodeId, true)
   ├── invalidate() → triggers re-render
   └── Visible items cache invalidated → next render re-flattens

Result: New children appear indented under the expanded node.
```

For the agent tree use case, the lazy callback would be:

```php
$node = new TreeNode(
    id: 'agent-1',
    label: 'agent-1',
    icon: '●',
    iconColor: Theme::agentGeneral(),
    loadChildren: function () use ($orchestrator, $agentId) {
        $children = $this->treeBuilder->buildSubtree($orchestrator, $agentId);
        return array_map(fn ($c) => new TreeNode(
            id: $c['id'],
            label: $c['task'],
            icon: match($c['status']) {
                'done' => '✓',
                'failed' => '✗',
                'running' => '●',
                default => '◌',
            },
            iconColor: match($c['status']) {
                'done' => Theme::success(),
                'failed' => Theme::error(),
                'running' => Theme::accent(),
                default => Theme::dim(),
            },
            detail: $c['elapsed'] > 0 ? $this->formatElapsed($c['elapsed']) : null,
            detailStyle: Theme::dim(),
            children: [], // Could recursively nest
        ), $children);
    },
);
```

---

## 8. Integration Points

### 8.1 Subagent Display (`SubagentDisplayManager`)

**Before** (current — `SubagentDisplayManager.php:431`):
```php
private function renderLiveTree(array $nodes): string
{
    // 80+ lines of manual ANSI tree rendering
}
```

**After**:
```php
// In showSpawn() or showRunning():
$treeNodes = $this->buildTreeNodes($entries);  // Convert to TreeNode[]

$this->treeWidget = new TreeWidget($treeNodes);
$this->treeWidget->setId('subagent-tree');
$this->treeWidget->onSelect(function (TreeNode $node) {
    // Show agent details on selection
});
$container->add($this->treeWidget);
```

Timer-based refresh calls `$this->treeWidget->setNodes($newNodes)` instead of rebuilding ANSI text.

### 8.2 File Tree (Future)

```php
$root = new TreeNode(
    id: 'src',
    label: 'src/',
    icon: '📁',
    loadChildren: function () {
        return $this->scanDirectory('src');  // Returns TreeNode[]
    },
);
$tree = new TreeWidget([$root]);
```

### 8.3 Diff File List (Future)

```php
$files = [];
foreach ($diff->getFiles() as $file) {
    $files[] = new TreeNode(
        id: $file->path,
        label: $file->path,
        icon: match($file->status) {
            'added' => '+',
            'modified' => '~',
            'deleted' => '-',
        },
        iconColor: match($file->status) {
            'added' => Theme::success(),
            'modified' => Theme::warning(),
            'deleted' => Theme::error(),
        },
        detail: "+{$file->added} -{$file->removed}",
        loadChildren: fn() => $this->buildHunkNodes($file),
    );
}
$tree = new TreeWidget($files);
```

---

## 9. File Structure

```
src/UI/Tui/Widget/
├── Tree/
│   ├── TreeNode.php        # Immutable data model for a tree node
│   ├── TreeState.php       # Mutable interaction state (selection, expand, scroll)
│   └── VisibleItem.php     # Flattened visible-item entry (node + depth + connectors)
└── TreeWidget.php          # The widget (rendering + keyboard input)

src/UI/Tui/KosmokratorStyleSheet.php    # Add ::selected, ::connector, ::expand-ind rules

tests/Unit/UI/Tui/Widget/Tree/
├── TreeNodeTest.php        # Node construction, withChildren(), hasChildren()
├── TreeStateTest.php       # Navigation, expand/collapse, scroll, flatten
└── TreeWidgetTest.php      # Render output, ANSI styling, scroll window
```

---

## 10. Test Plan

### 10.1 `TreeNodeTest`

| Test | Assertion |
|------|-----------|
| Basic construction | `id`, `label`, `icon` stored correctly |
| `hasChildren()` with children | Returns `true` |
| `hasChildren()` with loadChildren callback | Returns `true` |
| `hasChildren()` leaf | Returns `false` |
| `isChildrenLoaded()` with pre-populated | Returns `true` |
| `isChildrenLoaded()` with callback | Returns `false` |
| `withChildren()` | Returns new instance with children, loadChildren=null, expanded=true |
| `withChildren()` immutability | Original node unchanged |

### 10.2 `TreeStateTest`

| Test | Input | Expected |
|------|-------|----------|
| Empty tree | Root with no children | `getVisibleItems() = []`, `getSelectedId() = null` |
| Single node | One child | `getVisibleCount() = 1`, `getSelectedId() = child.id` |
| Expand/collapse | Toggle expanded on parent | Children appear/disappear in visible list |
| Move up/down | 3 siblings, select middle | Up → first, Down → last |
| Move up at top | Select first item | Returns `false`, selection unchanged |
| Move down at bottom | Select last item | Returns `false`, selection unchanged |
| Move to first/last | 5 items, select middle | `moveToFirst()` → index 0, `moveToLast()` → index 4 |
| Page up/down | 20 items, 8-row viewport | `pageUp(8)` moves 7 items up |
| Scroll window | 20 items, viewport 5, select index 15 | `ensureSelectedVisible(5)` sets offset to 11 |
| Scroll clamping | 3 items, viewport 10 | `ensureSelectedVisible(10)` → offset stays 0 |
| `setRoot()` preserves selection | Replace root, keep same IDs | Selected ID preserved |
| `setRoot()` resets missing | Replace root, remove selected ID | Selected ID → null, reverts to first |
| Nested expand | Parent expanded, child collapsed | Only parent's direct children visible |
| Deep navigation | 3 levels, all expanded | Up/down traverses through all depths |
| Left collapses | Expanded node, press left | Node collapsed, selection stays |
| Left moves to parent | Collapsed leaf node, press left | Selection moves to parent |

### 10.3 `TreeWidgetTest`

| Test | Assertion |
|------|-----------|
| Empty tree renders nothing | `render() = []` |
| Single node renders one line | `count(render()) = 1` |
| Selected node has highlight | Selected line contains `selectedBg` ANSI code |
| Unselected node has no highlight | Non-selected lines lack `selectedBg` |
| Collapsed node shows ▸ | Line contains `▸` |
| Expanded node shows ▾ | Line contains `▾` |
| Leaf node has no indicator | No `▸` or `▾` on leaf lines |
| Connectors render correctly | `├─` for middle siblings, `└─` for last |
| Vertical continuation | Expanded parent with sibling below shows `│` |
| Scroll indicator | Content overflows → last line contains `(n/m)` |
| No scroll indicator | Content fits → no indicator |
| Truncation | Long labels truncated to viewport width |
| Lazy load on expand | loadChildren callback called, children appear |
| Focus/unfocus toggle | `setFocused(false)` → no selection highlight |

---

## 11. Edge Cases & Design Decisions

### 11.1 Node ID Uniqueness

Node IDs must be unique across the entire tree, not just among siblings. This simplifies the expanded/selected sets and avoids collisions. The `TreeState::rebuildIndex()` method enforces this — if duplicates exist, later nodes overwrite earlier ones in the index.

**Decision**: Document this requirement. Add an assertion in debug mode.

### 11.2 Immutable TreeNodes with Lazy Loading

`TreeNode` is immutable, but lazy loading requires replacing a node with one that has children. The solution is `TreeNode::withChildren()` which returns a new instance. The tree root must be rebuilt when a node is replaced.

**Simplified approach**: Store the tree as a mutable structure internally in `TreeState` (convert `TreeNode` to a mutable internal representation) or accept that `setRoot()` with the updated tree is called after lazy loading.

**Chosen approach**: `TreeState` holds a reference to the root `TreeNode`. When lazy loading occurs, `TreeWidget` rebuilds the root by walking the tree and replacing the target node. This is O(n) but happens only on user interaction (expand), so it's acceptable.

### 11.3 Scroll vs Focus

The `getViewportHeight()` method is called during `handleInput()` but the widget only knows its allocated height during `render()`. Two solutions:

1. **Cache the last render context height** — store `$lastHeight` in `render()` and use it in `handleInput()`
2. **Defer scroll adjustment to render time** — only adjust offset during `render()`

**Chosen approach**: Cache the last allocated height from `render()` in a private field. This is the same pattern used by other widgets.

### 11.4 Very Deep Trees

With 10+ levels of nesting, indentation can consume most of the line width. Options:

- **Max depth display** — collapse beyond depth N with a "…" indicator
- **Adaptive indentation** — reduce indent width at deeper levels
- **Horizontal scroll** — allow scrolling the tree horizontally (complex)

**Chosen approach for v1**: Use fixed 2-char indentation per level. If the rendered content exceeds line width, it's truncated. Deeper nesting support is a future enhancement.

### 11.5 Empty Tree

When no nodes are provided, the widget renders nothing (`[]`). The caller can add a "No items" message by wrapping `TreeWidget` in a `ContainerWidget` with a `TextWidget` fallback.

### 11.6 Selection Persistence Across Data Refresh

When `setNodes()` is called with updated data (e.g., agent statuses change), `TreeState::setRoot()` preserves the selected ID and expanded set. If the selected node no longer exists, selection falls back to the first visible item.

---

## 12. Migration Strategy

### Phase 1: Widget Implementation
1. Create `src/UI/Tui/Widget/Tree/` directory with `TreeNode`, `VisibleItem`, `TreeState`
2. Create `TreeWidget.php` with render and input handling
3. Add stylesheet entries to `KosmokratorStyleSheet.php`
4. Write unit tests for all three classes

### Phase 2: Subagent Display Migration
1. Add `buildTreeNodes(array $agentTree): array` to `SubagentDisplayManager`
2. Replace `renderLiveTree()` and `renderTreeNodes()` with `TreeWidget` usage
3. Keep the timer-based refresh but call `setNodes()` instead of `setText()`
4. The tree becomes interactive — focus can be given to it during agent execution

### Phase 3: Future Use Cases
1. File tree for project browser
2. Diff file list for PR review
3. Settings category navigation (replace flat list)

---

## 13. Open Questions

1. **Mutable tree nodes?** — Should we use mutable nodes internally for easier lazy-load replacement, keeping `TreeNode` as a public immutable API? This avoids O(n) tree walks on each lazy load.
2. **Multi-select?** — Some use cases (diff file list) might benefit from checkbox-style multi-selection. Not needed for v1.
3. **Search/filter** — Should the tree widget support a built-in filter (like `SelectListWidget::setFilter()`)? Or should filtering be external (rebuild the tree with filtered data)?
4. **Mouse support** — Click-to-select and click-to-expand depend on `05-mouse-support`. The widget should be designed to support it later without major refactoring.
5. **Animation** — Smooth expand/collapse animation (children slide in/out) depends on `08-animation`. The current design renders the full visible state each frame, which is animation-ready.
