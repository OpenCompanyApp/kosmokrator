<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\MemoriesCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class MemoriesCommandTest extends TestCase
{
    private MemoriesCommand $command;

    protected function setUp(): void
    {
        $this->command = new MemoriesCommand;
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
        $this->assertSame('/memories', $this->command->name());
    }

    public function test_aliases(): void
    {
        $this->assertSame([], $this->command->aliases());
    }

    public function test_description(): void
    {
        $this->assertSame('List stored memories', $this->command->description());
    }

    public function test_immediate(): void
    {
        $this->assertTrue($this->command->immediate());
    }

    public function test_execute_empty_memories_shows_notice(): void
    {
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('getMemories')
            ->willReturn([]);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('No memories stored yet.');

        $ctx = $this->makeContext(sessionManager: $sessionManager, ui: $ui);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_memories_shows_formatted_lines(): void
    {
        $memories = [
            ['id' => 1, 'type' => 'project', 'title' => 'Uses PHP 8.4'],
            ['id' => 2, 'type' => 'user', 'title' => 'Prefers concise output'],
        ];

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->expects($this->once())
            ->method('getMemories')
            ->willReturn($memories);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with("Memories:\n  [1] (project) Uses PHP 8.4\n  [2] (user) Prefers concise output");

        $ctx = $this->makeContext(sessionManager: $sessionManager, ui: $ui);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
