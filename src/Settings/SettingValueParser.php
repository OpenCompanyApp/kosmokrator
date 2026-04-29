<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

use Symfony\Component\Yaml\Yaml;

final class SettingValueParser
{
    public function parse(SettingDefinition $definition, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($definition->type) {
            'number' => $this->number($value),
            'toggle' => $this->toggle($value),
            'string_list' => $this->stringList($value),
            'json' => $this->json($value),
            'yaml' => $this->yaml($value),
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    /**
     * @return list<string>
     */
    public function stringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ), static fn (string $item): bool => $item !== ''));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        $decoded = json_decode($string, true);
        if (is_array($decoded)) {
            return $this->stringList($decoded);
        }

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            preg_split('/[,\s]+/', $string) ?: [],
        ), static fn (string $item): bool => $item !== ''));
    }

    private function number(mixed $value): mixed
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    private function toggle(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'on', 'yes', 'y' => 'on',
            '0', 'false', 'off', 'no', 'n' => 'off',
            default => (string) $value,
        };
    }

    private function json(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: '.json_last_error_msg());
        }

        return $decoded;
    }

    private function yaml(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return Yaml::parse($value);
    }
}
