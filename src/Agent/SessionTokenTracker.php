<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Value object that tracks cumulative token usage across an agent session.
 * Replaces scattered token counters in AgentLoop.
 */
class SessionTokenTracker
{
    public int $tokensIn = 0;

    public int $tokensOut = 0;

    public int $cacheReadInputTokens = 0;

    public int $cacheWriteInputTokens = 0;

    public int $lastCacheReadInputTokens = 0;

    public int $lastCacheWriteInputTokens = 0;

    /** Accumulate token usage from an LLM response. */
    public function accumulate(int $tokensIn, int $tokensOut, int $cacheRead = 0, int $cacheWrite = 0): void
    {
        $this->tokensIn += $tokensIn;
        $this->tokensOut += $tokensOut;
        $this->cacheReadInputTokens += $cacheRead;
        $this->cacheWriteInputTokens += $cacheWrite;
        $this->lastCacheReadInputTokens = $cacheRead;
        $this->lastCacheWriteInputTokens = $cacheWrite;
    }

    /** Reset all counters (used by /reset when starting a new session). */
    public function reset(): void
    {
        $this->tokensIn = 0;
        $this->tokensOut = 0;
        $this->cacheReadInputTokens = 0;
        $this->cacheWriteInputTokens = 0;
        $this->lastCacheReadInputTokens = 0;
        $this->lastCacheWriteInputTokens = 0;
    }

    /** @return array{tokens_in: int, tokens_out: int, cache_read_input_tokens: int, cache_write_input_tokens: int, last_cache_read_input_tokens: int, last_cache_write_input_tokens: int} */
    public function toArray(): array
    {
        return [
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'last_cache_read_input_tokens' => $this->lastCacheReadInputTokens,
            'last_cache_write_input_tokens' => $this->lastCacheWriteInputTokens,
        ];
    }
}
