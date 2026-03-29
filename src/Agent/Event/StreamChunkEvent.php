<?php

namespace Kosmokrator\Agent\Event;

class StreamChunkEvent
{
    public function __construct(
        public readonly string $text,
    ) {}
}
