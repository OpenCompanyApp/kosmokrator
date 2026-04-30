<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

use Kosmokrator\Integration\Runtime\IntegrationToolMetadata;
use Kosmokrator\Settings\SettingsManager;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

class IntegrationManager
{
    public function __construct(
        private readonly ToolProviderRegistry $providers,
        private readonly SettingsManager $settings,
        private readonly CredentialResolver $credentials,
        private readonly ?IntegrationCapabilityResolver $capabilityResolver = null,
    ) {}

    private function capabilities(): IntegrationCapabilityResolver
    {
        return $this->capabilityResolver ?? new IntegrationCapabilityResolver;
    }

    /**
     * Get all registered providers that can execute in the local CLI runtime.
     *
     * @return array<string, ToolProvider>
     */
    public function getLocallyRunnableProviders(): array
    {
        $result = [];
        foreach ($this->getDiscoverableProviders() as $name => $provider) {
            if ($this->isLocallyRunnable($provider)) {
                $result[$name] = $provider;
            }
        }

        return $result;
    }

    /**
     * Get all integration providers that should be shown in catalog/docs.
     *
     * @return array<string, ToolProvider>
     */
    public function getDiscoverableProviders(): array
    {
        return $this->providers->all();
    }

    /**
     * Get providers that can be configured from the headless CLI.
     *
     * @return array<string, ToolProvider>
     */
    public function getCliConfigurableProviders(): array
    {
        $result = [];
        foreach ($this->getDiscoverableProviders() as $name => $provider) {
            if ($this->isCliSetupSupported($provider)) {
                $result[$name] = $provider;
            }
        }

        return $result;
    }

    /**
     * Get providers the user has configured and enabled.
     *
     * @return array<string, ToolProvider>
     */
    public function getActiveProviders(): array
    {
        $result = [];
        foreach ($this->getLocallyRunnableProviders() as $name => $provider) {
            if ($this->isEnabled($name) && $this->isConfiguredForActivation($name, $provider)) {
                $result[$name] = $provider;
            }
        }

        return $result;
    }

    /**
     * Check if a provider can execute in the local CLI runtime.
     */
    public function isLocallyRunnable(ToolProvider $provider): bool
    {
        return $this->isCliRuntimeSupported($provider);
    }

    public function isCliSetupSupported(ToolProvider $provider): bool
    {
        return $this->capabilities()->cliSetupSupported($provider);
    }

    public function isCliRuntimeSupported(ToolProvider $provider): bool
    {
        return $this->capabilities()->cliRuntimeSupported($provider);
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilityMetadata(ToolProvider $provider): array
    {
        return $this->capabilities()->resolve($provider);
    }

    /**
     * Check if an integration is enabled in settings (YAML).
     */
    public function isEnabled(string $integration): bool
    {
        $enabled = $this->settings->getRaw("integrations.{$integration}.enabled")
            ?? $this->settings->getRaw("kosmokrator.integrations.{$integration}.enabled");

        return $enabled === true || $enabled === 'on';
    }

    /**
     * Get the effective permission for an integration + operation.
     *
     * Reads from YAML config. Returns 'allow', 'ask', or 'deny'.
     */
    public function getPermission(string $integration, string $operation): string
    {
        $permission = $this->settings->getRaw("integrations.{$integration}.permissions.{$operation}")
            ?? $this->settings->getRaw("kosmokrator.integrations.{$integration}.permissions.{$operation}");

        if (in_array($permission, ['allow', 'ask', 'deny'], true)) {
            return $permission;
        }

        $default = $this->settings->getRaw('integrations.permissions_default')
            ?? $this->settings->getRaw('kosmokrator.integrations.permissions_default');

        return in_array($default, ['allow', 'ask', 'deny'], true) ? $default : 'ask';
    }

    /**
     * Set permission for an integration + operation in YAML.
     */
    public function setPermission(string $integration, string $operation, string $value, string $scope = 'global'): void
    {
        if (! in_array($value, ['allow', 'ask', 'deny'], true)) {
            return;
        }

        $this->settings->setRaw(
            "integrations.{$integration}.permissions.{$operation}",
            $value,
            $scope,
        );
    }

    /**
     * Enable or disable an integration in YAML.
     */
    public function setEnabled(string $integration, bool $enabled, string $scope = 'global'): void
    {
        $this->settings->setRaw(
            "integrations.{$integration}.enabled",
            $enabled,
            $scope,
        );
    }

    /**
     * Set all integration permissions to a given value (bulk).
     *
     * @param  string  $value  'allow', 'ask', or 'deny'
     * @param  string|null  $operation  'read', 'write', or null for both
     */
    public function setAllPermissions(string $value, ?string $operation = null, string $scope = 'global'): void
    {
        foreach ($this->getLocallyRunnableProviders() as $name => $provider) {
            if (! $this->isConfiguredForActivation($name, $provider)) {
                continue;
            }

            if ($operation === null) {
                $this->setPermission($name, 'read', $value, $scope);
                $this->setPermission($name, 'write', $value, $scope);
            } else {
                $this->setPermission($name, $operation, $value, $scope);
            }
        }
    }

    /**
     * Build a tool catalog suitable for LuaCatalogBuilder.
     *
     * @return list<array{name: string, description: string, tools: array, isIntegration: bool, accounts?: array<string, mixed>}>
     */
    public function getToolCatalog(): array
    {
        $catalog = [];

        foreach ($this->getActiveProviders() as $name => $provider) {
            $tools = [];
            foreach (IntegrationToolMetadata::forProvider($provider) as $slug => $meta) {
                $tools[] = [
                    'slug' => $slug,
                    'name' => (string) ($meta['name'] ?? $slug),
                    'description' => $meta['description'] ?? '',
                    'parameters' => $meta['parameters'] ?? [],
                ];
            }

            $entry = [
                'name' => $name,
                'description' => $provider->appMeta()['description'] ?? '',
                'tools' => $tools,
                'isIntegration' => true,
            ];

            // Inject account aliases from credential resolver
            $accounts = $this->credentials->getAccounts($name);
            if ($accounts !== []) {
                $entry['accounts'] = $accounts;
            }

            $catalog[] = $entry;
        }

        return $catalog;
    }

    /**
     * Get all installed providers that should be visible in settings and catalogs.
     *
     * @return array<string, ToolProvider>
     */
    public function getAllProviders(): array
    {
        return $this->providers->all();
    }

    /**
     * Check whether an integration has all required local credentials.
     */
    public function isConfiguredForActivation(string $integration, ToolProvider $provider, ?string $account = null): bool
    {
        $requiredFields = array_filter(
            $provider->credentialFields(),
            static fn (array $field): bool => (bool) ($field['required'] ?? false),
        );

        if ($requiredFields === []) {
            return true;
        }

        foreach ($requiredFields as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = $this->credentials->get($integration, $key, null, $account);
            if ($value === null) {
                return false;
            }

            if (is_string($value) && trim($value) === '') {
                return false;
            }

            if (is_array($value) && $value === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function getAccounts(string $integration): array
    {
        return $this->credentials->getAccounts($integration);
    }

    public function credentialValue(string $integration, string $key, ?string $account = null): mixed
    {
        return $this->credentials->get($integration, $key, null, $account);
    }
}
