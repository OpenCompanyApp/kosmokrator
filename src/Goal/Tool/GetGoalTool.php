<?php

declare(strict_types=1);

namespace Kosmokrator\Goal\Tool;

use Kosmokrator\Goal\Goal;
use Kosmokrator\Goal\GoalUsageFormatter;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

class GetGoalTool extends AbstractTool
{
    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function name(): string
    {
        return 'get_goal';
    }

    public function description(): string
    {
        return 'Get the current goal for this session, including status, token budget, token usage, and elapsed time.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function requiredParameters(): array
    {
        return [];
    }

    protected function handle(array $args): ToolResult
    {
        return ToolResult::success($this->response($this->session->currentGoal(), includeCompletionReport: false));
    }

    protected function response(?Goal $goal, bool $includeCompletionReport): string
    {
        $remainingTokens = $goal?->tokenBudget === null ? null : max(0, $goal->tokenBudget - $goal->tokensUsed);
        $completionReport = null;
        if ($includeCompletionReport && $goal !== null && $goal->status->value === 'complete') {
            $parts = [];
            if ($goal->tokenBudget !== null) {
                $parts[] = 'tokens used: '.$goal->tokensUsed.' of '.$goal->tokenBudget;
            }
            if ($goal->timeUsedSeconds > 0) {
                $parts[] = 'time used: '.GoalUsageFormatter::elapsed($goal->timeUsedSeconds);
            }
            if ($parts !== []) {
                $completionReport = 'Goal achieved. Report final budget usage to the user: '.implode('; ', $parts).'.';
            }
        }

        return json_encode([
            'goal' => $goal?->toArray(),
            'remainingTokens' => $remainingTokens,
            'completionBudgetReport' => $completionReport,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
