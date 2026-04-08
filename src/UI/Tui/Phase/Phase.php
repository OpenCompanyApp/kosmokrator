<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Phase;

/**
 * Formal TUI phase. Extends the agent lifecycle with Compacting,
 * which was previously handled outside the phase enum.
 *
 * Transition graph:
 *
 *   Idle ──think──→ Thinking ──execute──→ Tools ──settle──→ Idle
 *     │               │                                         │
 *     │               └──cancel──→ Idle                         │
 *     │                                                    │
 *     └──compact──→ Compacting ──compactDone──→ Idle
 */
enum Phase: string
{
    case Idle = 'idle';
    case Thinking = 'thinking';
    case Tools = 'tools';
    case Compacting = 'compacting';
}
