<?php

namespace Kosmokrator\Agent\Event;

class ResponseCompleteEvent
{
    public function __construct(
        public readonly string $text,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
    ) {}
}
