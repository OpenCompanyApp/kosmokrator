<?php

namespace Kosmokrator\UI;

class Theme
{
    private const ESC = "\033";

    public static function rgb(int $r, int $g, int $b): string
    {
        return self::ESC . "[38;2;{$r};{$g};{$b}m";
    }

    public static function bgRgb(int $r, int $g, int $b): string
    {
        return self::ESC . "[48;2;{$r};{$g};{$b}m";
    }

    public static function color256(int $code): string
    {
        return self::ESC . "[38;5;{$code}m";
    }

    // Core palette
    public static function primary(): string { return self::rgb(255, 60, 40); }
    public static function primaryDim(): string { return self::rgb(160, 30, 30); }
    public static function accent(): string { return self::rgb(255, 200, 80); }
    public static function success(): string { return self::rgb(80, 220, 100); }
    public static function warning(): string { return self::rgb(255, 200, 80); }
    public static function error(): string { return self::rgb(255, 80, 60); }
    public static function info(): string { return self::rgb(100, 200, 255); }
    public static function link(): string { return self::rgb(80, 140, 255); }
    public static function code(): string { return self::rgb(200, 120, 255); }
    public static function dim(): string { return self::color256(240); }
    public static function dimmer(): string { return self::color256(236); }
    public static function text(): string { return self::rgb(180, 180, 190); }
    public static function white(): string { return self::ESC . '[1;37m'; }
    public static function bold(): string { return self::ESC . '[1m'; }
    public static function reset(): string { return self::ESC . '[0m'; }

    // Diff colors
    public static function diffAdd(): string { return self::rgb(60, 160, 80); }
    public static function diffRemove(): string { return self::rgb(180, 60, 60); }

    // Code background
    public static function codeBg(): string { return self::bgRgb(40, 40, 40); }

    // Terminal control
    public static function hideCursor(): string { return self::ESC . '[?25l'; }
    public static function showCursor(): string { return self::ESC . '[?25h'; }
    public static function clearScreen(): string { return self::ESC . '[2J' . self::ESC . '[H'; }
    public static function moveTo(int $row, int $col): string { return self::ESC . "[{$row};{$col}H"; }

    // Tool icons
    public static function toolIcon(string $name): string
    {
        return match ($name) {
            'file_read' => '☽',   // Moon — illumination, revealing hidden text
            'file_write' => '☉',  // Sun — creation, bringing into being
            'file_edit' => '♅',   // Uranus — transformation, change
            'bash' => '⚡',       // Lightning — raw power, execution
            'grep' => '⊛',       // Astral search — seeking through the cosmos
            'glob' => '✧',       // Star cluster — surveying many points of light
            default => '◈',       // Gemstone — generic cosmic artifact
        };
    }

    // Context bar
    public static function contextBar(int $tokensIn, int $maxContext): string
    {
        $ratio = min(1.0, $tokensIn / max(1, $maxContext));
        $barWidth = 16;
        $filled = (int) round($ratio * $barWidth);
        $empty = $barWidth - $filled;

        $pct = (int) round($ratio * 100);

        // Color gradient: green → yellow → red
        if ($ratio < 0.6) {
            $color = self::success();
        } elseif ($ratio < 0.85) {
            $color = self::warning();
        } else {
            $color = self::error();
        }

        $bar = $color . str_repeat('━', $filled) . self::dimmer() . str_repeat('─', $empty) . self::reset();
        $label = self::formatTokenCount($tokensIn) . '/' . self::formatTokenCount($maxContext);

        return $bar . ' ' . self::dim() . $label . ' (' . $pct . '%)' . self::reset();
    }

    public static function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return round($tokens / 1_000_000, 1) . 'M';
        }
        if ($tokens >= 1_000) {
            return round($tokens / 1_000, 1) . 'k';
        }

        return (string) $tokens;
    }

    public static function maxContextForModel(string $model): int
    {
        $m = strtolower($model);

        if (str_contains($m, 'claude')) {
            return 200_000;
        }
        if (str_contains($m, 'glm')) {
            return 128_000;
        }
        if (str_contains($m, 'gpt-4')) {
            return 128_000;
        }

        return 200_000;
    }
}
