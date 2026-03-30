<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\TokenEstimator;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class TokenEstimatorTest extends TestCase
{
    public function test_estimate_empty_string(): void
    {
        $this->assertSame(0, TokenEstimator::estimate(''));
    }

    public function test_estimate_short_string(): void
    {
        // "hello" = 5 chars → ceil(5/4) = 2
        $this->assertSame(2, TokenEstimator::estimate('hello'));
    }

    public function test_estimate_exact_multiple(): void
    {
        // 8 chars → ceil(8/4) = 2
        $this->assertSame(2, TokenEstimator::estimate('abcdefgh'));
    }

    public function test_estimate_user_message(): void
    {
        $msg = new UserMessage('hello world'); // 11 chars → ceil(11/4) = 3
        $this->assertSame(3, TokenEstimator::estimateMessage($msg));
    }

    public function test_estimate_assistant_message(): void
    {
        $msg = new AssistantMessage('response text'); // 13 chars → ceil(13/4) = 4
        $this->assertSame(4, TokenEstimator::estimateMessage($msg));
    }

    public function test_estimate_assistant_with_tool_calls(): void
    {
        $msg = new AssistantMessage(
            content: '',
            toolCalls: [
                new ToolCall(id: 'tc1', name: 'bash', arguments: ['command' => 'ls']),
            ],
        );

        $tokens = TokenEstimator::estimateMessage($msg);
        $this->assertGreaterThan(0, $tokens);
    }

    public function test_estimate_tool_result_message(): void
    {
        $msg = new ToolResultMessage([
            new ToolResult(toolCallId: 'tc1', toolName: 'bash', args: [], result: str_repeat('x', 400)),
        ]);

        // 400 chars → ceil(400/4) = 100
        $this->assertSame(100, TokenEstimator::estimateMessage($msg));
    }

    public function test_estimate_system_message(): void
    {
        $msg = new SystemMessage('system prompt text'); // 18 chars → ceil(18/4) = 5
        $this->assertSame(5, TokenEstimator::estimateMessage($msg));
    }

    public function test_estimate_messages_array(): void
    {
        $messages = [
            new UserMessage('hello'),           // 5 → 2
            new AssistantMessage('world'),       // 5 → 2
            new UserMessage('test'),             // 4 → 1
        ];

        $this->assertSame(5, TokenEstimator::estimateMessages($messages));
    }
}
