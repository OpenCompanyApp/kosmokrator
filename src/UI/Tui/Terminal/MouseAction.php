<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Terminal;

/**
 * Mouse action type — what happened with the mouse.
 *
 * Maps to the SGR-1006 event types:
 *   - M (uppercase) → Press/Drag/Scroll
 *   - m (lowercase) → Release
 *
 * @see docs/plans/tui-overhaul/05-mouse-support/01-mouse-tracking.md
 */
enum MouseAction: string
{
    /** Button pressed down. */
    case Press = 'press';

    /** Button released. */
    case Release = 'release';

    /** Motion while a button is held (drag). */
    case Drag = 'drag';

    /** Scroll wheel rotated upward (toward user). */
    case ScrollUp = 'scroll_up';

    /** Scroll wheel rotated downward (away from user). */
    case ScrollDown = 'scroll_down';
}
