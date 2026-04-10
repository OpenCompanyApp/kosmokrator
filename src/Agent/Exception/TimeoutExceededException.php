<?php

declare(strict_types=1);

namespace Kosmokrator\Agent\Exception;

/**
 * Thrown when a headless agent run exceeds the configured timeout in seconds.
 */
final class TimeoutExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $timeoutSeconds,
        public readonly string $partialResult,
    ) {
        parent::__construct("Agent timed out after {$timeoutSeconds} seconds.");
    }
}
