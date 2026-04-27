<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

final class SettingValueFormatter
{
    public static function display(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public static function masked(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (strlen($value) < 12) {
            return '***';
        }

        return substr($value, 0, 8).'...'.substr($value, -4);
    }
}
