<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\PrometheusCommand;
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

class PrometheusCommandTest extends TestCase
{
    private PrometheusCommand $command;

    protected function setUp(): void
    {
        $this->command = new PrometheusCommand;
    }

    private function makeContext(
        ?UIManager $ui = null,
        ?PermissionEvaluator $permissions = null,
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
        $this->assertSame('/prometheus', $this->command->name());
    }

    public function test_aliases_returns_empty_array(): void
    {
        $this->assertSame([], $this->command->aliases());
    }

    public function test_description(): void
    {
        $this->assertSame('Switch to Prometheus permission mode', $this->command->description());
    }

    public function test_immediate_returns_true(): void
    {
        $this->assertTrue($this->command->immediate());
    }

    public function test_execute_plays_prometheus(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('playPrometheus');

        $ctx = $this->makeContext(ui: $ui);

        $this->command->execute('', $ctx);
    }

    public function test_execute_sets_permission_mode(): void
    {
        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())
            ->method('setPermissionMode')
            ->with(PermissionMode::Prometheus);

        $ctx = $this->makeContext(permissions: $permissions);

        $this->command->execute('', $ctx);
    }

    public function test_execute_updates_ui(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('setPermissionMode')
            ->with(PermissionMode::Prometheus->statusLabel(), PermissionMode::Prometheus->color());

        $ctx = $this->makeContext(ui: $ui);

        $this->command->execute('', $ctx);
    }

    public function test_execute_persists_setting(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('setSetting')
            ->with('permission_mode', 'prometheus');

        $ctx = $this->makeContext(sessionManager: $sessionManager);

        $this->command->execute('', $ctx);
    }

    public function test_execute_shows_notice(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('⚡ Prometheus unbound — all tools auto-approved.');

        $ctx = $this->makeContext(ui: $ui);

        $this->command->execute('', $ctx);
    }

    public function test_execute_returns_continue(): void
    {
        $ctx = $this->makeContext();

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
