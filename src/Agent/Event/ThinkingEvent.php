<?php

namespace Kosmokrator\Agent\Event;

class ThinkingEvent
{
    public function __construct(
        public readonly string $model,
        public readonly string $provider,
    ) {}
}
