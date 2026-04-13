<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;

/**
 * Tracks recent provider/model selections so lightweight model pickers can show
 * likely choices without exposing the full catalog.
 */
final class ModelSwitcherHistory
{
    private const RECENT_MODELS_KEY = 'kosmokrator.model_switcher.recent_models';

    private const RECENT_PROVIDERS_KEY = 'kosmokrator.model_switcher.recent_providers';

    private const RECENT_MODELS_LIMIT = 6;

    private const RECENT_PROVIDERS_LIMIT = 4;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ?SettingsManager $configSettings = null,
    ) {}

    /**
     * @return list<array{provider: string, model: string}>
     */
    public function recentModels(ProviderCatalog $catalog): array
    {
        $decoded = json_decode($this->settings->get('global', self::RECENT_MODELS_KEY) ?? '[]', true);
        if (! is_array($decoded)) {
            return [];
        }

        $recent = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $provider = trim((string) ($entry['provider'] ?? ''));
            $model = trim((string) ($entry['model'] ?? ''));

            if ($provider === '' || $model === '' || ! $catalog->supportsModel($provider, $model)) {
                continue;
            }

            $key = strtolower($provider."\0".$model);
            if (isset($recent[$key])) {
                continue;
            }

            $recent[$key] = ['provider' => $provider, 'model' => $model];
        }

        return array_values($recent);
    }

    /**
     * @return list<string>
     */
    public function recentProviders(ProviderCatalog $catalog): array
    {
        $decoded = json_decode($this->settings->get('global', self::RECENT_PROVIDERS_KEY) ?? '[]', true);
        if (! is_array($decoded)) {
            return [];
        }

        $recent = [];
        foreach ($decoded as $provider) {
            $provider = trim((string) $provider);
            if ($provider === '' || $catalog->provider($provider) === null || in_array($provider, $recent, true)) {
                continue;
            }

            $recent[] = $provider;
        }

        return $recent;
    }

    public function lastModelForProvider(string $provider, ProviderCatalog $catalog): ?string
    {
        foreach ($this->recentModels($catalog) as $entry) {
            if ($entry['provider'] === $provider) {
                return $entry['model'];
            }
        }

        $configured = $this->configSettings?->getProviderLastModel($provider);
        if ($configured !== null && $catalog->supportsModel($provider, $configured)) {
            return $configured;
        }

        $defaultModel = $catalog->defaultModel($provider);
        if ($defaultModel !== null && $defaultModel !== '') {
            return $defaultModel;
        }

        return $catalog->modelIds($provider)[0] ?? null;
    }

    public function record(string $provider, string $model): void
    {
        $recentModels = $this->recentModelsFromStore();
        array_unshift($recentModels, ['provider' => $provider, 'model' => $model]);
        $recentModels = $this->dedupeRecentModels($recentModels);
        $recentModels = array_slice($recentModels, 0, self::RECENT_MODELS_LIMIT);

        $recentProviders = $this->recentProvidersFromStore();
        array_unshift($recentProviders, $provider);
        $recentProviders = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $recentProviders,
        ))));
        $recentProviders = array_slice($recentProviders, 0, self::RECENT_PROVIDERS_LIMIT);

        $this->settings->set('global', self::RECENT_MODELS_KEY, (string) json_encode($recentModels, JSON_THROW_ON_ERROR));
        $this->settings->set('global', self::RECENT_PROVIDERS_KEY, (string) json_encode($recentProviders, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{provider: string, model: string}>
     */
    private function recentModelsFromStore(): array
    {
        $decoded = json_decode($this->settings->get('global', self::RECENT_MODELS_KEY) ?? '[]', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function recentProvidersFromStore(): array
    {
        $decoded = json_decode($this->settings->get('global', self::RECENT_PROVIDERS_KEY) ?? '[]', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<array{provider?: mixed, model?: mixed}>  $entries
     * @return list<array{provider: string, model: string}>
     */
    private function dedupeRecentModels(array $entries): array
    {
        $deduped = [];

        foreach ($entries as $entry) {
            $provider = trim((string) ($entry['provider'] ?? ''));
            $model = trim((string) ($entry['model'] ?? ''));

            if ($provider === '' || $model === '') {
                continue;
            }

            $key = strtolower($provider."\0".$model);
            if (isset($deduped[$key])) {
                continue;
            }

            $deduped[$key] = ['provider' => $provider, 'model' => $model];
        }

        return array_values($deduped);
    }
}
