<?php

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched after a tool finishes execution within the agent loop.
 * Complements ToolCallEvent which fires before execution.
 */
class ToolResultEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $output,
        public readonly bool $success,
    ) {}
}
