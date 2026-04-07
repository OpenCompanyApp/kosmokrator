<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Terminal;

use KosmoKrator\UI\Tui\Theme\ColorProfile;
use KosmoKrator\UI\Tui\Theme\TerminalColorDetector;

/**
 * Detects terminal support for advanced features: styled underlines, mouse
 * tracking, synchronized output, Kitty keyboard protocol, etc.
 *
 * Uses environment-variable heuristics for instant detection (no I/O at
 * startup). Results are cached for the lifetime of the process — terminal
 * capabilities do not change within a session.
 *
 * Usage:
 *   $caps = TerminalCapabilities::getInstance();
 *   if ($caps->supportsStyledUnderline()) { ... }
 *
 * @see docs/plans/tui-overhaul/12-terminal-features/01-undercurl-underline.md
 */
final class TerminalCapabilities
{
    private static ?self $instance = null;

    private readonly ColorProfile $colorProfile;
    private readonly bool $supportsStyledUnderline;
    private readonly bool $supportsUnderlineColor;
    private readonly bool $supportsOverline;
    private readonly bool $supportsMouse;
    private readonly bool $supportsSynchronizedOutput;
    private readonly bool $supportsKittyProtocol;

    private function __construct()
    {
        $this->colorProfile = TerminalColorDetector::detect();

        $program = (string) getenv('TERM_PROGRAM');
        $term = strtolower((string) getenv('TERM'));

        $this->supportsStyledUnderline = $this->detectStyledUnderline($program, $term);
        $this->supportsUnderlineColor = $this->supportsStyledUnderline;
        $this->supportsOverline = $this->detectOverline($program, $term);
        $this->supportsMouse = $this->detectMouse();
        $this->supportsSynchronizedOutput = $this->detectSynchronizedOutput($program, $term);
        $this->supportsKittyProtocol = $this->detectKittyProtocol($program);
    }

    /**
     * Get the singleton instance (created once, then cached).
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Reset the singleton (for testing or after terminal change).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * The detected color profile (TrueColor, Ansi256, Ansi16, or Ascii).
     */
    public function getColorProfile(): ColorProfile
    {
        return $this->colorProfile;
    }

    /**
     * Whether the terminal supports styled underline variants (SGR 4:2–4:5).
     *
     * Terminals that support this: Kitty, WezTerm, Ghostty, iTerm2 ≥ 3.5,
     * Windows Terminal, foot ≥ 1.13, Alacritty ≥ 0.13, Konsole ≥ 22.12,
     * tmux ≥ 3.4 (pass-through).
     */
    public function supportsStyledUnderline(): bool
    {
        return $this->supportsStyledUnderline;
    }

    /**
     * Whether the terminal supports colored underlines (SGR 58).
     *
     * In practice, this matches {@see supportsStyledUnderline()} — all
     * terminals that support styled underlines also support underline color.
     */
    public function supportsUnderlineColor(): bool
    {
        return $this->supportsUnderlineColor;
    }

    /**
     * Whether the terminal supports overline (SGR 53).
     *
     * Supported by: Kitty, WezTerm, Ghostty, foot, Konsole.
     * NOT supported by: iTerm2, Windows Terminal, Alacritty.
     */
    public function supportsOverline(): bool
    {
        return $this->supportsOverline;
    }

    /**
     * Whether the terminal supports SGR-1006 mouse tracking.
     *
     * Disabled in CI, SSH without $TERM, dumb terminals, and Windows
     * environments without stty.
     */
    public function supportsMouse(): bool
    {
        return $this->supportsMouse;
    }

    /**
     * Whether the terminal supports synchronized output (mode 2026).
     *
     * Reduces flicker during large screen updates by buffering output
     * until the terminal receives the end marker.
     */
    public function supportsSynchronizedOutput(): bool
    {
        return $this->supportsSynchronizedOutput;
    }

    /**
     * Whether the Kitty keyboard protocol is available (or detected at runtime).
     *
     * The real Kitty protocol detection happens at runtime via DA1 query in
     * Terminal::start(). This method returns true only for terminals that are
     * known to support it based on environment variables.
     */
    public function supportsKittyProtocol(): bool
    {
        return $this->supportsKittyProtocol;
    }

    // ── Detection helpers ────────────────────────────────────────────────

    /**
     * Terminals known to support styled underlines (SGR 4:N).
     *
     * @return list<string>
     */
    public static function styledUnderlineTerminals(): array
    {
        return ['kitty', 'WezTerm', 'ghostty', 'iTerm.app'];
    }

