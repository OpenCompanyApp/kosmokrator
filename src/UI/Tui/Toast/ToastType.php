<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Toast;

/**
 * Semantic toast notification type.
 *
 * Each type has a fixed icon, color scheme, and default auto-dismiss duration.
 */
enum ToastType: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Error   = 'error';
    case Info    = 'info';

    /**
     * Unicode icon prefix for this toast type.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Success => '✓',
            self::Warning => '⚠',
            self::Error   => '✕',
            self::Info    => 'ℹ',
        };
    }

    /**
     * Default auto-dismiss duration in milliseconds.
     */
    public function defaultDuration(): int
    {
        return match ($this) {
            self::Success => 2000,
            self::Warning => 3000,
            self::Error   => 4000,
            self::Info    => 2000,
        };
    }

    /**
     * ANSI foreground color for the toast icon and text.
     */
    public function foregroundColor(): string
    {
        return match ($this) {
            self::Success => "\033[38;2;120;240;140m",
            self::Warning => "\033[38;2;255;220;120m",
            self::Error   => "\033[38;2;255;120;100m",
            self::Info    => "\033[38;2;140;190;255m",
        };
    }

    /**
     * ANSI foreground color for the toast border and background tint.
     */
    public function borderColor(): string
    {
        return match ($this) {
            self::Success => "\033[38;2;80;220;100m",
            self::Warning => "\033[38;2;255;200;80m",
            self::Error   => "\033[38;2;255;80;60m",
            self::Info    => "\033[38;2;100;160;255m",
        };
    }

    /**
     * ANSI background color (subtle tint matching the type).
     */
    public function backgroundColor(): string
    {
        return match ($this) {
            self::Success => "\033[48;2;20;40;25m",
            self::Warning => "\033[48;2;40;35;15m",
            self::Error   => "\033[48;2;45;18;15m",
            self::Info    => "\033[48;2;18;25;45m",
        };
    }

    /**
     * Dark border character color (for the box outline).
     */
    public function borderDimColor(): string
    {
        return match ($this) {
            self::Success => "\033[38;2;50;130;60m",
            self::Warning => "\033[38;2;160;120;40m",
            self::Error   => "\033[38;2;160;50;35m",
            self::Info    => "\033[38;2;60;100;160m",
        };
    }
}
