<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\Agent\ContextPruner;
use Kosmokrator\Agent\ToolResultDeduplicator;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;

class ToolResultDeduplicatorTest extends TestCase
{
    private ToolResultDeduplicator $dedup;

    protected function setUp(): void
    {
        $this->dedup = new ToolResultDeduplicator();
    }

    public function test_exact_duplicate_superseded(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('first'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/test.php'], 'content A'),
        ]));
        $history->addMessage(new UserMessage('second'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_read', ['path' => '/tmp/test.php'], 'content A'),
        ]));

        $count = $this->dedup->deduplicate($history);

        $this->assertSame(1, $count);
        $messages = $history->messages();
        // First result superseded
        $this->assertStringContainsString('[Superseded', $messages[1]->toolResults[0]->result);
        // Second result preserved
        $this->assertSame('content A', $messages[3]->toolResults[0]->result);
    }

    public function test_exact_duplicate_keeps_latest(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('go'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'glob', ['pattern' => '**/*.php'], 'file1.php\nfile2.php'),
        ]));
        $history->addMessage(new UserMessage('again'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'glob', ['pattern' => '**/*.php'], 'file1.php\nfile2.php'),
        ]));

        $this->dedup->deduplicate($history);

        $messages = $history->messages();
        // Latest (index 3) preserved
        $this->assertSame('file1.php\nfile2.php', $messages[3]->toolResults[0]->result);
    }

    public function test_same_file_reread_without_edit_not_superseded(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('read'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/foo.php', 'offset' => 1, 'limit' => 100], 'old content'),
        ]));
        $history->addMessage(new UserMessage('read again with different range'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_read', ['path' => '/tmp/foo.php', 'offset' => 50, 'limit' => 200], 'new content'),
        ]));

        $count = $this->dedup->deduplicate($history);

        // No edit between reads — tier 2 should NOT fire (file wasn't modified)
        // Different args means tier 1 also won't match
        $this->assertSame(0, $count);
        $messages = $history->messages();
        $this->assertSame('old content', $messages[1]->toolResults[0]->result);
    }

    public function test_same_file_reread_with_edit_between_superseded(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('read'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/foo.php'], 'old content'),
        ]));
        $history->addMessage(new UserMessage('edit'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_edit', ['path' => '/tmp/foo.php', 'old_string' => 'a', 'new_string' => 'b'], 'Edit successful'),
        ]));
        $history->addMessage(new UserMessage('read again'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_3', 'file_read', ['path' => '/tmp/foo.php'], 'new content'),
        ]));

        $count = $this->dedup->deduplicate($history);

        // Edit between the two reads — tier 2 fires
        $this->assertGreaterThanOrEqual(1, $count);
        $messages = $history->messages();
        $this->assertStringContainsString('[Superseded', $messages[1]->toolResults[0]->result);
        $this->assertSame('new content', $messages[5]->toolResults[0]->result);
    }

    public function test_grep_subsumed_by_file_read(): void
    {
        // Use a real file so is_dir() returns false
        $tmpFile = tempnam(sys_get_temp_dir(), 'dedup_test_');

        try {
            $history = new ConversationHistory();
            $history->addMessage(new UserMessage('search'));
            $history->addMessage(new ToolResultMessage([
                new ToolResult('call_1', 'grep', ['pattern' => 'class Foo', 'path' => $tmpFile], 'line 10: class Foo'),
            ]));
            $history->addMessage(new UserMessage('read'));
            $history->addMessage(new ToolResultMessage([
                new ToolResult('call_2', 'file_read', ['path' => $tmpFile], 'full file content with class Foo'),
            ]));

            $count = $this->dedup->deduplicate($history);

            $this->assertSame(1, $count);
            $messages = $history->messages();
            $this->assertStringContainsString('[Superseded — content included in later file_read', $messages[1]->toolResults[0]->result);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_grep_on_directory_not_subsumed(): void
    {
        $tmpDir = sys_get_temp_dir();

        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('search'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'grep', ['pattern' => 'class', 'path' => $tmpDir], 'many matches'),
        ]));
        $history->addMessage(new UserMessage('read'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_read', ['path' => $tmpDir . '/Foo.php'], 'file content'),
        ]));

        $count = $this->dedup->deduplicate($history);

        // grep on directory should NOT be subsumed by read of single file
        $messages = $history->messages();
        $this->assertSame('many matches', $messages[1]->toolResults[0]->result);
    }

    public function test_different_files_not_deduplicated(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('read both'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/a.php'], 'content A'),
            new ToolResult('call_2', 'file_read', ['path' => '/tmp/b.php'], 'content B'),
        ]));

        $count = $this->dedup->deduplicate($history);

        $this->assertSame(0, $count);
    }

    public function test_bash_not_deduplicated(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('run'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'bash', ['command' => 'date'], '2026-03-30'),
        ]));
        $history->addMessage(new UserMessage('run again'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'bash', ['command' => 'date'], '2026-03-30'),
        ]));

        $count = $this->dedup->deduplicate($history);

        $this->assertSame(0, $count);
    }

    public function test_already_pruned_skipped(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('read'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/test.php'], ContextPruner::PLACEHOLDER),
        ]));
        $history->addMessage(new UserMessage('read again'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_read', ['path' => '/tmp/test.php'], 'content'),
        ]));

        $count = $this->dedup->deduplicate($history);

        // Already-pruned result should be skipped, not counted as duplicate
        $this->assertSame(0, $count);
    }

    public function test_already_superseded_skipped(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('read'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/test.php'], '[Superseded — identical result returned by later call]'),
        ]));
        $history->addMessage(new UserMessage('read again'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_read', ['path' => '/tmp/test.php'], 'content'),
        ]));

        $count = $this->dedup->deduplicate($history);

        $this->assertSame(0, $count);
    }

    public function test_file_edit_confirmations_never_superseded(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('edit'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_edit', ['path' => '/tmp/foo.php', 'old_string' => 'a', 'new_string' => 'b'], 'Edit applied'),
        ]));
        $history->addMessage(new UserMessage('edit same thing again'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_edit', ['path' => '/tmp/foo.php', 'old_string' => 'a', 'new_string' => 'b'], 'old_string not found'),
        ]));

        $count = $this->dedup->deduplicate($history);

        // Both edit confirmations must be preserved — they have different outcomes
        $this->assertSame(0, $count);
        $messages = $history->messages();
        $this->assertSame('Edit applied', $messages[1]->toolResults[0]->result);
        $this->assertSame('old_string not found', $messages[3]->toolResults[0]->result);
    }

    public function test_file_read_different_content_not_tier1_superseded(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('read'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/test.php'], 'version 1 content'),
        ]));
        $history->addMessage(new UserMessage('read again'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_2', 'file_read', ['path' => '/tmp/test.php'], 'version 2 content'),
        ]));

        $count = $this->dedup->deduplicate($history);

        // Same args but different content — tier 1 should NOT fire (not exact duplicate)
        // Tier 2 also won't fire (no edit between reads)
        $this->assertSame(0, $count);
        $messages = $history->messages();
        $this->assertSame('version 1 content', $messages[1]->toolResults[0]->result);
        $this->assertSame('version 2 content', $messages[3]->toolResults[0]->result);
    }

    public function test_no_duplicates_returns_zero(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('do things'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_1', 'file_read', ['path' => '/tmp/a.php'], 'content A'),
            new ToolResult('call_2', 'grep', ['pattern' => 'foo', 'path' => '/tmp/b.php'], 'match'),
        ]));

        $count = $this->dedup->deduplicate($history);

        $this->assertSame(0, $count);
    }

    public function test_preserves_tool_call_metadata(): void
    {
        $history = new ConversationHistory();
        $history->addMessage(new UserMessage('first'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_original', 'file_read', ['path' => '/tmp/test.php'], 'content'),
        ]));
        $history->addMessage(new UserMessage('second'));
        $history->addMessage(new ToolResultMessage([
            new ToolResult('call_newer', 'file_read', ['path' => '/tmp/test.php'], 'content'),
        ]));

        $this->dedup->deduplicate($history);

        $messages = $history->messages();
        $superseded = $messages[1]->toolResults[0];

        // Metadata preserved
        $this->assertSame('call_original', $superseded->toolCallId);
        $this->assertSame('file_read', $superseded->toolName);
        $this->assertSame(['path' => '/tmp/test.php'], $superseded->args);
        // Content replaced
        $this->assertStringContainsString('[Superseded', $superseded->result);
    }
}
