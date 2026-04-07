<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

/**
 * Animation playback direction.
 */
enum PlaybackDirection: string
{
    /** Normal: from → to */
    case Normal = 'normal';
    /** Reverse: to → from */
    case Reverse = 'reverse';
    /** Alternating: normal then reverse on repeat */
    case Alternate = 'alternate';
}
