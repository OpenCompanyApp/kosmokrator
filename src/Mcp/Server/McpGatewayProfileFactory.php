<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp\Server;

use Kosmokrator\Settings\SettingsManager;

final class McpGatewayProfileFactory
{
    public function __construct(private readonly SettingsManager $settings) {}

    /**
     * @param  list<string>  $integrationValues
     * @param  list<string>  $upstreamValues
     */
    public function create(
        ?string $profileName = null,
        array $integrationValues = [],
        array $upstreamValues = [],
        ?string $writePolicy = null,
        ?bool $exposeResources = null,
        ?bool $exposePrompts = null,
        ?int $maxResultChars = null,
        bool $force = false,
    ): McpGatewayProfile {
        $profile = $this->profileConfig($profileName);

        $integrations = $this->names($integrationValues);
        if ($integrations === []) {
            $integrations = $this->names($profile['integrations']['include'] ?? $profile['integrations'] ?? []);
        }

        $upstreams = $this->names($upstreamValues);
        if ($upstreams === []) {
            $upstreams = $this->names($profile['upstream_mcp']['include'] ?? $profile['upstreams'] ?? $profile['mcp'] ?? []);
        }

        $policy = $writePolicy ?: (string) ($profile['write_policy'] ?? 'deny');
        if (! in_array($policy, ['allow', 'deny'], true)) {
            $policy = 'deny';
        }

        return new McpGatewayProfile(
            integrations: $integrations,
            upstreams: $upstreams,
            writePolicy: $policy,
            exposeResources: $exposeResources ?? $this->bool($profile['expose_resources'] ?? true),
            exposePrompts: $exposePrompts ?? $this->bool($profile['expose_prompts'] ?? true),
            maxResultChars: max(1000, $maxResultChars ?? (int) ($profile['max_result_chars'] ?? 50000)),
            force: $force || $this->bool($profile['force'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function profileConfig(?string $profileName): array
    {
        if ($profileName === null || $profileName === '') {
            return [];
        }

        $value = $this->settings->getRaw("mcp_gateway.profiles.{$profileName}")
            ?? $this->settings->getRaw("mcp_proxy.profiles.{$profileName}")
            ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private function names(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        $names = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
