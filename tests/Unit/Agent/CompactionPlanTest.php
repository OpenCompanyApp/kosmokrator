<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\CompactionPlan;
use PHPUnit\Framework\TestCase;

class CompactionPlanTest extends TestCase
{
    public function test_constructor_with_all_arguments(): void
    {
        $plan = new CompactionPlan(
            keepFromMessageIndex: 5,
            compactedMessageCount: 10,
            summary: 'A summary of the conversation',
            replacementMessages: [['role' => 'system', 'content' => 'summary']],
            protectedMessages: [['role' => 'user', 'content' => 'protected']],
            extractedMemories: ['memory1', 'memory2'],
            tokensIn: 1500,
            tokensOut: 300,
            stats: ['ratio' => 0.8],
        );

        $this->assertSame(5, $plan->keepFromMessageIndex);
        $this->assertSame(10, $plan->compactedMessageCount);
        $this->assertSame('A summary of the conversation', $plan->summary);
        $this->assertSame([['role' => 'system', 'content' => 'summary']], $plan->replacementMessages);
        $this->assertSame([['role' => 'user', 'content' => 'protected']], $plan->protectedMessages);
        $this->assertSame(['memory1', 'memory2'], $plan->extractedMemories);
        $this->assertSame(1500, $plan->tokensIn);
        $this->assertSame(300, $plan->tokensOut);
        $this->assertSame(['ratio' => 0.8], $plan->stats);
    }

    public function test_constructor_with_defaults(): void
    {
        $plan = new CompactionPlan(
            keepFromMessageIndex: 3,
            compactedMessageCount: 5,
            summary: 'Some summary',
            replacementMessages: [],
        );

        $this->assertSame([], $plan->protectedMessages);
        $this->assertSame([], $plan->extractedMemories);
        $this->assertSame(0, $plan->tokensIn);
        $this->assertSame(0, $plan->tokensOut);
        $this->assertSame([], $plan->stats);
    }

    public function test_is_empty_returns_true_when_summary_is_empty(): void
    {
        $plan = new CompactionPlan(
            keepFromMessageIndex: 0,
            compactedMessageCount: 5,
            summary: '',
            replacementMessages: [],
        );

        $this->assertTrue($plan->isEmpty());
    }

    public function test_is_empty_returns_true_when_compacted_message_count_is_zero(): void
    {
        $plan = new CompactionPlan(
            keepFromMessageIndex: 0,
            compactedMessageCount: 0,
            summary: 'A summary',
            replacementMessages: [],
        );

        $this->assertTrue($plan->isEmpty());
    }

    public function test_is_empty_returns_true_when_both_summary_empty_and_count_zero(): void
    {
        $plan = new CompactionPlan(
            keepFromMessageIndex: 0,
            compactedMessageCount: 0,
            summary: '',
            replacementMessages: [],
        );

        $this->assertTrue($plan->isEmpty());
    }

    public function test_is_empty_returns_false_when_summary_non_empty_and_count_positive(): void
    {
        $plan = new CompactionPlan(
            keepFromMessageIndex: 2,
            compactedMessageCount: 3,
            summary: 'A non-empty summary',
            replacementMessages: [['role' => 'assistant', 'content' => 'summary']],
        );

        $this->assertFalse($plan->isEmpty());
    }

    public function test_properties_are_accessible_and_readonly(): void
    {
        $plan = new CompactionPlan(
            keepFromMessageIndex: 1,
            compactedMessageCount: 2,
            summary: 'test',
            replacementMessages: ['msg'],
            protectedMessages: ['p'],
            extractedMemories: ['m'],
            tokensIn: 42,
            tokensOut: 7,
            stats: ['key' => 'val'],
        );

        $this->assertSame(1, $plan->keepFromMessageIndex);
        $this->assertSame(2, $plan->compactedMessageCount);
        $this->assertSame('test', $plan->summary);
        $this->assertSame(['msg'], $plan->replacementMessages);
        $this->assertSame(['p'], $plan->protectedMessages);
        $this->assertSame(['m'], $plan->extractedMemories);
        $this->assertSame(42, $plan->tokensIn);
        $this->assertSame(7, $plan->tokensOut);
        $this->assertSame(['key' => 'val'], $plan->stats);
    }
}
