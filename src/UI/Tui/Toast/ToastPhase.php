<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Toast;

/**
 * Lifecycle phase of a single toast notification.
 */
enum ToastPhase: string
{
    case Entering = 'entering';   // Slide-from-right + fade-in animation
    case Visible  = 'visible';   // Fully shown, auto-dismiss timer running
    case Exiting  = 'exiting';   // Fade-out animation
    case Done     = 'done';      // Animation complete, ready for removal
}
