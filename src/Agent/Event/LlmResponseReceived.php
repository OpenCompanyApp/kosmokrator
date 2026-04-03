<?php

declare(strict_types=1);

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched after each LLM chat-completion call returns successfully.
 * Carries per-request token usage and model identity for external listeners
 * (analytics, cost webhooks, etc.).
 */
readonly class LlmResponseReceived
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public string $model,
    ) {}
}
