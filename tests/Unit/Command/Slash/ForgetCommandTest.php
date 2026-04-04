<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\ForgetCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class ForgetCommandTest extends TestCase
{
    private ForgetCommand $command;

    protected function setUp(): void
    {
        $this->command = new ForgetCommand;
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
        $this->assertSame('/forget', $this->command->name());
    }

    public function test_execute_deletes_memory(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('findMemory')
            ->with(42)
            ->willReturn(['id' => 42, 'title' => 'test']);
        $sessionManager->expects($this->once())
            ->method('deleteMemory')
            ->with(42);

        $ctx = $this->makeContext(sessionManager: $sessionManager);

        $this->command->execute('42', $ctx);
    }

    public function test_execute_invalid_id_shows_usage(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('Usage: /forget <id>');

        $ctx = $this->makeContext(ui: $ui);

        $this->command->execute('', $ctx);
    }

    public function test_execute_returns_continue(): void
    {
        $ctx = $this->makeContext();

        $result = $this->command->execute('42', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
