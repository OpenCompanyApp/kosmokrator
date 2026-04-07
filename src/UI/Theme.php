<?php

declare(strict_types=1);

namespace KosmoKrator\UI;

use KosmoKrator\UI\Tui\Theme\ThemeManager;

/**
 * Centralized ANSI color/theme definitions and terminal control sequences.
 *
 * Provides static helpers for colors, icons, formatting utilities, and
 * cursor/terminal control used across all renderers.
 *
 * ## Facade Pattern
 *
 * Color methods delegate to a lazily-initialized {@see ThemeManager} instance.
 * The manager handles:
 *   - Semantic token resolution with dark/light variants
 *   - Terminal color capability detection and downsampling
 *   - Theme registry and runtime switching
 *
 * Non-color methods (cursor control, formatting utilities) remain as-is.
 *
 * ## Migration Path
 *
 * Callers continue using the same static API unchanged:
 *   Theme::primary(), Theme::success(), Theme::text(), etc.
 *
 * Internally, these now resolve through ThemeManager:
 *   Theme::primary() → manager->ansi('primary') → downsampled escape sequence
 */
class Theme
{
    /** @var ThemeManager|null Injected or lazily-created manager */
    private static ?ThemeManager $manager = null;

    /**
     * Set the global ThemeManager instance (called during bootstrap).
     */
    public static function setManager(ThemeManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * Get the global ThemeManager instance.
     */
    public static function getManager(): ThemeManager
    {
        return self::$manager ??= ThemeManager::create();
    }

    /**
     * Internal shorthand for the manager.
     */
    private static function m(): ThemeManager
    {
        return self::$manager ??= ThemeManager::create();
    }

    // ── Legacy helpers (preserved for internal use by downsampler path) ──

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
        return "\033[38;2;{$r};{$g};{$b}m";
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
        return "\033[48;2;{$r};{$g};{$b}m";
    }

    /**
     * Build a 256-color foreground escape sequence.
     *
     * @param  int  $code  256-color palette index (0–255)
     * @return string ANSI escape sequence
     */
    public static function color256(int $code): string
    {
        return "\033[38;5;{$code}m";
    }

    // ── Core palette (delegated to ThemeManager) ───────────────────────

    /** Primary brand color (fiery red-orange). */
    public static function primary(): string
    {
        return self::m()->ansi('primary');
    }

    /** Dimmed primary for subtle accents. */
    public static function primaryDim(): string
    {
        return self::m()->ansi('primary-dim');
    }

    /** Accent highlight (gold). */
    public static function accent(): string
    {
        return self::m()->ansi('accent');
    }

    /** Success/positive indicator (green). */
    public static function success(): string
    {
        return self::m()->ansi('success');
    }

    /** Warning indicator (amber). */
    public static function warning(): string
    {
        return self::m()->ansi('warning');
    }

    /** Error/danger indicator (red). */
    public static function error(): string
    {
        return self::m()->ansi('error');
    }

    /** Informational highlight (sky blue). */
    public static function info(): string
    {
        return self::m()->ansi('info');
    }

    /** URL/link color (blue). */
    public static function link(): string
    {
        return self::m()->ansi('link');
    }

    /** Inline code color (purple). */
    public static function code(): string
    {
        return self::m()->ansi('code-fg');
    }

    /** Muted/secondary text color. */
    public static function dim(): string
    {
        return self::m()->ansi('text-dim');
    }

    /** Even more muted color for separators and backgrounds. */
    public static function dimmer(): string
    {
        return self::m()->ansi('text-dimmer');
    }

    /** Default body text color (light gray). */
    public static function text(): string
    {
        return self::m()->ansi('text');
    }

    /** Bright white (bold). */
    public static function white(): string
    {
        return self::m()->ansi('text-bright');
    }

    /** Bold intensity attribute. */
    public static function bold(): string
    {
        return "\033[1m";
    }

