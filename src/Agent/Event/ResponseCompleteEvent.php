<?php

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched when the LLM finishes a complete (non-streamed) response.
 * Carries the full text and token usage. See StreamChunkEvent for streamed output.
 */
class ResponseCompleteEvent
{
    public function __construct(
        public readonly string $text,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
    ) {}
}
