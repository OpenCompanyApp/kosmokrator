<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Theme;

/**
 * Pure static conversion algorithms for mapping TrueColor RGB values
 * to lower color depths (256-color, 16-color).
 *
 * All methods are stateless and side-effect free.
 *
 * Conversion algorithms:
 *
 * **TrueColor → 256-color (Ansi8):**
 *   - Grayscale path (r ≈ g ≈ b): indices 232–255
 *   - Color cube path: 6×6×6 cube at indices 16–231
 *
 * **TrueColor → 16-color (Ansi4):**
 *   - 1-bit-per-channel threshold: round(b/255)<<2 | round(g/255)<<1 | round(r/255)
 *   - Brightness heuristic: luminance ≥ 128 → bright variant
 */
final class ColorConverter
{
    /**
     * The 6-level channel values in the 256-color cube (indices 16–231).
     *
     * Each channel maps to one of: [0, 95, 135, 175, 215, 255].
     */
    private const CUBE_LEVELS = [0, 95, 135, 175, 215, 255];

    /**
     * Convert an RGB color to the nearest 256-color palette index.
     *
     * Uses the standard xterm 256-color palette:
     *   - Indices 16–231: 6×6×6 color cube
     *   - Indices 232–255: grayscale ramp (8–238)
     *
     * @param  int  $r  Red channel (0–255)
     * @param  int  $g  Green channel (0–255)
     * @param  int  $b  Blue channel (0–255)
     * @return int Palette index (16–255)
     */
    public static function rgbTo256(int $r, int $g, int $b): int
    {
        // Clamp to valid range
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));

        // Grayscale path: if all channels are very close to each other
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        if ($max - $min <= 10) {
            // Near-black → index 16
            if ($r < 8) {
                return 16;
            }
            // Near-white → index 231
            if ($r > 248) {
                return 231;
            }

            // Grayscale ramp: indices 232–255
            return (int) round(($r - 8) / 247 * 24) + 232;
        }

        // Color cube path: 16 + 36×R + 6×G + B
        return 16
            + 36 * self::cubeIndex($r)
            + 6 * self::cubeIndex($g)
            + self::cubeIndex($b);
    }

    /**
     * Convert an RGB color to an ANSI 16-color escape sequence.
     *
     * Uses the standard 4-bit mapping:
     *   index = round(b/255)<<2 | round(g/255)<<1 | round(r/255)
     *
     * A luminance-based brightness heuristic selects the bright variant
     * (indices 8–15) when the color's perceived brightness is high.
     *
     * @param  int   $r           Red channel (0–255)
     * @param  int   $g           Green channel (0–255)
     * @param  int   $b           Blue channel (0–255)
     * @param  bool  $foreground  True for foreground (3Xm/9Xm), false for background (4Xm/10Xm)
     * @return string ANSI escape sequence
     */
    public static function rgbTo16(int $r, int $g, int $b, bool $foreground = true): string
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));

        // Base color index (0–7)
        $index = (int) (round($b / 255) << 2 | round($g / 255) << 1 | round($r / 255));

        // Luminance-based brightness heuristic
        // If the color is bright, use the bright variant (index + 8)
        $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        if ($luminance >= 128 && $index < 8) {
            $index += 8;
        }

        // Build escape sequence
        if ($foreground) {
            // Standard fg: 30–37, bright fg: 90–97
            $code = $index < 8 ? (30 + $index) : (90 + $index - 8);

            return "\033[{$code}m";
        }

        // Standard bg: 40–47, bright bg: 100–107
        $code = $index < 8 ? (40 + $index) : (100 + $index - 8);

        return "\033[{$code}m";
    }

    /**
     * Convert a 256-color palette index to its RGB equivalent.
     *
     * Useful for 256→16 conversion via the RGB path.
     *
     * @param  int  $index  Palette index (0–255)
     * @return array{int, int, int} RGB tuple
     */
    public static function paletteToRgb(int $index): array
    {
        $index = max(0, min(255, $index));

        // Standard 16 colors (approximate RGB values)
        $standard16 = [
            [0, 0, 0],       // 0  Black
            [205, 0, 0],     // 1  Red
            [0, 205, 0],     // 2  Green
            [205, 205, 0],   // 3  Yellow
            [0, 0, 238],     // 4  Blue
            [205, 0, 205],   // 5  Magenta
            [0, 205, 205],   // 6  Cyan
            [229, 229, 229], // 7  White
            [127, 127, 127], // 8  Bright Black
            [255, 0, 0],     // 9  Bright Red
            [0, 255, 0],     // 10 Bright Green
            [255, 255, 0],   // 11 Bright Yellow
            [92, 92, 255],   // 12 Bright Blue
            [255, 0, 255],   // 13 Bright Magenta
            [0, 255, 255],   // 14 Bright Cyan
            [255, 255, 255], // 15 Bright White
        ];

        if ($index < 16) {
            return $standard16[$index];
        }

        // Color cube (16–231)
        if ($index < 232) {
            $i = $index - 16;
            $r = self::CUBE_LEVELS[(int) ($i / 36)];
            $g = self::CUBE_LEVELS[(int) (($i % 36) / 6)];
            $b = self::CUBE_LEVELS[$i % 6];

            return [$r, $g, $b];
        }

        // Grayscale ramp (232–255)
        $gray = 8 + 10 * ($index - 232);

        return [$gray, $gray, $gray];
    }

    /**
     * Calculate WCAG 2.0 relative luminance for a color.
     *
     * @param  int  $r  Red channel (0–255)
     * @param  int  $g  Green channel (0–255)
     * @param  int  $b  Blue channel (0–255)
     * @return float Luminance (0.0 = black, 1.0 = white)
     */
    public static function relativeLuminance(int $r, int $g, int $b): float
    {
        $srgb = [$r / 255.0, $g / 255.0, $b / 255.0];
        $linear = array_map(static fn(float $c): float =>
            $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $srgb
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * Calculate the WCAG 2.0 contrast ratio between two colors.
     *
     * @return float Contrast ratio (1:1 to 21:1)
     */
    public static function contrastRatio(int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): float
    {
        $l1 = self::relativeLuminance($r1, $g1, $b1);
        $l2 = self::relativeLuminance($r2, $g2, $b2);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Parse a hex color string to an RGB tuple.
     *
     * @param  string  $hex  Color in #RRGGBB or RRGGBB format
     * @return array{int, int, int}
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Map a channel value (0–255) to the nearest 6×6×6 cube index (0–5).
     */
    private static function cubeIndex(int $channel): int
    {
        return (int) round($channel / 255 * 5);
    }
}
