<?php

declare(strict_types=1);

namespace Kosmokrator\Goal\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\ToolResult;

final class CreateGoalTool extends GetGoalTool
{
    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {
        parent::__construct($sessionManager);
    }

    public function name(): string
    {
        return 'create_goal';
    }

    public function description(): string
    {
        return 'Create a goal only when explicitly requested by the user or system instructions. Fails if a goal already exists. Set token_budget only when explicitly requested.';
    }

    public function parameters(): array
    {
        return [
            'objective' => ['type' => 'string', 'description' => 'The concrete objective to start pursuing.'],
            'token_budget' => ['type' => 'integer', 'description' => 'Optional positive token budget for the new active goal.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['objective'];
    }

    protected function handle(array $args): ToolResult
    {
        $objective = (string) ($args['objective'] ?? '');
        $tokenBudget = isset($args['token_budget']) && $args['token_budget'] !== ''
            ? (int) $args['token_budget']
            : null;

        $goal = $this->sessionManager->createGoal($objective, $tokenBudget);

        return ToolResult::success($this->response($goal, includeCompletionReport: false));
    }
}
