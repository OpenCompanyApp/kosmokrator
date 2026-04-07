# 06.2 — Compositor with Z-Ordering

> **Module**: `src/UI/Tui/Render/`
> **Dependencies**: Signal primitives (`01-reactive-state`), responsive layout (`06.1`)
> **Relates**: Modal dialog system (`02-widget-library/07`), toast notifications (`02-widget-library/08`), command palette (`02-widget-library/10`)
> **Replaces**: The flat `ContainerWidget` overlay in `TuiCoreRenderer`

## 1. Problem Statement

KosmoKrator's current TUI renders everything in a **single vertical flow**. The overlay container is a `ContainerWidget` appended after the conversation and before the status bar. This means:

| Issue | Detail |
|-------|--------|
| **No overlapping** | All widgets stack vertically — modals, dropdowns, and toasts cannot float over content |
| **No absolute positioning** | Widgets can only be at the next vertical position, never at arbitrary X/Y coordinates |
| **No Z-ordering** | Every widget is at the same depth — a toast cannot guarantee it renders above a modal or below a dropdown |
| **Manual overlay management** | `TuiModalManager` adds/removes widgets from a single overlay container — no layering semantics |
| **No transparency** | The overlay container is opaque; content below is invisible when the overlay has widgets |
| **No hit-testing by depth** | Mouse/keyboard input cannot be routed to the topmost layer first |

### 1.1 Current Architecture

```
TuiCoreRenderer builds:
  session (ContainerWidget, vertical)
  ├── conversation (scrollable area)
  ├── history-status (conditional)
  ├── overlay (ContainerWidget) ← flat, vertical, no positioning
  │   └── [modal widgets added/removed dynamically]
  ├── task-bar
  ├── thinking-bar
  ├── input (EditorWidget)
  └── status-bar
```

The `Renderer` renders `session` into `string[]` lines, which `ScreenWriter` diffs to terminal. The existing `Compositor` and `CellBuffer` in Symfony TUI are used for specialized widgets (e.g. Figlet) but **not for the main render pipeline**.

### 1.2 What We Need

```
Screen (CellBuffer)
├── Z=0:  Main content (conversation, task-bar, input, status)
├── Z=40: Dropdown menus (slash completion, command palette)
├── Z=50: "New messages" pill, floating indicators
├── Z=90: Toast notifications
└── Z=100: Modal dialogs (permission, plan approval, settings)
```

---

## 2. Research

### 2.1 Lip Gloss v2 (Go) — Cell-Based Compositor

Lip Gloss v2 introduced a cell-based compositing model:

```go
layer := NewLayer(content).
    X(10).       // absolute column offset
    Y(5).        // absolute row offset
    Z(100)       // Z-index (higher = on top)
```

Key design decisions in Lip Gloss:
- **Each layer is a rendered rectangle** — a `string[]` of ANSI lines with known dimensions
- **Compositing is cell-by-cell** — each cell from a higher-Z layer overwrites the cell below
- **Transparency** — cells with no explicit background are transparent, letting the layer below show through
- **The canvas is sized to the terminal** — layers can extend beyond the visible area (clipped)
- **Layers are ordered by Z, then insertion order** — stable sorting

### 2.2 Symfony TUI — Existing Compositor + CellBuffer

Symfony TUI already ships exactly the building blocks we need:

**`CellBuffer`** (`vendor/symfony/tui/src/Symfony/Component/Tui/Render/CellBuffer.php`):
- 2D grid of terminal cells using flat parallel arrays for memory efficiency
- Stores per-cell: character (grapheme), display width, fg color, bg color, attribute bitmask
- `writeAnsiLines(array $lines, int $startRow, int $startCol, bool $transparent)` — writes ANSI lines into the buffer at an offset, with optional transparency (cells with no explicit bg preserve the layer below)
- `toLines(): array` — serializes back to ANSI strings with optimized SGR output

**`Compositor`** (`vendor/symfony/tui/src/Symfony/Component/Tui/Render/Compositor.php`):
- `composite(Layer ...$layers): array` — takes N layers, merges into final output
- First layer defines canvas dimensions
- Subsequent layers are painted on top with optional transparency
- Uses `CellBuffer::writeAnsiLines()` internally

**`Layer`** (`vendor/symfony/tui/src/Symfony/Component/Tui/Render/Layer.php`):
- Holds: `$lines` (ANSI content), `$row`, `$col`, `$transparent`, `$width`, `$height`
- Already supports absolute positioning via `$row` and `$col`
- **Missing**: no `$z` parameter — layers are composited in insertion order

**`PositionTracker`** (`vendor/symfony/tui/src/Symfony/Component/Tui/Render/PositionTracker.php`):
- Tracks absolute screen positions of widgets via `WeakMap<AbstractWidget, WidgetRect>`
- Maintains a stack of `[row, col]` offsets during rendering
- Used by `LayoutEngine` and `Renderer` for hit-testing and mouse routing

