<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\Command\Slash\CompactCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class CompactCommandTest extends TestCase
{
    private CompactCommand $command;

    protected function setUp(): void
    {
        $this->command = new CompactCommand;
    }

    public function test_name(): void
    {
        $this->assertSame('/compact', $this->command->name());
    }

    public function test_aliases(): void
    {
        $this->assertSame([], $this->command->aliases());
    }

    public function test_description(): void
    {
        $this->assertSame('Force context compaction', $this->command->description());
    }

    public function test_immediate(): void
    {
        $this->assertFalse($this->command->immediate());
    }

    public function test_execute_calls_perform_compaction_and_returns_continue(): void
    {
        $history = $this->createStub(ConversationHistory::class);
        $history->method('count')->willReturnOnConsecutiveCalls(5, 2);

        $agentLoop = $this->createMock(AgentLoop::class);
        $agentLoop->method('history')->willReturn($history);
        $agentLoop->expects($this->once())->method('performCompaction');

        $ctx = new SlashCommandContext(
            ui: $this->createStub(UIManager::class),
            agentLoop: $agentLoop,
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_handles_compaction_failure_and_returns_continue(): void
    {
        $history = $this->createStub(ConversationHistory::class);
        $history->method('count')->willReturn(5);

        $agentLoop = $this->createMock(AgentLoop::class);
        $agentLoop->method('history')->willReturn($history);
        $agentLoop->expects($this->once())
            ->method('performCompaction')
            ->willThrowException(new \RuntimeException('provider unavailable'));

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('Context compaction failed: provider unavailable');

        $ctx = new SlashCommandContext(
            ui: $ui,
            agentLoop: $agentLoop,
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }
}
