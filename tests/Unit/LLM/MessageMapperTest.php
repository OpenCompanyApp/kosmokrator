<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\MessageMapper;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageMapperTest extends TestCase
{
    public function test_user_message_factory(): void
    {
        $message = MessageMapper::userMessage('Hello');

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('Hello', $message->content);
    }

    public function test_assistant_message_factory_without_tool_calls(): void
    {
        $message = MessageMapper::assistantMessage('Response text');

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Response text', $message->content);
        $this->assertEmpty($message->toolCalls);
    }

    public function test_assistant_message_factory_with_tool_calls(): void
    {
        $toolCalls = [new ToolCall(id: 'tc1', name: 'bash', arguments: ['command' => 'ls'])];
        $message = MessageMapper::assistantMessage('', $toolCalls);

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertCount(1, $message->toolCalls);
    }

    public function test_system_message_factory(): void
    {
        $message = MessageMapper::systemMessage('Context');

        $this->assertInstanceOf(SystemMessage::class, $message);
        $this->assertSame('Context', $message->content);
    }

    public function test_tool_result_message_factory(): void
    {
        $results = [new ToolResult('tc1', 'bash', [], 'output')];
        $message = MessageMapper::toolResultMessage($results);

        $this->assertInstanceOf(ToolResultMessage::class, $message);
        $this->assertCount(1, $message->toolResults);
    }

    public function test_role_of_user_message(): void
    {
        $this->assertSame('user', MessageMapper::roleOf(new UserMessage('hi')));
    }

    public function test_role_of_assistant_message(): void
    {
        $this->assertSame('assistant', MessageMapper::roleOf(new AssistantMessage('hello')));
    }

    public function test_role_of_tool_result_message(): void
    {
        $results = [new ToolResult('tc1', 'bash', [], 'output')];
        $this->assertSame('tool', MessageMapper::roleOf(new ToolResultMessage($results)));
    }

    public function test_role_of_system_message(): void
    {
        $this->assertSame('system', MessageMapper::roleOf(new SystemMessage('context')));
    }

    public function test_is_type_returns_true_for_matching_type(): void
    {
        $message = new UserMessage('hi');

        $this->assertTrue(MessageMapper::isType($message, UserMessage::class));
    }

    public function test_is_type_returns_false_for_non_matching_type(): void
    {
        $message = new UserMessage('hi');

        $this->assertFalse(MessageMapper::isType($message, AssistantMessage::class));
    }
}
