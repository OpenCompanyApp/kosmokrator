<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

/**
 * Shared compatibility helpers for the kosmokrator -> kosmo config migration.
 */
final class ConfigCompatibility
{
    public const CANONICAL_ROOT = 'kosmo';

    public const LEGACY_ROOT = 'kosmokrator';

    /**
     * @return list<string>
     */
    public static function kosmoRootKeys(): array
    {
        return [
            'agent',
            'ui',
            'integrations',
            'mcp',
            'mcp_gateway',
            'web',
            'gateway',
            'tools',
            'context',
            'session',
            'codex',
            'audio',
            'provider_state',
        ];
    }

    /**
     * Normalize legacy `kosmokrator:` roots and flat runtime sections into `kosmo:`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeKosmoNamespace(array $data): array
    {
        if (isset($data[self::LEGACY_ROOT]) && is_array($data[self::LEGACY_ROOT])) {
            $data[self::CANONICAL_ROOT] = isset($data[self::CANONICAL_ROOT]) && is_array($data[self::CANONICAL_ROOT])
                ? self::mergeDeep($data[self::LEGACY_ROOT], $data[self::CANONICAL_ROOT])
                : $data[self::LEGACY_ROOT];
            unset($data[self::LEGACY_ROOT]);
        }

        foreach (self::kosmoRootKeys() as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $data[self::CANONICAL_ROOT] ??= [];
            if (! is_array($data[self::CANONICAL_ROOT])) {
                $data[self::CANONICAL_ROOT] = [];
            }

            $data[self::CANONICAL_ROOT][$key] = isset($data[self::CANONICAL_ROOT][$key])
                && is_array($data[self::CANONICAL_ROOT][$key])
                && is_array($data[$key])
                    ? self::mergeDeep($data[$key], $data[self::CANONICAL_ROOT][$key])
                    : $data[$key];
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Recursively merge $override into $base; scalar values in $override win.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
