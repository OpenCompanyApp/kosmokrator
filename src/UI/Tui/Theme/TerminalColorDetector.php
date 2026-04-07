<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Theme;

/**
 * Detects the terminal's color capability by probing environment variables.
 *
 * Runs once at startup, cached statically. Probes in priority order:
 *
 *  1. NO_COLOR       → Ascii  (no-color.org standard)
 *  2. COLORTERM      → TrueColor / Ansi256
 *  3. TERM_PROGRAM   → per-terminal mapping
 *  4. TERM           → generic terminal type heuristics
 *  5. WT_SESSION     → TrueColor (Windows Terminal)
 *  6. ConEmuANSI     → Ansi256  (ConEmu on Windows)
 *  7. Default        → Ansi16
 *
 * Usage:
 *   $profile = TerminalColorDetector::detect();
 *   TerminalColorDetector::force(ColorProfile::TrueColor);   // --color=always
 *   TerminalColorDetector::reset();                           // testing
 */
final class TerminalColorDetector
{
    private static ?ColorProfile $profile = null;

    /**
     * Detect the terminal color profile (cached after first call).
     */
    public static function detect(): ColorProfile
    {
        if (self::$profile !== null) {
            return self::$profile;
        }

        self::$profile = self::doDetect();

        return self::$profile;
    }

    /**
     * Force a specific profile (e.g. for --color=always / --color=never flags).
     */
    public static function force(ColorProfile $profile): void
    {
        self::$profile = $profile;
    }

    /**
     * Reset the cached detection (for testing).
     */
    public static function reset(): void
    {
        self::$profile = null;
    }

    /**
     * Return the terminals known to support TrueColor via TERM_PROGRAM.
     *
     * @return list<string>
     */
    public static function trueColorTermPrograms(): array
    {
        return ['iTerm.app', 'WezTerm', 'ghostty', 'Hyper', 'kitty', 'vscode'];
    }

    private static function doDetect(): ColorProfile
    {
        // 1. NO_COLOR standard — explicit opt-out (https://no-color.org)
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return ColorProfile::Ascii;
        }

        // 2. COLORTERM — most reliable indicator of TrueColor support
        $colorterm = strtolower((string) getenv('COLORTERM'));
        if ($colorterm !== '') {
            if (str_contains($colorterm, 'truecolor') || str_contains($colorterm, '24bit')) {
                return ColorProfile::TrueColor;
            }
            if (str_contains($colorterm, '256color')) {
                return ColorProfile::Ansi256;
            }
        }

        // 3. TERM_PROGRAM — specific terminal identification
        $termProgram = (string) getenv('TERM_PROGRAM');
        if ($termProgram !== '') {
            // macOS Terminal.app: supports 256-color only, never TrueColor
            if ($termProgram === 'Apple_Terminal') {
                return ColorProfile::Ansi256;
            }

            // JetBrains IDE terminal
            $terminalEmulator = (string) getenv('TERMINAL_EMULATOR');
            if ($terminalEmulator === 'JetBrains-JediTerm') {
                return ColorProfile::TrueColor;
            }

            // Known TrueColor terminals
            if (in_array($termProgram, self::trueColorTermPrograms(), true)) {
                return ColorProfile::TrueColor;
            }
        }

        // 3b. Additional environment signals for TrueColor terminals
        // Kitty sets KITTY_WINDOW_ID; Ghostty sets GHOSTTY_RESOURCES_DIR
        if (getenv('KITTY_WINDOW_ID') !== false) {
            return ColorProfile::TrueColor;
        }
        if (getenv('GHOSTTY_RESOURCES_DIR') !== false) {
            return ColorProfile::TrueColor;
        }

        // 4. TERM — generic terminal type heuristics
        $term = strtolower((string) getenv('TERM'));

        // TERM=dumb → no capability
        if ($term === '' || $term === 'dumb') {
            return ColorProfile::Ascii;
        }

        if (str_contains($term, 'truecolor')) {
            return ColorProfile::TrueColor;
        }

        if (str_contains($term, '256color')) {
            // screen-256color / tmux-256color: downgrade unless COLORTERM
            // was already checked (it was, at step 2). Without COLORTERM,
            // assume no TrueColor passthrough.
            return ColorProfile::Ansi256;
        }

        // screen/tmux without 256-color suffix — assume basic 16
        if (str_contains($term, 'screen') || str_contains($term, 'tmux')) {
            return ColorProfile::Ansi16;
        }

        if (str_contains($term, 'xterm')) {
            return ColorProfile::Ansi256;
        }

        // 5. Windows Terminal
        if (getenv('WT_SESSION') !== false) {
            return ColorProfile::TrueColor;
        }

        // 6. ConEmu on Windows
        if (getenv('ConEmuANSI') === 'ON') {
            return ColorProfile::Ansi256;
        }

        // 7. Safe default
        return ColorProfile::Ansi16;
    }
}
