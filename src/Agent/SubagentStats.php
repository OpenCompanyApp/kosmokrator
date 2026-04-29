<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Mutable stats for a single subagent. Updated by the agent's loop during execution.
 * Fiber-safe: Amp uses cooperative scheduling, so no mid-statement interleaving.
 */
class SubagentStats
{
    public string $status = 'queued';

    public string $mode = 'await';

    public int $toolCalls = 0;

    public int $tokensIn = 0;

    public int $tokensOut = 0;

    public float $startTime = 0.0;

    public float $endTime = 0.0;

    public float $lastActivityTime = 0.0;

    public string $task = '';

    public string $agentType = '';

    public ?string $group = null;

    /** @var string[] */
    public array $dependsOn = [];

    public ?string $error = null;

    public ?string $parentId = null;

    public int $depth = 0;

    public int $retries = 0;

    public ?string $queueReason = null;

    public ?string $lastTool = null;

    public ?string $lastMessagePreview = null;

    public ?float $nextRetryAt = null;

    public ?string $outputRef = null;

    public int $outputBytes = 0;

    public ?string $outputPreview = null;

    public function __construct(public readonly string $id) {}

    /** Returns elapsed seconds since start, or 0 if not yet started. */
    public function elapsed(): float
    {
        if ($this->startTime === 0.0) {
            return 0.0;
        }

        $end = $this->endTime > 0.0 ? $this->endTime : microtime(true);

        return $end - $this->startTime;
    }

    public function incrementToolCalls(): void
    {
        $this->toolCalls++;
    }

    public function markQueueReason(?string $reason): void
    {
        $this->queueReason = $reason;
        $this->touchActivity();
    }

    public function markTool(string $tool): void
    {
        $this->lastTool = $tool;
        $this->touchActivity();
    }

    public function markMessagePreview(?string $preview): void
    {
        $normalized = $preview === null
            ? null
            : trim((string) preg_replace('/\s+/', ' ', $preview));

        if ($normalized === '') {
            $normalized = null;
        }

        $this->lastMessagePreview = $normalized !== null && mb_strlen($normalized) > 120
            ? mb_substr($normalized, 0, 117).'...'
            : $normalized;
        $this->touchActivity();
    }

    public function markRetryDelay(float $seconds): void
    {
        $this->nextRetryAt = microtime(true) + $seconds;
        $this->queueReason = 'retry backoff';
        $this->touchActivity();
    }

    public function clearRetryDelay(): void
    {
        $this->nextRetryAt = null;
    }

    public function clearQueueState(): void
    {
        $this->queueReason = null;
        $this->nextRetryAt = null;
    }

    public function markOutput(string $ref, int $bytes, ?string $preview): void
    {
        $this->outputRef = $ref;
        $this->outputBytes = $bytes;
        $this->outputPreview = $preview;
        $this->touchActivity();
    }

    public function addTokens(int $in, int $out): void
    {
        $this->tokensIn += $in;
        $this->tokensOut += $out;
    }

    public function touchActivity(): void
    {
        $this->lastActivityTime = microtime(true);
    }

    public function idleSeconds(): float
    {
        if ($this->lastActivityTime === 0.0) {
            return 0.0;
        }

        return microtime(true) - $this->lastActivityTime;
    }
}
