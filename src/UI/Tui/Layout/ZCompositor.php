<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Layout;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\CellBuffer;

/**
 * Composites multiple Z-layers into a single screen buffer.
 *
 * Layers are sorted by Z-index (ascending) and composited in order using
 * CellBuffer. Higher-Z layers overwrite lower-Z cells. Transparent layers
 * preserve the background from layers below.
 *
 * ## Usage
 *
 *     $compositor = new ZCompositor();
 *     $compositor->setLayer(new ZLayer('base', $baseLines, z: 0, transparent: false));
 *     $compositor->setLayer(new ZLayer('modal', $modalLines, z: 100, row: 5, col: 10));
 *
 *     if ($compositor->isDirty()) {
 *         $output = $compositor->composite($cols, $rows);
 *         $screenWriter->writeLines($output);
 *     }
 *
 * ## Dirty tracking
 *
 * Each ZLayer carries a monotonically increasing revision counter. The
 * compositor records the last-seen revision after each composite. If no
 * layer's revision has changed (and dimensions are unchanged), isDirty()
 * returns false and the caller can skip the output entirely.
 *
 * ## Backdrop dim effect
 *
 * For modal dialogs, create a full-screen layer at Z=99 (just below the
 * modal at Z=100) using createBackdropLayer(). This renders dimmed spaces
 * that darken everything underneath.
 */
final class ZCompositor
{
    /**
     * Standard Z-index constants for KosmoKrator UI layers.
     *
     * These are provided as named constants for discoverability and
     * consistency. Callers may use any integer — these are conventions,
     * not constraints.
     */
    public const Z_BASE = 0;
    public const Z_INLINE = 10;
    public const Z_DROPDOWN = 40;
    public const Z_PILL = 50;
    public const Z_SIDE = 70;
    public const Z_TOAST = 90;
    public const Z_MODAL = 100;
    public const Z_STACKED = 110;
    public const Z_SYSTEM = 200;

    /** @var array<string, ZLayer> Indexed by ID for O(1) lookup */
    private array $layers = [];

    /**
     * Insertion-order index for stable sorting at same Z.
     * Maps layer ID → insertion sequence number.
     *
     * @var array<string, int>
     */
    private array $insertionOrder = [];

    /** Monotonically increasing insertion counter */
    private int $insertionCounter = 0;

    /** @var array<string, int> Last-seen revision per layer ID */
    private array $lastRevisions = [];

    /** Whether the layer order has changed since last composite */
    private bool $orderDirty = true;

    /**
     * Sorted layer IDs (by Z ascending, then insertion order).
     *
     * @var string[]
     */
    private array $sortedIds = [];

    /** Cached canvas width from last composite */
    private int $cachedWidth = 0;

    /** Cached canvas height from last composite */
    private int $cachedHeight = 0;

    /**
     * Add or replace a layer in the compositor.
     *
     * If a layer with the same ID already exists, it is replaced.
     * Replacement preserves the original insertion order for stable sorting.
     * If the Z-index changed (or the layer is new), orderDirty is set.
     */
    public function setLayer(ZLayer $layer): void
    {
        $id = $layer->getId();
        $isNew = !isset($this->layers[$id]);

        if ($isNew) {
            $this->insertionOrder[$id] = $this->insertionCounter++;
        }

        // Check if Z changed (only when replacing)
        if (!$isNew && $this->layers[$id]->getZ() !== $layer->getZ()) {
            $this->orderDirty = true;
        }

        $this->layers[$id] = $layer;

        if ($isNew) {
            $this->orderDirty = true;
        }
    }

    /**
     * Remove a layer by ID.
     *
     * The layer's insertion-order slot is released. orderDirty is set so
     * the sort is recalculated on the next composite.
     */
    public function removeLayer(string $id): void
    {
        if (!isset($this->layers[$id])) {
            return;
        }

        unset($this->layers[$id]);
        unset($this->lastRevisions[$id]);
        unset($this->insertionOrder[$id]);
        $this->orderDirty = true;
    }

    /**
     * Retrieve a layer by ID, or null if not present.
     */
    public function getLayer(string $id): ?ZLayer
    {
        return $this->layers[$id] ?? null;
    }

    /**
     * Check whether any layer (or the layer order) has changed since the
     * last composite call.
     *
     * Also returns true if dimensions changed (caller should compare
     * against cached values or use compositeIfNeeded()).
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
     * Composite all layers into final ANSI output lines if anything changed.
     *
     * Returns null when nothing is dirty and dimensions match the last
     * composite, allowing the caller to skip the ScreenWriter update.
     *
     * @param int $width  Canvas width (terminal columns)
     * @param int $height Canvas height (terminal rows)
     *
     * @return string[]|null ANSI-formatted lines, or null if unchanged
     */
    public function compositeIfNeeded(int $width, int $height): ?array
    {
        if (!$this->isDirty() && $width === $this->cachedWidth && $height === $this->cachedHeight) {
            return null;
        }

        return $this->composite($width, $height);
    }

