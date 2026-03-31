<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\Command\Slash\NewCommand;
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

class NewCommandTest extends TestCase
{
    private NewCommand $command;

    protected function setUp(): void
    {
        $this->command = new NewCommand;
    }

    private function makeContext(
        ?AgentLoop $agentLoop = null,
        ?PermissionEvaluator $permissions = null,
    ): SlashCommandContext {
        $llm = $this->createStub(LlmClientInterface::class);
        $llm->method('getProvider')->willReturn('anthropic');
        $llm->method('getModel')->willReturn('claude-4');

        return new SlashCommandContext(
            ui: $this->createStub(UIManager::class),
            agentLoop: $agentLoop ?? $this->createStub(AgentLoop::class),
            permissions: $permissions ?? $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $llm,
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }

    public function test_name(): void
    {
        $this->assertSame('/new', $this->command->name());
    }

    public function test_execute_clears_history(): void
    {
        $history = $this->createMock(ConversationHistory::class);
        $history->expects($this->once())->method('clear');

        $agentLoop = $this->createStub(AgentLoop::class);
        $agentLoop->method('history')->willReturn($history);

        $ctx = $this->makeContext(agentLoop: $agentLoop);

        $this->command->execute('', $ctx);
    }

    public function test_execute_resets_grants(): void
    {
        $history = $this->createStub(ConversationHistory::class);
        $agentLoop = $this->createStub(AgentLoop::class);
        $agentLoop->method('history')->willReturn($history);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())->method('resetGrants');

        $ctx = $this->makeContext(agentLoop: $agentLoop, permissions: $permissions);

        $this->command->execute('', $ctx);
    }

    public function test_execute_resets_to_guardian(): void
    {
        $history = $this->createStub(ConversationHistory::class);
        $agentLoop = $this->createStub(AgentLoop::class);
        $agentLoop->method('history')->willReturn($history);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())
            ->method('setPermissionMode')
            ->with(PermissionMode::Guardian);

        $ctx = $this->makeContext(agentLoop: $agentLoop, permissions: $permissions);

        $this->command->execute('', $ctx);
    }

    public function test_execute_returns_continue(): void
    {
        $history = $this->createStub(ConversationHistory::class);
        $agentLoop = $this->createStub(AgentLoop::class);
        $agentLoop->method('history')->willReturn($history);

        $ctx = $this->makeContext(agentLoop: $agentLoop);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