    /** Reset all attributes to terminal defaults. */
    public static function reset(): string
    {
        return "\033[0m";
    }

    /** Agent type: general (goldenrod). */
    public static function agentGeneral(): string
    {
        return self::m()->ansi('agent-general');
    }

    /** Agent type: plan (purple). */
    public static function agentPlan(): string
    {
        return self::m()->ansi('agent-plan');
    }

    /** Agent type: default/explore (cyan). */
    public static function agentDefault(): string
    {
        return self::m()->ansi('agent-explore');
    }

    /** Dimmed white for subtle UI text. */
    public static function dimWhite(): string
    {
        return self::m()->ansi('text-dim');
    }

    /** Waiting/queued status indicator (blue). */
    public static function waiting(): string
    {
        return self::m()->ansi('agent-waiting');
    }

    /** Italic text attribute. */
    public static function italic(): string
    {
        return "\033[3m";
    }

    /** Strikethrough text attribute. */
    public static function strikethrough(): string
    {
        return "\033[9m";
    }

    // ── Border colors (delegated to ThemeManager) ──────────────────────

    /** Dimmed gold — for agent dialogs (ask_user, ask_choice, permissions). */
    public static function borderAccent(): string
    {
        return self::m()->ansi('border-accent');
    }

    /** Dimmed purple — for plan mode dialogs. */
    public static function borderPlan(): string
    {
        return self::m()->ansi('border-plan');
    }

    /** Warm brown — for task bar and collapsible results. */
    public static function borderTask(): string
    {
        return self::m()->ansi('border-task');
    }

    // ── Diff colors (delegated to ThemeManager) ───────────────────────

    /** Diff added-line foreground (green). */
    public static function diffAdd(): string
    {
        return self::m()->ansi('diff-add');
    }

    /** Diff removed-line foreground (red). */
    public static function diffRemove(): string
    {
        return self::m()->ansi('diff-remove');
    }

    /** Diff added-line background (dark green). */
    public static function diffAddBg(): string
    {
        return self::m()->ansiBg('diff-add-bg');
    }

    /** Diff removed-line background (dark red). */
    public static function diffRemoveBg(): string
    {
        return self::m()->ansiBg('diff-remove-bg');
    }

    /** Diff strong added background for word-level highlights. */
    public static function diffAddBgStrong(): string
    {
        return self::m()->ansiBg('diff-add-bg-strong');
    }

    /** Diff strong removed background for word-level highlights. */
    public static function diffRemoveBgStrong(): string
    {
        return self::m()->ansiBg('diff-remove-bg-strong');
    }

    /** Diff context/unchanged line color (gray). */
    public static function diffContext(): string
    {
        return self::m()->ansi('diff-context');
    }

    /** Code block background. */
    public static function codeBg(): string
    {
        return self::m()->ansiBg('code-bg');
    }

    // ── Terminal control (no color dependency) ────────────────────────

    /** Hide the terminal cursor. */
    public static function hideCursor(): string
    {
        return "\033[?25l";
    }

    /** Show the terminal cursor. */
    public static function showCursor(): string
    {
        return "\033[?25h";
    }

    /** Clear the entire screen and move cursor to home position. */
    public static function clearScreen(): string
    {
        return "\033[2J\033[H";
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
        return "\033[{$row};{$col}H";
    }

    // ── Tool icons and labels (no color dependency) ───────────────────

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
            'lua_list_docs' => '☽',  // Moon — documentation illumination
            'lua_search_docs' => '⊛', // Search — finding docs
            'lua_read_doc' => '☽',    // Moon — reading docs
            'execute_lua' => '✦',     // Spark — executing a script
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
            'lua_list_docs' => 'Lua Docs',
            'lua_search_docs' => 'Lua Search',
            'lua_read_doc' => 'Lua Docs',
            'execute_lua' => 'Lua',
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

    // ── Composite helpers (delegated colors) ──────────────────────────

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
