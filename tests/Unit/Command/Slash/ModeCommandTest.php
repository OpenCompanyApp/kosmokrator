<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Command\Slash\ModeCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class ModeCommandTest extends TestCase
{
    private function makeContext(?AgentLoop $agentLoop = null, ?SessionManager $sessionManager = null): SlashCommandContext
    {
        return new SlashCommandContext(
            ui: $this->createStub(UIManager::class),
            agentLoop: $agentLoop ?? $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $sessionManager ?? $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }

    public function test_name_for_edit(): void
    {
        $command = new ModeCommand(AgentMode::Edit);

        $this->assertSame('/edit', $command->name());
    }

    public function test_name_for_plan(): void
    {
        $command = new ModeCommand(AgentMode::Plan);

        $this->assertSame('/plan', $command->name());
    }

    public function test_execute_sets_mode(): void
    {
        $agentLoop = $this->createMock(AgentLoop::class);
        $agentLoop->expects($this->once())
            ->method('setMode')
            ->with(AgentMode::Edit);

        $command = new ModeCommand(AgentMode::Edit);
        $ctx = $this->makeContext(agentLoop: $agentLoop);

        $command->execute('', $ctx);
    }

    public function test_execute_persists_canonical_mode_setting(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('setSetting')
            ->with('agent.mode', 'plan');

        $command = new ModeCommand(AgentMode::Plan);
        $ctx = $this->makeContext(sessionManager: $sessionManager);

        $command->execute('', $ctx);
    }

    public function test_execute_returns_continue(): void
    {
        $command = new ModeCommand(AgentMode::Edit);
        $ctx = $this->makeContext();

        $result = $command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
