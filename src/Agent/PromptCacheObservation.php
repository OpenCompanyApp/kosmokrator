<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

readonly class PromptCacheObservation
{
    public function __construct(
        public int $round,
        public string $provider,
        public string $model,
        public string $stableHash,
        public string $volatileHash,
        public string $toolsHash,
        public string $messagesHash,
        public int $promptTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public ?string $dropCause = null,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'round' => $this->round,
            'provider' => $this->provider,
            'model' => $this->model,
            'stable_hash' => $this->stableHash,
            'volatile_hash' => $this->volatileHash,
            'tools_hash' => $this->toolsHash,
            'messages_hash' => $this->messagesHash,
            'prompt_tokens' => $this->promptTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'drop_cause' => $this->dropCause,
        ];
    }
}
