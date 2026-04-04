<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

/**
 * Cached terminal size helper.
 *
 * Caches `tput cols` and `tput lines` for the lifetime of a single
 * animation call so that repeated queries within the same animation
 * don't shell out to tput on every frame.
 */
final class TerminalSize
{
    private static ?int $cachedCols = null;

    private static ?int $cachedLines = null;

    /**
     * Get terminal width in columns, cached for the animation lifetime.
     */
    public static function cols(): int
    {
        if (self::$cachedCols === null) {
            self::$cachedCols = (int) exec('tput cols') ?: 120;
        }

        return self::$cachedCols;
    }

    /**
     * Get terminal height in lines, cached for the animation lifetime.
     */
    public static function lines(): int
    {
        if (self::$cachedLines === null) {
            self::$cachedLines = (int) exec('tput lines') ?: 30;
        }

        return self::$cachedLines;
    }

    /**
     * Clear the cache (call at the end of each animation).
     */
    public static function reset(): void
    {
        self::$cachedCols = null;
        self::$cachedLines = null;
    }
}
