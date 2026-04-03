<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentPhase;
use PHPUnit\Framework\TestCase;

class AgentPhaseTest extends TestCase
{
    public function test_thinking_value(): void
    {
        $this->assertSame('thinking', AgentPhase::Thinking->value);
    }

    public function test_tools_value(): void
    {
        $this->assertSame('tools', AgentPhase::Tools->value);
    }

    public function test_idle_value(): void
    {
        $this->assertSame('idle', AgentPhase::Idle->value);
    }

    public function test_from_returns_correct_case_for_each_value(): void
    {
        $this->assertSame(AgentPhase::Thinking, AgentPhase::from('thinking'));
        $this->assertSame(AgentPhase::Tools, AgentPhase::from('tools'));
        $this->assertSame(AgentPhase::Idle, AgentPhase::from('idle'));
    }

    public function test_from_throws_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);
        AgentPhase::from('unknown');
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(AgentPhase::tryFrom('nonexistent'));
        $this->assertNull(AgentPhase::tryFrom(''));
        $this->assertNull(AgentPhase::tryFrom('Thinking'));
    }

    public function test_try_from_returns_correct_case_for_valid_values(): void
    {
        $this->assertSame(AgentPhase::Thinking, AgentPhase::tryFrom('thinking'));
        $this->assertSame(AgentPhase::Tools, AgentPhase::tryFrom('tools'));
        $this->assertSame(AgentPhase::Idle, AgentPhase::tryFrom('idle'));
    }

    public function test_cases_returns_exactly_three_cases(): void
    {
        $cases = AgentPhase::cases();
        $this->assertCount(3, $cases);
        $this->assertSame(AgentPhase::Thinking, $cases[0]);
        $this->assertSame(AgentPhase::Tools, $cases[1]);
        $this->assertSame(AgentPhase::Idle, $cases[2]);
    }

    public function test_all_cases_have_expected_values(): void
    {
        $expected = [
            'Thinking' => 'thinking',
            'Tools' => 'tools',
            'Idle' => 'idle',
        ];

        foreach (AgentPhase::cases() as $case) {
            $this->assertSame($expected[$case->name], $case->value);
        }
    }
}
