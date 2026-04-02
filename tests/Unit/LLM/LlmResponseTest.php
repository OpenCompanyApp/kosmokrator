<?php

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\LlmResponse;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;

class LlmResponseTest extends TestCase
{
    public function test_constructor_assigns_all_properties(): void
    {
        $response = new LlmResponse(
            text: 'Hello world',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 100,
            completionTokens: 50,
        );

        $this->assertSame('Hello world', $response->text);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertEmpty($response->toolCalls);
        $this->assertSame(100, $response->promptTokens);
        $this->assertSame(50, $response->completionTokens);
    }

    public function test_empty_tool_calls(): void
    {
        $response = new LlmResponse('text', FinishReason::Stop, [], 0, 0);

        $this->assertIsArray($response->toolCalls);
        $this->assertEmpty($response->toolCalls);
    }

    public function test_with_tool_calls(): void
    {
        $tc1 = new ToolCall(id: 'tc_1', name: 'file_read', arguments: '{"path": "/tmp/a"}');
        $tc2 = new ToolCall(id: 'tc_2', name: 'bash', arguments: '{"command": "ls"}');

        $response = new LlmResponse('', FinishReason::ToolCalls, [$tc1, $tc2], 200, 100);

        $this->assertCount(2, $response->toolCalls);
        $this->assertInstanceOf(ToolCall::class, $response->toolCalls[0]);
        $this->assertSame('file_read', $response->toolCalls[0]->name);
        $this->assertSame('bash', $response->toolCalls[1]->name);
    }

    public function test_finish_reason_stop(): void
    {
        $response = new LlmResponse('', FinishReason::Stop, [], 0, 0);

        $this->assertSame(FinishReason::Stop, $response->finishReason);
    }

    public function test_finish_reason_tool_calls(): void
    {
        $response = new LlmResponse('', FinishReason::ToolCalls, [], 0, 0);

        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
    }

    public function test_zero_tokens(): void
    {
        $response = new LlmResponse('', FinishReason::Stop, [], 0, 0);

        $this->assertSame(0, $response->promptTokens);
        $this->assertSame(0, $response->completionTokens);
    }

    public function test_cache_metrics_are_assigned(): void
    {
        $response = new LlmResponse(
            text: 'cached',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 100,
            completionTokens: 25,
            cacheWriteInputTokens: 80,
            cacheReadInputTokens: 20,
            thoughtTokens: 5,
        );

        $this->assertSame(80, $response->cacheWriteInputTokens);
        $this->assertSame(20, $response->cacheReadInputTokens);
        $this->assertSame(5, $response->thoughtTokens);
    }
}
