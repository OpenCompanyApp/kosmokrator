<?php

namespace Kosmokrator\UI;

/**
 * Emits terminal notification sequences (BEL + OSC) when the agent finishes responding.
 *
 * Write target defaults to STDERR so raw sequences never corrupt piped stdout.
 */
final class TerminalNotification
{
    private const BEL = "\x07";
    private const ESC = "\x1b";

    /** @var (\Closure(string): void)|null */
    private static ?\Closure $writer = null;

    /**
     * Send a completion notification to the terminal.
     *
     * Always emits BEL (universal). Additionally emits an OSC sequence when
     * the current terminal is known to support one.
     */
    public static function notify(): void
    {
        $writer = self::writer();

        // BEL — universal signal detected by IDEs and terminal multiplexers
        $writer(self::BEL);

        // Terminal-specific OSC notification
        match (self::detectTerminal()) {
            'iterm'   => self::notifyIterm2($writer),
            'ghostty' => self::notifyGhostty($writer),
            'kitty'   => self::notifyKitty($writer),
            default   => null,
        };
    }

    /**
     * Override the write target (for testing). Pass null to restore default.
     */
    public static function setWriter(?\Closure $writer): void
    {
        self::$writer = $writer;
    }

    /** @return \Closure(string): void */
    private static function writer(): \Closure
    {
        if (self::$writer !== null) {
            return self::$writer;
        }

        return static function (string $data): void { fwrite(\STDERR, $data); };
    }

    private static function detectTerminal(): string
    {
        $program = getenv('TERM_PROGRAM') ?: '';

        return match (true) {
            str_contains($program, 'iTerm') => 'iterm',
            $program === 'ghostty' => 'ghostty',
            $program === 'kitty' => 'kitty',
            default => '',
        };
    }

    /** @param \Closure(string): void $w */
    private static function notifyIterm2(\Closure $w): void
    {
        // OSC 9 — triggers macOS notification center via iTerm2
        $w(self::ESC . ']9;KosmoKrator — response ready' . self::BEL);
    }

    /** @param \Closure(string): void $w */
    private static function notifyGhostty(\Closure $w): void
    {
        // OSC 777 — Ghostty desktop notification
        $w(self::ESC . ']777;notify;KosmoKrator;Response ready' . self::BEL);
    }

    /** @param \Closure(string): void $w */
    private static function notifyKitty(\Closure $w): void
    {
        // OSC 99 — Kitty inline toast notification
        $id = random_int(1, 9999);
        $st = self::ESC . '\\';
        $w(self::ESC . "]99;i={$id}:d=0:p=title;KosmoKrator{$st}");
        $w(self::ESC . "]99;i={$id}:p=body;Response ready{$st}");
        $w(self::ESC . "]99;i={$id}:d=1:a=focus;{$st}");
    }
}
