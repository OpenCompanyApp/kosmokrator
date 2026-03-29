<?php

namespace Kosmokrator\Agent\Event;

class ToolCallEvent
{
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
    ) {}
}
