<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\ArgusCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class ArgusCommandTest extends TestCase
{
    private function makeContext(
        ?PermissionEvaluator $permissions = null,
        ?UIManager $ui = null,
        ?SessionManager $sessionManager = null,
    ): SlashCommandContext {
        return new SlashCommandContext(
            ui: $ui ?? $this->createStub(UIManager::class),
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $permissions ?? $this->createStub(PermissionEvaluator::class),
            sessionManager: $sessionManager ?? $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }

    public function test_name(): void
    {
        $command = new ArgusCommand;

        $this->assertSame('/argus', $command->name());
    }

    public function test_aliases(): void
    {
        $command = new ArgusCommand;

        $this->assertSame([], $command->aliases());
    }

    public function test_description(): void
    {
        $command = new ArgusCommand;

        $this->assertSame('Switch to Argus permission mode', $command->description());
    }

    public function test_immediate(): void
    {
        $command = new ArgusCommand;

        $this->assertTrue($command->immediate());
    }

    public function test_execute_sets_permission_mode(): void
    {
        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())
            ->method('setPermissionMode')
            ->with(PermissionMode::Argus);

        $command = new ArgusCommand;
        $ctx = $this->makeContext(permissions: $permissions);

        $command->execute('', $ctx);
    }

    public function test_execute_updates_ui_permission_mode(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('setPermissionMode')
            ->with(
                PermissionMode::Argus->statusLabel(),
                PermissionMode::Argus->color(),
            );

        $command = new ArgusCommand;
        $ctx = $this->makeContext(ui: $ui);

        $command->execute('', $ctx);
    }

    public function test_execute_persists_setting(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('setSetting')
            ->with('tools.default_permission_mode', 'argus');

        $command = new ArgusCommand;
        $ctx = $this->makeContext(sessionManager: $sessionManager);

        $command->execute('', $ctx);
    }

    public function test_execute_shows_notice(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('◉ Argus mode — all write operations require approval.');

        $command = new ArgusCommand;
        $ctx = $this->makeContext(ui: $ui);

        $command->execute('', $ctx);
    }

    public function test_execute_returns_continue(): void
    {
        $command = new ArgusCommand;
        $ctx = $this->makeContext();

        $result = $command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
