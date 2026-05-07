<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\GoalCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Goal\GoalRepository;
use Kosmokrator\Goal\GoalStatus;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\RendererInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoalCommandTest extends TestCase
{
    private GoalCommand $command;

    private SessionManager $session;

    protected function setUp(): void
    {
        $this->command = new GoalCommand;
        $db = new Database(':memory:');
        $this->session = new SessionManager(
            new SessionRepository($db),
            new MessageRepository($db),
            new SettingsRepository($db),
            new MemoryRepository($db),
            new NullLogger,
            goals: new GoalRepository($db),
        );
        $this->session->setProject('/project');
        $this->session->createSession('model');
    }

    public function test_sets_goal_and_injects_continuation(): void
    {
        $ui = $this->createMock(RendererInterface::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Goal active.'));

        $result = $this->command->execute('Ship the release', $this->makeContext($ui));

        $this->assertSame(SlashCommandAction::Inject, $result->action);
        $this->assertSame('Ship the release', $this->session->currentGoal()?->objective);
    }

    public function test_pause_only_pauses_active_goal(): void
    {
        $this->session->setGoal('Ship the release');
        $ui = $this->createMock(RendererInterface::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('Goal paused.');

        $result = $this->command->execute('pause', $this->makeContext($ui));

        $this->assertSame(SlashCommandAction::Continue, $result->action);
        $this->assertSame(GoalStatus::Paused, $this->session->currentGoal()?->status);
    }

    public function test_pause_does_not_change_complete_goal(): void
    {
        $this->session->setGoal('Ship the release');
        $this->session->updateGoal(GoalStatus::Complete);
        $ui = $this->createMock(RendererInterface::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Only active goals can be paused.'));

        $result = $this->command->execute('pause', $this->makeContext($ui));

        $this->assertSame(SlashCommandAction::Continue, $result->action);
        $this->assertSame(GoalStatus::Complete, $this->session->currentGoal()?->status);
    }

    public function test_resume_only_injects_when_goal_becomes_active(): void
    {
        $this->session->setGoal('Ship the release');
        $this->session->updateGoal(GoalStatus::Paused);
        $ui = $this->createMock(RendererInterface::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Goal active.'));

        $result = $this->command->execute('resume', $this->makeContext($ui));

        $this->assertSame(SlashCommandAction::Inject, $result->action);
        $this->assertSame(GoalStatus::Active, $this->session->currentGoal()?->status);
    }

    public function test_resume_does_not_reopen_budget_limited_goal(): void
    {
        $this->session->setGoal('Stay under budget', tokenBudget: 10);
        $this->session->accountGoalUsage(tokenDelta: 10, timeDeltaSeconds: 1);
        $ui = $this->createMock(RendererInterface::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Only paused goals can be resumed.'));

        $result = $this->command->execute('resume', $this->makeContext($ui));

        $this->assertSame(SlashCommandAction::Continue, $result->action);
        $this->assertSame(GoalStatus::BudgetLimited, $this->session->currentGoal()?->status);
    }

    private function makeContext(RendererInterface $ui): SlashCommandContext
    {
        return new SlashCommandContext(
            ui: $ui,
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->session,
            llm: $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepositoryInterface::class),
        );
    }
}