### 2.3 Claude Code — Overlay Positioning

Claude Code (Anthropic's CLI) handles overlays by:
- Rendering the main conversation content as a baseline
- Overlay widgets (permission prompts, tool results) render at absolute positions calculated relative to the viewport
- The overlay replaces the bottom portion of the screen (no true Z-stacking — it overwrites)
- Input is routed to the overlay widget when active, blocking the main content

### 2.4 Pattern Summary

| Feature | Lip Gloss v2 | Symfony TUI | Claude Code | **Our Design** |
|---------|-------------|-------------|-------------|----------------|
| Cell buffer | ✅ (internal) | ✅ `CellBuffer` | ❌ | ✅ Use existing `CellBuffer` |
| Layer model | `NewLayer().X().Y().Z()` | `Layer(lines, row, col)` | Manual overwrite | ✅ `ZLayer(lines, row, col, z)` |
| Z-ordering | ✅ Z-index | ❌ Insertion order | ❌ | ✅ Sorted by Z |
| Transparency | ✅ | ✅ `transparent` flag | ❌ | ✅ Inherit from `Layer` |
| Absolute positioning | ✅ X/Y | ✅ row/col | ✅ Manual | ✅ row/col from Layer |
| Partial recomposite | ❌ | ❌ | ❌ | ✅ Dirty tracking per layer |

---

## 3. Architecture

### 3.1 Overview

The compositor sits between the `Renderer` and `ScreenWriter` in the render pipeline:

```
Current flow:
  Renderer::render(root) → string[] → ScreenWriter::writeLines()

New flow:
  1. Renderer::render(mainContent) → string[]     (Z=0 base layer)
  2. For each overlay layer:
     Renderer::render(widget) → string[]           (at X/Y/Z)
  3. ZCompositor::composite(baseLayer, ...overlayLayers) → string[]
  4. ScreenWriter::writeLines(compositedOutput)
```

This is a **post-render compositing** approach: each widget renders independently into ANSI lines, then the compositor merges them cell-by-cell.

### 3.2 Component Diagram

```
┌─────────────────────────────────────────────────┐
│                  ZCompositor                     │
│                                                  │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐      │
│  │ ZLayer   │  │ ZLayer   │  │ ZLayer   │      │
│  │ z=0      │  │ z=90     │  │ z=100    │      │
│  │ row=0    │  │ row=40   │  │ row=10   │      │
│  │ col=0    │  │ col=60   │  │ col=20   │      │
│  │ lines=… │  │ lines=… │  │ lines=… │      │
│  └──────────┘  └──────────┘  └──────────┘      │
│                                                  │
│  sorts by z → composites into CellBuffer → []lines │
└─────────────────────────────────────────────────┘
```

---

## 4. Data Structures

### 4.1 ZLayer

Extend Symfony TUI's `Layer` with a Z-index:

```php
// src/UI/Tui/Render/ZLayer.php
namespace Kosmokrator\UI\Tui\Render;

use Symfony\Component\Tui\Render\Layer;

/**
 * A compositing layer with Z-ordering.
 *
 * Extends Symfony TUI's Layer with a Z-index for depth ordering.
 * Higher Z values render on top of lower Z values.
 * Layers at the same Z are composited in insertion order.
 */
final class ZLayer
{
    private int $revision = 0;

    public function __construct(
        private readonly string $id,
        /** @var string[] ANSI-formatted content lines */
        private array $lines,
        private int $z = 0,
        private int $row = 0,
        private int $col = 0,
        private bool $transparent = true,
        private ?int $width = null,
        private ?int $height = null,
    ) {
    }

    public function getId(): string { return $this->id; }
    public function getLines(): array { return $this->lines; }
    public function getZ(): int { return $this->z; }
    public function getRow(): int { return $this->row; }
    public function getCol(): int { return $this->col; }
    public function isTransparent(): bool { return $this->transparent; }
    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function getRevision(): int { return $this->revision; }

    /** Update content and bump revision. */
    public function updateLines(array $lines): void
    {
        $this->lines = $lines;
        ++$this->revision;
    }

    /** Update position (from signals) and bump revision. */
    public function updatePosition(int $row, int $col): void
    {
        $this->row = $row;
        $this->col = $col;
        ++$this->revision;
    }

    /** Update Z-index and bump revision. */
    public function updateZ(int $z): void
    {
        $this->z = $z;
        ++$this->revision;
    }

    /** Convert to Symfony Layer for compositing. */
    public function toLayer(): Layer
    {
        return new Layer(
            $this->lines,
            $this->row,
            $this->col,
            $this->transparent,
            $this->width,
            $this->height,
        );
    }
}
```

### 4.2 ZCompositor

The main compositing engine:

```php
// src/UI/Tui/Render/ZCompositor.php
namespace Kosmokrator\UI\Tui\Render;

use Symfony\Component\Tui\Render\CellBuffer;
use Symfony\Component\Tui\Render\Layer;

/**
 * Composites multiple Z-layers into a single screen buffer.
 *
 * Layers are sorted by Z-index (ascending), then composited in order
 * using CellBuffer. Higher-Z layers overwrite lower-Z cells.
 * Transparent layers preserve background from layers below.
 */
final class ZCompositor
{
    /** @var array<string, ZLayer> Indexed by ID for O(1) lookup */
    private array $layers = [];

    /** @var array<string, int> Last-seen revision per layer ID */
    private array $lastRevisions = [];

    /** Whether the layer order has changed since last composite */
    private bool $orderDirty = true;

    /** Sorted layer IDs (by Z, then insertion order) */
    private array $sortedIds = [];

    /** Cached canvas dimensions */
    private int $cachedWidth = 0;
    private int $cachedHeight = 0;

    /**
     * Add or replace a layer.
     */
    public function setLayer(ZLayer $layer): void
    {
        $id = $layer->getId();
        $isNew = !isset($this->layers[$id]);
        $this->layers[$id] = $layer;

        if ($isNew || $this->layers[$id]->getZ() !== $layer->getZ()) {
            $this->orderDirty = true;
        }
    }

    /**
     * Remove a layer by ID.
     */
    public function removeLayer(string $id): void
    {
        if (isset($this->layers[$id])) {
            unset($this->layers[$id]);
            unset($this->lastRevisions[$id]);
            $this->orderDirty = true;
        }
    }

    /**
     * Get a layer by ID.
     */
    public function getLayer(string $id): ?ZLayer
    {
        return $this->layers[$id] ?? null;
    }

    /**
     * Composite all layers into final ANSI output lines.
     *
     * @param int $width  Canvas width (terminal columns)
     * @param int $height Canvas height (terminal rows)
     * @return string[] ANSI-formatted lines
     */
    public function composite(int $width, int $height): array
    {
        if ([] === $this->layers) {
            return array_fill(0, $height, str_repeat(' ', $width));
        }

        // Sort layers by Z (ascending) if order has changed
        if ($this->orderDirty) {
            $this->sortLayers();
            $this->orderDirty = false;
        }

        $buffer = new CellBuffer($width, $height);

        foreach ($this->sortedIds as $id) {
            $layer = $this->layers[$id];
            $buffer->writeAnsiLines(
                $layer->getLines(),
                $layer->getRow(),
                $layer->getCol(),
                $layer->isTransparent(),
            );
            $this->lastRevisions[$id] = $layer->getRevision();
        }

        $this->cachedWidth = $width;
        $this->cachedHeight = $height;

        return $buffer->toLines();
    }

    /**
     * Check if any layer has changed since the last composite.
     */
    public function isDirty(): bool
    {
        if ($this->orderDirty) {
            return true;
        }

        foreach ($this->layers as $id => $layer) {
            if (($this->lastRevisions[$id] ?? -1) !== $layer->getRevision()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine which layers are affected by a change at the given screen region.
     *
     * Used for partial recomposite optimization: only re-render layers
     * that intersect the dirty region.
     *
     * @return string[] IDs of affected layers
     */
    public function getLayersInRegion(int $row, int $col, int $width, int $height): array
    {
        $affected = [];
        foreach ($this->layers as $id => $layer) {
            $layerLines = $layer->getLines();
            $layerHeight = $layer->getHeight() ?? count($layerLines);
            $layerWidth = $layer->getWidth() ?? ($layerHeight > 0
                ? strlen($layerLines[0]) // Approximate; real code would use visibleWidth
                : 0);

            // AABB intersection test
            if ($layer->getRow() < $row + $height
                && $layer->getRow() + $layerHeight > $row
                && $layer->getCol() < $col + $width
                && $layer->getCol() + $layerWidth > $col
            ) {
                $affected[] = $id;
            }
        }
        return $affected;
    }

    /**
     * Get all layers sorted by Z (lowest first).
     *
     * @return ZLayer[]
     */
    public function getLayersByZ(): array
    {
        if ($this->orderDirty) {
            $this->sortLayers();
        }
        return array_map(fn ($id) => $this->layers[$id], $this->sortedIds);
    }

    /**
     * Hit-test: find the topmost layer at the given screen coordinates.
     *
     * Returns the layer ID or null if no layer occupies that cell.
     * Checks from highest Z to lowest, returning the first hit.
     */
    public function layerAt(int $row, int $col): ?string
    {
        // Iterate in reverse Z order (highest first)
        for ($i = count($this->sortedIds) - 1; $i >= 0; --$i) {
            $id = $this->sortedIds[$i];
            $layer = $this->layers[$id];
            $layerLines = $layer->getLines();
            $layerHeight = $layer->getHeight() ?? count($layerLines);

            if ($row >= $layer->getRow()
                && $row < $layer->getRow() + $layerHeight
                && $col >= $layer->getCol()
            ) {
                return $id;
            }
        }
        return null;
    }

    private function sortLayers(): void
    {
        $this->sortedIds = array_keys($this->layers);
        usort($this->sortedIds, function (string $a, string $b): int {
            $zA = $this->layers[$a]->getZ();
            $zB = $this->layers[$b]->getZ();
            if ($zA !== $zB) {
                return $zA <=> $zB;
            }
            // Same Z: stable insertion order (by array_keys order, which is insertion order)
            return 0;
        });
    }
}
```

---

## 5. Z-Index Conventions

Standard Z-index values for KosmoKrator UI layers:

| Z Value | Layer | Description |
|---------|-------|-------------|
| `0` | **Base content** | Main conversation, task-bar, input, status bar |
| `10` | **Inline overlays** | Inline picker (settings workspace), context menus |
| `40` | **Dropdowns** | Slash completion dropdown, command palette |
| `50` | **Floating indicators** | "New messages" pill, scroll-to-bottom arrow, progress indicators |
| `70` | **Side panels** | Agent detail sidebar (ultra-wide), help overlay |
| `90` | **Toasts** | Transient notifications (auto-dismiss) |
| `100` | **Modals** | Permission prompt, plan approval, question dialog |
| `110` | **Modal stack** | Second modal on top of first (nested dialogs) |
| `200` | **System** | Terminal resize warning, crash notification |

Layers at the same Z are composited in insertion order (first added = below, last added = above).

---

## 6. Integration with Existing Symfony TUI Pipeline

### 6.1 Current Pipeline (No Compositing)

```
Tui::processRender()
  → Renderer::render($this->root, $columns, $rows)  // Widget tree → string[]
  → ScreenWriter::writeLines($lines)                  // string[] → terminal
```

The `Renderer` walks the widget tree (containers, layout engine, chrome applier) and produces a flat array of ANSI lines. The `ScreenWriter` diffs against the previous frame and writes only changed lines to the terminal.

### 6.2 New Pipeline (With Z-Compositing)

```
TuiCoreRenderer::flushRender()
  1. Render base content (session widget tree) → $baseLines
  2. Update ZLayer 'base' with $baseLines (Z=0)
  3. For each overlay widget in ZCompositor:
     - Render widget independently → $overlayLines
     - Update ZLayer with new content/position
  4. ZCompositor::composite($cols, $rows) → $compositedLines
  5. ScreenWriter::writeLines($compositedLines)
```

### 6.3 Integration Point: TuiCoreRenderer

The change is isolated to `TuiCoreRenderer::flushRender()` and related render methods:

```php
// src/UI/Tui/TuiCoreRenderer.php (modified)

private ZCompositor $zCompositor;

public function initialize(): void
{
    // ... existing setup ...

    $this->zCompositor = new ZCompositor();

    // Base content layer (Z=0, full terminal, opaque)
    $this->zCompositor->setLayer(new ZLayer(
        id: 'base',
        lines: [],
        z: 0,
        row: 0,
        col: 0,
        transparent: false,  // Opaque: covers entire screen
    ));

    // Overlay layer (Z=100, transparent) — replaces flat ContainerWidget
    $this->zCompositor->setLayer(new ZLayer(
        id: 'modal-overlay',
        lines: [],
        z: 100,
        row: 0,
        col: 0,
        transparent: true,
    ));

    // Toast layer (Z=90, transparent)
    $this->zCompositor->setLayer(new ZLayer(
        id: 'toast',
        lines: [],
        z: 90,
        row: 0,
        col: 0,
        transparent: true,
    ));

    // ... add session to tui as before, but overlay is no longer
    // a ContainerWidget in the session tree ...
}

public function flushRender(): void
{
    $columns = $this->tui->getTerminal()->getColumns();
    $rows = $this->tui->getTerminal()->getRows();

    // 1. Render the main session (conversation + task-bar + input + status)
    //    This no longer includes the overlay container
    $baseLines = $this->renderer->render($this->session, $columns, $rows);
    $this->zCompositor->getLayer('base')->updateLines($baseLines);

    // 2. Render overlay widgets independently
    $this->renderOverlayLayers($columns, $rows);

    // 3. Composite all layers
    $composited = $this->zCompositor->composite($columns, $rows);

    // 4. Write to terminal (use Symfony TUI's ScreenWriter)
    $this->tui->writeComposited($composited);
}
```

### 6.4 Integration Point: Tui Class

Add a method to `Tui` that accepts pre-composited lines (bypassing the normal `Renderer::render()` call):

```php
// In Tui.php (or via a new ZCompositorTuiBridge)

/**
 * Write pre-composited lines directly to the ScreenWriter.
 *
 * Used when Z-compositing is active and rendering is handled
 * externally by ZCompositor rather than the standard Renderer pipeline.
 */
public function writeComposited(array $lines): void
{
    $this->screenWriter->writeLines($lines);
}
```

Alternatively, introduce a `RenderPipeline` interface:

```php
interface RenderPipeline
{
    /** @return string[] */
    public function render(int $columns, int $rows): array;
}
```

The default implementation delegates to `Renderer::render()`. The Z-composited implementation delegates to `ZCompositor::composite()`.

### 6.5 PositionTracker Compatibility

The existing `PositionTracker` tracks widget positions during `Renderer::render()`. With Z-compositing:

1. **Base layer**: Position tracking works unchanged — `Renderer::render($session)` populates `WeakMap` as before
2. **Overlay layers**: Each overlay widget is rendered independently. We need to **adjust tracked positions** by the overlay's row/col offset:

```php
// After rendering an overlay widget:
$widgetRect = $this->renderer->getWidgetRect($overlayWidget);
if ($widgetRect) {
    $this->positionTracker->setWidgetRect($overlayWidget, new WidgetRect(
        $widgetRect->getRow() + $layer->getRow(),
        $widgetRect->getCol() + $layer->getCol(),
        $widgetRect->getColumns(),
        $widgetRect->getRows(),
    ));
}
```

3. **Hit-testing**: `layerAt()` returns the topmost Z-layer at a given coordinate. The mouse handler uses this to route input to the correct widget:

```php
public function handleMouseClick(int $row, int $col): void
{
    $layerId = $this->zCompositor->layerAt($row, $col);
    if ($layerId === 'base' || $layerId === null) {
        // Route to base content widget (existing logic)
        $this->handleBaseClick($row, $col);
    } else {
        // Route to overlay widget at that position
        $layer = $this->zCompositor->getLayer($layerId);
        $relativeRow = $row - $layer->getRow();
        $relativeCol = $col - $layer->getCol();
        $this->handleOverlayClick($layerId, $relativeRow, $relativeCol);
    }
}
```

---

## 7. Signal-Driven Layer Positions

Layer positions (row, col) should be derived from reactive signals so they update automatically when dependencies change (terminal resize, scroll position, widget content size).

### 7.1 Position Signals

```php
// Example: Modal dialog centered on screen
$modalPosition = Signal::computed(function () use ($terminalCols, $terminalRows, $modalWidth, $modalHeight) {
    return [
        'row' => (int) floor(($terminalRows->get() - $modalHeight->get()) / 2),
        'col' => (int) floor(($terminalCols->get() - $modalWidth->get()) / 2),
    ];
});
```

### 7.2 ZLayer with Signal Binding

```php
// Bind ZLayer position to a computed signal
$layer = new ZLayer(
    id: 'modal',
    lines: $modalLines,
    z: 100,
    row: 0,
    col: 0,
    transparent: true,
);

// Effect: update layer position when signal changes
Effect::create(function () use ($layer, $modalPosition) {
    $pos = $modalPosition->get();
    $layer->updatePosition($pos['row'], $pos['col']);
});
```

### 7.3 Common Position Computations

| Layer Type | Position Derivation |
|-----------|-------------------|
| Modal | `center(row, col)` = `(termRows - modalRows) / 2`, `(termCols - modalCols) / 2` |
| Toast | `bottom-right(row)` = `termRows - 2 - toastIndex * 3`, `col = termCols - toastWidth - 1` |
| Dropdown | `below-input(row)` = `inputRow + inputHeight`, `col = inputCursorCol` |
| "New messages" pill | `bottom-center(row)` = `termRows - statusBarHeight - 2`, `col = (termCols - pillWidth) / 2` |
| Side panel | `right(col)` = `termCols - panelWidth`, `row = 0` |

---

## 8. Performance Optimizations

### 8.1 Dirty Tracking

Avoid full recomposite every frame:

```php
public function compositeIfNeeded(int $width, int $height): ?array
{
    if (!$this->isDirty() && $width === $this->cachedWidth && $height === $this->cachedHeight) {
        return null; // No change — caller can skip ScreenWriter update
    }
    return $this->composite($width, $height);
}
```

### 8.2 Partial Recomposite (Future Optimization)

For large screens, only recomposite the region that changed:

1. When a layer changes, compute its bounding box (row, col, width, height)
2. Find all layers that intersect that bounding box
3. Only re-composite cells within the intersection

This requires extending `CellBuffer` with a region-based compositing API:

```php
// Future: partial recomposite
$buffer->writeAnsiLinesInRegion(
    $dirtyRow, $dirtyCol, $dirtyWidth, $dirtyHeight,
    $layer->getLines(),
    $layer->getRow(),
    $layer->getCol(),
    $layer->isTransparent(),
);
```

**Phase 1**: Full recomposite (simple, correct). CellBuffer is fast enough for typical terminal sizes (200×60 = 12,000 cells).

**Phase 2**: Partial recomposite for layers that change frequently (toasts, animations) while the base content is static.

### 8.3 Layer Content Caching

Symfony TUI's `AbstractWidget` already has render caching (`getRenderCache`/`setRenderCache`). Leverage this:

1. Base content layer: cached by `Renderer` unless a widget is invalidated
2. Overlay layers: each widget caches independently
3. Only layers whose content actually changed need to be re-rendered before compositing

### 8.4 Benchmarks

Target performance for compositing at common terminal sizes:

| Terminal Size | Cells | Compositing Target |
|--------------|-------|--------------------|
| 80×24 | 1,920 | < 0.5ms |
| 120×40 | 4,800 | < 1ms |
| 200×60 | 12,000 | < 2ms |
| 300×80 | 24,000 | < 5ms |

The existing `CellBuffer` uses flat arrays (not objects) and inline ANSI parsing — it should comfortably meet these targets.

---

## 9. Use Cases — Detailed Design

### 9.1 Modal Dialog (Z=100)

```
┌────────────────────────── 80 cols ──────────────────────────┐
│ [conversation content visible behind dimmed backdrop]       │
│                                                             │
│         ┌─────── Permission Required ────────┐             │
│         │                                    │             │
│         │  file_read: /etc/hosts             │             │
│         │                                    │             │
│         │  [ Allow ]  [ Deny ]  [ Always ]  │             │
│         └────────────────────────────────────┘             │
│                                                             │
│ [status bar, input]                                         │
└─────────────────────────────────────────────────────────────┘
```

Implementation:
```php
// In TuiModalManager (modified)
public function askToolPermission(...): int
{
    // 1. Create modal content widget
    $dialog = new ModalDialog($title, $body, $buttons);

    // 2. Render to lines
    $lines = $this->renderer->renderWidget($dialog, new RenderContext(
        $dialogWidth, $dialogHeight
    ));

    // 3. Create backdrop (dim overlay covering full screen)
    $backdrop = $this->createBackdrop($columns, $rows);

    // 4. Calculate centered position
    $modalRow = (int) floor(($rows - $dialogHeight) / 2);
    $modalCol = (int) floor(($columns - $dialogWidth) / 2);

    // 5. Add layers
    $this->zCompositor->setLayer(new ZLayer('modal-backdrop', $backdrop, z: 99));
    $this->zCompositor->setLayer(new ZLayer('modal-dialog', $lines, z: 100,
        row: $modalRow, col: $modalCol, transparent: true));

    // 6. Block until resolved
    $suspension = EventLoop::getSuspension();
    // ... button handlers ...
    $result = $suspension->suspend();

    // 7. Clean up layers
    $this->zCompositor->removeLayer('modal-backdrop');
    $this->zCompositor->removeLayer('modal-dialog');

    return $result;
}
```

### 9.2 Toast Notifications (Z=90)

```
┌────────────────────────── 80 cols ──────────────────────────┐
│ [conversation content]                          ┌─────────┐ │
│                                                 │ ✓ Saved │ │
│                                                 └─────────┘ │
│ [conversation content]                                      │
│ [input]                                                     │
│ [status bar]                                                │
└─────────────────────────────────────────────────────────────┘
```

Implementation:
```php
// ToastManager manages multiple toasts as a single ZLayer
public function addToast(string $message, ToastType $type, int $durationMs = 5000): void
{
    $this->toasts[] = new Toast($message, $type, $durationMs);

    // Re-render the toast layer (all toasts stacked vertically)
    $this->updateToastLayer();
}

private function updateToastLayer(): void
{
    $cols = $this->terminal->getColumns();
    $rows = $this->terminal->getRows();

    $lines = [];
    foreach ($this->toasts as $i => $toast) {
        $toastLines = $this->renderToast($toast, $cols);
        array_push($lines, ...$toastLines);
    }

    $layer = $this->zCompositor->getLayer('toast');
    $layer->updateLines($lines);
    $layer->updatePosition(
        row: $rows - $this->statusBarHeight - count($lines) - 1,
        col: $cols - $this->maxToastWidth - 1,
    );
}
```

### 9.3 "New Messages" Pill (Z=50)

```
┌────────────────────────── 80 cols ──────────────────────────┐
│ [old conversation scrolled up]                               │
│ [old conversation scrolled up]                               │
│              ┌─── 3 new messages ↓ ───┐                     │
│              └─────────────────────────┘                     │
│ [status bar, input at bottom]                               │
└─────────────────────────────────────────────────────────────┘
```

### 9.4 Slash Completion Dropdown (Z=40)

```
┌────────────────────────── 80 cols ──────────────────────────┐
│ [conversation content]                                       │
│ > /re█                                                      │
│   ┌── completions ──┐                                      │
│   │ /read           │                                      │
│   │ /refresh        │                                      │
│   │ /reset          │                                      │
│   └─────────────────┘                                      │
│ [status bar]                                                │
└─────────────────────────────────────────────────────────────┘
```

The dropdown positions itself below the input cursor, at the cursor's column. It's transparent so content below shows through outside the dropdown box.

---

## 10. Backdrop / Dim Effect

Modals need a dimmed backdrop. Two approaches:

### 10.1 Rendered Backdrop Layer

Render a full-screen layer at Z=99 (just below the modal at Z=100) with every cell set to a dim background:

```php
private function createBackdrop(int $cols, int $rows, float $opacity = 0.5): array
{
    // Use ANSI dim attribute on spaces to create a darkened overlay
    $line = "\x1b[2m" . str_repeat(' ', $cols) . "\x1b[0m";
    return array_fill(0, $rows, $line);
}
```

The `CellBuffer` transparency system handles the rest: cells with no explicit background in the modal layer let the backdrop show through, and the backdrop's dim spaces overlay the base content.

### 10.2 Alternative: Background-Only Backdrop

Set a dark background color on every cell instead of using dim:

```php
$line = "\x1b[48;2;0;0;0m" . str_repeat(' ', $cols) . "\x1b[0m";
```

This gives more precise control over the backdrop color (true black at 100% or a custom darkened color).

---

## 11. Migration from Flat Overlay

### 11.1 Current State

```php
// TuiCoreRenderer::initialize()
$this->overlay = new ContainerWidget;
$this->session->add($this->overlay);  // Part of vertical flow

// TuiModalManager::askToolPermission()
$this->overlay->add($widget);  // Add widget to vertical flow
$this->overlay->remove($widget);  // Remove when done
```

### 11.2 Migration Steps

**Step 1**: Create `ZCompositor` in `TuiCoreRenderer::initialize()`, pre-populate base layer.

**Step 2**: Remove `$this->overlay` from the session widget tree. Overlays are no longer vertical children.

**Step 3**: Modify `TuiCoreRenderer::flushRender()` to use compositor pipeline (§6.3).

**Step 4**: Migrate `TuiModalManager` to use `ZCompositor::setLayer()` instead of `ContainerWidget::add()`.

**Step 5**: Migrate `TuiInputHandler` slash completion from inline widget to Z=40 layer.

**Step 6**: Add toast layer (Z=90) and "new messages" pill layer (Z=50).

### 11.3 Backward Compatibility

During migration, both systems can coexist:
- If `ZCompositor` has only the base layer → fall back to current `Renderer::render()` path
- Overlay container can remain as a Z=100 layer for widgets not yet migrated

---

## 12. Implementation Phases

### Phase 1: Core ZCompositor + Base Layer (4 hours)

**Goal**: Establish the compositing pipeline with a single Z=0 base layer. No visible change yet.

**New files**:
| File | Purpose |
|------|---------|
| `src/UI/Tui/Render/ZLayer.php` | Layer data structure with Z-index |
| `src/UI/Tui/Render/ZCompositor.php` | Compositing engine |

**Modified files**:
| File | Change |
|------|--------|
| `TuiCoreRenderer.php` | Create `ZCompositor`, render base through it |
| `Tui.php` (or bridge) | Add `writeComposited()` method |

**Validation**:
- All existing tests pass (behavior unchanged)
- ZCompositor with single layer produces identical output to `Renderer::render()`

### Phase 2: Modal Overlay Layer (6 hours)

**Goal**: Move modal dialogs from flat `ContainerWidget` to Z=100 layer with backdrop.

**Modified files**:
| File | Change |
|------|--------|
| `TuiCoreRenderer.php` | Remove `$this->overlay` from session tree |
| `TuiModalManager.php` | Use `ZCompositor::setLayer()` instead of `ContainerWidget::add()` |
| `TuiCoreRenderer.php::flushRender()` | Render modal widgets as Z-layers |

**New capabilities**:
- Modals appear centered, floating over content
- Backdrop dimming
- Content below is visible through transparent areas

**Validation**:
- Permission prompt, plan approval, question dialogs all work
- Visual: centered dialog, dimmed backdrop
- Input focus trapped in modal layer

### Phase 3: Toast Layer (4 hours)

**Goal**: Add Z=90 toast notification layer.

**New files**:
| File | Purpose |
|------|---------|
| `src/UI/Tui/Widget/ToastWidget.php` | Toast rendering widget |
| `src/UI/Tui/ToastManager.php` | Manages toast lifecycle, renders toast layer |

**Validation**:
- Toasts appear bottom-right, auto-dismiss
- Multiple toasts stack vertically
- Toasts render above base content, below modals

### Phase 4: Dropdown + Floating Indicators (4 hours)

**Goal**: Slash completion dropdown (Z=40), "new messages" pill (Z=50).

**Modified files**:
| File | Change |
|------|--------|
| `TuiInputHandler.php` | Slash completion as Z=40 layer positioned below cursor |
| `TuiCoreRenderer.php` | "New messages" pill as Z=50 layer |

**Validation**:
- Dropdown appears at cursor position, overlapping conversation content
- "New messages" pill floats above input, below toasts

### Phase 5: Signal Integration + Mouse Routing (3 hours)

**Goal**: Layer positions driven by signals. Mouse events routed to topmost layer.

**Modified files**:
| File | Change |
|------|--------|
| `ZLayer.php` | Signal binding helpers |
| `TuiCoreRenderer.php` | Signal-driven layer positions |
| Mouse handling code | Route clicks to topmost layer via `layerAt()` |

---

## 13. Testing Strategy

### 13.1 Unit Tests: ZCompositor

```
testCompositeEmptyLayersReturnsBlankScreen()
testCompositeSingleOpaqueLayer()
testCompositeTwoLayersHigherZWins()
testCompositeTransparentLayerPreservesBackground()
testCompositeSameZInsertionOrderWins()
testLayerAtReturnsTopmostZ()
testLayerAtReturnsNullForEmptyArea()
testIsDirtyAfterLayerUpdate()
testIsNotDirtyAfterComposite()
testRemoveLayerMarksOrderDirty()
testCompositeClipsContentBeyondCanvasBounds()
testCompositeWithNegativeRowColOffset()
```

### 13.2 Unit Tests: ZLayer

```
testUpdateLinesBumpsRevision()
testUpdatePositionBumpsRevision()
testToLayerProducesSymfonyLayer()
testTransparentFlagDefaultsTrue()
```

### 13.3 Integration Tests

```
testModalOverlaysBaseContent()
testToastOverlaysBaseButBelowModal()
testDropdownPositionedBelowCursor()
testBackdropDimsEntireScreen()
testMouseClickRoutedToTopmostLayer()
testBaseClickWhenNoOverlay()
testResizeRecompositesAllLayers()
testMultipleModalsStackByZ()
```

### 13.4 Visual Snapshot Tests

Create snapshot tests at 120×40 for:
- Base content only (Z=0)
- Modal overlay (Z=100) with backdrop (Z=99)
- Toast notification (Z=90) over base content
- Dropdown (Z=40) over base content
- Full stack: dropdown + toast + modal all visible

---

## 14. Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| `CellBuffer` performance for large terminals | Slow compositing at 300×80+ | Benchmark early; partial recomposite (Phase 2 optimization) |
| `ScreenWriter` diffing breaks with composited output | Flickering or missed updates | Composite always produces full-screen output; ScreenWriter diffs against previous composited frame |
| Widget render cache invalidation | Stale content in overlay layers | Each layer tracks its own content revision; cache cleared on widget invalidation |
| PositionTracker confusion with overlay positions | Mouse events misrouted | Adjust tracked positions by layer offset (§6.5) |
| Backward compatibility during migration | Some widgets still use flat overlay | Both systems coexist during migration (§11.3) |
| ANSI parsing overhead in `CellBuffer::writeAnsiLines()` | CPU cost per frame | Existing inline parser is already fast; profile before optimizing |

---

## 15. File Summary

### New Files

| File | Purpose |
|------|---------|
| `src/UI/Tui/Render/ZLayer.php` | Layer data structure with Z-index, position, transparency |
| `src/UI/Tui/Render/ZCompositor.php` | Compositing engine: sort by Z, merge into CellBuffer |
| `tests/UI/Tui/Render/ZCompositorTest.php` | Unit tests for compositor |
| `tests/UI/Tui/Render/ZLayerTest.php` | Unit tests for ZLayer |

### Modified Files

| File | Change |
|------|--------|
| `src/UI/Tui/TuiCoreRenderer.php` | Add `ZCompositor`, modify `flushRender()`, remove overlay from session tree |
| `src/UI/Tui/TuiModalManager.php` | Use Z-layers instead of `ContainerWidget::add()` |
| `src/UI/Tui/TuiInputHandler.php` | Slash completion as Z=40 layer |
| `vendor/symfony/tui/.../Tui.php` | Add `writeComposited()` method (or bridge class) |

### Future Files (Later Phases)

| File | Purpose |
|------|---------|
| `src/UI/Tui/ToastManager.php` | Toast lifecycle management |
| `src/UI/Tui/Widget/ToastWidget.php` | Individual toast rendering |
| `src/UI/Tui/Widget/FloatingPillWidget.php` | Reusable floating indicator widget |
