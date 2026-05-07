<?php

declare(strict_types=1);

namespace Kosmokrator\Goal\Tool;

use Kosmokrator\Goal\GoalStatus;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\ToolResult;

final class UpdateGoalTool extends GetGoalTool
{
    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {
        parent::__construct($sessionManager);
    }

    public function name(): string
    {
        return 'update_goal';
    }

    public function description(): string
    {
        return 'Update the existing goal. Use only to mark the goal achieved. Set status to complete only when the objective is fully achieved and no required work remains.';
    }

    public function parameters(): array
    {
        return [
            'status' => [
                'type' => 'enum',
                'description' => 'Required. Set to complete only when the objective is achieved.',
                'options' => ['complete'],
            ],
        ];
    }

    public function requiredParameters(): array
    {
        return ['status'];
    }

    protected function handle(array $args): ToolResult
    {
        $status = (string) ($args['status'] ?? '');
        if ($status !== GoalStatus::Complete->value) {
            return ToolResult::error('update_goal can only mark the existing goal complete; pause, resume, and budget-limited status changes are controlled by the user or system.');
        }

        $goal = $this->sessionManager->updateGoal(GoalStatus::Complete);
        if ($goal === null) {
            return ToolResult::error('cannot update goal because this session does not have a goal');
        }

        return ToolResult::success($this->response($goal, includeCompletionReport: true));
    }
}
