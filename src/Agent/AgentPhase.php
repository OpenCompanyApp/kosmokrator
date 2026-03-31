<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

enum AgentPhase: string
{
    case Thinking = 'thinking';
    case Tools = 'tools';
    case Idle = 'idle';
}
