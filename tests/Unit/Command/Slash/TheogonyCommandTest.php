<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\TheogonyCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class TheogonyCommandTest extends TestCase
{
    private function makeContext(?UIManager $ui = null): SlashCommandContext
    {
        return new SlashCommandContext(
            ui: $ui ?? $this->createStub(UIManager::class),
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }

    public function test_name(): void
    {
        $command = new TheogonyCommand();

        $this->assertSame('/theogony', $command->name());
    }

    public function test_aliases(): void
    {
        $command = new TheogonyCommand();

        $this->assertSame(['/cosmogony'], $command->aliases());
    }

    public function test_description(): void
    {
        $command = new TheogonyCommand();

        $this->assertSame('Play the Theogony', $command->description());
    }

    public function test_immediate(): void
    {
        $command = new TheogonyCommand();

        $this->assertTrue($command->immediate());
    }

    public function test_execute_plays_theogony(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('playTheogony');

        $command = new TheogonyCommand();
        $ctx = $this->makeContext(ui: $ui);

        $command->execute('', $ctx);
    }

    public function test_execute_returns_continue(): void
    {
        $command = new TheogonyCommand();
        $ctx = $this->makeContext();

        $result = $command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
