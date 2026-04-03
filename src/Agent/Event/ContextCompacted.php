<?php

declare(strict_types=1);

namespace Kosmokrator\Agent\Event;

/**
 * Dispatched after the context manager performs a compaction pass.
 * Reports how many tokens were consumed by the compaction LLM call itself,
 * enabling cost attribution for context management overhead.
 */
readonly class ContextCompacted
{
    public function __construct(
        public int $tokensSaved,
        public int $compactionTokensIn,
        public int $compactionTokensOut,
    ) {}
}
