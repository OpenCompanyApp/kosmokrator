<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Amp\CancelledException;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionRule;
use Kosmokrator\Tool\Permission\SessionGrants;
use Kosmokrator\UI\RendererInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\NullLogger;

class AgentLoopTest extends TestCase
{
    private LlmClientInterface&Stub $llm;

    private RendererInterface&MockObject $ui;

    private AgentLoop $loop;

    protected function setUp(): void
    {
        $this->llm = $this->createStub(LlmClientInterface::class);
        $this->ui = $this->createMock(RendererInterface::class);
        $this->loop = new AgentLoop($this->llm, $this->ui, new NullLogger, 'You are a test assistant.');
    }

    public function test_simple_text_response_no_tools(): void
    {
        $this->llm->method('chat')->willReturn(
            new LlmResponse('Hello!', FinishReason::Stop, [], 100, 50),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $this->ui->expects($this->atLeastOnce())->method('setPhase');
        $this->ui->expects($this->once())->method('streamChunk')->with('Hello!');
        $this->ui->expects($this->once())->method('streamComplete');
        $this->ui->expects($this->once())->method('showStatus');

        $this->loop->run('Hi');

        $messages = $this->loop->history()->messages();
        $this->assertCount(2, $messages); // user + assistant
    }

    public function test_queued_user_messages_are_included_before_memory_selection_for_same_turn(): void
    {
        $db = new Database(':memory:');
        $sessionManager = new SessionManager(
            new SessionRepository($db),
            new MessageRepository($db),
            new SettingsRepository($db),
            new MemoryRepository($db),
            new NullLogger,
        );
        $sessionManager->setProject('/project');
        $sessionManager->createSession('model');

        $jwtMemoryId = $sessionManager->addMemory('project', 'JWT note', 'JWT auth is enabled');
        $db->connection()->prepare('UPDATE memories SET created_at = :ts, updated_at = :ts WHERE id = :id')
            ->execute(['ts' => '2000-01-01T00:00:00+00:00', 'id' => $jwtMemoryId]);

        for ($i = 1; $i <= 5; $i++) {
            $sessionManager->addMemory('project', "Pinned {$i}", "Pinned filler {$i}", 'durable', true);
        }
        $sessionManager->addMemory('project', 'Recent filler', 'Recent filler memory');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->once())
            ->method('setSystemPrompt')
            ->with($this->stringContains('JWT note'));
        $llm->expects($this->once())
            ->method('chat')
            ->willReturn(new LlmResponse('Done.', FinishReason::Stop, [], 100, 50));
        $llm->method('getProvider')->willReturn('test');
        $llm->method('getModel')->willReturn('model');

        $ui = $this->createMock(RendererInterface::class);
        $ui->method('consumeQueuedMessage')->willReturnOnConsecutiveCalls('JWT', null);

        $loop = new AgentLoop($llm, $ui, new NullLogger, 'You are a test assistant.', sessionManager: $sessionManager);
        $loop->run('Unrelated request');
    }

    public function test_tool_call_round_then_final_response(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "hello"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Done!', FinishReason::Stop, [], 200, 50),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        // Tool name must be in AgentMode::Edit allowed list
        $tool = (new Tool)
            ->as('grep')
            ->for('Search files')
            ->withStringParameter('pattern', 'Pattern')
            ->using(fn (string $pattern) => "result: {$pattern}");
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
            ->with('nonexistent', $this->stringContains('not found'), false);

        $this->loop->run('Call missing tool');
    }

    public function test_tool_execution_exception(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: '{}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Recovered', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $tool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withoutErrorHandling()
            ->using(function () {
                throw new \RuntimeException('Tool exploded');
            });
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('showToolResult')
            ->with('bash', $this->stringContains('Error'), false);

        $this->loop->run('Call failing tool');
    }

