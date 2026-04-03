<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\MessageSerializer;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageSerializerTest extends TestCase
{
    private MessageSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new MessageSerializer;
    }

    public function test_decompose_user_message(): void
    {
        $message = new UserMessage('Hello');

        $decomposed = $this->serializer->decompose($message);

        $this->assertSame('user', $decomposed['role']);
        $this->assertSame('Hello', $decomposed['content']);
        $this->assertNull($decomposed['toolCalls']);
        $this->assertNull($decomposed['toolResults']);
    }

    public function test_decompose_assistant_message_without_tool_calls(): void
    {
        $message = new AssistantMessage('Sure, I can help.');

        $decomposed = $this->serializer->decompose($message);

        $this->assertSame('assistant', $decomposed['role']);
        $this->assertSame('Sure, I can help.', $decomposed['content']);
        $this->assertNull($decomposed['toolCalls']);
        $this->assertNull($decomposed['toolResults']);
    }

    public function test_decompose_assistant_message_with_tool_calls(): void
    {
        $toolCalls = [new ToolCall(id: 'tc1', name: 'bash', arguments: ['command' => 'ls'])];
        $message = new AssistantMessage('', $toolCalls);

        $decomposed = $this->serializer->decompose($message);

        $this->assertSame('assistant', $decomposed['role']);
        $this->assertCount(1, $decomposed['toolCalls']);
        $this->assertSame('tc1', $decomposed['toolCalls'][0]->id);
    }

    public function test_decompose_tool_result_message(): void
    {
        $results = [new ToolResult('tc1', 'bash', ['command' => 'ls'], 'output')];
        $message = new ToolResultMessage($results);

        $decomposed = $this->serializer->decompose($message);

        $this->assertSame('tool_result', $decomposed['role']);
        $this->assertNull($decomposed['content']);
        $this->assertNull($decomposed['toolCalls']);
        $this->assertCount(1, $decomposed['toolResults']);
    }

    public function test_decompose_system_message(): void
    {
        $message = new SystemMessage('Context summary');

        $decomposed = $this->serializer->decompose($message);

        $this->assertSame('system', $decomposed['role']);
        $this->assertSame('Context summary', $decomposed['content']);
        $this->assertNull($decomposed['toolCalls']);
        $this->assertNull($decomposed['toolResults']);
    }

    public function test_serialize_tool_calls(): void
    {
        $toolCalls = [
            new ToolCall(id: 'tc1', name: 'file_read', arguments: ['path' => '/foo.txt']),
            new ToolCall(id: 'tc2', name: 'bash', arguments: '{"command": "ls"}'),
        ];

        $serialized = $this->serializer->serializeToolCalls($toolCalls);

        $this->assertCount(2, $serialized);
        $this->assertSame('tc1', $serialized[0]['id']);
        $this->assertSame('file_read', $serialized[0]['name']);
        $this->assertSame(['path' => '/foo.txt'], $serialized[0]['arguments']);
        $this->assertSame('tc2', $serialized[1]['id']);
        $this->assertSame(['command' => 'ls'], $serialized[1]['arguments']);
    }

    public function test_serialize_tool_results(): void
    {
        $results = [
            new ToolResult('tc1', 'file_read', ['path' => '/foo.txt'], 'contents'),
        ];

        $serialized = $this->serializer->serializeToolResults($results);

        $this->assertCount(1, $serialized);
        $this->assertSame('tc1', $serialized[0]['toolCallId']);
        $this->assertSame('file_read', $serialized[0]['toolName']);
        $this->assertSame(['path' => '/foo.txt'], $serialized[0]['args']);
        $this->assertSame('contents', $serialized[0]['result']);
    }

    public function test_deserialize_user_message(): void
    {
        $row = ['role' => 'user', 'content' => 'Hello', 'tool_calls' => null, 'tool_results' => null];

        $message = $this->serializer->deserializeMessage($row);

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('Hello', $message->content);
    }

    public function test_deserialize_assistant_message_with_tool_calls(): void
    {
        $toolCallsJson = json_encode([['id' => 'tc1', 'name' => 'bash', 'arguments' => ['command' => 'ls']]]);
        $row = ['role' => 'assistant', 'content' => '', 'tool_calls' => $toolCallsJson, 'tool_results' => null];

        $message = $this->serializer->deserializeMessage($row);

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertCount(1, $message->toolCalls);
        $this->assertSame('bash', $message->toolCalls[0]->name);
    }

    public function test_deserialize_tool_result_message(): void
    {
        $resultsJson = json_encode([['toolCallId' => 'tc1', 'toolName' => 'bash', 'args' => [], 'result' => 'output']]);
        $row = ['role' => 'tool_result', 'content' => null, 'tool_calls' => null, 'tool_results' => $resultsJson];

        $message = $this->serializer->deserializeMessage($row);

        $this->assertInstanceOf(ToolResultMessage::class, $message);
        $this->assertCount(1, $message->toolResults);
        $this->assertSame('output', $message->toolResults[0]->result);
    }

    public function test_deserialize_system_message(): void
    {
        $row = ['role' => 'system', 'content' => 'Summary', 'tool_calls' => null, 'tool_results' => null];

        $message = $this->serializer->deserializeMessage($row);

        $this->assertInstanceOf(SystemMessage::class, $message);
        $this->assertSame('Summary', $message->content);
    }

    public function test_deserialize_unknown_role_returns_null(): void
    {
        $row = ['role' => 'alien', 'content' => 'beep', 'tool_calls' => null, 'tool_results' => null];

        $this->assertNull($this->serializer->deserializeMessage($row));
    }

    public function test_deserialize_tool_result_with_null_results_returns_null(): void
    {
        $row = ['role' => 'tool_result', 'content' => null, 'tool_calls' => null, 'tool_results' => null];

        $this->assertNull($this->serializer->deserializeMessage($row));
    }

    public function test_deserialize_tool_calls_with_invalid_json_returns_empty(): void
    {
        $this->assertSame([], $this->serializer->deserializeToolCalls('not json'));
    }

    public function test_deserialize_tool_results_with_invalid_json_returns_empty(): void
    {
        $this->assertSame([], $this->serializer->deserializeToolResults('not json'));
    }

    public function test_roundtrip_tool_calls(): void
    {
        $original = [new ToolCall(id: 'tc1', name: 'grep', arguments: ['pattern' => 'foo'])];

        $json = json_encode($this->serializer->serializeToolCalls($original));
        $restored = $this->serializer->deserializeToolCalls($json);

        $this->assertCount(1, $restored);
        $this->assertSame('tc1', $restored[0]->id);
        $this->assertSame('grep', $restored[0]->name);
        $this->assertSame(['pattern' => 'foo'], $restored[0]->arguments());
    }

    public function test_roundtrip_tool_results(): void
    {
        $original = [new ToolResult('tc1', 'file_read', ['path' => '/a.txt'], 'contents here')];

        $json = json_encode($this->serializer->serializeToolResults($original));
        $restored = $this->serializer->deserializeToolResults($json);

        $this->assertCount(1, $restored);
        $this->assertSame('tc1', $restored[0]->toolCallId);
        $this->assertSame('file_read', $restored[0]->toolName);
        $this->assertSame('contents here', $restored[0]->result);
    }
}
