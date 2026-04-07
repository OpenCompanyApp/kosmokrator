<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Terminal;

/**
 * Immutable value object representing a parsed mouse event.
 *
 * Coordinates are 0-indexed (converted from SGR's 1-indexed values).
 * Modifier keys (shift, alt, ctrl) are decoded from the button code bits.
 *
 * @see docs/plans/tui-overhaul/05-mouse-support/01-mouse-tracking.md
 */
final class MouseEvent
{
    public function __construct(
        public readonly MouseAction $action,
        public readonly MouseButton $button,
        public readonly int $col,
        public readonly int $row,
        public readonly bool $shift = false,
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
    ) {}

    public function getAction(): MouseAction
    {
        return $this->action;
    }

    public function getButton(): MouseButton
    {
        return $this->button;
    }

    /**
     * Column position (0-indexed).
     */
    public function getCol(): int
    {
        return $this->col;
    }

    /**
     * Row position (0-indexed).
     */
    public function getRow(): int
    {
        return $this->row;
    }

    public function isShift(): bool
    {
        return $this->shift;
    }

    public function isAlt(): bool
    {
        return $this->alt;
    }

    public function isCtrl(): bool
    {
        return $this->ctrl;
    }
}
