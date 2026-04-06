<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\Command\Slash\ResumeCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class ResumeCommandTest extends TestCase
{
    private ResumeCommand $command;

    protected function setUp(): void
    {
        $this->command = new ResumeCommand;
    }

    private function makeContext(
        ?UIManager $ui = null,
        ?AgentLoop $agentLoop = null,
        ?PermissionEvaluator $permissions = null,
        ?SessionManager $sessionManager = null,
    ): SlashCommandContext {
        $llm = $this->createStub(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('anthropic');
        $llm->method('getModel')->willReturn('claude-4');

        return new SlashCommandContext(
            ui: $ui ?? $this->createStub(UIManager::class),
            agentLoop: $agentLoop ?? $this->createStub(AgentLoop::class),
            permissions: $permissions ?? $this->createStub(PermissionEvaluator::class),
            sessionManager: $sessionManager ?? $this->createStub(SessionManager::class),
            llm: $llm,
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }

    public function test_name(): void
    {
        $this->assertSame('/resume', $this->command->name());
    }

    public function test_aliases_is_empty(): void
    {
        $this->assertSame([], $this->command->aliases());
    }

    public function test_description(): void
    {
        $this->assertSame('Resume a previous session', $this->command->description());
    }

    public function test_immediate_is_false(): void
    {
        $this->assertFalse($this->command->immediate());
    }

    public function test_execute_with_empty_args_and_no_sessions_shows_notice(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())->method('listSessions')->with(50)->willReturn([]);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())->method('showNotice')->with('No sessions to resume.');

        $ctx = $this->makeContext(ui: $ui, sessionManager: $sessionManager);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_empty_args_shows_interactive_picker(): void
    {
        $sessions = [
            [
                'id' => 'sess-1',
                'title' => 'Test session',
                'message_count' => 5,
                'updated_at' => '2026-04-03T10:00:00Z',
                'last_user_message' => 'Hello world',
            ],
        ];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())->method('listSessions')->with(50)->willReturn($sessions);
        $sessionManager->expects($this->once())->method('currentSessionId')->willReturn('other-id');
        $sessionManager->expects($this->once())->method('resumeSession')->with('sess-1')->willReturn(
            $history = $this->createStub(ConversationHistory::class)
        );
        $sessionManager->expects($this->once())->method('findSession')->with('sess-1')->willReturn($sessions[0]);

        $history->method('messages')->willReturn([]);
        $history->method('count')->willReturn(5);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())->method('pickSession')->willReturn('sess-1');
        $ui->expects($this->once())->method('clearConversation');
        $ui->expects($this->once())->method('replayHistory')->with([]);
        $ui->expects($this->once())->method('showNotice')->with('Resumed: Test session (5 messages)');

        $agentLoop = $this->createMock(AgentLoop::class);
        $agentLoop->expects($this->once())->method('setHistory')->with($history);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())->method('resetGrants');

        $ctx = $this->makeContext(
            ui: $ui,
            agentLoop: $agentLoop,
            permissions: $permissions,
            sessionManager: $sessionManager,
        );

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_empty_args_picker_cancelled(): void
    {
        $sessions = [
            [
                'id' => 'sess-1',
                'title' => 'Test session',
                'message_count' => 5,
                'updated_at' => '2026-04-03T10:00:00Z',
                'last_user_message' => 'Hello world',
            ],
        ];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())->method('listSessions')->with(50)->willReturn($sessions);
        $sessionManager->expects($this->once())->method('currentSessionId')->willReturn('other-id');
        $sessionManager->expects($this->never())->method('resumeSession');

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())->method('pickSession')->willReturn(null);
        $ui->expects($this->never())->method('clearConversation');

        $ctx = $this->makeContext(ui: $ui, sessionManager: $sessionManager);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_args_finds_session(): void
    {
        $sessionData = ['id' => 'sess-abc', 'title' => 'My session'];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->exactly(2))->method('findSession')
            ->willReturnMap([['abc', $sessionData], ['sess-abc', $sessionData]]);
        $sessionManager->expects($this->once())->method('resumeSession')->with('sess-abc')->willReturn(
            $history = $this->createStub(ConversationHistory::class)
        );

        $history->method('messages')->willReturn([]);
        $history->method('count')->willReturn(3);

        $agentLoop = $this->createMock(AgentLoop::class);
        $agentLoop->expects($this->once())->method('setHistory')->with($history);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())->method('resetGrants');

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())->method('clearConversation');
        $ui->expects($this->once())->method('replayHistory')->with([]);
        $ui->expects($this->once())->method('showNotice')->with('Resumed: My session (3 messages)');

        $ctx = $this->makeContext(
            ui: $ui,
            agentLoop: $agentLoop,
            permissions: $permissions,
            sessionManager: $sessionManager,
        );

        $result = $this->command->execute('abc', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_args_not_found_shows_notice(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())->method('findSession')->with('nonexistent')->willReturn(null);
        $sessionManager->expects($this->never())->method('resumeSession');

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())->method('showNotice')->with("No session found matching 'nonexistent'.");
        $ui->expects($this->never())->method('clearConversation');

        $ctx = $this->makeContext(ui: $ui, sessionManager: $sessionManager);

        $result = $this->command->execute('nonexistent', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_resumes_with_untitled_session(): void
    {
        $sessionData = ['id' => 'sess-x'];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->exactly(2))->method('findSession')
            ->willReturnMap([['x', $sessionData], ['sess-x', $sessionData]]);
        $sessionManager->expects($this->once())->method('resumeSession')->with('sess-x')->willReturn(
            $history = $this->createStub(ConversationHistory::class)
        );

        $history->method('messages')->willReturn([]);
        $history->method('count')->willReturn(0);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())->method('showNotice')->with('Resumed: (untitled) (0 messages)');

        $ctx = $this->makeContext(ui: $ui, sessionManager: $sessionManager);

        $this->command->execute('x', $ctx);
    }
}
