<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

readonly class ContextBucket
{
    public function __construct(
        public string $name,
        public int $tokens,
        public string $source = '',
        public ?int $messageIndex = null,
        public ?string $toolName = null,
        public ?string $path = null,
        public ?string $preview = null,
    ) {}

    public function percentOf(int $total): float
    {
        return $total > 0 ? round(($this->tokens / $total) * 100, 1) : 0.0;
    }
}
