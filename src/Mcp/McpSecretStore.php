<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingValueFormatter;

final class McpSecretStore
{
    public function __construct(private readonly SettingsRepositoryInterface $settings) {}

    public function set(string $server, string $key, string $value): void
    {
        $this->settings->set('global', $this->storageKey($server, $key), $value);
    }

    public function get(string $server, string $key): ?string
    {
        return $this->settings->get('global', $this->storageKey($server, $key));
    }

    public function unset(string $server, string $key): void
    {
        $this->settings->delete('global', $this->storageKey($server, $key));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $server = null): array
    {
        $prefix = $server === null ? 'mcp.' : "mcp.{$server}.";
        $rows = [];

        foreach ($this->settings->all('global') as $key => $value) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'server' => explode('.', $key)[1] ?? '',
                'configured' => trim($value) !== '',
                'masked' => SettingValueFormatter::masked($value),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        return $rows;
    }

    public function resolveValue(string $server, string $value): string
    {
        if (str_starts_with($value, '@secret:')) {
            return $this->resolveSecretReference(substr($value, 8)) ?? '';
        }

        if (preg_match('/^\$\{KOSMO_SECRET:([^}]+)\}$/', $value, $m) === 1) {
            return $this->resolveSecretReference($m[1]) ?? '';
        }

        if (preg_match('/^\$\{env:([^}]+)\}$/', $value, $m) === 1) {
            return getenv($m[1]) !== false ? (string) getenv($m[1]) : '';
        }

        if (preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)(:-([^}]*))?\}$/', $value, $m) === 1) {
            $env = getenv($m[1]);

            return $env !== false ? (string) $env : (string) ($m[3] ?? '');
        }

        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $value, $m) === 1) {
            return getenv($m[1]) !== false ? (string) getenv($m[1]) : '';
        }

        return $value;
    }

    private function resolveSecretReference(string $reference): ?string
    {
        if (str_starts_with($reference, 'mcp.')) {
            return $this->settings->get('global', $reference);
        }

        return null;
    }

    private function storageKey(string $server, string $key): string
    {
        $key = trim($key, '.');
        if (str_starts_with($key, 'mcp.')) {
            return $key;
        }

        return "mcp.{$server}.{$key}";
    }
}
