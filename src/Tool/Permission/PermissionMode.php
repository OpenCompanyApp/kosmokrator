<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

enum PermissionMode: string
{
    case Guardian = 'guardian';
    case Argus = 'argus';
    case Prometheus = 'prometheus';

    public function label(): string
    {
        return match ($this) {
            self::Guardian => 'Guardian',
            self::Argus => 'Argus',
            self::Prometheus => 'Prometheus',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Guardian => '◈',
            self::Argus => '◉',
            self::Prometheus => '⚡',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Guardian => "\033[38;2;180;180;200m",   // silver
            self::Argus => "\033[38;2;100;140;200m",      // steel blue
            self::Prometheus => "\033[38;2;255;200;80m",   // gold
        };
    }

    public function statusLabel(): string
    {
        return $this->label() . ' ' . $this->symbol();
    }
}
