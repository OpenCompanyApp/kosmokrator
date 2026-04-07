<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Terminal;

/**
 * Generates ANSI escape sequences for advanced text decorations.
 *
 * Every method degrades gracefully: if the terminal does not support a
 * particular decoration, a safe fallback is returned instead. No broken
 * escape sequences are ever emitted on unsupported terminals.
 *
 * Supported decorations (with fallbacks):
 *
 *   ┌────────────────┬──────────────────────────────┬────────────────────────┐
 *   │ Decoration     │ Supported                    │ Unsupported fallback   │
 *   ├────────────────┼──────────────────────────────┼────────────────────────┤
 *   │ Undercurl      │ SGR 4:3 (wavy)              │ Standard underline     │
 *   │ Double under.  │ SGR 4:2                      │ Standard underline     │
 *   │ Dotted under.  │ SGR 4:4                      │ Standard underline     │
 *   │ Dashed under.  │ SGR 4:5                      │ Standard underline     │
 *   │ Underline color│ SGR 58;2;R;G;Bm              │ Empty string (no-op)   │
 *   │ Overline       │ SGR 53                       │ Empty string (no-op)   │
 *   │ Synchronized   │ Mode 2026 begin/end          │ Empty string (no-op)   │
 *   └────────────────┴──────────────────────────────┴────────────────────────┘
 *
 * Usage:
 *   echo AdvancedTextDecoration::undercurl()
 *       . AdvancedTextDecoration::underlineColor(255, 0, 0)
 *       . $errorText
 *       . AdvancedTextDecoration::underlineColorReset()
 *       . AdvancedTextDecoration::underlineReset();
 *
 * @see docs/plans/tui-overhaul/12-terminal-features/01-undercurl-underline.md
 */
final class AdvancedTextDecoration
{
    private const ESC = "\x1b[";

    private static ?TerminalCapabilities $caps = null;

    /**
     * Override the capabilities instance (for testing).
     */
    public static function setCapabilities(?TerminalCapabilities $caps): void
    {
        self::$caps = $caps;
    }

    private static function caps(): TerminalCapabilities
    {
        return self::$caps ??= TerminalCapabilities::getInstance();
    }

    // ── Underline styles ─────────────────────────────────────────────────

    /**
     * Standard underline (SGR 4).
     *
     * Universal — works on all terminals. Used as the fallback for all
     * styled underline variants.
     */
    public static function underline(): string
    {
        return self::ESC . '4m';
    }

    /**
     * Undercurl / wavy underline (SGR 4:3).
     *
     * Ideal for errors and warnings. Falls back to standard underline.
     */
    public static function undercurl(): string
    {
        if (!self::caps()->supportsStyledUnderline()) {
            return self::underline();
        }

        return self::ESC . '4:3m';
    }

    /**
     * Double underline (SGR 4:2).
     *
     * Ideal for search matches and emphasis. Falls back to standard underline.
     */
    public static function doubleUnderline(): string
    {
        if (!self::caps()->supportsStyledUnderline()) {
            return self::underline();
        }

        return self::ESC . '4:2m';
    }

    /**
     * Dotted underline (SGR 4:4).
     *
     * Ideal for interactive/clickable elements (mirrors web link styling).
     * Falls back to standard underline.
     */
    public static function dottedUnderline(): string
    {
        if (!self::caps()->supportsStyledUnderline()) {
            return self::underline();
        }

        return self::ESC . '4:4m';
    }

    /**
     * Dashed underline (SGR 4:5).
     *
     * Ideal for de-emphasized links and annotations.
     * Falls back to standard underline.
     */
    public static function dashedUnderline(): string
    {
        if (!self::caps()->supportsStyledUnderline()) {
            return self::underline();
        }

        return self::ESC . '4:5m';
    }

    /**
     * Reset all underline styles (SGR 24).
     *
     * Works on all terminals — safe to call unconditionally.
     */
    public static function underlineReset(): string
    {
        return self::ESC . '24m';
    }

    // ── Underline color ──────────────────────────────────────────────────

