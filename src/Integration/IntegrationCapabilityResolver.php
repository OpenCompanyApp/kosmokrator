<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

use OpenCompany\IntegrationCore\Contracts\ToolProvider;

final class IntegrationCapabilityResolver
{
    /**
     * @return array{
     *     auth: string,
     *     auth_strategy: string,
     *     host_availability: array<string, mixed>,
     *     runtime_requirements: array<string, mixed>,
     *     compatibility: array<string, mixed>,
     *     compatibility_summary: string,
     *     cli_setup_supported: bool,
     *     cli_runtime_supported: bool
     * }
     */
    public function resolve(ToolProvider $provider): array
    {
        $providerCapabilities = $this->providerCapabilities($provider);
        $raw = array_replace_recursive(
            $this->fallbackCapabilities($provider),
            $providerCapabilities,
        );

        $providerSeo = is_array($providerCapabilities['seo'] ?? null) ? $providerCapabilities['seo'] : [];
        $providerCompatibility = is_array($providerCapabilities['compatibility'] ?? null) ? $providerCapabilities['compatibility'] : [];
        $compatibility = is_array($raw['compatibility'] ?? null) ? $raw['compatibility'] : [];
        $cliSetupSupported = $this->boolValue(
            $providerCapabilities['cli_setup_supported']
                ?? $providerSeo['cli_setup_supported']
                ?? $providerCompatibility['cli_setup_supported']
                ?? $compatibility['cli_setup_supported']
                ?? $raw['cli_setup_supported']
                ?? null,
            true,
        );
        $cliRuntimeSupported = $this->boolValue(
            $providerCapabilities['cli_runtime_supported']
                ?? $providerSeo['cli_runtime_supported']
                ?? $providerCompatibility['cli_runtime_supported']
                ?? $compatibility['cli_runtime_supported']
                ?? $raw['cli_runtime_supported']
                ?? null,
            true,
        );

        $compatibility['cli_setup_supported'] = $cliSetupSupported;
        $compatibility['cli_runtime_supported'] = $cliRuntimeSupported;

        $authStrategy = (string) (
            $providerCapabilities['auth_strategy']
            ?? $providerSeo['auth_strategy']
            ?? $raw['auth_strategy']
            ?? $raw['auth']
            ?? 'none'
        );
        $auth = (string) (
            $providerCapabilities['auth']
            ?? $providerSeo['auth']
            ?? $raw['auth']
            ?? $authStrategy
        );
        $hostAvailability = is_array($raw['host_availability'] ?? null) ? $raw['host_availability'] : [];
        $runtimeRequirements = is_array($raw['runtime_requirements'] ?? null) ? $raw['runtime_requirements'] : [];

        return [
            'auth' => $auth,
            'auth_strategy' => $authStrategy,
            'host_availability' => $hostAvailability,
            'runtime_requirements' => $runtimeRequirements,
            'compatibility' => $compatibility,
            'compatibility_summary' => (string) (
                $providerCapabilities['compatibility_summary']
                ?? $providerSeo['compatibility_summary']
                ?? $providerSeo['auth_summary']
                ?? $raw['compatibility_summary']
                ?? $this->summary($cliSetupSupported, $cliRuntimeSupported)
            ),
            'cli_setup_supported' => $cliSetupSupported,
            'cli_runtime_supported' => $cliRuntimeSupported,
        ];
    }

    public function cliSetupSupported(ToolProvider $provider): bool
    {
        return $this->resolve($provider)['cli_setup_supported'];
    }

    public function cliRuntimeSupported(ToolProvider $provider): bool
    {
        return $this->resolve($provider)['cli_runtime_supported'];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerCapabilities(ToolProvider $provider): array
    {
        $capabilities = [];

        foreach (['capabilities', 'integrationCapabilities'] as $method) {
            if (! method_exists($provider, $method)) {
                continue;
            }

            try {
                $capabilities = $this->toArray($provider->{$method}());
            } catch (\Throwable) {
                $capabilities = [];
            }

            if ($capabilities !== []) {
                break;
            }
        }

        $meta = $provider->appMeta();
        foreach (['auth', 'auth_strategy', 'host_availability', 'runtime_requirements', 'compatibility', 'compatibility_summary', 'cli_setup_supported', 'cli_runtime_supported', 'seo'] as $key) {
            if (array_key_exists($key, $meta) && ! array_key_exists($key, $capabilities)) {
                $capabilities[$key] = $meta[$key];
            }
        }

        return $capabilities;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackCapabilities(ToolProvider $provider): array
    {
        $fields = $provider->credentialFields();
        $authStrategy = $this->fallbackAuthStrategy($fields);
        $redirectOauth = $this->hasCredentialType($fields, 'oauth_connect');
        $cliSetupSupported = ! $redirectOauth;
        $cliRuntimeSupported = ! $redirectOauth;

        return [
            'auth' => $authStrategy,
            'auth_strategy' => $authStrategy,
            'host_availability' => [
                'cli' => $cliRuntimeSupported,
                'web' => false,
                'proxy' => false,
            ],
            'runtime_requirements' => [],
            'compatibility' => [
                'cli_setup_supported' => $cliSetupSupported,
                'cli_runtime_supported' => $cliRuntimeSupported,
            ],
            'compatibility_summary' => $this->summary($cliSetupSupported, $cliRuntimeSupported),
            'cli_setup_supported' => $cliSetupSupported,
            'cli_runtime_supported' => $cliRuntimeSupported,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function fallbackAuthStrategy(array $fields): string
    {
        if ($fields === []) {
            return 'none';
        }

        if ($this->hasCredentialType($fields, 'oauth_connect')) {
            return 'oauth2_authorization_code';
        }

        $keys = array_map(
            static fn (array $field): string => strtolower((string) ($field['key'] ?? '')),
            $fields,
        );

        foreach (['access_token', 'token', 'api_token'] as $key) {
            if (in_array($key, $keys, true)) {
                return 'bearer_token';
            }
        }

        if (in_array('api_key', $keys, true)) {
            return 'api_key';
        }

        return 'manual_credentials';
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function hasCredentialType(array $fields, string $type): bool
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? $serialized : [];
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $array = $value->toArray();

            return is_array($array) ? $array : [];
        }

        return [];
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    private function summary(bool $cliSetupSupported, bool $cliRuntimeSupported): string
    {
        if ($cliSetupSupported && $cliRuntimeSupported) {
            return 'CLI setup and runtime supported.';
        }

        if (! $cliSetupSupported && $cliRuntimeSupported) {
            return 'CLI runtime supported when credentials are available, but headless setup is not supported yet.';
        }

        return 'Not supported by the local CLI runtime yet; visible for future proxy support.';
    }
}
