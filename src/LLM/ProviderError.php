<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final class ProviderError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $model,
        public readonly bool $retryable = false,
        public readonly ?float $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
