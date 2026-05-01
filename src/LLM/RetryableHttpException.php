<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

/**
 * Thrown by AsyncLlmClient for retryable HTTP errors (429, 5xx).
 *
 * Carries the HTTP status code and an optional retry-after hint
 * parsed from response headers, so RetryableLlmClient can honor
 * provider-specified backoff durations.
 */
class RetryableHttpException extends \Exception
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
        public readonly ?float $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
