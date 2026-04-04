<?php

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\AsyncLlmClient;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;

class AsyncLlmClientMessageMappingTest extends TestCase
{
    private AsyncLlmClient $client;

    protected function setUp(): void
    {
        $this->client = new AsyncLlmClient(
            apiKey: 'fake-key',
            baseUrl: 'http://localhost:9999',
            model: 'test-model',
            systemPrompt: 'Be helpful',
        );
    }

    public function test_map_tools(): void
    {
        $tool = (new Tool)
            ->as('my_tool')
            ->for('Does something')
            ->withStringParameter('path', 'File path', true)
            ->using(fn () => 'ok');

        $mapped = $this->invokeMapTools([$tool]);

        $this->assertCount(1, $mapped);
        $this->assertSame('function', $mapped[0]['type']);
        $this->assertSame('my_tool', $mapped[0]['function']['name']);
        $this->assertSame('Does something', $mapped[0]['function']['description']);
        $this->assertArrayHasKey('parameters', $mapped[0]['function']);
        $this->assertSame('object', $mapped[0]['function']['parameters']['type']);
    }

    public function test_map_finish_reason_stop(): void
    {
        $this->assertSame(FinishReason::Stop, $this->invokeMapFinishReason('stop'));
    }

    public function test_map_finish_reason_tool_calls(): void
    {
        $this->assertSame(FinishReason::ToolCalls, $this->invokeMapFinishReason('tool_calls'));
    }

    public function test_map_finish_reason_length(): void
    {
        $this->assertSame(FinishReason::Length, $this->invokeMapFinishReason('length'));
    }

    public function test_map_finish_reason_unknown(): void
    {
        $this->assertSame(FinishReason::Unknown, $this->invokeMapFinishReason('something_else'));
    }

    public function test_map_finish_reason_empty_string(): void
    {
        $this->assertSame(FinishReason::Unknown, $this->invokeMapFinishReason(''));
    }

    private function invokeMapTools(array $tools): array
    {
        $ref = new \ReflectionMethod(AsyncLlmClient::class, 'mapTools');

        return $ref->invoke($this->client, $tools);
    }

    private function invokeMapFinishReason(string $reason): FinishReason
    {
        $ref = new \ReflectionMethod(AsyncLlmClient::class, 'mapFinishReason');

        return $ref->invoke($this->client, $reason);
    }
}
