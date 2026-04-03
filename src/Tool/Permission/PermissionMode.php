<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

/**
 * The three permission modes that control how tool-call approval works:
 *   - Guardian:   auto-approve safe operations, ask for risky ones.
 *   - Argus:      ask the user for every tool call that requires approval.
 *   - Prometheus: auto-approve everything (full auto-pilot).
 */
enum PermissionMode: string
{
    case Guardian = 'guardian';
    case Argus = 'argus';
    case Prometheus = 'prometheus';

    /** Human-readable mode name. */
    public function label(): string
    {
        return match ($this) {
            self::Guardian => 'Guardian',
            self::Argus => 'Argus',
            self::Prometheus => 'Prometheus',
        };
    }

    /** Unicode symbol representing the mode in the TUI. */
    public function symbol(): string
    {
        return match ($this) {
            self::Guardian => '◈',
            self::Argus => '◉',
            self::Prometheus => '⚡',
        };
    }

    /** ANSI 24-bit color code for the mode's UI accent. */
    public function color(): string
    {
        return match ($this) {
            self::Guardian => "\033[38;2;180;180;200m",   // silver
            self::Argus => "\033[38;2;100;140;200m",      // steel blue
            self::Prometheus => "\033[38;2;255;200;80m",   // gold
        };
    }

    /** Combined label + symbol string for status-bar display. */
    public function statusLabel(): string
    {
        return $this->label().' '.$this->symbol();
    }
}
