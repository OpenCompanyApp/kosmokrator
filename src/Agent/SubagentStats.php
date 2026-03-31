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

    public int $toolCalls = 0;

    public int $tokensIn = 0;

    public int $tokensOut = 0;

    public float $startTime = 0.0;

    public float $endTime = 0.0;

    public string $task = '';

    public string $agentType = '';

    public ?string $group = null;

    /** @var string[] */
    public array $dependsOn = [];

    public ?string $error = null;

    public ?string $parentId = null;

    public int $depth = 0;

    public int $retries = 0;

    public function __construct(public readonly string $id) {}

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

    public function addTokens(int $in, int $out): void
    {
        $this->tokensIn += $in;
        $this->tokensOut += $out;
    }
}