    public function test_only_one_ask_tool_is_allowed_per_response(): void
    {
        $first = new ToolCall(id: 'tc_1', name: 'ask_user', arguments: '{"question":"First question?"}');
        $second = new ToolCall(id: 'tc_2', name: 'ask_user', arguments: '{"question":"Second question?"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$first, $second], 100, 20),
            new LlmResponse('Done.', FinishReason::Stop, [], 150, 40),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $tool = (new Tool)
            ->as('ask_user')
            ->for('Ask the user a question')
            ->withStringParameter('question', 'Question')
            ->using(fn (string $question) => $this->ui->askUser($question));
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('askUser')
            ->with('First question?')
            ->willReturn('yes');

        $this->loop->run('Need clarification');

        $messages = $this->loop->history()->messages();
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $this->assertCount(2, $messages[2]->toolResults);
        $this->assertSame('yes', $messages[2]->toolResults[0]->result);
        $this->assertStringContainsString(
            'Only one interactive question may be asked per response',
            (string) $messages[2]->toolResults[1]->result
        );
    }

    public function test_plan_mode_blocks_mutative_bash(): void
    {
        $rules = [new PermissionRule('bash', PermissionAction::Allow)];
        $this->loop = new AgentLoop(
            $this->llm,
            $this->ui,
            new NullLogger,
            'You are a test assistant.',
            new PermissionEvaluator($rules, new SessionGrants, [], new GuardianEvaluator(getcwd(), ['git *'])),
        );
        $this->loop->setMode(AgentMode::Plan);

        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: '{"command":"touch tmp.txt"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Blocked', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $executed = false;
        $tool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withStringParameter('command', 'Command')
            ->using(function () use (&$executed) {
                $executed = true;

                return 'should not run';
            });
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('showToolResult')
            ->with('bash', $this->stringContains('Command blocked in Plan mode'), false);

        $this->loop->run('Try to mutate in plan mode');

        $this->assertFalse($executed);
    }

    public function test_ask_mode_blocks_mutative_bash(): void
    {
        $rules = [new PermissionRule('bash', PermissionAction::Allow)];
        $this->loop = new AgentLoop(
            $this->llm,
            $this->ui,
            new NullLogger,
            'You are a test assistant.',
            new PermissionEvaluator($rules, new SessionGrants, [], new GuardianEvaluator(getcwd(), ['git *'])),
        );
        $this->loop->setMode(AgentMode::Ask);

        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: '{"command":"git commit -m \\"x\\""}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Blocked', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $executed = false;
        $tool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withStringParameter('command', 'Command')
            ->using(function () use (&$executed) {
                $executed = true;

                return 'should not run';
            });
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('showToolResult')
            ->with('bash', $this->stringContains('Command blocked in Ask mode'), false);

        $this->loop->run('Try to mutate in ask mode');

        $this->assertFalse($executed);
    }

    public function test_plan_mode_blocks_mutative_shell_start(): void
    {
        $rules = [new PermissionRule('shell_start', PermissionAction::Allow)];
        $this->loop = new AgentLoop(
            $this->llm,
            $this->ui,
            new NullLogger,
            'You are a test assistant.',
            new PermissionEvaluator($rules, new SessionGrants, [], new GuardianEvaluator(getcwd(), ['git *'])),
        );
        $this->loop->setMode(AgentMode::Plan);

        $toolCall = new ToolCall(id: 'tc_1', name: 'shell_start', arguments: '{"command":"touch tmp.txt"}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Blocked', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $executed = false;
        $tool = (new Tool)
            ->as('shell_start')
            ->for('Start shell')
            ->withStringParameter('command', 'Command')
            ->using(function () use (&$executed) {
                $executed = true;

                return 'should not run';
            });
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('showToolResult')
            ->with('shell_start', $this->stringContains('Command blocked in Plan mode'), false);

        $this->loop->run('Try to start mutative shell in plan mode');

        $this->assertFalse($executed);
    }

    public function test_ask_mode_blocks_mutative_shell_write(): void
    {
        $rules = [new PermissionRule('shell_write', PermissionAction::Allow)];
        $this->loop = new AgentLoop(
            $this->llm,
            $this->ui,
            new NullLogger,
            'You are a test assistant.',
            new PermissionEvaluator($rules, new SessionGrants, [], new GuardianEvaluator(getcwd(), ['git *'])),
        );
        $this->loop->setMode(AgentMode::Ask);

        $toolCall = new ToolCall(id: 'tc_1', name: 'shell_write', arguments: '{"session_id":"sh_1","input":"git commit -m \\"x\\""}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Blocked', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $executed = false;
        $tool = (new Tool)
            ->as('shell_write')
            ->for('Write shell')
            ->withStringParameter('session_id', 'Session')
            ->withStringParameter('input', 'Input')
            ->using(function () use (&$executed) {
                $executed = true;

                return 'should not run';
            });
        $this->loop->setTools([$tool]);

        $this->ui->expects($this->once())
            ->method('showToolResult')
            ->with('shell_write', $this->stringContains('Command blocked in Ask mode'), false);

        $this->loop->run('Try to write mutative shell input in ask mode');

        $this->assertFalse($executed);
    }

    public function test_cancelled_exception_returns_early(): void
    {
        $this->llm->method('chat')->willThrowException(new CancelledException);

        $this->ui->expects($this->atLeastOnce())->method('setPhase');
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
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_read', arguments: '{}');

        $this->llm->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 100, 20),
            new LlmResponse('Done', FinishReason::Stop, [], 100, 20),
        );
        $this->llm->method('getProvider')->willReturn('test');
        $this->llm->method('getModel')->willReturn('model');

        $executed = false;
        $tool = (new Tool)
            ->as('file_read')
            ->for('Read files')
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
