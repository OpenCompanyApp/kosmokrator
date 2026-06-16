<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Goal;

use Kosmokrator\Goal\GoalRepository;
use Kosmokrator\Goal\Tool\CreateGoalTool;
use Kosmokrator\Goal\Tool\GetGoalTool;
use Kosmokrator\Goal\Tool\UpdateGoalTool;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoalToolTest extends TestCase
{
    private SessionManager $session;

    protected function setUp(): void
    {
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

    public function test_create_and_get_goal_tools_return_goal_payload(): void
    {
        $create = (new CreateGoalTool($this->session))->execute([
            'objective' => 'Finish the implementation',
            'token_budget' => 500,
        ]);
        $this->assertTrue($create->success);
        $this->assertStringContainsString('Finish the implementation', $create->output);

        $get = (new GetGoalTool($this->session))->execute([]);
        $this->assertTrue($get->success);
        $this->assertStringContainsString('"remainingTokens": 500', $get->output);
    }

    public function test_update_goal_tool_only_allows_complete(): void
    {
        $this->session->createGoal('Finish the implementation');

        $rejected = (new UpdateGoalTool($this->session))->execute(['status' => 'paused']);
        $this->assertFalse($rejected->success);

        $complete = (new UpdateGoalTool($this->session))->execute(['status' => 'complete']);
        $this->assertTrue($complete->success);
        $this->assertStringContainsString('"status": "complete"', $complete->output);
    }
}
