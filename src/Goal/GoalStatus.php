<?php

declare(strict_types=1);

namespace Kosmokrator\Goal;

enum GoalStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case BudgetLimited = 'budget_limited';
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'active',
            self::Paused => 'paused',
            self::BudgetLimited => 'limited by budget',
            self::Complete => 'complete',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::BudgetLimited || $this === self::Complete;
    }
}
