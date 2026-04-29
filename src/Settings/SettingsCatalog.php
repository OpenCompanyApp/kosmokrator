<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

use Illuminate\Container\Container;
use Kosmokrator\LLM\ProviderCatalog;

final class SettingsCatalog
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SettingsSchema $schema,
        private readonly Container $container,
    ) {}

    public function setProjectRoot(?string $projectRoot): void
    {
        $this->settings->setProjectRoot($projectRoot);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'categories' => $this->categories(),
            'settings' => $this->settings(),
            'paths' => $this->paths(),
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function categories(): array
    {
        $categories = [];
        foreach ($this->schema->categoryLabels() as $id => $label) {
            $categories[] = ['id' => $id, 'label' => $label];
        }

        return $categories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function settings(?string $category = null): array
    {
        $rows = [];
        foreach ($this->schema->definitions() as $definition) {
            if ($category !== null && $definition->category !== $category) {
                continue;
            }

            $rows[] = $this->settingRow($definition);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setting(string $id): ?array
    {
        $definition = $this->schema->definition($id);

        return $definition === null ? null : $this->settingRow($definition);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public function options(string $id, array $context = []): array
    {
        $definition = $this->schema->definition($id);
        if ($definition === null) {
            return [];
        }

        if (str_starts_with($definition->id, 'web.')) {
            return $this->staticOptions($definition);
        }

        if ($definition->id === 'agent.default_provider'
            || str_ends_with($definition->id, '_provider')) {
            return $this->providerOptions();
        }

        if ($definition->id === 'agent.default_model'
            || str_ends_with($definition->id, '_model')) {
            $provider = (string) ($context['provider'] ?? $this->settings->get('agent.default_provider') ?? '');

            return $this->modelOptions($provider);
        }

        return $this->staticOptions($definition);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function staticOptions(SettingDefinition $definition): array
    {
        return array_map(
            static fn (string $option): array => [
                'value' => $option,
                'label' => $option,
                'description' => '',
            ],
            $definition->options,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paths(): array
    {
        return [
            'global' => $this->settings->globalConfigPath(),
            'project' => $this->settings->projectConfigPath(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingRow(SettingDefinition $definition): array
    {
        $effective = $this->settings->resolve($definition->id);
        $options = $this->options($definition->id);

        return [
            'id' => $definition->id,
            'path' => $definition->path,
            'label' => $definition->label,
            'description' => $definition->description,
            'category' => $definition->category,
            'type' => $definition->type,
            'options' => $options,
            'option_values' => array_map(static fn (array $option): string => (string) $option['value'], $options),
            'default' => $definition->default,
            'value' => $effective?->value,
            'display_value' => SettingValueFormatter::display($effective?->value),
            'source' => $effective?->source ?? 'default',
            'effect' => $definition->effect,
            'scopes' => $definition->scopes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function providerOptions(): array
    {
        if (! $this->container->bound(ProviderCatalog::class)) {
            return [];
        }

        return $this->container->make(ProviderCatalog::class)->providerOptions();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modelOptions(string $provider): array
    {
        if ($provider === '' || ! $this->container->bound(ProviderCatalog::class)) {
            return [];
        }

        $catalog = $this->container->make(ProviderCatalog::class);
        $providerDefinition = $catalog->provider($provider);
        if ($providerDefinition?->freeTextModel) {
            return [[
                'value' => '*',
                'label' => 'Any model',
                'description' => 'This provider accepts free-text model identifiers.',
            ]];
        }

        return $catalog->modelOptionsByProvider()[$provider] ?? [];
    }
}
