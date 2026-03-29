<?php

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\AsyncLlmClient;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

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

    public function test_map_messages_prepends_system_prompt(): void
    {
        $messages = [new UserMessage('hello')];
        $mapped = $this->invokeMapMessages($messages);

        $this->assertSame('system', $mapped[0]['role']);
        $this->assertSame('Be helpful', $mapped[0]['content']);
    }

    public function test_map_messages_empty_system_prompt_not_prepended(): void
    {
        $client = new AsyncLlmClient(
            apiKey: 'fake',
            baseUrl: 'http://localhost',
            model: 'model',
            systemPrompt: '',
        );

        $messages = [new UserMessage('hello')];
        $mapped = $this->invokeMapMessages($messages, $client);

        $this->assertSame('user', $mapped[0]['role']);
    }

    public function test_map_messages_user_message(): void
    {
        $messages = [new UserMessage('hello')];
        $mapped = $this->invokeMapMessages($messages);

        // Index 1 because index 0 is the system prompt
        $this->assertSame('user', $mapped[1]['role']);
    }

    public function test_map_messages_assistant_message_without_tool_calls(): void
    {
        $messages = [new AssistantMessage('reply')];
        $mapped = $this->invokeMapMessages($messages);

        $entry = $mapped[1]; // after system prompt
        $this->assertSame('assistant', $entry['role']);
        $this->assertSame('reply', $entry['content']);
        $this->assertArrayNotHasKey('tool_calls', $entry);
    }

    public function test_map_messages_assistant_message_with_tool_calls(): void
    {
        $tc = new ToolCall(id: 'tc_1', name: 'bash', arguments: '{"command": "ls"}');
        $messages = [new AssistantMessage('', [$tc])];
        $mapped = $this->invokeMapMessages($messages);

        $entry = $mapped[1];
        $this->assertSame('assistant', $entry['role']);
        $this->assertArrayHasKey('tool_calls', $entry);
        $this->assertCount(1, $entry['tool_calls']);
        $this->assertSame('tc_1', $entry['tool_calls'][0]['id']);
        $this->assertSame('function', $entry['tool_calls'][0]['type']);
        $this->assertSame('bash', $entry['tool_calls'][0]['function']['name']);
    }

    public function test_map_messages_tool_result_message(): void
    {
        $result = new ToolResult(toolCallId: 'tc_1', toolName: 'bash', args: [], result: 'output');
        $messages = [new ToolResultMessage([$result])];
        $mapped = $this->invokeMapMessages($messages);

        $entry = $mapped[1];
        $this->assertSame('tool', $entry['role']);
        $this->assertSame('tc_1', $entry['tool_call_id']);
        $this->assertSame('output', $entry['content']);
    }

    public function test_map_messages_system_message(): void
    {
        $messages = [new SystemMessage('system text')];
        $mapped = $this->invokeMapMessages($messages);

        $entry = $mapped[1]; // after prepended system prompt
        $this->assertSame('system', $entry['role']);
        $this->assertSame('system text', $entry['content']);
    }

    public function test_map_messages_unsupported_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message type');

        // Create an anonymous class implementing Message
        $fake = new class implements \Prism\Prism\Contracts\Message {
            public function toArray(): array { return []; }
        };

        $this->invokeMapMessages([$fake]);
    }

    public function test_map_tools(): void
    {
        $tool = (new Tool())
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

    private function invokeMapMessages(array $messages, ?AsyncLlmClient $client = null): array
    {
        $ref = new \ReflectionMethod(AsyncLlmClient::class, 'mapMessages');

        return $ref->invoke($client ?? $this->client, $messages);
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
