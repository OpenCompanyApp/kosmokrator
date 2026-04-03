<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

use Illuminate\Config\Repository;
use Kosmokrator\ConfigLoader;

final class SettingsManager
{
    private ?string $projectRoot = null;

    public function __construct(
        private readonly Repository $config,
        private readonly SettingsSchema $schema,
        private readonly YamlConfigStore $store,
        private readonly string $baseConfigPath,
    ) {}

    public function setProjectRoot(?string $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    public function get(string $id): ?string
    {
        $effective = $this->resolve($id);
        if ($effective === null || $effective->value === null) {
            return null;
        }

        return $this->stringify($effective->value);
    }

    public function resolve(string $id): ?EffectiveSetting
    {
        $definition = $this->schema->definition($id);
        if ($definition === null) {
            return null;
        }

        $paths = new SettingsPaths($this->projectRoot);
        $projectValue = $this->store->get($this->store->load($paths->projectReadPath()), $definition->path);
        if ($projectValue !== null) {
            return new EffectiveSetting($definition->id, $projectValue, 'project', 'project', $definition);
        }

        $globalValue = $this->store->get($this->store->load($paths->globalReadPath()), $definition->path);
        if ($globalValue !== null) {
            return new EffectiveSetting($definition->id, $globalValue, 'global', 'global', $definition);
        }

        $configValue = $this->config->get($definition->path);
        if ($configValue !== null) {
            return new EffectiveSetting($definition->id, $configValue, 'default', 'default', $definition);
        }

        return new EffectiveSetting($definition->id, $definition->default, 'default', 'default', $definition);
    }

    public function set(string $id, mixed $value, string $scope = 'project'): void
    {
        $definition = $this->schema->definition($id);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown setting [{$id}].");
        }

        $paths = new SettingsPaths($this->projectRoot);
        [$path, $data] = $this->configTarget($paths, $scope);
        $this->store->set($data, $definition->path, $this->normalizeValue($definition, $value));
        $this->store->save($path, $data);
        $this->reloadRepository();
    }

    public function delete(string $id, string $scope = 'project'): void
    {
        $definition = $this->schema->definition($id);
        if ($definition === null) {
            return;
        }

        $paths = new SettingsPaths($this->projectRoot);
        [$path, $data] = $this->configTarget($paths, $scope);
        $this->store->unset($data, $definition->path);
        $this->store->save($path, $data);
        $this->reloadRepository();
    }

    public function getProviderLastModel(string $provider): ?string
    {
        return $this->getRaw("kosmokrator.provider_state.{$provider}.last_model");
    }

    public function setProviderLastModel(string $provider, string $model, string $scope = 'global'): void
    {
        $this->setRaw("kosmokrator.provider_state.{$provider}.last_model", $model, $scope);
    }

    /**
     * @return array<string, mixed>
     */
    public function customProviders(): array
    {
        return is_array($this->config->get('relay.providers', []))
            ? $this->config->get('relay.providers', [])
            : [];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function saveCustomProvider(string $providerId, array $definition, string $scope = 'project'): void
    {
        $this->setRaw("relay.providers.{$providerId}", $definition, $scope);

        if (isset($definition['url'])) {
            $this->setRaw("prism.providers.{$providerId}.url", (string) $definition['url'], $scope);
        }
    }

    public function deleteCustomProvider(string $providerId, string $scope = 'project'): void
    {
        $this->unsetRaw("relay.providers.{$providerId}", $scope);
        $this->unsetRaw("prism.providers.{$providerId}.url", $scope);
    }

    public function getRaw(string $path): mixed
    {
        $paths = new SettingsPaths($this->projectRoot);
        $projectValue = $this->store->get($this->store->load($paths->projectReadPath()), $path);
        if ($projectValue !== null) {
            return $projectValue;
        }

        $globalValue = $this->store->get($this->store->load($paths->globalReadPath()), $path);
        if ($globalValue !== null) {
            return $globalValue;
        }

        return $this->config->get($path);
    }

    public function setRaw(string $path, mixed $value, string $scope = 'project'): void
    {
        $paths = new SettingsPaths($this->projectRoot);
        [$targetPath, $data] = $this->configTarget($paths, $scope);
        $this->store->set($data, $path, $value);
        $this->store->save($targetPath, $data);
        $this->reloadRepository();
    }

    public function unsetRaw(string $path, string $scope = 'project'): void
    {
        $paths = new SettingsPaths($this->projectRoot);
        [$targetPath, $data] = $this->configTarget($paths, $scope);
        $this->store->unset($data, $path);
        $this->store->save($targetPath, $data);
        $this->reloadRepository();
    }

    public function globalConfigPath(): string
    {
        return (new SettingsPaths($this->projectRoot))->globalWritePath();
    }

    public function projectConfigPath(): ?string
    {
        return (new SettingsPaths($this->projectRoot))->projectWritePath();
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function configTarget(SettingsPaths $paths, string $scope): array
    {
        if ($scope === 'project' && $paths->projectWritePath() !== null) {
            $path = $paths->projectWritePath();

            return [$path, $this->store->load($path)];
        }

        $path = $paths->globalWritePath();

        return [$path, $this->store->load($path)];
    }

    private function reloadRepository(): void
    {
        $reloaded = (new ConfigLoader($this->baseConfigPath))->load();

        foreach (['app', 'kosmokrator', 'prism', 'models', 'relay'] as $key) {
            $this->config->set($key, $reloaded->get($key, []));
        }
    }

    private function normalizeValue(SettingDefinition $definition, mixed $value): mixed
    {
        if ($value === '' && $definition->type === 'number') {
            return null;
        }

        return match ($definition->type) {
            'number' => is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : $value,
            'toggle' => in_array((string) $value, ['1', 'true', 'on', 'yes'], true) ? 'on' : ((string) $value === 'off' ? 'off' : (string) $value),
            default => $value,
        };
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        if (is_scalar($value) || $value === null) {
            return $value === null ? '' : (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
