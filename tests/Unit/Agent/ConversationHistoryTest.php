<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ConversationHistory;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class ConversationHistoryTest extends TestCase
{
    private ConversationHistory $history;

    protected function setUp(): void
    {
        $this->history = new ConversationHistory();
    }

    public function test_starts_empty(): void
    {
        $this->assertEmpty($this->history->messages());
    }

    public function test_add_user_message(): void
    {
        $this->history->addUser('hello');

        $messages = $this->history->messages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertSame('hello', $messages[0]->content);
    }

    public function test_add_assistant_message(): void
    {
        $this->history->addAssistant('reply');

        $messages = $this->history->messages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame('reply', $messages[0]->content);
    }

    public function test_add_assistant_with_tool_calls(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: '{"command": "ls"}');
        $this->history->addAssistant('', [$toolCall]);

        $messages = $this->history->messages();
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertCount(1, $messages[0]->toolCalls);
        $this->assertSame('bash', $messages[0]->toolCalls[0]->name);
    }

    public function test_add_tool_results(): void
    {
        $result = new ToolResult(
            toolCallId: 'tc_1',
            toolName: 'bash',
            args: ['command' => 'ls'],
            result: 'output',
        );
        $this->history->addToolResults([$result]);

        $messages = $this->history->messages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[0]);
    }

    public function test_messages_returns_all_in_order(): void
    {
        $this->history->addUser('q1');
        $this->history->addAssistant('a1');
        $this->history->addUser('q2');
        $this->history->addAssistant('a2');

        $messages = $this->history->messages();
        $this->assertCount(4, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertInstanceOf(UserMessage::class, $messages[2]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[3]);
    }

    public function test_clear_empties_history(): void
    {
        $this->history->addUser('hello');
        $this->history->addAssistant('reply');
        $this->history->clear();

        $this->assertEmpty($this->history->messages());
    }

    public function test_trim_oldest_removes_first_turn(): void
    {
        $this->history->addUser('q1');
        $this->history->addAssistant('a1');
        $this->history->addUser('q2');
        $this->history->addAssistant('a2');

        $result = $this->history->trimOldest();

        $this->assertTrue($result);
        $messages = $this->history->messages();
        $this->assertCount(2, $messages);
        $this->assertSame('q2', $messages[0]->content);
        $this->assertSame('a2', $messages[1]->content);
    }

    public function test_trim_oldest_returns_false_with_fewer_than_3_messages(): void
    {
        $this->history->addUser('q1');
        $this->history->addAssistant('a1');

        $result = $this->history->trimOldest();

        $this->assertFalse($result);
        $this->assertCount(2, $this->history->messages());
    }

    public function test_trim_oldest_returns_false_with_empty_history(): void
    {
        $this->assertFalse($this->history->trimOldest());
    }

    public function test_trim_oldest_removes_turn_with_tool_results(): void
    {
        // Turn 1: user + assistant(tool) + tool_result
        $this->history->addUser('q1');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: '{}');
        $this->history->addAssistant('', [$toolCall]);
        $toolResult = new ToolResult(toolCallId: 'tc_1', toolName: 'bash', args: [], result: 'out');
        $this->history->addToolResults([$toolResult]);

        // Turn 2: user + assistant
        $this->history->addUser('q2');
        $this->history->addAssistant('a2');

        $result = $this->history->trimOldest();
        $this->assertTrue($result);

        $messages = $this->history->messages();
        // Entire turn (user + assistant + tool_result) removed, only turn 2 remains
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertSame('q2', $messages[0]->content);
    }

    public function test_trim_oldest_reindexes_array(): void
    {
        $this->history->addUser('q1');
        $this->history->addAssistant('a1');
        $this->history->addUser('q2');
        $this->history->addAssistant('a2');

        $this->history->trimOldest();

        $keys = array_keys($this->history->messages());
        $this->assertSame([0, 1], $keys);
    }

    public function test_trim_oldest_preserves_at_least_one_message(): void
    {
        $this->history->addUser('q1');
        $this->history->addAssistant('a1');
        $this->history->addUser('q2');

        $result = $this->history->trimOldest();
        $this->assertTrue($result);
        $this->assertGreaterThanOrEqual(1, count($this->history->messages()));
    }

    public function test_multiple_trims(): void
    {
        // 3 full turns = 6 messages
        $this->history->addUser('q1');
        $this->history->addAssistant('a1');
        $this->history->addUser('q2');
        $this->history->addAssistant('a2');
        $this->history->addUser('q3');
        $this->history->addAssistant('a3');

        // First trim: removes q1+a1
        $this->assertTrue($this->history->trimOldest());
        $this->assertCount(4, $this->history->messages());
        $this->assertSame('q2', $this->history->messages()[0]->content);

        // Second trim: removes q2+a2
        $this->assertTrue($this->history->trimOldest());
        $this->assertCount(2, $this->history->messages());
        $this->assertSame('q3', $this->history->messages()[0]->content);
    }
}
