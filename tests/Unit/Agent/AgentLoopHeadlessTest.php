<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Amp\CancelledException;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\UI\NullRenderer;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\NullLogger;

class AgentLoopHeadlessTest extends TestCase
{
    private LlmClientInterface&Stub $llm;

    private AgentLoop $loop;

    protected function setUp(): void
    {
        $this->llm = $this->createStub(LlmClientInterface::class);
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $ui = new NullRenderer;
        $this->loop = new AgentLoop($this->llm, $ui, new NullLogger, 'You are a test sub-agent.');
    }

    public function test_simple_response_returns_text(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('Found 3 interfaces.', FinishReason::Stop, [], 100, 50),
        );

        $result = $this->loop->runHeadless('Find all interfaces');
        $this->assertSame('Found 3 interfaces.', $result);
    }

    public function test_tool_call_then_response(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "interface"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Found interfaces in 5 files.', FinishReason::Stop, [], 200, 50),
        );

        $tool = (new Tool)
            ->as('grep')
            ->for('Search')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => "matches for {$pattern}");
        $this->loop->setTools([$tool]);

        $result = $this->loop->runHeadless('Find interfaces');
        $this->assertSame('Found interfaces in 5 files.', $result);
    }

    public function test_error_returns_error_string(): void
    {
        $this->llm->method('chat')->willThrowException(
            new \RuntimeException('API unavailable'),
        );

        $result = $this->loop->runHeadless('Do something');
        $this->assertStringContainsString('Error: API unavailable', $result);
    }

    public function test_returns_final_text_not_intermediate(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "test"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('Let me search...', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Final answer.', FinishReason::Stop, [], 200, 50),
        );

        $tool = (new Tool)
            ->as('grep')
            ->for('Search')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => 'results');
        $this->loop->setTools([$tool]);

        $result = $this->loop->runHeadless('Search');
        $this->assertSame('Final answer.', $result);
    }

    public function test_stats_track_tokens(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('Done.', FinishReason::Stop, [], 150, 75),
        );

        $stats = new SubagentStats('test');
        $this->loop->setStats($stats);
        $this->loop->runHeadless('Task');

        $this->assertSame(150, $stats->tokensIn);
        $this->assertSame(75, $stats->tokensOut);
    }

    public function test_stats_increment_tool_calls(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "x"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Done.', FinishReason::Stop, [], 200, 50),
        );

        $tool = (new Tool)
            ->as('grep')
            ->for('Search')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => 'match');
        $this->loop->setTools([$tool]);

        $stats = new SubagentStats('test');
        $this->loop->setStats($stats);
        $this->loop->runHeadless('Go');

        $this->assertSame(1, $stats->toolCalls);
    }

    public function test_history_is_populated(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('Hello.', FinishReason::Stop, [], 100, 50),
        );

        $this->loop->runHeadless('Hi');

        $messages = $this->loop->history()->messages();
        $this->assertCount(2, $messages); // user + assistant
    }

    public function test_watchdog_cancellation_becomes_runtime_failure(): void
    {
        $this->llm->method('chat')->willThrowException(
            new CancelledException(new \RuntimeException('watchdog: subagent idle for 901.2s with no activity')),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('watchdog: subagent idle for 901.2s with no activity');

        $this->loop->runHeadless('Do something');
    }
}
