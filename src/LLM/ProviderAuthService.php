<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Illuminate\Config\Repository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;

/**
 * Provider authentication concerns — API key storage, OAuth status, credential masking.
 *
 * Extracted from ProviderCatalog so that auth logic (key lookup, codex token checking)
 * lives separately from provider/model definition queries.
 */
class ProviderAuthService
{
    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Repository $config,
        private readonly CodexTokenStore $codexTokens,
    ) {}

    /**
     * @return array<string, string>
     */
    public function authModes(): array
    {
        $modes = [];
        foreach ($this->catalog->providers() as $provider) {
            $modes[$provider->id] = $provider->authMode;
        }

        return $modes;
    }

    /**
     * @return array<string, string>
     */
    public function authStatuses(): array
    {
        $statuses = [];
        foreach ($this->catalog->providers() as $provider) {
            $statuses[$provider->id] = $this->authStatus($provider->id);
        }

        return $statuses;
    }

    public function authMode(string $provider): string
    {
        return $this->catalog->provider($provider)?->authMode ?? 'api_key';
    }

    public function authStatus(string $provider): string
    {
        return match ($this->authMode($provider)) {
            'oauth' => $this->codexStatus(),
            'none' => 'No authentication required',
            default => $this->apiKeyStatus($provider),
        };
    }

    public function maskedCredential(string $provider): string
    {
        return match ($this->authMode($provider)) {
            'oauth' => '(managed by login flow)',
            'none' => '(not required)',
            default => $this->maskKey($this->apiKey($provider)),
        };
    }

    public function apiKey(string $provider): string
    {
        return (string) ($this->settings->get('global', "provider.{$provider}.api_key")
            ?? $this->config->get("prism.providers.{$provider}.api_key", ''));
    }

    private function codexStatus(): string
    {
        $token = $this->codexTokens->current();
        if ($token === null) {
            return 'Not authenticated';
        }

        $label = $token->email ?? $token->accountId ?? 'ChatGPT account';

        if ($token->isExpired()) {
            return "Expired · {$label}";
        }

        if ($token->isExpiringSoon()) {
            return "Active, refresh soon · {$label}";
        }

        return "Authenticated · {$label}";
    }

    private function apiKeyStatus(string $provider): string
    {
        $key = $this->apiKey($provider);

        if ($key === '') {
            return 'API key not configured';
        }

        return 'Configured · '.$this->maskKey($key);
    }

    private function maskKey(string $key): string
    {
        if ($key === '') {
            return '(not set)';
        }

        if (strlen($key) < 12) {
            return '***';
        }

        return substr($key, 0, 8).'...'.substr($key, -4);
    }
}
