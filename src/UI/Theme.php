<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

/**
 * Centralized ANSI color/theme definitions and terminal control sequences.
 *
 * Provides static helpers for colors, icons, formatting utilities, and
 * cursor/terminal control used across all renderers.
 */
class Theme
{
    /** ANSI escape prefix. */
    private const ESC = "\033";

    /**
     * Build a 24-bit foreground color escape sequence.
     *
     * @param  int  $r  Red channel (0–255)
     * @param  int  $g  Green channel (0–255)
     * @param  int  $b  Blue channel (0–255)
     * @return string ANSI escape sequence
     */
    public static function rgb(int $r, int $g, int $b): string
    {
        return self::ESC."[38;2;{$r};{$g};{$b}m";
    }

    /**
     * Build a 24-bit background color escape sequence.
     *
     * @param  int  $r  Red channel (0–255)
     * @param  int  $g  Green channel (0–255)
     * @param  int  $b  Blue channel (0–255)
     * @return string ANSI escape sequence
     */
    public static function bgRgb(int $r, int $g, int $b): string
    {
        return self::ESC."[48;2;{$r};{$g};{$b}m";
    }

    /**
     * Build a 256-color foreground escape sequence.
     *
     * @param  int  $code  256-color palette index (0–255)
     * @return string ANSI escape sequence
     */
    public static function color256(int $code): string
    {
        return self::ESC."[38;5;{$code}m";
    }

    // Core palette
    /** Primary brand color (fiery red-orange). */
    public static function primary(): string
    {
        return self::rgb(255, 60, 40);
    }

    /** Dimmed primary for subtle accents. */
    public static function primaryDim(): string
    {
        return self::rgb(160, 30, 30);
    }

    /** Accent highlight (gold). */
    public static function accent(): string
    {
        return self::rgb(255, 200, 80);
    }

    /** Success/positive indicator (green). */
    public static function success(): string
    {
        return self::rgb(80, 220, 100);
    }

    /** Warning indicator (amber). */
    public static function warning(): string
    {
        return self::rgb(255, 200, 80);
    }

    /** Error/danger indicator (red). */
    public static function error(): string
    {
        return self::rgb(255, 80, 60);
    }

    /** Informational highlight (sky blue). */
    public static function info(): string
    {
        return self::rgb(100, 200, 255);
    }

    /** URL/link color (blue). */
    public static function link(): string
    {
        return self::rgb(80, 140, 255);
    }

    /** Inline code color (purple). */
    public static function code(): string
    {
        return self::rgb(200, 120, 255);
    }

    /** Muted/secondary text color. */
    public static function dim(): string
    {
        return self::color256(240);
    }

    /** Even more muted color for separators and backgrounds. */
    public static function dimmer(): string
    {
        return self::color256(236);
    }

    /** Default body text color (light gray). */
    public static function text(): string
    {
        return self::rgb(180, 180, 190);
    }

    /** Bright white (bold). */
    public static function white(): string
    {
        return self::rgb(240, 240, 245);
    }

    /** Bold intensity attribute. */
    public static function bold(): string
    {
        return self::ESC.'[1m';
    }

    /** Reset all attributes to terminal defaults. */
    public static function reset(): string
    {
        return self::ESC.'[0m';
    }

    /** Agent type: general (goldenrod). */
    public static function agentGeneral(): string
    {
        return self::rgb(218, 165, 32);
    }

    /** Agent type: plan (purple). */
    public static function agentPlan(): string
    {
        return self::rgb(160, 120, 255);
    }

    /** Agent type: default/explore (cyan). */
    public static function agentDefault(): string
    {
        return self::rgb(100, 200, 220);
    }

    /** Dimmed white for subtle UI text. */
    public static function dimWhite(): string
    {
        return self::rgb(140, 140, 150);
    }

    /** Waiting/queued status indicator (blue). */
    public static function waiting(): string
    {
        return self::rgb(100, 149, 237);
    }

