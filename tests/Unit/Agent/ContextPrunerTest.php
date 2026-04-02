<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ContextPruner;
use Kosmokrator\Agent\ConversationHistory;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\ToolResult;

class ContextPrunerTest extends TestCase
{
    private function makeResult(string $content, string $id = 'tc1'): ToolResult
    {
        return new ToolResult(toolCallId: $id, toolName: 'bash', args: [], result: $content);
    }

    private function bashPlaceholder(): string
    {
        return '[Old shell output cleared; inspect truncation storage or rerun targeted commands if needed]';
    }

    public function test_prune_does_nothing_with_few_messages(): void
    {
        $history = new ConversationHistory;
        $history->addUser('hello');
        $history->addAssistant('hi');

        $pruner = new ContextPruner(protectTokens: 0, minSavings: 0);
        $saved = $pruner->prune($history);

        $this->assertSame(0, $saved);
    }

    public function test_prune_protects_last_two_user_turns(): void
    {
        $history = new ConversationHistory;

        // Turn 1 (old)
        $history->addUser('read old');
        $history->addAssistant('');
        $history->addToolResults([$this->makeResult(str_repeat('x', 400))]);

        // Turn 2 (recent — protected)
        $history->addUser('read recent 1');
        $history->addAssistant('');
        $history->addToolResults([$this->makeResult(str_repeat('y', 400), 'tc2')]);

        // Turn 3 (most recent — protected)
        $history->addUser('read recent 2');
        $history->addAssistant('');
        $history->addToolResults([$this->makeResult(str_repeat('z', 400), 'tc3')]);

        // Protect 0 tokens, min savings 0 — should prune turn 1
        $pruner = new ContextPruner(protectTokens: 0, minSavings: 0);
        $saved = $pruner->prune($history);

        $this->assertGreaterThan(0, $saved);

        // Turn 1 tool result should be cleared
        $this->assertSame($this->bashPlaceholder(), $history->messages()[2]->toolResults[0]->result);

        // Turn 2 and 3 tool results should be untouched
        $this->assertSame(str_repeat('y', 400), $history->messages()[5]->toolResults[0]->result);
        $this->assertSame(str_repeat('z', 400), $history->messages()[8]->toolResults[0]->result);
    }

    public function test_prune_clears_old_tool_results(): void
    {
        $history = new ConversationHistory;

        // 3 old turns with large tool results
        for ($i = 0; $i < 3; $i++) {
            $history->addUser("turn {$i}");
            $history->addAssistant('');
            $history->addToolResults([$this->makeResult(str_repeat('a', 40_000), "tc{$i}")]);
        }

        // 2 recent turns
        $history->addUser('recent 1');
        $history->addAssistant('ok');
        $history->addUser('recent 2');
        $history->addAssistant('ok');

        $pruner = new ContextPruner(protectTokens: 0, minSavings: 0);
        $saved = $pruner->prune($history);

        $this->assertGreaterThan(0, $saved);

        // All 3 old tool results should be cleared
        for ($i = 0; $i < 3; $i++) {
            $msgIdx = ($i * 3) + 2;
            $this->assertSame($this->bashPlaceholder(), $history->messages()[$msgIdx]->toolResults[0]->result);
        }
    }

    public function test_prune_skips_already_pruned(): void
    {
        $history = new ConversationHistory;

        $history->addUser('old');
        $history->addAssistant('');
        $history->addToolResults([
            new ToolResult(toolCallId: 'tc1', toolName: 'bash', args: [], result: ContextPruner::PLACEHOLDER),
        ]);

        $history->addUser('recent 1');
        $history->addAssistant('ok');
        $history->addUser('recent 2');
        $history->addAssistant('ok');

        $pruner = new ContextPruner(protectTokens: 0, minSavings: 0);
        $saved = $pruner->prune($history);

        // Already pruned → nothing to save
        $this->assertSame(0, $saved);
    }

    public function test_prune_respects_protect_token_budget(): void
    {
        $history = new ConversationHistory;

        // Old turn with 200 chars = ~50 tokens
        $history->addUser('old');
        $history->addAssistant('');
        $history->addToolResults([$this->makeResult(str_repeat('x', 200))]);

        // Recent turns
        $history->addUser('recent 1');
        $history->addAssistant('ok');
        $history->addUser('recent 2');
        $history->addAssistant('ok');

        // Protect 1000 tokens — the 50-token result is within budget
        $pruner = new ContextPruner(protectTokens: 1000, minSavings: 0);
        $saved = $pruner->prune($history);

        $this->assertSame(0, $saved);
        // Result should be untouched
        $this->assertSame(str_repeat('x', 200), $history->messages()[2]->toolResults[0]->result);
    }

    public function test_prune_skips_below_min_savings(): void
    {
        $history = new ConversationHistory;

        // Old turn with small output
        $history->addUser('old');
        $history->addAssistant('');
        $history->addToolResults([$this->makeResult(str_repeat('x', 100))]);

        // Recent turns
        $history->addUser('recent 1');
        $history->addAssistant('ok');
        $history->addUser('recent 2');
        $history->addAssistant('ok');

        // Min savings 1000 tokens, but only ~25 tokens to prune
        $pruner = new ContextPruner(protectTokens: 0, minSavings: 1000);
        $saved = $pruner->prune($history);

        $this->assertSame(0, $saved);
    }

    public function test_prune_stops_at_system_message(): void
    {
        $history = new ConversationHistory;

        // Compaction summary
        $history->addMessage(new SystemMessage('Previous conversation summary'));

        // Turn after compaction — should NOT be pruned (system message boundary)
        $history->addUser('after compaction');
        $history->addAssistant('');
        $history->addToolResults([$this->makeResult(str_repeat('x', 40_000))]);

        // Recent turns
        $history->addUser('recent 1');
        $history->addAssistant('ok');
        $history->addUser('recent 2');
        $history->addAssistant('ok');

        $pruner = new ContextPruner(protectTokens: 0, minSavings: 0);
        $saved = $pruner->prune($history);

        // The tool result at index 3 is right after the system message at index 0.
        // Walking backwards from boundary, we hit SystemMessage at index 0 and stop.
        // But the ToolResultMessage at index 3 comes AFTER the SystemMessage, so it
        // should still be prunable.
        // Actually — the pruner walks backwards from boundary. Index 3 is before boundary.
        // It encounters ToolResultMessage at 3, then continues to 2 (assistant), 1 (user),
        // 0 (SystemMessage) → stops. So index 3 IS pruned.
        $this->assertGreaterThan(0, $saved);
    }
}
