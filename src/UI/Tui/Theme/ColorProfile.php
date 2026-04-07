<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Theme;

/**
 * Terminal color capability profile.
 *
 * Represents the maximum color depth a terminal supports, from full 24-bit
 * TrueColor down to no color at all (monochrome/ASCII).
 *
 * Detection is handled by {@see TerminalColorDetector}. The active profile
 * determines how RGB colors are downsampled via {@see ColorDownsampler}.
 */
enum ColorProfile: string
{
    /** 24-bit (16.7M colors) — modern terminals with COLORTERM=truecolor. */
    case TrueColor = 'truecolor';

    /** 8-bit (256 colors) — xterm-256color, macOS Terminal.app. */
    case Ansi256 = '256';

    /** 4-bit (16 colors) — basic ANSI, screen/tmux without 256-color support. */
    case Ansi16 = '16';

    /** No color support — dumb terminals, CI, or NO_COLOR env var. */
    case Ascii = 'ascii';

    /**
     * Whether this profile supports any ANSI color at all.
     */
    public function hasColor(): bool
    {
        return $this !== self::Ascii;
    }

    /**
     * Whether this profile supports TrueColor (24-bit) output.
     */
    public function isTrueColor(): bool
    {
        return $this === self::TrueColor;
    }

    /**
     * Get the maximum number of colors this profile can represent.
     */
    public function maxColors(): int
    {
        return match ($this) {
            self::TrueColor => 16_777_216,
            self::Ansi256 => 256,
            self::Ansi16 => 16,
            self::Ascii => 0,
        };
    }
}
