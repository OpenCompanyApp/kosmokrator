<?php

declare(strict_types=1);

namespace Kosmokrator\Task;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function icon(): string
    {
        return match ($this) {
            self::Pending => "\u{25CB}",     // ○
            self::InProgress => "\u{25CE}",  // ◎
            self::Completed => "\u{25CF}",   // ●
            self::Cancelled => "\u{2717}",   // ✗
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
