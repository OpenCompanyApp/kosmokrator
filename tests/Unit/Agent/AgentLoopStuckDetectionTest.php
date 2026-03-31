<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\UI\NullRenderer;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\NullLogger;

class AgentLoopStuckDetectionTest extends TestCase
{
    private LlmClientInterface&Stub $llm;

    private AgentLoop $loop;

    private Tool $grepTool;

    protected function setUp(): void
    {
        $this->llm = $this->createStub(LlmClientInterface::class);
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $ui = new NullRenderer;
        $this->loop = new AgentLoop($this->llm, $ui, new NullLogger, 'Test agent.');

        $this->grepTool = (new Tool)
            ->as('grep')
            ->for('Search files')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => "matches for {$pattern}");
        $this->loop->setTools([$this->grepTool]);
    }

    public function test_repetitive_tool_calls_trigger_nudge(): void
    {
        // 3 identical tool calls → nudge, then agent returns
        $sameCall = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "foo"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$sameCall], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_2', name: 'grep', arguments: '{"pattern": "foo"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_3', name: 'grep', arguments: '{"pattern": "foo"}')], 100, 20),
            // After nudge, agent returns
            new LlmResponse('Found results.', FinishReason::Stop, [], 100, 20),
        );

        $result = $this->loop->runHeadless('Search for foo');

        $this->assertSame('Found results.', $result);

        // Verify nudge was injected into history
        $hasNudge = false;
        foreach ($this->loop->history()->messages() as $msg) {
            if ($msg instanceof UserMessage && str_contains($msg->content, '[SYSTEM]')) {
                $hasNudge = true;
                break;
            }
        }
        $this->assertTrue($hasNudge, 'Nudge message should be in history');
    }

    public function test_force_return_after_full_escalation(): void
    {
        // 7+ identical calls: nudge at 3, final notice at 5, force-return at 7
        $calls = [];
        for ($i = 1; $i <= 10; $i++) {
            $calls[] = new LlmResponse('', FinishReason::ToolCalls, [
                new ToolCall(id: "tc_{$i}", name: 'grep', arguments: '{"pattern": "stuck"}'),
            ], 100, 20);
        }

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(...$calls);

        $stats = new SubagentStats('test');
        $this->loop->setStats($stats);

        $result = $this->loop->runHeadless('Find something');

        $this->assertStringContainsString('(forced return: agent did not converge after repeated nudges)', $result);
        $this->assertNotNull($stats->error);
        $this->assertStringContainsString('forced return', $stats->error);
    }

    public function test_recovery_resets_escalation(): void
    {
        // 3 identical calls → nudge, then different calls → recovery, then 3 identical again → new nudge (not force-return)
        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            // First 3: trigger nudge
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "aaa"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_2', name: 'grep', arguments: '{"pattern": "aaa"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_3', name: 'grep', arguments: '{"pattern": "aaa"}')], 100, 20),
            // Recovery: different calls push out the window
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_4', name: 'grep', arguments: '{"pattern": "bbb"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_5', name: 'grep', arguments: '{"pattern": "ccc"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_6', name: 'grep', arguments: '{"pattern": "ddd"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_7', name: 'grep', arguments: '{"pattern": "eee"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_8', name: 'grep', arguments: '{"pattern": "fff"}')], 100, 20),
            // New repetition: should get a fresh nudge, not force-return
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_9', name: 'grep', arguments: '{"pattern": "zzz"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_10', name: 'grep', arguments: '{"pattern": "zzz"}')], 100, 20),
            new LlmResponse('', FinishReason::ToolCalls, [new ToolCall(id: 'tc_11', name: 'grep', arguments: '{"pattern": "zzz"}')], 100, 20),
            // Agent complies this time
            new LlmResponse('Recovered results.', FinishReason::Stop, [], 100, 20),
        );

        $result = $this->loop->runHeadless('Search');

        $this->assertSame('Recovered results.', $result);
        $this->assertStringNotContainsString('forced return', $result);
    }

    public function test_diverse_tool_calls_no_trigger(): void
    {
        // 8 different calls — no trigger
        $calls = [];
        for ($i = 1; $i <= 8; $i++) {
            $calls[] = new LlmResponse('', FinishReason::ToolCalls, [
                new ToolCall(id: "tc_{$i}", name: 'grep', arguments: json_encode(['pattern' => "pattern_{$i}"])),
            ], 100, 20);
        }
        $calls[] = new LlmResponse('All done.', FinishReason::Stop, [], 100, 20);

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(...$calls);

        $result = $this->loop->runHeadless('Explore');

        $this->assertSame('All done.', $result);
    }

    public function test_window_limited_to_8(): void
    {
        // 3 identical calls, then 6 different calls pushing them out of window, then check no trigger
        $calls = [];

        // 3 identical
        for ($i = 1; $i <= 3; $i++) {
            $calls[] = new LlmResponse('', FinishReason::ToolCalls, [
                new ToolCall(id: "tc_{$i}", name: 'grep', arguments: '{"pattern": "old"}'),
            ], 100, 20);
        }

        // 6 different — pushes 'old' signatures out of the 8-slot window
        for ($i = 4; $i <= 9; $i++) {
            $calls[] = new LlmResponse('', FinishReason::ToolCalls, [
                new ToolCall(id: "tc_{$i}", name: 'grep', arguments: json_encode(['pattern' => "new_{$i}"])),
            ], 100, 20);
        }

        $calls[] = new LlmResponse('Done.', FinishReason::Stop, [], 100, 20);

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(...$calls);

        $result = $this->loop->runHeadless('Search');

        // Should complete without force-return — the old signatures were pushed out
        $this->assertSame('Done.', $result);
        $this->assertStringNotContainsString('forced return', $result);
    }

    public function test_force_return_uses_last_assistant_text(): void
    {
        $calls = [];

        // First call with text, then repeated calls without text
        $calls[] = new LlmResponse('Here is what I found so far...', FinishReason::ToolCalls, [
            new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "x"}'),
        ], 100, 20);

        for ($i = 2; $i <= 10; $i++) {
            $calls[] = new LlmResponse('', FinishReason::ToolCalls, [
                new ToolCall(id: "tc_{$i}", name: 'grep', arguments: '{"pattern": "x"}'),
            ], 100, 20);
        }

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(...$calls);

        $result = $this->loop->runHeadless('Find x');

        // Force-return should include the last non-empty assistant text
        $this->assertStringContainsString('Here is what I found so far...', $result);
        $this->assertStringContainsString('(forced return:', $result);
    }

    public function test_multi_tool_batch_fills_window_faster(): void
    {
        // Single turn with 3 identical calls fills 3 slots at once
        $sameCalls = [
            new ToolCall(id: 'tc_1a', name: 'grep', arguments: '{"pattern": "same"}'),
            new ToolCall(id: 'tc_1b', name: 'grep', arguments: '{"pattern": "same"}'),
            new ToolCall(id: 'tc_1c', name: 'grep', arguments: '{"pattern": "same"}'),
        ];

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, $sameCalls, 100, 20),
            // After nudge, agent returns
            new LlmResponse('OK.', FinishReason::Stop, [], 100, 20),
        );

        $result = $this->loop->runHeadless('Do thing');
        $this->assertSame('OK.', $result);

        // Nudge should have fired
        $hasNudge = false;
        foreach ($this->loop->history()->messages() as $msg) {
            if ($msg instanceof UserMessage && str_contains($msg->content, '[SYSTEM]')) {
                $hasNudge = true;
                break;
            }
        }
        $this->assertTrue($hasNudge);
    }
}