    /**
     * Terminals known to support overline (SGR 53).
     *
     * @return list<string>
     */
    public static function overlineTerminals(): array
    {
        return ['kitty', 'WezTerm', 'ghostty'];
    }

    // ── Private detection methods ────────────────────────────────────────

    private function detectStyledUnderline(string $program, string $term): bool
    {
        // Direct TERM_PROGRAM match
        if (in_array($program, self::styledUnderlineTerminals(), true)) {
            return true;
        }

        // Windows Terminal
        if (getenv('WT_SESSION') !== false) {
            return true;
        }

        // foot terminal (sets $TERM=foot or $TERM=foot-direct)
        if (str_starts_with($term, 'foot')) {
            return true;
        }

        // Konsole
        if (getenv('KONSOLE_VERSION') !== false) {
            return true;
        }

        // Alacritty — sets TERM containing "alacritty"
        if (str_contains($term, 'alacritty')) {
            return true;
        }

        // JetBrains IDE terminal
        if (getenv('TERMINAL_EMULATOR') === 'JetBrains-JediTerm') {
            return true;
        }

        // Kitty via KITTY_WINDOW_ID (more reliable than TERM_PROGRAM in some setups)
        if (getenv('KITTY_WINDOW_ID') !== false) {
            return true;
        }

        // Ghostty via GHOSTTY_RESOURCES_DIR
        if (getenv('GHOSTTY_RESOURCES_DIR') !== false) {
            return true;
        }

        // tmux ≥ 3.4 passes through styled underlines
        if ($this->isTmuxWithPassThrough()) {
            return true;
        }

        return false;
    }

    private function detectOverline(string $program, string $term): bool
    {
        if (in_array($program, self::overlineTerminals(), true)) {
            return true;
        }

        // foot
        if (str_starts_with($term, 'foot')) {
            return true;
        }

        // Konsole
        if (getenv('KONSOLE_VERSION') !== false) {
            return true;
        }

        // Kitty via KITTY_WINDOW_ID
        if (getenv('KITTY_WINDOW_ID') !== false) {
            return true;
        }

        // Ghostty via GHOSTTY_RESOURCES_DIR
        if (getenv('GHOSTTY_RESOURCES_DIR') !== false) {
            return true;
        }

        return false;
    }

    private function detectMouse(): bool
    {
        // No mouse support without a real tty
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return false;
        }

        // Disable in CI environments
        if (getenv('CI') !== false || getenv('CONTINUOUS_INTEGRATION') !== false) {
            return false;
        }

        // Disable for dumb terminals
        $term = getenv('TERM');
        if (false === $term || 'dumb' === $term) {
            return false;
        }

        // Check that stty is available (real terminal, not piped)
        if (!$this->hasSttyAvailable()) {
            return false;
        }

        return true;
    }

    private function detectSynchronizedOutput(string $program, string $term): bool
    {
        // Mode 2026 (Synchronized Output) is supported by:
        // Kitty, WezTerm, Ghostty, foot, Alacritty, iTerm2 ≥ 3.5,
        // Windows Terminal, tmux ≥ 3.4
        if (in_array($program, ['kitty', 'WezTerm', 'ghostty', 'iTerm.app'], true)) {
            return true;
        }

        if (getenv('WT_SESSION') !== false) {
            return true;
        }

        if (str_starts_with($term, 'foot') || str_contains($term, 'alacritty')) {
            return true;
        }

        if (getenv('KONSOLE_VERSION') !== false) {
            return true;
        }

        if (getenv('KITTY_WINDOW_ID') !== false || getenv('GHOSTTY_RESOURCES_DIR') !== false) {
            return true;
        }

        if ($this->isTmuxWithPassThrough()) {
            return true;
        }

        return false;
    }

    private function detectKittyProtocol(string $program): bool
    {
        return in_array($program, ['kitty', 'WezTerm', 'ghostty'], true)
            || getenv('KITTY_WINDOW_ID') !== false
            || getenv('GHOSTTY_RESOURCES_DIR') !== false;
    }

    private function isTmuxWithPassThrough(): bool
    {
        if (getenv('TMUX') === false) {
            return false;
        }

        $version = trim((string) shell_exec('tmux -V 2>/dev/null'));
        if (!preg_match('/(\d+)\.(\d+)/', $version, $m)) {
            return false;
        }

        // tmux 3.4+ passes through styled underlines and other advanced sequences
        return (int) $m[1] > 3 || ((int) $m[1] === 3 && (int) $m[2] >= 4);
    }

    private function hasSttyAvailable(): bool
    {
        static $available = null;

        if (null !== $available) {
            return $available;
        }

        return $available = (bool) shell_exec('stty 2>/dev/null');
    }
}
