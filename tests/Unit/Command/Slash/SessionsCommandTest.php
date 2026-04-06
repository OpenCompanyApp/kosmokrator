<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\SessionsCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class SessionsCommandTest extends TestCase
{
    private SessionsCommand $command;

    protected function setUp(): void
    {
        $this->command = new SessionsCommand;
    }

    private function makeContext(
        ?SessionManager $sessionManager = null,
        ?UIManager $ui = null,
    ): SlashCommandContext {
        return new SlashCommandContext(
            ui: $ui ?? $this->createStub(UIManager::class),
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $sessionManager ?? $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }

    public function test_name(): void
    {
        $this->assertSame('/sessions', $this->command->name());
    }

    public function test_aliases(): void
    {
        $this->assertSame([], $this->command->aliases());
    }

    public function test_description(): void
    {
        $this->assertSame('List, delete, or clean up sessions', $this->command->description());
    }

    public function test_immediate(): void
    {
        $this->assertTrue($this->command->immediate());
    }

    public function test_execute_empty_sessions_shows_notice(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('listSessions')
            ->with(10)
            ->willReturn([]);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('No sessions found for this project.');

        $ctx = $this->makeContext(sessionManager: $sessionManager, ui: $ui);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_sessions_shows_formatted_lines(): void
    {
        $now = (string) time();
        $past = (string) (time() - 300);

        $sessions = [
            [
                'id' => 'abcdef1234567890',
                'message_count' => 5,
                'updated_at' => $now,
                'last_user_message' => 'Fix the login bug',
            ],
            [
                'id' => 'deadbeefdeadbeef',
                'message_count' => 12,
                'updated_at' => $past,
                'title' => 'Refactor module',
            ],
        ];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('listSessions')
            ->with(10)
            ->willReturn($sessions);
        $sessionManager->expects($this->exactly(2))
            ->method('currentSessionId')
            ->willReturn('abcdef1234567890');

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with($this->callback(function (string $notice): bool {
                // Verify header
                $this->assertStringStartsWith("Recent sessions:\n", $notice);
                // Verify first session (current) has arrow marker and truncated id
                $this->assertStringContainsString('abcdef12', $notice);
                $this->assertStringContainsString('←', $notice);
                // Verify second session has no arrow and shows the title
                $this->assertStringContainsString('deadbeef', $notice);
                $this->assertStringContainsString('Refactor module', $notice);
                // Verify message counts
                $this->assertStringContainsString('5 msgs', $notice);
                $this->assertStringContainsString('12 msgs', $notice);

                return true;
            }));

        $ctx = $this->makeContext(sessionManager: $sessionManager, ui: $ui);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_session_without_preview_shows_empty(): void
    {
        $sessions = [
            [
                'id' => '0000000000000000',
                'message_count' => 0,
                'updated_at' => (string) time(),
            ],
        ];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('listSessions')
            ->with(10)
            ->willReturn($sessions);
        $sessionManager->expects($this->once())
            ->method('currentSessionId')
            ->willReturn(null);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with($this->callback(function (string $notice): bool {
                $this->assertStringContainsString('(empty)', $notice);

                return true;
            }));

        $ctx = $this->makeContext(sessionManager: $sessionManager, ui: $ui);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
