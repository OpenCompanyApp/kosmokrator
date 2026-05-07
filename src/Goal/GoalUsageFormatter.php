<?php

declare(strict_types=1);

namespace Kosmokrator\Goal;

final class GoalUsageFormatter
{
    public static function elapsed(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        if ($hours >= 24) {
            $days = intdiv($hours, 24);
            $remainingHours = $hours % 24;

            return "{$days}d {$remainingHours}h {$remainingMinutes}m";
        }

        return $remainingMinutes === 0 ? "{$hours}h" : "{$hours}h {$remainingMinutes}m";
    }

    public static function tokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return rtrim(rtrim(number_format($tokens / 1_000_000, 1), '0'), '.').'M';
        }
        if ($tokens >= 1_000) {
            return rtrim(rtrim(number_format($tokens / 1_000, 1), '0'), '.').'K';
        }

        return (string) $tokens;
    }

    public static function summary(Goal $goal): string
    {
        $parts = [
            'Status: '.$goal->status->label(),
            'Objective: '.$goal->objective,
            'Time: '.self::elapsed($goal->timeUsedSeconds),
            'Tokens: '.self::tokens($goal->tokensUsed),
        ];

        if ($goal->tokenBudget !== null) {
            $parts[] = 'Budget: '.self::tokens($goal->tokenBudget);
        }

        return implode("\n", $parts);
    }
}
