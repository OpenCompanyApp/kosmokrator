<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\CompactionPlan;
use Kosmokrator\Agent\ConversationHistory;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ConversationHistoryCompactTest extends TestCase
{
    public function test_compact_replaces_old_with_summary(): void
    {
        $history = new ConversationHistory;
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');
        $history->addAssistant('Second answer');
        $history->addUser('Third question');
        $history->addAssistant('Third answer');

        $history->compact('Summary of old turns', keepRecent: 2);

        $messages = $history->messages();
        $this->assertInstanceOf(SystemMessage::class, $messages[0]);
        $this->assertSame('Summary of old turns', $messages[0]->content);

        // Last 2 user turns preserved
        $this->assertInstanceOf(UserMessage::class, $messages[1]);
        $this->assertSame('Second question', $messages[1]->content);
    }

    public function test_compact_noop_when_too_few_messages(): void
    {
        $history = new ConversationHistory;
        $history->addUser('Only message');

        $history->compact('Summary', keepRecent: 2);

        $messages = $history->messages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
    }

    public function test_add_message_adds_arbitrary_message(): void
    {
        $history = new ConversationHistory;
        $history->addMessage(new SystemMessage('Restored summary'));
        $history->addMessage(new UserMessage('Continued question'));

        $messages = $history->messages();
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(SystemMessage::class, $messages[0]);
        $this->assertInstanceOf(UserMessage::class, $messages[1]);
    }

    public function test_apply_compaction_plan_uses_replacement_history(): void
    {
        $history = new ConversationHistory;
        $history->addUser('First question');
        $history->addAssistant('First answer');
        $history->addUser('Second question');

        $plan = new CompactionPlan(
            keepFromMessageIndex: 2,
            compactedMessageCount: 2,
            summary: 'Summary',
            replacementMessages: [
                new SystemMessage('Protected Context'),
                new SystemMessage('Summary'),
                new UserMessage('Second question'),
            ],
        );

        $history->applyCompactionPlan($plan);

        $messages = $history->messages();
        $this->assertCount(3, $messages);
        $this->assertSame('Protected Context', $messages[0]->content);
        $this->assertSame('Summary', $messages[1]->content);
    }

    public function test_count(): void
    {
        $history = new ConversationHistory;
        $this->assertSame(0, $history->count());

        $history->addUser('Hello');
        $this->assertSame(1, $history->count());

        $history->addAssistant('Hi');
        $this->assertSame(2, $history->count());
    }
}
