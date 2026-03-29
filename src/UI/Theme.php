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
    public static function text(): string { return self::color256(245); }
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
}
