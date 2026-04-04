<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\QuitCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class QuitCommandTest extends TestCase
{
    private QuitCommand $command;

    protected function setUp(): void
    {
        $this->command = new QuitCommand;
    }

    public function test_name(): void
    {
        $this->assertSame('/quit', $this->command->name());
    }

    public function test_aliases(): void
    {
        $this->assertSame(['/exit', '/q'], $this->command->aliases());
    }

    public function test_execute_returns_quit(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->never())->method('teardown');

        $ctx = new SlashCommandContext(
            ui: $ui,
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Quit, $result->action);
    }
}
