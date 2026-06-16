<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Goal;

use Kosmokrator\Goal\GoalRepository;
use Kosmokrator\Goal\GoalStatus;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SessionRepository;
use PHPUnit\Framework\TestCase;

final class GoalRepositoryTest extends TestCase
{
    private Database $db;

    private string $sessionId;

    private GoalRepository $goals;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->sessionId = (new SessionRepository($this->db))->create('/project', 'model');
        $this->goals = new GoalRepository($this->db);
    }

    public function test_replace_creates_active_goal(): void
    {
        $goal = $this->goals->replace($this->sessionId, 'Ship the goal feature');

        $this->assertSame('Ship the goal feature', $goal->objective);
        $this->assertSame(GoalStatus::Active, $goal->status);
        $this->assertSame(0, $goal->tokensUsed);
    }

    public function test_account_usage_moves_goal_to_budget_limited(): void
    {
        $this->goals->replace($this->sessionId, 'Stay under budget', tokenBudget: 10);

        $goal = $this->goals->accountUsage($this->sessionId, tokenDelta: 12, timeDeltaSeconds: 3);

        $this->assertNotNull($goal);
        $this->assertSame(GoalStatus::BudgetLimited, $goal->status);
        $this->assertSame(12, $goal->tokensUsed);
        $this->assertSame(3, $goal->timeUsedSeconds);
    }

    public function test_create_rejects_existing_goal(): void
    {
        $this->goals->create($this->sessionId, 'First goal');

        $this->expectException(\RuntimeException::class);
        $this->goals->create($this->sessionId, 'Second goal');
    }

    public function test_complete_goal_cannot_be_reopened_by_status_update(): void
    {
        $this->goals->replace($this->sessionId, 'Already done');
        $this->goals->update($this->sessionId, GoalStatus::Complete);

        $goal = $this->goals->update($this->sessionId, GoalStatus::Active);

        $this->assertNotNull($goal);
        $this->assertSame(GoalStatus::Complete, $goal->status);
    }
}
