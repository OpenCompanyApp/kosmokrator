<?php

namespace Kosmokrator\Agent\Event;

class ToolResultEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $output,
        public readonly bool $success,
    ) {}
}
