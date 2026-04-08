<?php

declare(strict_types=1);

namespace Kosmokrator\Agent\Exception;

/**
 * Thrown when a headless agent run exceeds the configured maximum number of agentic turns.
 */
final class MaxTurnsExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $maxTurns,
        public readonly string $partialResult,
    ) {
        parent::__construct("Agent exceeded maximum of {$maxTurns} turns without completing.");
    }
}
