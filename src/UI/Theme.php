<?php

namespace Kosmokrator\UI;

class Theme
{
    private const ESC = "\033";

    public static function rgb(int $r, int $g, int $b): string
    {
        return self::ESC."[38;2;{$r};{$g};{$b}m";
    }

    public static function bgRgb(int $r, int $g, int $b): string
    {
        return self::ESC."[48;2;{$r};{$g};{$b}m";
    }

    public static function color256(int $code): string
    {
        return self::ESC."[38;5;{$code}m";
    }

    // Core palette
    public static function primary(): string
    {
        return self::rgb(255, 60, 40);
    }

    public static function primaryDim(): string
    {
        return self::rgb(160, 30, 30);
    }

    public static function accent(): string
    {
        return self::rgb(255, 200, 80);
    }

    public static function success(): string
    {
        return self::rgb(80, 220, 100);
    }

    public static function warning(): string
    {
        return self::rgb(255, 200, 80);
    }

    public static function error(): string
    {
        return self::rgb(255, 80, 60);
    }

    public static function info(): string
    {
        return self::rgb(100, 200, 255);
    }

    public static function link(): string
    {
        return self::rgb(80, 140, 255);
    }

    public static function code(): string
    {
        return self::rgb(200, 120, 255);
    }

    public static function dim(): string
    {
        return self::color256(240);
    }

    public static function dimmer(): string
    {
        return self::color256(236);
    }

    public static function text(): string
    {
        return self::rgb(180, 180, 190);
    }

    public static function white(): string
    {
        return self::ESC.'[1;37m';
    }

    public static function bold(): string
    {
        return self::ESC.'[1m';
    }

    public static function reset(): string
    {
        return self::ESC.'[0m';
    }

    // Border colors — dimmed variants of mode/accent colors
    public static function borderAccent(): string
    {
        return self::rgb(180, 140, 50);
    }   // dimmed gold — for agent dialogs (ask_user, ask_choice, permissions)

    public static function borderPlan(): string
    {
        return self::rgb(120, 90, 200);
    }      // dimmed purple — for plan mode dialogs

    public static function borderTask(): string
    {
        return self::rgb(128, 100, 40);
    }      // warm brown — for task bar and collapsible results

    // Diff colors
    public static function diffAdd(): string
    {
        return self::rgb(60, 160, 80);
    }

    public static function diffRemove(): string
    {
        return self::rgb(180, 60, 60);
    }

    public static function diffAddBg(): string
    {
        return self::bgRgb(20, 45, 20);
    }

    public static function diffRemoveBg(): string
    {
        return self::bgRgb(55, 15, 15);
    }

    public static function diffAddBgStrong(): string
    {
        return self::bgRgb(30, 70, 30);
    }

    public static function diffRemoveBgStrong(): string
    {
        return self::bgRgb(80, 20, 20);
    }

    public static function diffContext(): string
    {
        return self::color256(244);
    }

    // Code background
    public static function codeBg(): string
    {
        return self::bgRgb(40, 40, 40);
    }

    // Terminal control
    public static function hideCursor(): string
    {
        return self::ESC.'[?25l';
    }

    public static function showCursor(): string
    {
        return self::ESC.'[?25h';
    }

    public static function clearScreen(): string
    {
        return self::ESC.'[2J'.self::ESC.'[H';
    }

    public static function moveTo(int $row, int $col): string
    {
        return self::ESC."[{$row};{$col}H";
    }

    // Tool icons
    public static function toolIcon(string $name): string
    {
        return match ($name) {
            'file_read' => '☽',   // Moon — illumination, revealing hidden text
            'file_write' => '☉',  // Sun — creation, bringing into being
            'file_edit' => '♅',   // Uranus — transformation, change
            'apply_patch' => '✎', // Inscription — deliberate multi-file change
            'bash' => '⚡︎',      // Lightning — force text presentation to avoid wide emoji spacing
            'shell_start' => '◌', // Opening a live shell orbit
            'shell_write' => '↦', // Sending input into a session
            'shell_read' => '↤',  // Pulling output from a session
            'shell_kill' => '✕',  // Terminating a live session
            'grep' => '⊛',       // Astral search — seeking through the cosmos
            'glob' => '✧',       // Star cluster — surveying many points of light
            'task_create' => '⊕', // Circled plus — bringing new labors into being
            'task_update' => '⊙', // Circled dot — altering the fate of a labor
            'task_list' => '☰',   // Trigram — surveying all labors
            'task_get' => '⊘',    // Circled division — examining a single labor
            'subagent' => '⏺',    // Orbital — spawning a child agent
            default => '◈',       // Gemstone — generic cosmic artifact
        };
    }

    // Friendly display names for tools
    public static function toolLabel(string $name): string
    {
        return match ($name) {
            'file_read' => 'Read',
            'file_write' => 'Write',
            'file_edit' => 'Edit',
            'apply_patch' => 'Patch',
            'bash' => 'Bash',
            'shell_start' => 'Shell',
            'shell_write' => 'Shell',
            'shell_read' => 'Shell',
            'shell_kill' => 'Shell',
            'grep' => 'Search',
            'glob' => 'Glob',
            'task_create' => 'Task',
            'task_update' => 'Task',
            'task_list' => 'Tasks',
            'task_get' => 'Task',
            'subagent' => 'Agent',
            default => $name,
        };
    }

    // Context usage color: green → yellow → red
    public static function contextColor(float $ratio): string
    {
        if ($ratio < 0.5) {
            return self::success();
        } elseif ($ratio < 0.75) {
            return self::warning();
        } else {
            return self::error();
        }
    }

    // Context bar
    public static function contextBar(int $tokensIn, int $maxContext): string
    {
        $ratio = min(1.0, $tokensIn / max(1, $maxContext));
        $barWidth = 16;
        $filled = (int) round($ratio * $barWidth);
        $empty = $barWidth - $filled;

        $pct = (int) round($ratio * 100);
        $color = self::contextColor($ratio);

        $bar = $color.str_repeat('━', $filled).self::dimmer().str_repeat('─', $empty).self::reset();
        $label = self::formatTokenCount($tokensIn).'/'.self::formatTokenCount($maxContext);

        return $bar.' '.self::dim().$label.' ('.$pct.'%)'.self::reset();
    }

    public static function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return round($tokens / 1_000_000, 1).'M';
        }
        if ($tokens >= 1_000) {
            return round($tokens / 1_000, 1).'k';
        }

        return (string) $tokens;
    }

    public static function formatCost(float $cost): string
    {
        if ($cost < 0.01) {
            return '$'.number_format($cost, 4);
        }

        return '$'.number_format($cost, 2);
    }

    /**
     * Strip the current working directory prefix from a path to show relative paths.
     */
    public static function relativePath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd !== false && str_starts_with($path, $cwd.'/')) {
            return substr($path, strlen($cwd) + 1);
        }

        return $path;
    }
}
