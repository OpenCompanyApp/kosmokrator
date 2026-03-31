<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ConversationHistory;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolResult;

class ConversationHistoryPruneTest extends TestCase
{
    public function test_prune_tool_results_replaces_content(): void
    {
        $history = new ConversationHistory;
        $history->addUser('read file');
        $history->addAssistant('', []);
        $history->addToolResults([
            new ToolResult(toolCallId: 'tc1', toolName: 'file_read', args: [], result: 'long file content here'),
        ]);

        $history->pruneToolResults([[2, 0, 100]], '[cleared]');

        $msg = $history->messages()[2];
        $this->assertInstanceOf(ToolResultMessage::class, $msg);
        $this->assertSame('[cleared]', $msg->toolResults[0]->result);
    }

    public function test_prune_tool_results_preserves_metadata(): void
    {
        $history = new ConversationHistory;
        $history->addUser('run bash');
        $history->addAssistant('', []);
        $history->addToolResults([
            new ToolResult(
                toolCallId: 'tc42',
                toolName: 'bash',
                args: ['command' => 'ls -la'],
                result: 'lots of output',
            ),
        ]);

        $history->pruneToolResults([[2, 0, 50]], '[pruned]');

        $result = $history->messages()[2]->toolResults[0];
        $this->assertSame('tc42', $result->toolCallId);
        $this->assertSame('bash', $result->toolName);
        $this->assertSame(['command' => 'ls -la'], $result->args);
        $this->assertSame('[pruned]', $result->result);
    }

    public function test_prune_invalid_index_ignored(): void
    {
        $history = new ConversationHistory;
        $history->addUser('hello');

        // Index 5 doesn't exist, index 0 is a UserMessage not ToolResultMessage
        $history->pruneToolResults([[5, 0, 100], [0, 0, 50]], '[cleared]');

        // No crash, user message unchanged
        $this->assertSame('hello', $history->messages()[0]->content);
    }

    public function test_prune_multiple_results_in_same_message(): void
    {
        $history = new ConversationHistory;
        $history->addUser('multi tool');
        $history->addAssistant('', []);
        $history->addToolResults([
            new ToolResult(toolCallId: 'tc1', toolName: 'bash', args: [], result: 'output 1'),
            new ToolResult(toolCallId: 'tc2', toolName: 'grep', args: [], result: 'output 2'),
        ]);

        // Prune only the first result
        $history->pruneToolResults([[2, 0, 50]], '[cleared]');

        $msg = $history->messages()[2];
        $this->assertSame('[cleared]', $msg->toolResults[0]->result);
        $this->assertSame('output 2', $msg->toolResults[1]->result);
    }
}
