<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

use Kosmokrator\Session\SettingsRepositoryInterface;

final class SecretStore
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->settings->all('global') as $key => $value) {
            if (! $this->isManagedSecretKey($key)) {
                continue;
            }

            $rows[] = $this->status($key);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $key): array
    {
        $key = $this->normalizeKey($key);
        $value = $this->settings->get('global', $key);

        return [
            'key' => $key,
            'configured' => $value !== null && trim($value) !== '',
            'masked' => SettingValueFormatter::masked($value),
            'scope' => 'global',
        ];
    }

    public function set(string $key, string $value): void
    {
        $key = $this->normalizeKey($key);
        $this->assertAllowedKey($key);
        $this->settings->set('global', $key, $value);
    }

    public function unset(string $key): void
    {
        $key = $this->normalizeKey($key);
        $this->assertAllowedKey($key);
        $this->settings->delete('global', $key);
    }

    private function normalizeKey(string $key): string
    {
        return match ($key) {
            'kosmokrator.gateway.telegram.token' => 'gateway.telegram.token',
            default => $key,
        };
    }

    private function assertAllowedKey(string $key): void
    {
        if ($this->isManagedSecretKey($key)) {
            return;
        }

        throw new \InvalidArgumentException("Unsupported secret key [{$key}].");
    }

    private function isManagedSecretKey(string $key): bool
    {
        return (bool) preg_match('/^provider\.[A-Za-z0-9_.-]+\.api_key$/', $key)
            || $key === 'gateway.telegram.token';
    }
}
