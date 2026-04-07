<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Terminal;

/**
 * Mouse button identifier.
 *
 * Encoded in the low 2 bits of the SGR button code:
 *   - 0 = Left, 1 = Middle, 2 = Right
 *   - None is used for release and scroll events.
 *
 * @see docs/plans/tui-overhaul/05-mouse-support/01-mouse-tracking.md
 */
enum MouseButton: int
{
    /** No button (release events, scroll events). */
    case None = 0;

    /** Primary / left button. */
    case Left = 1;

    /** Middle button (scroll wheel click). */
    case Middle = 2;

    /** Secondary / right button. */
    case Right = 3;
}