    /**
     * Set underline color using true-color RGB (SGR 58;2;R;G;Bm).
     *
     * Falls back to empty string (no-op) when unsupported.
     *
     * @param int $r Red channel (0–255)
     * @param int $g Green channel (0–255)
     * @param int $b Blue channel (0–255)
     */
    public static function underlineColor(int $r, int $g, int $b): string
    {
        if (!self::caps()->supportsUnderlineColor()) {
            return '';
        }

        // Clamp to valid range
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));

        return self::ESC . "58;2;{$r};{$g};{$b}m";
    }

    /**
     * Set underline color using 256-color palette index (SGR 58;5;Nm).
     *
     * Falls back to empty string (no-op) when unsupported.
     *
     * @param int $index Color index (0–255)
     */
    public static function underlineColor256(int $index): string
    {
        if (!self::caps()->supportsUnderlineColor()) {
            return '';
        }

        $index = max(0, min(255, $index));

        return self::ESC . "58;5;{$index}m";
    }

    /**
     * Reset underline color to default (SGR 59).
     *
     * Falls back to empty string (no-op) when unsupported.
     */
    public static function underlineColorReset(): string
    {
        if (!self::caps()->supportsUnderlineColor()) {
            return '';
        }

        return self::ESC . '59m';
    }

    // ── Overline ─────────────────────────────────────────────────────────

    /**
     * Enable overline (SGR 53).
     *
     * Draws a line above the text. Useful as a lightweight section divider.
     * Falls back to empty string (no-op) when unsupported.
     */
    public static function overline(): string
    {
        if (!self::caps()->supportsOverline()) {
            return '';
        }

        return self::ESC . '53m';
    }

    /**
     * Reset overline (SGR 55).
     *
     * Falls back to empty string (no-op) when unsupported.
     */
    public static function overlineReset(): string
    {
        if (!self::caps()->supportsOverline()) {
            return '';
        }

        return self::ESC . '55m';
    }

    // ── Synchronized output ──────────────────────────────────────────────

    /**
     * Begin synchronized output (mode 2026).
     *
     * Terminal buffers all output until {@see syncEnd()} is called,
     * then renders atomically — eliminates flicker during large updates.
     * Falls back to empty string (no-op) when unsupported.
     */
    public static function syncBegin(): string
    {
        if (!self::caps()->supportsSynchronizedOutput()) {
            return '';
        }

        return self::ESC . '?2026h';
    }

    /**
     * End synchronized output (mode 2026).
     *
     * Flushes the buffered output to the screen.
     * Falls back to empty string (no-op) when unsupported.
     */
    public static function syncEnd(): string
    {
        if (!self::caps()->supportsSynchronizedOutput()) {
            return '';
        }

        return self::ESC . '?2026l';
    }

    // ── Mouse tracking ───────────────────────────────────────────────────

    /**
     * Enable SGR mouse tracking (modes 1000 + 1002 + 1006).
     *
     *   - 1000: basic button press/release tracking
     *   - 1002: drag tracking (motion while button held)
     *   - 1006: SGR extended encoding (decimal coordinates)
     *
     * Safe to call when mouse is not supported — returns empty string.
     */
    public static function mouseEnable(): string
    {
        if (!self::caps()->supportsMouse()) {
            return '';
        }

        return self::ESC . '?1000h' . self::ESC . '?1002h' . self::ESC . '?1006h';
    }

    /**
     * Disable SGR mouse tracking (modes 1006 + 1002 + 1000).
     *
     * Must be called on exit to restore normal terminal behavior.
     * Returns empty string when mouse is not supported.
     */
    public static function mouseDisable(): string
    {
        if (!self::caps()->supportsMouse()) {
            return '';
        }

        return self::ESC . '?1006l' . self::ESC . '?1002l' . self::ESC . '?1000l';
    }

    // ── Convenience: semantic decoration combos ──────────────────────────

    /**
     * Error decoration: red undercurl.
     *
     * Returns underline style + underline color in a single call.
     * Falls back to standard underline without color.
     *
     * @param int $r Red (0–255), defaults to 255
     * @param int $g Green (0–255), defaults to 0
     * @param int $b Blue (0–255), defaults to 0
     */
    public static function errorUnderline(int $r = 255, int $g = 0, int $b = 0): string
    {
        return self::undercurl() . self::underlineColor($r, $g, $b);
    }

    /**
     * Warning decoration: amber undercurl.
     *
     * @param int $r Red (0–255), defaults to 255
     * @param int $g Green (0–255), defaults to 200
     * @param int $b Blue (0–255), defaults to 80
     */
    public static function warningUnderline(int $r = 255, int $g = 200, int $b = 80): string
    {
        return self::undercurl() . self::underlineColor($r, $g, $b);
    }

    /**
     * Search match decoration: double underline.
     *
     * No color parameter — typically the foreground color is used.
     */
    public static function searchMatchUnderline(): string
    {
        return self::doubleUnderline();
    }

    /**
     * Interactive/clickable element decoration: dotted underline.
     *
     * No color parameter — typically combined with a link foreground color.
     */
    public static function interactiveUnderline(): string
    {
        return self::dottedUnderline();
    }

    /**
     * Reset all decorations applied by this class.
     *
     * Resets underline style, underline color, and overline in one call.
     * Safe to call unconditionally.
     */
    public static function resetAll(): string
    {
        // SGR 24 resets underline (all styles)
        // SGR 59 resets underline color (safe even if not supported)
        // SGR 55 resets overline (safe even if not supported)
        return self::ESC . '24m' . self::ESC . '59m' . self::ESC . '55m';
    }
}
