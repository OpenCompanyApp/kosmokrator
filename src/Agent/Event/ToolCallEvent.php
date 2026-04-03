<?php

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched just before a tool is invoked by the agent loop.
 * See ToolResultEvent for the outcome after execution.
 */
class ToolCallEvent
{
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
    ) {}
}
