<?php

declare(strict_types=1);

namespace Kosmokrator\Agent\Listener;

use Kosmokrator\Agent\Event\LlmResponseReceived;

/**
 * Accumulates session-wide token totals from LlmResponseReceived events.
 *
 * Registered as a singleton so external code (analytics, dashboards, webhooks)
 * can read running totals without coupling to AgentLoop internals.
 */
class TokenTrackingListener
{
    private int $totalIn = 0;

    private int $totalOut = 0;

    private int $totalCacheRead = 0;

    private int $totalCacheWrite = 0;

    public function handle(LlmResponseReceived $event): void
    {
        $this->totalIn += $event->promptTokens;
        $this->totalOut += $event->completionTokens;
        $this->totalCacheRead += $event->cacheReadTokens;
        $this->totalCacheWrite += $event->cacheWriteTokens;
    }

    public function getTotalIn(): int
    {
        return $this->totalIn;
    }

    public function getTotalOut(): int
    {
        return $this->totalOut;
    }

    public function getTotalCacheRead(): int
    {
        return $this->totalCacheRead;
    }

    public function getTotalCacheWrite(): int
    {
        return $this->totalCacheWrite;
    }

    public function reset(): void
    {
        $this->totalIn = 0;
        $this->totalOut = 0;
        $this->totalCacheRead = 0;
        $this->totalCacheWrite = 0;
    }
}
