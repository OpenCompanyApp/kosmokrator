<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Amp\CancelledException;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\UI\RendererInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\NullLogger;

class AgentLoopTest extends TestCase
{
    private LlmClientInterface&\PHPUnit\Framework\MockObject\Stub $llm;
    private RendererInterface&\PHPUnit\Framework\MockObject\MockObject $ui;
    private AgentLoop $loop;

    protected function setUp(): void
    {
        $this->llm = $this->createStub(LlmClientInterface::class);
        $this->ui = $this->createMock(RendererInterface::class);
        $this->loop = new AgentLoop($this->llm, $this->ui, new NullLogger());
    }

    public function test_simple_text_response_no_tools(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('Hello!', FinishReason::Stop, [], 100, 50),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $this->ui->expects($this->once())->method('showThinking');
        $this->ui->expects($this->once())->method('clearThinking');
        $this->ui->expects($this->once())->method('streamChunk')->with('Hello!');
        $this->ui->expects($this->once())->method('streamComplete');
        $this->ui->expects($this->once())->method('showStatus');

        $this->loop->run('Hi');

        $messages = $this->loop->history()->messages();
        $this->assertCount(2, $messages); // user + assistant
    }

    public function test_tool_call_round_then_final_response(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'test_tool', arguments: '{"input": "hello"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Done!', FinishReason::Stop, [], 200, 50),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        // Set up a tool that matches
        $tool = (new Tool())
            ->as('test_tool')
            ->for('Test tool')
            ->withStringParameter('input', 'Input')
            ->using(fn (string $input) => "result: {$input}");
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())->method('showToolCall');
        $this->ui->expects($this->once())->method('showToolResult');

        $this->loop->run('Do something');
    }

    public function test_tool_not_found(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'nonexistent', arguments: '{}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Sorry', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $this->ui->expects($this->once())
            ->method('showToolResult')
            ->with('nonexistent', $this->stringContains("not found"), false);

        $this->loop->run('Call missing tool');
    }

    public function test_tool_execution_exception(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'failing', arguments: '{}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Recovered', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $tool = (new Tool())
            ->as('failing')
            ->for('A failing tool')
            ->withoutErrorHandling()
            ->using(function () { throw new \RuntimeException('Tool exploded'); });
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('showToolResult')
            ->with('failing', $this->stringContains('Error'), false);

        $this->loop->run('Call failing tool');
    }

    public function test_max_tool_rounds_reached(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'loop_tool', arguments: '{}');

        // Always return tool calls — never stop
        $this->llm->method('chat')->willReturn(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
        );

        $tool = (new Tool())
            ->as('loop_tool')
            ->for('Loops forever')
            ->using(fn () => 'ok');
        $loop = new AgentLoop($this->llm, $this->ui, new NullLogger(), maxToolRounds: 2);
        $loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('showError')
            ->with($this->stringContains('Maximum tool rounds (2)'));

        $loop->run('Loop forever');
    }

    public function test_cancelled_exception_returns_early(): void
    {
        $this->llm->method('chat')->willThrowException(new CancelledException());

        $this->ui->expects($this->once())->method('clearThinking');
        $this->ui->expects($this->never())->method('showStatus');
        $this->ui->expects($this->never())->method('showError');

        $this->loop->run('Cancelled');
    }

    public function test_context_overflow_trims_and_retries(): void
    {
        $callCount = 0;
        $this->llm->method('chat')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \RuntimeException('Prompt exceeds max length');
            }

            return new LlmResponse('Recovered', FinishReason::Stop, [], 100, 50);
        });
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        // Pre-populate history so trimOldest has something to trim
        $this->loop->history()->addUser('old question');
        $this->loop->history()->addAssistant('old answer');

        $this->ui->expects($this->never())->method('showError');
        $this->ui->expects($this->once())->method('showStatus');

        $this->loop->run('New question');
    }

    public function test_context_overflow_max_three_trim_attempts(): void
    {
        $this->llm->method('chat')->willThrowException(
            new \RuntimeException('context length exceeded'),
        );

        // Add enough history for 3 trims
        for ($i = 0; $i < 4; $i++) {
            $this->loop->history()->addUser("q{$i}");
            $this->loop->history()->addAssistant("a{$i}");
        }

        $this->ui->expects($this->once())->method('showError');

        $this->loop->run('Trigger overflow');
    }

    public function test_context_overflow_when_cannot_trim(): void
    {
        $this->llm->method('chat')->willThrowException(
            new \RuntimeException('Prompt too long'),
        );

        // Empty history — nothing to trim (only the user message just added, <3 messages)
        $this->ui->expects($this->once())->method('showError');

        $this->loop->run('Question');
    }

    public function test_non_context_error_shows_error(): void
    {
        $this->llm->method('chat')->willThrowException(
            new \RuntimeException('Connection refused'),
        );

        $this->ui->expects($this->once())
            ->method('showError')
            ->with($this->stringContains('Connection refused'));

        $this->loop->run('Question');
    }

    public function test_empty_text_response_does_not_call_stream(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('', FinishReason::Stop, [], 100, 50),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $this->ui->expects($this->never())->method('streamChunk');
        $this->ui->expects($this->never())->method('streamComplete');

        $this->loop->run('Question');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_history_returns_conversation_history_instance(): void
    {
        $this->assertInstanceOf(ConversationHistory::class, $this->loop->history());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_set_tools(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'my_tool', arguments: '{}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Done', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $executed = false;
        $tool = (new Tool())
            ->as('my_tool')
            ->for('My tool')
            ->using(function () use (&$executed) {
                $executed = true;

                return 'ok';
            });
        $this->loop->setTools([$tool]);

        $this->loop->run('Use my tool');

        $this->assertTrue($executed);
    }

    public function test_estimate_cost_calculation(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('text', FinishReason::Stop, [], 1000, 500),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        // Cost = (1000 * 3 / 1M) + (500 * 15 / 1M) = 0.003 + 0.0075 = 0.0105
        $this->ui->expects($this->once())
            ->method('showStatus')
            ->with(
                'test/model',
                1000,
                500,
                0.0105,
            );

        $this->loop->run('Price check');
    }

    public function test_get_model_name_combines_provider_and_model(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('hi', FinishReason::Stop, [], 10, 5),
        );
        $this->llm->method('getProvider')->willReturn('anthropic');
        $this->llm->method('getModel')->willReturn('claude-4');

        $this->ui->expects($this->once())
            ->method('showStatus')
            ->with('anthropic/claude-4', $this->anything(), $this->anything(), $this->anything());

        $this->loop->run('Test');
    }
}
