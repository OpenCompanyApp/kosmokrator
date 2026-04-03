<?php

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched when the LLM enters an extended-thinking phase.
 * Identifies which model and provider is processing, for UI feedback.
 */
class ThinkingEvent
{
    public function __construct(
        public readonly string $model,
        public readonly string $provider,
    ) {}
}
