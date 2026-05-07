<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Goal\Goal;
use Kosmokrator\Goal\GoalRepository;
use Kosmokrator\Goal\GoalStatus;
use Kosmokrator\Goal\GoalUsageFormatter;

final class GoalCommand implements SlashCommand
{
    public function name(): string
    {
        return '/goal';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Set, view, pause, resume, or clear the active goal';
    }

    public function immediate(): bool
    {
        return false;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $args = trim($args);
        if ($args === '') {
            $goal = $ctx->sessionManager->currentGoal();
            if ($goal === null) {
                $ctx->ui->showNotice("Usage: /goal <objective>\nCommands: /goal pause, /goal resume, /goal clear");

                return SlashCommandResult::continue();
            }

            $ctx->ui->showNotice("Goal\n".GoalUsageFormatter::summary($goal)."\n\n".$this->commandHint($goal->status));

            return SlashCommandResult::continue();
        }

        $control = strtolower($args);
        if ($control === 'clear') {
            $cleared = $ctx->sessionManager->clearGoal();
            $ctx->ui->showNotice($cleared ? 'Goal cleared.' : 'No goal to clear.');

            return SlashCommandResult::continue();
        }

        if ($control === 'pause') {
            $goal = $ctx->sessionManager->currentGoal();
            if ($goal === null) {
                $ctx->ui->showNotice('No goal to pause.');

                return SlashCommandResult::continue();
            }

            if ($goal->status !== GoalStatus::Active) {
                $ctx->ui->showNotice('Only active goals can be paused. Current status: '.$goal->status->label().'.');

                return SlashCommandResult::continue();
            }

            $ctx->sessionManager->updateGoal(GoalStatus::Paused);
            $ctx->ui->showNotice('Goal paused.');

            return SlashCommandResult::continue();
        }

        if ($control === 'resume') {
            $goal = $ctx->sessionManager->currentGoal();
            if ($goal === null) {
                $ctx->ui->showNotice('No goal to resume.');

                return SlashCommandResult::continue();
            }

            if ($goal->status !== GoalStatus::Paused) {
                $ctx->ui->showNotice('Only paused goals can be resumed. Current status: '.$goal->status->label().'.');

                return SlashCommandResult::continue();
            }

            $goal = $ctx->sessionManager->updateGoal(GoalStatus::Active);
            if ($goal === null || $goal->status !== GoalStatus::Active) {
                $ctx->ui->showNotice('Goal could not be resumed. Current status: '.($goal?->status->label() ?? 'unknown').'.');

                return SlashCommandResult::continue();
            }

            $ctx->ui->showNotice('Goal active. '.$this->shortSummary($goal));

            return SlashCommandResult::inject('Continue working toward the active goal.');
        }

        try {
            $objective = GoalRepository::validateObjective($args);
        } catch (\InvalidArgumentException $e) {
            $ctx->ui->showNotice($e->getMessage());

            return SlashCommandResult::continue();
        }

        $goal = $ctx->sessionManager->setGoal($objective);
        $ctx->ui->showNotice('Goal active. '.$this->shortSummary($goal));

        return SlashCommandResult::inject('Continue working toward the active goal.');
    }

    private function commandHint(GoalStatus $status): string
    {
        return match ($status) {
            GoalStatus::Active => 'Commands: /goal pause, /goal clear',
            GoalStatus::Paused => 'Commands: /goal resume, /goal clear',
            GoalStatus::BudgetLimited, GoalStatus::Complete => 'Commands: /goal clear',
        };
    }

    private function shortSummary(Goal $goal): string
    {
        $usage = GoalUsageFormatter::elapsed($goal->timeUsedSeconds);
        if ($goal->tokenBudget !== null) {
            $usage .= ', '.GoalUsageFormatter::tokens($goal->tokensUsed).'/'.GoalUsageFormatter::tokens($goal->tokenBudget);
        }

        return "Objective: {$goal->objective} ({$usage}).";
    }
}