    /**
     * Composite all layers into final ANSI output lines.
     *
     * Always performs a full composite regardless of dirty state. Use
     * compositeIfNeeded() for the optimized path.
     *
     * @param int $width  Canvas width (terminal columns)
     * @param int $height Canvas height (terminal rows)
     *
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
     * Hit-test: find the topmost layer at the given screen coordinates.
     *
     * Iterates from highest Z to lowest, returning the first layer whose
     * bounding rectangle contains (row, col). Returns null if no layer
     * occupies that cell.
     *
     * Useful for routing mouse/keyboard input to the correct layer.
     *
     * @return string|null Layer ID, or null if no layer at (row, col)
     */
    public function layerAt(int $row, int $col): ?string
    {
        if ([] === $this->sortedIds) {
            if ($this->orderDirty) {
                $this->sortLayers();
            }
        }

        // Iterate in reverse Z order (highest first)
        for ($i = \count($this->sortedIds) - 1; $i >= 0; --$i) {
            $id = $this->sortedIds[$i];
            $layer = $this->layers[$id];

            $layerRow = $layer->getRow();
            $layerCol = $layer->getCol();
            $layerHeight = $layer->getHeight() ?? \count($layer->getLines());

            if ($row < $layerRow || $row >= $layerRow + $layerHeight) {
                continue;
            }

            if ($col < $layerCol) {
                continue;
            }

            // Check column bounds: if width is known, use it; otherwise
            // estimate from the actual line content at that row offset
            $lineIndex = $row - $layerRow;
            $lines = $layer->getLines();
            $layerWidth = $layer->getWidth();
            if (null === $layerWidth && isset($lines[$lineIndex])) {
                $layerWidth = AnsiUtils::visibleWidth($lines[$lineIndex]);
            }

            if (null !== $layerWidth && $col >= $layerCol + $layerWidth) {
                continue;
            }

            return $id;
        }

        return null;
    }

    /**
     * Get all layers sorted by Z (lowest first), preserving insertion
     * order for layers at the same Z.
     *
     * @return ZLayer[]
     */
    public function getLayersByZ(): array
    {
        if ($this->orderDirty) {
            $this->sortLayers();
        }

        return array_map(fn (string $id): ZLayer => $this->layers[$id], $this->sortedIds);
    }

    /**
     * Determine which layers intersect a given screen region.
     *
     * Uses axis-aligned bounding box (AABB) intersection testing.
     * Useful for partial recomposite optimizations: only re-render the
     * layers that overlap the dirty region.
     *
     * @return string[] IDs of affected layers
     */
    public function getLayersInRegion(int $row, int $col, int $width, int $height): array
    {
        $affected = [];

        foreach ($this->layers as $id => $layer) {
            $layerLines = $layer->getLines();
            $layerRow = $layer->getRow();
            $layerCol = $layer->getCol();
            $layerHeight = $layer->getHeight() ?? \count($layerLines);
            $layerWidth = $layer->getWidth() ?? ($layerHeight > 0
                ? AnsiUtils::visibleWidth($layerLines[0])
                : 0);

            // AABB intersection test
            if ($layerRow < $row + $height
                && $layerRow + $layerHeight > $row
                && $layerCol < $col + $width
                && $layerCol + $layerWidth > $col
            ) {
                $affected[] = $id;
            }
        }

        return $affected;
    }

    /**
     * Create a backdrop dim-effect layer.
     *
     * Produces a full-screen layer of dimmed spaces that darkens all
     * content below. Typically used at Z=99 (just below a modal at Z=100).
     *
     * The dim effect uses ANSI SGR attribute 2 (dim/faint) on spaces,
     * which most terminals render as darkened text. For a more precise
     * solid backdrop, use `bgColor` to set an explicit background color
     * (e.g., `'48;2;0;0;0'` for true black).
     *
     * @param int    $width   Canvas width (terminal columns)
     * @param int    $height  Canvas height (terminal rows)
     * @param string $id      Layer ID (default: 'backdrop')
     * @param int    $z       Z-index (default: 99, just below Z_MODAL)
     * @param string $bgColor Optional ANSI background color code (e.g. '48;2;0;0;0')
     *
     * @return ZLayer A new backdrop layer ready for setLayer()
     */
    public static function createBackdropLayer(
        int $width,
        int $height,
        string $id = 'backdrop',
        int $z = 99,
        string $bgColor = '',
    ): ZLayer {
        if ('' !== $bgColor) {
            // Solid background color approach
            $line = "\x1b[{$bgColor}m" . str_repeat(' ', $width) . "\x1b[0m";
        } else {
            // Dim attribute approach — darkens whatever is below
            $line = "\x1b[2m" . str_repeat(' ', $width) . "\x1b[0m";
        }

        return new ZLayer(
            id: $id,
            lines: array_fill(0, $height, $line),
            z: $z,
            row: 0,
            col: 0,
            transparent: false,
            width: $width,
            height: $height,
        );
    }

    /**
     * Reset the compositor, removing all layers and cached state.
     */
    public function clear(): void
    {
        $this->layers = [];
        $this->insertionOrder = [];
        $this->insertionCounter = 0;
        $this->lastRevisions = [];
        $this->sortedIds = [];
        $this->orderDirty = true;
        $this->cachedWidth = 0;
        $this->cachedHeight = 0;
    }

    /**
     * Get the number of layers currently in the compositor.
     */
    public function count(): int
    {
        return \count($this->layers);
    }

    /**
     * Sort layer IDs by Z-index ascending, with insertion order as the
     * stable tiebreaker.
     */
    private function sortLayers(): void
    {
        $this->sortedIds = array_keys($this->layers);

        usort($this->sortedIds, function (string $a, string $b): int {
            $zA = $this->layers[$a]->getZ();
            $zB = $this->layers[$b]->getZ();

            if ($zA !== $zB) {
                return $zA <=> $zB;
            }

            // Same Z: stable insertion order
            return $this->insertionOrder[$a] <=> $this->insertionOrder[$b];
        });
    }
}
