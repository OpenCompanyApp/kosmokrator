<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * UI phase indicator: tracks whether the agent is thinking, executing tools, or idle.
 * Drives status bar / spinner rendering in the TUI. Managed by AgentLoop.
 */
enum AgentPhase: string
{
    case Thinking = 'thinking';
    case Tools = 'tools';
    case Idle = 'idle';
}
