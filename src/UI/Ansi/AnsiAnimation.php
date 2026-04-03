<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

/**
 * Contract for full-screen ANSI terminal animations.
 *
 * Each implementation renders a multi-phase animation sequence using raw
 * ANSI escape codes. The animate() method blocks until the animation
 * completes and is responsible for its own cursor/screen management.
 */
interface AnsiAnimation
{
    /** Play the full animation sequence. Blocks until complete. */
    public function animate(): void;
}
