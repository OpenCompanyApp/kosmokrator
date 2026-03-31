<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\SubagentStats;
use PHPUnit\Framework\TestCase;

class SubagentStatsTest extends TestCase
{
    public function test_defaults(): void
    {
        $stats = new SubagentStats('test-1');
        $this->assertSame('test-1', $stats->id);
        $this->assertSame('queued', $stats->status);
        $this->assertSame(0, $stats->toolCalls);
        $this->assertSame(0, $stats->tokensIn);
        $this->assertSame(0, $stats->tokensOut);
    }

    public function test_increment_tool_calls(): void
    {
        $stats = new SubagentStats('x');
        $stats->incrementToolCalls();
        $stats->incrementToolCalls();
        $this->assertSame(2, $stats->toolCalls);
    }

    public function test_add_tokens(): void
    {
        $stats = new SubagentStats('x');
        $stats->addTokens(100, 50);
        $stats->addTokens(200, 80);
        $this->assertSame(300, $stats->tokensIn);
        $this->assertSame(130, $stats->tokensOut);
    }

    public function test_elapsed_zero_when_not_started(): void
    {
        $stats = new SubagentStats('x');
        $this->assertSame(0.0, $stats->elapsed());
    }

    public function test_elapsed_returns_duration(): void
    {
        $stats = new SubagentStats('x');
        $stats->startTime = microtime(true) - 1.5;
        $stats->endTime = $stats->startTime + 1.5;
        $this->assertEqualsWithDelta(1.5, $stats->elapsed(), 0.01);
    }

    public function test_parent_id_defaults_to_null(): void
    {
        $stats = new SubagentStats('x');
        $this->assertNull($stats->parentId);
    }

    public function test_depth_defaults_to_zero(): void
    {
        $stats = new SubagentStats('x');
        $this->assertSame(0, $stats->depth);
    }

    public function test_parent_id_and_depth_are_settable(): void
    {
        $stats = new SubagentStats('child-1');
        $stats->parentId = 'parent-1';
        $stats->depth = 2;
        $this->assertSame('parent-1', $stats->parentId);
        $this->assertSame(2, $stats->depth);
    }
}
