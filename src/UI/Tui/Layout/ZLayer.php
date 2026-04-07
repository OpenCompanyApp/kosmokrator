<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Layout;

use Symfony\Component\Tui\Render\Layer;

/**
 * A compositing layer with Z-ordering, position, transparency, and dirty tracking.
 *
 * Each layer is identified by a unique string ID and carries:
 * - ANSI content lines (the rendered output of a widget or region)
 * - Absolute position (row, col) on the terminal canvas
 * - Z-index for depth ordering (higher = rendered on top)
 * - Transparency flag (whether unstyled cells let lower layers show through)
 * - A revision counter that increments on every mutation
 *
 * Standard Z-index conventions (defined as constants on ZCompositor):
 *
 *   0   = Base content (conversation, task-bar, input, status bar)
 *   10  = Inline overlays (inline picker, context menus)
 *   40  = Dropdowns (slash completion, command palette)
 *   50  = Floating indicators ("new messages" pill, progress indicators)
 *   70  = Side panels (agent detail sidebar, help overlay)
 *   90  = Toasts (transient notifications)
 *   100 = Modals (permission prompt, plan approval, question dialog)
 *   110 = Modal stack (nested dialogs on top of modals)
 *   200 = System (terminal resize warning, crash notification)
 */
final class ZLayer
{
    /**
     * Monotonically increasing revision counter.
     *
     * Bumped on every mutation (content, position, Z-index, transparency,
     * dimensions) so the compositor can skip recomposite when nothing changed.
     */
    private int $revision = 0;

    /**
     * @param string   $id          Unique identifier for layer lookup
     * @param string[] $lines       ANSI-formatted content lines
     * @param int      $z           Z-index for depth ordering (higher = on top)
     * @param int      $row         Vertical offset (0-based terminal row)
     * @param int      $col         Horizontal offset (0-based terminal column)
     * @param bool     $transparent When true, cells with default background
     *                              preserve the background from layers below
     * @param int|null $width       Explicit width override; null = auto-detect
     * @param int|null $height      Explicit height override; null = count($lines)
     */
    public function __construct(
        private readonly string $id,
        private array $lines,
        private int $z = 0,
        private int $row = 0,
        private int $col = 0,
        private bool $transparent = true,
        private ?int $width = null,
        private ?int $height = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string[] ANSI-formatted content lines
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getZ(): int
    {
        return $this->z;
    }

    public function getRow(): int
    {
        return $this->row;
    }

    public function getCol(): int
    {
        return $this->col;
    }

    public function isTransparent(): bool
    {
        return $this->transparent;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    /**
     * Current revision number for dirty tracking.
     *
     * The compositor compares this against its last-seen revision to decide
     * whether recomposite is needed.
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Update content lines and bump the revision.
     *
     * @param string[] $lines ANSI-formatted content lines
     */
    public function updateLines(array $lines): void
    {
        $this->lines = $lines;
        ++$this->revision;
    }

    /**
     * Update absolute position and bump the revision.
     *
     * Typically driven by reactive signals (terminal resize, scroll position).
     */
    public function updatePosition(int $row, int $col): void
    {
        $this->row = $row;
        $this->col = $col;
        ++$this->revision;
    }

    /**
     * Update Z-index and bump the revision.
     *
     * Changing Z triggers a re-sort in the compositor.
     */
    public function updateZ(int $z): void
    {
        $this->z = $z;
        ++$this->revision;
    }

    /**
     * Set transparency mode and bump the revision.
     *
     * When transparent, cells with no explicit background preserve the
     * layer below. Fully unstyled spaces are completely transparent.
     */
    public function setTransparent(bool $transparent): void
    {
        $this->transparent = $transparent;
        ++$this->revision;
    }

    /**
     * Set explicit dimensions and bump the revision.
     */
    public function setDimensions(?int $width, ?int $height): void
    {
        $this->width = $width;
        $this->height = $height;
        ++$this->revision;
    }

    /**
     * Convert to a Symfony TUI Layer for use with CellBuffer compositing.
     */
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