    /** Italic text attribute. */
    public static function italic(): string
    {
        return self::ESC.'[3m';
    }

    /** Strikethrough text attribute. */
    public static function strikethrough(): string
    {
        return self::ESC.'[9m';
    }

    // Border colors — dimmed variants of mode/accent colors
    /** Dimmed gold — for agent dialogs (ask_user, ask_choice, permissions). */
    public static function borderAccent(): string
    {
        return self::rgb(180, 140, 50);
    }

    /** Dimmed purple — for plan mode dialogs. */
    public static function borderPlan(): string
    {
        return self::rgb(120, 90, 200);
    }

    /** Warm brown — for task bar and collapsible results. */
    public static function borderTask(): string
    {
        return self::rgb(128, 100, 40);
    }

    // Diff colors
    /** Diff added-line foreground (green). */
    public static function diffAdd(): string
    {
        return self::rgb(60, 160, 80);
    }

    /** Diff removed-line foreground (red). */
    public static function diffRemove(): string
    {
        return self::rgb(180, 60, 60);
    }

    /** Diff added-line background (dark green). */
    public static function diffAddBg(): string
    {
        return self::bgRgb(20, 45, 20);
    }

    /** Diff removed-line background (dark red). */
    public static function diffRemoveBg(): string
    {
        return self::bgRgb(55, 15, 15);
    }

    /** Diff strong added background for word-level highlights. */
    public static function diffAddBgStrong(): string
    {
        return self::bgRgb(30, 70, 30);
    }

    /** Diff strong removed background for word-level highlights. */
    public static function diffRemoveBgStrong(): string
    {
        return self::bgRgb(80, 20, 20);
    }

    /** Diff context/unchanged line color (gray). */
    public static function diffContext(): string
    {
        return self::color256(244);
    }

    /** Code block background. */
    public static function codeBg(): string
    {
        return self::bgRgb(40, 40, 40);
    }

    // Terminal control
    /** Hide the terminal cursor. */
    public static function hideCursor(): string
    {
        return self::ESC.'[?25l';
    }

    /** Show the terminal cursor. */
    public static function showCursor(): string
    {
        return self::ESC.'[?25h';
    }

    /** Clear the entire screen and move cursor to home position. */
    public static function clearScreen(): string
    {
        return self::ESC.'[2J'.self::ESC.'[H';
    }

    /**
     * Move the cursor to an absolute row/column position.
     *
     * @param  int  $row  1-based row
     * @param  int  $col  1-based column
     * @return string ANSI cursor positioning sequence
     */
    public static function moveTo(int $row, int $col): string
    {
        return self::ESC."[{$row};{$col}H";
    }

    // Tool icons
    /**
     * Return the Unicode icon for a given tool name.
     *
     * @param  string  $name  Internal tool identifier (e.g. 'file_read', 'bash')
     * @return string Single Unicode glyph
     */
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
    /**
     * Return a human-readable label for a given tool name.
     *
     * @param  string  $name  Internal tool identifier
     * @return string Display label (e.g. 'Read', 'Bash', 'Agent')
     */
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

    /**
     * Return a color indicating context window usage (green → yellow → red).
     *
     * @param  float  $ratio  Usage ratio 0.0–1.0
     * @return string ANSI color escape sequence
     */
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

    /**
     * Render a horizontal context-usage bar with token counts and percentage.
     *
     * @param  int  $tokensIn  Tokens currently consumed
     * @param  int  $maxContext  Maximum context window size
     * @return string Formatted ANSI string (bar + label + percentage)
     */
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

    /**
     * Format a token count as a human-readable string (e.g. "1.2k", "3.5M").
     *
     * @param  int  $tokens  Raw token count
     * @return string Abbreviated representation
     */
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

    /**
     * Format a USD cost value with appropriate precision.
     *
     * @param  float  $cost  Cost in USD
     * @return string Formatted string (e.g. "$0.0042", "$1.23")
     */
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
