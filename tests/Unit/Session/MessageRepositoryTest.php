<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Session\Database;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionRepository;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageRepositoryTest extends TestCase
{
    private Database $db;

    private MessageRepository $messages;

    private string $sessionId;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $sessions = new SessionRepository($this->db);
        $this->messages = new MessageRepository($this->db);
        $this->sessionId = $sessions->create('/project', 'model-1');
    }

    public function test_append_and_load_user_message(): void
    {
        $this->messages->append($this->sessionId, 'user', 'Hello world');

        $loaded = $this->messages->loadActive($this->sessionId);

        $this->assertCount(1, $loaded);
        $this->assertInstanceOf(UserMessage::class, $loaded[0]);
        $this->assertSame('Hello world', $loaded[0]->content);
    }

    public function test_append_and_load_assistant_message(): void
    {
        $this->messages->append($this->sessionId, 'assistant', 'I can help');

        $loaded = $this->messages->loadActive($this->sessionId);

        $this->assertCount(1, $loaded);
        $this->assertInstanceOf(AssistantMessage::class, $loaded[0]);
        $this->assertSame('I can help', $loaded[0]->content);
    }

    public function test_append_and_load_assistant_with_tool_calls(): void
    {
        $toolCalls = [
            new ToolCall(id: 'tc1', name: 'file_read', arguments: ['path' => '/foo.txt']),
        ];

        $this->messages->append(
            sessionId: $this->sessionId,
            role: 'assistant',
            content: '',
            toolCalls: $toolCalls,
        );

        $loaded = $this->messages->loadActive($this->sessionId);

        $this->assertCount(1, $loaded);
        $this->assertInstanceOf(AssistantMessage::class, $loaded[0]);
        $this->assertCount(1, $loaded[0]->toolCalls);
        $this->assertSame('file_read', $loaded[0]->toolCalls[0]->name);
        $this->assertSame(['path' => '/foo.txt'], $loaded[0]->toolCalls[0]->arguments());
    }

    public function test_append_and_load_tool_results(): void
    {
        $results = [
            new ToolResult(toolCallId: 'tc1', toolName: 'file_read', args: ['path' => '/foo.txt'], result: 'file contents'),
        ];

        $this->messages->append(
            sessionId: $this->sessionId,
            role: 'tool_result',
            toolResults: $results,
        );

        $loaded = $this->messages->loadActive($this->sessionId);

        $this->assertCount(1, $loaded);
        $this->assertInstanceOf(ToolResultMessage::class, $loaded[0]);
    }

    public function test_append_and_load_system_message(): void
    {
        $this->messages->append($this->sessionId, 'system', 'Summary of previous conversation');

        $loaded = $this->messages->loadActive($this->sessionId);

        $this->assertCount(1, $loaded);
        $this->assertInstanceOf(SystemMessage::class, $loaded[0]);
        $this->assertSame('Summary of previous conversation', $loaded[0]->content);
    }

    public function test_ordering_preserved(): void
    {
        $this->messages->append($this->sessionId, 'user', 'First');
        $this->messages->append($this->sessionId, 'assistant', 'Second');
        $this->messages->append($this->sessionId, 'user', 'Third');

        $loaded = $this->messages->loadActive($this->sessionId);

        $this->assertCount(3, $loaded);
        $this->assertSame('First', $loaded[0]->content);
        $this->assertSame('Second', $loaded[1]->content);
        $this->assertSame('Third', $loaded[2]->content);
    }

    public function test_mark_compacted_excludes_from_load(): void
    {
        $id1 = $this->messages->append($this->sessionId, 'user', 'Old message');
        $id2 = $this->messages->append($this->sessionId, 'assistant', 'Old response');
        $id3 = $this->messages->append($this->sessionId, 'user', 'Recent message');

        $this->messages->markCompacted($this->sessionId, $id3);

        $loaded = $this->messages->loadActive($this->sessionId);

        $this->assertCount(1, $loaded);
        $this->assertSame('Recent message', $loaded[0]->content);
    }

    public function test_count_excludes_compacted(): void
    {
        $id1 = $this->messages->append($this->sessionId, 'user', 'Old');
        $id2 = $this->messages->append($this->sessionId, 'assistant', 'Old resp');
        $this->messages->append($this->sessionId, 'user', 'New');

        $this->assertSame(3, $this->messages->count($this->sessionId));

        $this->messages->markCompacted($this->sessionId, $id2 + 1);

        $this->assertSame(1, $this->messages->count($this->sessionId));
    }

    public function test_tokens_stored(): void
    {
        $this->messages->append(
            sessionId: $this->sessionId,
            role: 'assistant',
            content: 'response',
            tokensIn: 1500,
            tokensOut: 200,
        );

        $raw = $this->messages->loadRaw($this->sessionId);
        $this->assertSame(1500, (int) $raw[0]['tokens_in']);
        $this->assertSame(200, (int) $raw[0]['tokens_out']);
    }
}
