<?php

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched for each text chunk received during a streamed LLM response.
 * See ResponseCompleteEvent for the final aggregated result.
 */
class StreamChunkEvent
{
    public function __construct(
        public readonly string $text,
    ) {}
}
