<?php

declare(strict_types=1);

namespace Kosmokrator\Task;

/**
 * Backed enum for the lifecycle states of a Task.
 *
 * Defines valid transitions between states and provides display helpers
 * (icon, label). Used by Task and TaskStore for state-machine logic.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * Unicode icon representing this status for terminal display.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Pending => "\u{25CB}",     // ○
            self::InProgress => "\u{25CE}",  // ◎
            self::Completed => "\u{25CF}",   // ●
            self::Cancelled => "\u{2717}",   // ✗
            self::Failed => "\u{2620}",      // ☠
        };
    }

    /**
     * Whether this status represents a final state (no further transitions).
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled || $this === self::Failed;
    }

    /**
     * Whether the task is still actionable (pending or in-progress).
     */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }

    /**
     * Human-readable label for display (e.g. "In Progress").
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    /**
     * All valid status transitions for task lifecycle management.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            'pending' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled', 'failed'],
            'completed' => [],
            'cancelled' => [],
            'failed' => [],
        ];
    }

    /**
     * Whether a transition from this status to the target is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        $allowed = self::transitions()[$this->value] ?? [];

        return in_array($target->value, $allowed, true);
    }
}
