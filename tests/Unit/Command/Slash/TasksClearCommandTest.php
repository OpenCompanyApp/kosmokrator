<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\TasksClearCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class TasksClearCommandTest extends TestCase
{
    private TasksClearCommand $command;

    protected function setUp(): void
    {
        $this->command = new TasksClearCommand;
    }

    private function makeContext(?TaskStore $taskStore = null): SlashCommandContext
    {
        return new SlashCommandContext(
            ui: $this->createStub(UIManager::class),
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $taskStore ?? $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }

    public function test_name(): void
    {
        $this->assertSame('/tasks clear', $this->command->name());
    }

    public function test_execute_clears_tasks(): void
    {
        $taskStore = $this->createMock(TaskStore::class);
        $taskStore->method('all')->willReturn([]);
        $taskStore->expects($this->once())->method('clearAll');

        $ctx = $this->makeContext(taskStore: $taskStore);

        $this->command->execute('', $ctx);
    }

    public function test_execute_returns_continue(): void
    {
        $taskStore = $this->createStub(TaskStore::class);
        $taskStore->method('all')->willReturn([]);

        $ctx = $this->makeContext(taskStore: $taskStore);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
