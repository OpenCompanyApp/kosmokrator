<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Illuminate\Container\Container;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ModelSwitcherHistory;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Settings\SettingsManager;
use OpenCompany\PrismRelay\Registry\RelayRegistry;

/**
 * Curated provider/model switcher for day-to-day use.
 *
 * Shows only likely choices: recent selections, models from the current
 * provider, and the most recent non-current provider. Full catalog browsing
 * remains in /settings.
 */
final class ModelsCommand implements SlashCommand
{
    private const CURRENT_PROVIDER_LIMIT = 6;

    private const RECENT_PROVIDER_LIMIT = 4;

    public function __construct(
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return '/models';
    }

    /**
     * @return string[]
     */
    public function aliases(): array
    {
        return ['/model'];
    }

    public function description(): string
    {
        return 'Switch to a likely provider/model';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $catalog = $ctx->providers ?? $this->container->make(ProviderCatalog::class);
        $models = $ctx->models ?? $this->container->make(ModelCatalog::class);
        $registry = $this->container->make(RelayRegistry::class);
        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot($ctx->sessionManager->getProject() ?? getcwd());
        $history = new ModelSwitcherHistory($ctx->settings, $settings);

        $currentProvider = $ctx->llm->getProvider();
        $currentModel = $ctx->llm->getModel();

        $args = trim($args);
        if ($args === '') {
            $suggestions = $this->buildSuggestions($catalog, $history, $currentProvider, $currentModel);
            $choice = $ctx->ui->askChoice('Switch model', array_map(
                static fn (array $item): array => [
                    'label' => $item['label'],
                    'detail' => $item['detail'],
                    'recommended' => (bool) ($item['recommended'] ?? false),
                ],
                $suggestions['choices'],
            ));

            if ($choice !== 'dismissed') {
                foreach ($suggestions['choices'] as $item) {
                    if ($item['label'] === $choice) {
                        $this->switchSelection(
                            $ctx,
                            $settings,
                            $history,
                            $catalog,
                            $models,
                            $registry,
                            $item['provider'],
                            $item['model'],
                        );

                        return SlashCommandResult::continue();
                    }
                }
            }

            $ctx->ui->showNotice($suggestions['notice']);

            return SlashCommandResult::continue();
        }

        $selection = $this->resolveSelection($args, $catalog, $history, $currentProvider);
        if ($selection === null) {
            $ctx->ui->showNotice("Unknown model or provider: {$args}\nUse /models to see likely choices. Full inventory lives in /settings.");

            return SlashCommandResult::continue();
        }

        $this->switchSelection(
            $ctx,
            $settings,
            $history,
            $catalog,
            $models,
            $registry,
            $selection['provider'],
            $selection['model'],
        );

        return SlashCommandResult::continue();
    }

    /**
     * @return array{notice: string, choices: list<array{label: string, detail: string, provider: string, model: string, recommended?: bool}>}
     */
    private function buildSuggestions(
        ProviderCatalog $catalog,
        ModelSwitcherHistory $history,
        string $currentProvider,
        string $currentModel,
    ): array {
        $choices = [];
        $choiceKeys = [];
        $lines = [
            "Current: {$this->selectionLabel($catalog, $currentProvider, $currentModel)}",
            '',
        ];

        $recentModels = array_values(array_filter(
            $history->recentModels($catalog),
            static fn (array $entry): bool => ! ($entry['provider'] === $currentProvider && $entry['model'] === $currentModel),
        ));

        $lines[] = 'Recent used models:';
        if ($recentModels === []) {
            $lines[] = '  none yet';
        } else {
            foreach ($recentModels as $entry) {
                $lines[] = '  '.$this->selectionLabel($catalog, $entry['provider'], $entry['model']);
                $this->pushChoice($choices, $choiceKeys, $catalog, $entry['provider'], $entry['model'], 'Recent selection');
            }
        }

        $currentProviderModels = $this->likelyModelsForProvider(
            $catalog,
            $history,
            $currentProvider,
            self::CURRENT_PROVIDER_LIMIT,
        );

        $lines[] = '';
        $lines[] = 'Current provider:';
        foreach ($currentProviderModels as $model) {
            $suffix = $model === $currentModel ? '  ← current' : '';
            $lines[] = '  '.$this->selectionLabel($catalog, $currentProvider, $model).$suffix;
            $this->pushChoice(
                $choices,
                $choiceKeys,
                $catalog,
                $currentProvider,
                $model,
                'Current provider',
                $model === $currentModel,
            );
        }

        $recentProvider = null;
        foreach ($history->recentProviders($catalog) as $provider) {
            if ($provider !== $currentProvider) {
                $recentProvider = $provider;

                break;
            }
        }

        if ($recentProvider !== null) {
            $lines[] = '';
            $lines[] = 'Recent provider:';
            foreach ($this->likelyModelsForProvider($catalog, $history, $recentProvider, self::RECENT_PROVIDER_LIMIT) as $model) {
                $lines[] = '  '.$this->selectionLabel($catalog, $recentProvider, $model);
                $this->pushChoice($choices, $choiceKeys, $catalog, $recentProvider, $model, 'Recent provider');
            }
        }

        $lines[] = '';
        $lines[] = 'Use /models <provider:model>, /models <provider>, or /models <model>.';
        $lines[] = 'Full provider and model inventory stays in /settings.';

        return [
            'notice' => implode("\n", $lines),
            'choices' => $choices,
        ];
    }

