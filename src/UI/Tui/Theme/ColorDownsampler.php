<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Theme;

/**
 * Wires {@see TerminalColorDetector} + {@see ColorConverter} to produce
 * terminal-appropriate ANSI escape sequences from TrueColor RGB values.
 *
 * Usage:
 *   $ds = new ColorDownsampler();                         // auto-detect profile
 *   $ds = new ColorDownsampler(ColorProfile::Ansi256);    // force profile
 *
 *   $fg = $ds->foregroundHex('#ff3c28');                  // escape sequence
 *   $bg = $ds->backgroundRgb(18, 18, 18);                // escape sequence
 */
final class ColorDownsampler
{
    private readonly ColorProfile $profile;

    public function __construct(?ColorProfile $profile = null)
    {
        $this->profile = $profile ?? TerminalColorDetector::detect();
    }

    /**
     * Get the active color profile.
     */
    public function getProfile(): ColorProfile
    {
        return $this->profile;
    }

    /**
     * Convert a hex color to a foreground ANSI sequence for the active profile.
     *
     * @param  string  $hex  Color in #RRGGBB format
     */
    public function foregroundHex(string $hex): string
    {
        [$r, $g, $b] = ColorConverter::hexToRgb($hex);

        return $this->foregroundRgb($r, $g, $b);
    }

    /**
     * Convert a hex color to a background ANSI sequence for the active profile.
     *
     * @param  string  $hex  Color in #RRGGBB format
     */
    public function backgroundHex(string $hex): string
    {
        [$r, $g, $b] = ColorConverter::hexToRgb($hex);

        return $this->backgroundRgb($r, $g, $b);
    }

    /**
     * Convert an RGB color to a foreground ANSI sequence for the active profile.
     */
    public function foregroundRgb(int $r, int $g, int $b): string
    {
        return match ($this->profile) {
            ColorProfile::TrueColor => "\033[38;2;{$r};{$g};{$b}m",
            ColorProfile::Ansi256 => "\033[38;5;".ColorConverter::rgbTo256($r, $g, $b).'m',
            ColorProfile::Ansi16 => ColorConverter::rgbTo16($r, $g, $b, foreground: true),
            ColorProfile::Ascii => '',
        };
    }

    /**
     * Convert an RGB color to a background ANSI sequence for the active profile.
     */
    public function backgroundRgb(int $r, int $g, int $b): string
    {
        return match ($this->profile) {
            ColorProfile::TrueColor => "\033[48;2;{$r};{$g};{$b}m",
            ColorProfile::Ansi256 => "\033[48;5;".ColorConverter::rgbTo256($r, $g, $b).'m',
            ColorProfile::Ansi16 => ColorConverter::rgbTo16($r, $g, $b, foreground: false),
            ColorProfile::Ascii => '',
        };
    }
}
