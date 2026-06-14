<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\ContextBreakdown;
use Kosmokrator\Agent\ContextBucket;
use Kosmokrator\Agent\ContextSuggestion;
use Kosmokrator\Command\Slash\ContextCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\RendererInterface;
use PHPUnit\Framework\TestCase;

final class ContextCommandTest extends TestCase
{
    public function test_execute_renders_context_summary(): void
    {
        $command = new ContextCommand;
        $breakdown = new ContextBreakdown(
            model: 'z/glm',
            estimatedTokens: 1200,
            contextWindow: 100_000,
            effectiveWindow: 84_000,
            budget: ['warning_threshold' => 60_000, 'auto_compact_threshold' => 72_000, 'blocking_threshold' => 81_000],
            buckets: [new ContextBucket('stable_system', 300), new ContextBucket('tool:bash', 900)],
            largestItems: [new ContextBucket('tool:bash', 900, toolName: 'bash')],
            cache: ['cache_read_tokens' => 100, 'cache_write_tokens' => 50],
        );

        $agentLoop = $this->createMock(AgentLoop::class);
        $agentLoop->method('contextBreakdown')->willReturn($breakdown);
        $agentLoop->method('contextSuggestions')->willReturn([
            new ContextSuggestion('info', 'cache.test', 'Cache is warm.', 'Keep stable prompts stable.'),
        ]);

        $ui = $this->createMock(RendererInterface::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Context'));

        $result = $command->execute('', $this->ctx($ui, $agentLoop));

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    private function ctx(RendererInterface $ui, AgentLoop $agentLoop): SlashCommandContext
    {
        return new SlashCommandContext(
            ui: $ui,
            agentLoop: $agentLoop,
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
        );
    }
}