    /**
     * @return list<string>
     */
    private function likelyModelsForProvider(
        ProviderCatalog $catalog,
        ModelSwitcherHistory $history,
        string $provider,
        int $limit,
    ): array {
        $definition = $catalog->provider($provider);
        if ($definition === null) {
            return [];
        }

        $ordered = [];
        $push = static function (array &$items, string $model) use ($catalog, $provider): void {
            if ($model === '' || ! $catalog->supportsModel($provider, $model) || in_array($model, $items, true)) {
                return;
            }

            $items[] = $model;
        };

        $push($ordered, $history->lastModelForProvider($provider, $catalog) ?? '');
        $push($ordered, $definition->defaultModel);

        foreach ($definition->models as $model) {
            $push($ordered, $model->id);
            if (count($ordered) >= $limit) {
                break;
            }
        }

        return array_slice($ordered, 0, $limit);
    }

    /**
     * @return array{provider: string, model: string}|null
     */
    private function resolveSelection(
        string $raw,
        ProviderCatalog $catalog,
        ModelSwitcherHistory $history,
        string $currentProvider,
    ): ?array {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, ':')) {
            [$provider, $model] = array_map('trim', explode(':', $raw, 2));
            if ($provider !== '' && $model !== '' && $catalog->supportsModel($provider, $model)) {
                return ['provider' => $provider, 'model' => $model];
            }

            return null;
        }

        if ($catalog->provider($raw) !== null) {
            $model = $history->lastModelForProvider($raw, $catalog);

            return $model !== null ? ['provider' => $raw, 'model' => $model] : null;
        }

        if ($catalog->supportsModel($currentProvider, $raw)) {
            return ['provider' => $currentProvider, 'model' => $raw];
        }

        $matches = [];
        foreach ($catalog->providers() as $provider) {
            if ($catalog->supportsModel($provider->id, $raw)) {
                $matches[] = ['provider' => $provider->id, 'model' => $raw];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function switchSelection(
        SlashCommandContext $ctx,
        SettingsManager $settings,
        ModelSwitcherHistory $history,
        ProviderCatalog $catalog,
        ModelCatalog $models,
        RelayRegistry $registry,
        string $provider,
        string $model,
    ): void {
        if ($ctx->llm->getProvider() === $provider && $ctx->llm->getModel() === $model) {
            $ctx->ui->showNotice("Already using {$this->selectionLabel($catalog, $provider, $model)}.");

            return;
        }

        $settings->set('agent.default_provider', $provider, 'global');
        $settings->set('agent.default_model', $model, 'global');
        $settings->setProviderLastModel($provider, $model, 'global');
        $history->record($provider, $model);

        if ($this->requiresRestart($ctx->llm, $registry, $provider)) {
            $ctx->ui->showNotice("Saved {$this->selectionLabel($catalog, $provider, $model)}. Restart the session to switch runtime.");

            return;
        }

        $ctx->llm->setProvider($provider);
        $inner = self::innerClient($ctx->llm);

        if ($provider !== 'codex' && method_exists($inner, 'setBaseUrl')) {
            $inner->setBaseUrl(rtrim($catalog->provider($provider)?->url ?? $registry->url($provider), '/'));
        }

        if (method_exists($inner, 'setApiKey')) {
            $inner->setApiKey($provider === 'codex' ? '' : $catalog->apiKey($provider));
        }

        $ctx->llm->setModel($model);
        $ctx->ui->refreshRuntimeSelection($provider, $model, $models->contextWindow($model));
        $ctx->ui->showNotice("Switched to {$this->selectionLabel($catalog, $provider, $model)}.");
    }

    private function selectionLabel(ProviderCatalog $catalog, string $provider, string $model): string
    {
        $providerLabel = $catalog->provider($provider)?->label ?? $provider;

        return "{$providerLabel} · {$model}";
    }

    /**
     * @param  list<array{label: string, detail: string, provider: string, model: string, recommended?: bool}>  $choices
     * @param  array<string, true>  $seen
     */
    private function pushChoice(
        array &$choices,
        array &$seen,
        ProviderCatalog $catalog,
        string $provider,
        string $model,
        string $reason,
        bool $recommended = false,
    ): void {
        $key = strtolower($provider."\0".$model);
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $choices[] = [
            'label' => $this->selectionLabel($catalog, $provider, $model),
            'detail' => "{$reason}\n\nProvider: {$provider}\nModel: {$model}",
            'provider' => $provider,
            'model' => $model,
            'recommended' => $recommended,
        ];
    }

    private function requiresRestart(LlmClientInterface $llm, RelayRegistry $registry, string $provider): bool
    {
        $inner = self::innerClient($llm);

        return $inner instanceof AsyncLlmClient && ! $registry->supportsAsync($provider);
    }

    private static function innerClient(LlmClientInterface $llm): LlmClientInterface
    {
        return $llm instanceof RetryableLlmClient ? $llm->inner() : $llm;
    }
}
