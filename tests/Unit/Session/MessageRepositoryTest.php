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
        $this->assertInstanceOf(UserMessage::class, $loaded[0]);
        $this->assertInstanceOf(AssistantMessage::class, $loaded[1]);
        $this->assertInstanceOf(UserMessage::class, $loaded[2]);
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
        $this->assertInstanceOf(UserMessage::class, $loaded[0]);
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

    public function test_sum_tokens_across_messages(): void
    {
        $this->messages->append($this->sessionId, 'user', 'Hello');
        $this->messages->append($this->sessionId, 'assistant', 'Response 1', tokensIn: 1000, tokensOut: 200);
        $this->messages->append($this->sessionId, 'user', 'Follow up');
        $this->messages->append($this->sessionId, 'assistant', 'Response 2', tokensIn: 1500, tokensOut: 300);

        $totals = $this->messages->sumTokens($this->sessionId);

        $this->assertSame(2500, $totals['tokens_in']);
        $this->assertSame(500, $totals['tokens_out']);
    }

    public function test_sum_tokens_includes_compacted(): void
    {
        $id1 = $this->messages->append($this->sessionId, 'assistant', 'Old', tokensIn: 800, tokensOut: 100);
        $id2 = $this->messages->append($this->sessionId, 'assistant', 'New', tokensIn: 1200, tokensOut: 150);

        $this->messages->markCompacted($this->sessionId, $id2);

        $totals = $this->messages->sumTokens($this->sessionId);

        $this->assertSame(2000, $totals['tokens_in']);
        $this->assertSame(250, $totals['tokens_out']);
    }

    public function test_sum_tokens_empty_session(): void
    {
        $totals = $this->messages->sumTokens($this->sessionId);

        $this->assertSame(0, $totals['tokens_in']);
        $this->assertSame(0, $totals['tokens_out']);
    }

    public function test_compact_with_summary_rolls_back_when_purge_fails(): void
    {
        $id1 = $this->messages->append($this->sessionId, 'user', 'Old question');
        $id2 = $this->messages->append($this->sessionId, 'assistant', 'Old answer');

        $this->db->connection()->exec(
            <<<'SQL'
            CREATE TRIGGER fail_compacted_delete
            BEFORE DELETE ON messages
            WHEN old.compacted = 1
            BEGIN
                SELECT RAISE(ABORT, 'forced compact purge failure');
            END
            SQL
        );

        try {
            $this->messages->compactWithSummary($this->sessionId, [$id1, $id2], 'Summary');
            $this->fail('Expected compact purge failure.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('forced compact purge failure', $e->getMessage());
        }

        $rows = $this->messages->loadRaw($this->sessionId, includeCompacted: true);
        $this->assertCount(2, $rows);
        $this->assertSame([0, 0], array_map(fn (array $row): int => (int) $row['compacted'], $rows));
        $this->assertSame(['Old question', 'Old answer'], array_column($rows, 'content'));
    }

    public function test_search_project_history_finds_matching_messages(): void
    {
        $otherSession = (new SessionRepository($this->db))->create('/project', 'model-1');
        $this->messages->append($otherSession, 'assistant', 'JWT auth is enabled');
        $this->messages->append($this->sessionId, 'assistant', 'Current session mention');

        $results = $this->messages->searchProjectHistory('/project', 'JWT', $this->sessionId, 5);

        $this->assertCount(1, $results);
        $this->assertSame('JWT auth is enabled', $results[0]['content']);
    }

    public function test_search_project_history_supports_phrase_and_path_queries(): void
    {
        $otherSession = (new SessionRepository($this->db))->create('/project', 'model-1');
        $this->messages->append($otherSession, 'assistant', 'Updated src/Session/Database.php for JWT token refresh logic');

        $phraseResults = $this->messages->searchProjectHistory('/project', '"JWT token refresh"', $this->sessionId, 5);
        $pathResults = $this->messages->searchProjectHistory('/project', 'src/Session/Database.php', $this->sessionId, 5);

        $this->assertCount(1, $phraseResults);
        $this->assertCount(1, $pathResults);
        $this->assertSame('Updated src/Session/Database.php for JWT token refresh logic', $pathResults[0]['content']);
    }

    public function test_search_project_history_supports_partial_path_queries_via_fallback(): void
    {
        $otherSession = (new SessionRepository($this->db))->create('/project', 'model-1');
        $this->messages->append($otherSession, 'assistant', 'Updated src/Session/Database.php during auth refactor');

        $basenameResults = $this->messages->searchProjectHistory('/project', 'Database.php', $this->sessionId, 5);
        $directoryResults = $this->messages->searchProjectHistory('/project', 'src/Session', $this->sessionId, 5);
        $segmentResults = $this->messages->searchProjectHistory('/project', 'Session', $this->sessionId, 5);

        $this->assertCount(1, $basenameResults);
        $this->assertCount(1, $directoryResults);
        $this->assertCount(1, $segmentResults);
        $this->assertSame('Updated src/Session/Database.php during auth refactor', $basenameResults[0]['content']);
    }

    public function test_search_project_history_excludes_compacted_messages(): void
    {
        $otherSession = (new SessionRepository($this->db))->create('/project', 'model-1');
        $messageId = $this->messages->append($otherSession, 'assistant', 'Legacy migration note');
        $this->messages->markCompactedIds([$messageId]);

        $results = $this->messages->searchProjectHistory('/project', 'migration', $this->sessionId, 5);

        $this->assertSame([], $results);
    }

    public function test_search_grouped_returns_per_session_results(): void
    {
        $sessions = new SessionRepository($this->db);
        $s1 = $sessions->create('/project', 'model-1');
        $s2 = $sessions->create('/project', 'model-1');

        $this->messages->append($s1, 'user', 'How does JWT auth work?');
        $this->messages->append($s1, 'assistant', 'JWT tokens are signed credentials');
        $this->messages->append($s2, 'user', 'Fix the JWT refresh bug');
        $this->messages->append($s2, 'assistant', 'The JWT refresh endpoint was broken');

        $results = $this->messages->searchProjectHistoryGrouped('/project', 'JWT', $this->sessionId, 5);

        $this->assertCount(2, $results);
        // Each result has required fields
        foreach ($results as $r) {
            $this->assertArrayHasKey('session_id', $r);
            $this->assertArrayHasKey('match_count', $r);
            $this->assertArrayHasKey('best_match', $r);
            $this->assertArrayHasKey('context', $r);
            $this->assertGreaterThanOrEqual(1, $r['match_count']);
        }
    }

    public function test_search_grouped_excludes_current_session(): void
    {
        $this->messages->append($this->sessionId, 'user', 'JWT auth in current session');

        $results = $this->messages->searchProjectHistoryGrouped('/project', 'JWT', $this->sessionId, 5);

        $this->assertSame([], $results);
    }

    public function test_search_grouped_limits_unique_sessions(): void
    {
        $sessions = new SessionRepository($this->db);
        for ($i = 0; $i < 5; $i++) {
            $sid = $sessions->create('/project', 'model-1');
            $this->messages->append($sid, 'user', "Discussion about Redis caching #{$i}");
        }

        $results = $this->messages->searchProjectHistoryGrouped('/project', 'Redis', $this->sessionId, 2);

        $this->assertCount(2, $results);
    }

    public function test_search_grouped_includes_context_messages(): void
    {
        $sessions = new SessionRepository($this->db);
        $sid = $sessions->create('/project', 'model-1');

        $this->messages->append($sid, 'user', 'Tell me about Docker');
        $this->messages->append($sid, 'assistant', 'Docker uses containers for isolation');
        $this->messages->append($sid, 'user', 'How does Docker networking work?');

        $results = $this->messages->searchProjectHistoryGrouped('/project', 'Docker networking', $this->sessionId, 5);

        $this->assertCount(1, $results);
        // Context should contain surrounding messages
        $this->assertNotEmpty($results[0]['context']);
    }

    public function test_load_transcript_returns_ordered_messages(): void
    {
        $this->messages->append($this->sessionId, 'user', 'Hello');
        $this->messages->append($this->sessionId, 'assistant', 'Hi there');
        $this->messages->append($this->sessionId, 'user', 'Help me');

        $transcript = $this->messages->loadTranscript($this->sessionId);

        $this->assertCount(3, $transcript);
        $this->assertSame('user', $transcript[0]['role']);
        $this->assertSame('Hello', $transcript[0]['content']);
        $this->assertSame('assistant', $transcript[1]['role']);
        $this->assertSame('user', $transcript[2]['role']);
    }

    public function test_load_transcript_respects_limit(): void
    {
        $this->messages->append($this->sessionId, 'user', 'First');
        $this->messages->append($this->sessionId, 'assistant', 'Second');
        $this->messages->append($this->sessionId, 'user', 'Third');

        $transcript = $this->messages->loadTranscript($this->sessionId, 2);

        $this->assertCount(2, $transcript);
        $this->assertSame('First', $transcript[0]['content']);
    }

    public function test_load_transcript_excludes_compacted(): void
    {
        $id1 = $this->messages->append($this->sessionId, 'user', 'Old message');
        $this->messages->append($this->sessionId, 'assistant', 'Current message');
        $this->messages->markCompactedIds([$id1]);

        $transcript = $this->messages->loadTranscript($this->sessionId);

        $this->assertCount(1, $transcript);
        $this->assertSame('Current message', $transcript[0]['content']);
    }
}
