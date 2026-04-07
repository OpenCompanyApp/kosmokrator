<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

/**
 * What happens after an animation completes.
 *
 * Mirrors CSS animation-fill-mode semantics.
 */
enum FillMode: string
{
    /** Reset to initial value after completion */
    case None = 'none';
    /** Hold the final (to) value after completion */
    case Forwards = 'forwards';
    /** Apply the (from) value before the animation starts during delay */
    case Backwards = 'backwards';
    /** Both forwards and backwards */
    case Both = 'both';
}
