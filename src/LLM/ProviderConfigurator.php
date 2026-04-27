<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SecretStore;
use Kosmokrator\Settings\SettingsManager;

final class ProviderConfigurator
{
    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly SettingsManager $settings,
        private readonly SettingsRepositoryInterface $settingsRepository,
        private readonly SecretStore $secrets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function configure(
        string $provider,
        ?string $model = null,
        ?string $apiKey = null,
        string $scope = 'global',
    ): array {
        $definition = $this->catalog->provider($provider);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown provider [{$provider}].");
        }

        $model ??= $this->catalog->defaultModel($provider);
        if ($model !== null && $model !== '' && ! $this->catalog->supportsModel($provider, $model)) {
            throw new \InvalidArgumentException("Provider [{$provider}] does not advertise model [{$model}].");
        }

        $this->settings->set('agent.default_provider', $provider, $scope);
        if ($model !== null && $model !== '') {
            $this->settings->set('agent.default_model', $model, $scope);
            $this->settings->setProviderLastModel($provider, $model, $scope);
        }

        if ($apiKey !== null && $apiKey !== '') {
            if ($definition->authMode !== 'api_key') {
                throw new \InvalidArgumentException("Provider [{$provider}] does not use API key authentication.");
            }
            $this->secrets->set("provider.{$provider}.api_key", $apiKey);
        }

        return $this->status($provider);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $provider): array
    {
        $definition = $this->catalog->provider($provider);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown provider [{$provider}].");
        }

        return [
            'provider' => $provider,
            'label' => $definition->label,
            'description' => $definition->description,
            'auth_mode' => $definition->authMode,
            'auth_status' => $this->catalog->authStatus($provider),
            'source' => $definition->source,
            'driver' => $definition->driver,
            'url' => $definition->url,
            'default_model' => $this->settings->get('agent.default_provider') === $provider
                ? $this->settings->get('agent.default_model')
                : ($this->settings->getProviderLastModel($provider) ?? $definition->defaultModel),
            'configured_api_key' => $definition->authMode === 'api_key'
                ? $this->settingsRepository->get('global', "provider.{$provider}.api_key") !== null
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function upsertCustomProvider(string $id, array $definition, ?string $apiKey = null, string $scope = 'global'): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Custom provider ID is required.');
        }

        $normalized = $this->normalizeCustomDefinition($id, $definition);
        $this->settings->saveCustomProvider($id, $normalized, $scope);

        if ($apiKey !== null && $apiKey !== '') {
            $this->secrets->set("provider.{$id}.api_key", $apiKey);
        }

        return [
            'id' => $id,
            'definition' => $normalized,
            'scope' => $scope,
            'api_key_configured' => $apiKey !== null && $apiKey !== '',
        ];
    }

    public function deleteCustomProvider(string $id, string $scope = 'global'): void
    {
        $this->settings->deleteCustomProvider($id, $scope);
        $this->settingsRepository->delete('global', "provider.{$id}.api_key");
    }

    /**
     * @return array<string, mixed>
     */
    public function customProviders(): array
    {
        return $this->settings->customProviders();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalizeCustomDefinition(string $id, array $definition): array
    {
        $models = is_array($definition['models'] ?? null) ? $definition['models'] : [];
        $modelId = (string) ($definition['model'] ?? $definition['default_model'] ?? array_key_first($models) ?? '');
        if ($modelId === '') {
            throw new \InvalidArgumentException('Custom provider model is required.');
        }

        $label = (string) ($definition['label'] ?? $id);
        $input = $this->modalities($definition['input_modalities'] ?? $definition['modalities']['input'] ?? ['text']);
        $output = $this->modalities($definition['output_modalities'] ?? $definition['modalities']['output'] ?? ['text']);
        $model = is_array($models[$modelId] ?? null) ? $models[$modelId] : [];

        return [
            'label' => $label,
            'driver' => (string) ($definition['driver'] ?? 'openai-compatible'),
            'auth' => (string) ($definition['auth'] ?? 'api_key'),
            'url' => (string) ($definition['url'] ?? ''),
            'default_model' => (string) ($definition['default_model'] ?? $modelId),
            'modalities' => [
                'input' => $input,
                'output' => $output,
            ],
            'models' => [
                $modelId => [
                    'display_name' => (string) ($model['display_name'] ?? $definition['display_name'] ?? $label),
                    'context' => (int) ($model['context'] ?? $definition['context'] ?? 0),
                    'max_output' => (int) ($model['max_output'] ?? $definition['max_output'] ?? 0),
                    'modalities' => [
                        'input' => $this->modalities($model['modalities']['input'] ?? $input),
                        'output' => $this->modalities($model['modalities']['output'] ?? $output),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function modalities(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ), static fn (string $item): bool => $item !== ''));
        }

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) $value),
        ), static fn (string $item): bool => $item !== ''));
    }
}
