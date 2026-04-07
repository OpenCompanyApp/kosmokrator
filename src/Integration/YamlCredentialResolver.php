<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

use Kosmokrator\Session\SettingsRepositoryInterface;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;

class YamlCredentialResolver implements CredentialResolver
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settingsRepo,
    ) {}

    public function get(string $integration, string $key, mixed $default = null, ?string $account = null): mixed
    {
        $prefix = $account !== null
            ? "integration.{$integration}.accounts.{$account}.{$key}"
            : "integration.{$integration}.accounts.default.{$key}";

        $value = $this->settingsRepo->get('global', $prefix);

        return $value ?? $default;
    }

    public function isConfigured(string $integration, ?string $account = null): bool
    {
        // Check that at least one credential key exists for the account
        $prefix = $account !== null
            ? "integration.{$integration}.accounts.{$account}."
            : "integration.{$integration}.accounts.default.";

        // Check for common credential keys
        foreach (['api_key', 'token', 'url'] as $key) {
            $value = $this->settingsRepo->get('global', $prefix.$key);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    public function getAccounts(string $integration): array
    {
        // Accounts are tracked via a special key in SQLite
        $accountsJson = $this->settingsRepo->get('global', "integration.{$integration}.accounts");

        if ($accountsJson === null) {
            return [];
        }

        $accounts = json_decode($accountsJson, true);
        if (! is_array($accounts)) {
            return [];
        }

        // Return non-default account aliases
        return array_values(array_filter(
            array_map('strval', array_keys($accounts)),
            fn (string $key) => $key !== 'default',
        ));
    }

    /**
     * Store a credential value for an integration account.
     */
    public function set(string $integration, string $key, string $value, ?string $account = null): void
    {
        $prefix = $account !== null
            ? "integration.{$integration}.accounts.{$account}.{$key}"
            : "integration.{$integration}.accounts.default.{$key}";

        $this->settingsRepo->set('global', $prefix, $value);
    }

    /**
     * Remove all credentials for an integration.
     */
    public function removeIntegration(string $integration): void
    {
        // Remove the account index
        $accountsJson = $this->settingsRepo->get('global', "integration.{$integration}.accounts");
        if ($accountsJson !== null) {
            $accounts = json_decode($accountsJson, true);
            if (is_array($accounts)) {
                foreach (array_keys($accounts) as $account) {
                    $this->removeAccount($integration, (string) $account);
                }
            }
            $this->settingsRepo->delete('global', "integration.{$integration}.accounts");
        }
    }

    /**
     * Remove credentials for a specific account.
     */
    public function removeAccount(string $integration, string $account): void
    {
        $prefix = "integration.{$integration}.accounts.{$account}.";
        // The settings repo doesn't have a prefix-delete, so we delete known keys
        foreach (['api_key', 'token', 'url', 'secret', 'webhook_url'] as $key) {
            $this->settingsRepo->delete('global', $prefix.$key);
        }
    }

    /**
     * Register that an account exists for an integration.
     */
    public function registerAccount(string $integration, string $account = 'default'): void
    {
        $accountsJson = $this->settingsRepo->get('global', "integration.{$integration}.accounts");
        $accounts = $accountsJson !== null ? (json_decode($accountsJson, true) ?? []) : [];

        if (! isset($accounts[$account])) {
            $accounts[$account] = true;
            $this->settingsRepo->set('global', "integration.{$integration}.accounts", json_encode($accounts));
        }
    }
}
