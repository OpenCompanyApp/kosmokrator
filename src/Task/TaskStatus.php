<?php

declare(strict_types=1);

namespace Kosmokrator\Task;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

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

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
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

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::transitions()[$this->value] ?? [];
        return in_array($target->value, $allowed, true);
    }
}
