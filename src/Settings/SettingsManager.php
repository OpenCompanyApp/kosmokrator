<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

use Illuminate\Config\Repository;
use Kosmokrator\ConfigLoader;

/**
 * Central manager for reading and writing user-configurable settings.
 *
 * Resolves setting values through a layered priority chain
 * (project → global → built-in default) and persists changes via YAML config files.
 */
final class SettingsManager
{
    private ?string $projectRoot = null;

    public function __construct(
        private readonly Repository $config,
        private readonly SettingsSchema $schema,
        private readonly YamlConfigStore $store,
        private readonly string $baseConfigPath,
    ) {}

    /**
     * @param  string|null  $projectRoot  Absolute path to the project root, or null for global-only mode.
     */
    public function setProjectRoot(?string $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * @param  string  $id  Setting identifier defined in the schema.
     * @return string|null The resolved setting value as a string, or null when no value exists.
     */
    public function get(string $id): ?string
    {
        $effective = $this->resolve($id);
        if ($effective === null || $effective->value === null) {
            return null;
        }

        return $this->stringify($effective->value);
    }

    /**
     * Resolve the effective value for a setting, including its source layer.
     *
     * @param  string  $id  Setting identifier defined in the schema.
     * @return EffectiveSetting|null The resolved setting with source metadata, or null if the setting is unknown.
     */
    public function resolve(string $id): ?EffectiveSetting
    {
        $definition = $this->schema->definition($id);
        if ($definition === null) {
            return null;
        }

        $paths = new SettingsPaths($this->projectRoot);

        // Check project-level config first (highest priority).
        $projectValue = $this->store->get($this->store->load($paths->projectReadPath()), $definition->path);
        if ($projectValue !== null) {
            return new EffectiveSetting($definition->id, $projectValue, 'project', 'project', $definition);
        }

        // Fall back to global user config.
        $globalValue = $this->store->get($this->store->load($paths->globalReadPath()), $definition->path);
        if ($globalValue !== null) {
            return new EffectiveSetting($definition->id, $globalValue, 'global', 'global', $definition);
        }

        // Fall back to built-in config, then the schema default.
        $configValue = $this->config->get($definition->path);
        if ($configValue !== null) {
            return new EffectiveSetting($definition->id, $configValue, 'default', 'default', $definition);
        }

        return new EffectiveSetting($definition->id, $definition->default, 'default', 'default', $definition);
    }

    /**
     * @param  string  $id     Setting identifier defined in the schema.
     * @param  mixed   $value  The raw value to persist.
     * @param  string  $scope  'project' or 'global' — which config layer to write to.
     *
     * @throws \InvalidArgumentException If the setting identifier is not defined in the schema.
     */
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

    /**
     * @param  string  $id     Setting identifier defined in the schema.
     * @param  string  $scope  'project' or 'global' — which config layer to remove the value from.
     */
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

    /**
     * @param  string  $provider  Provider identifier (e.g. 'openai', 'anthropic').
     * @return string|null The last-used model identifier for the given provider, or null.
     */
    public function getProviderLastModel(string $provider): ?string
    {
        return $this->getRaw("kosmokrator.provider_state.{$provider}.last_model");
    }

    /**
     * @param  string  $provider  Provider identifier.
     * @param  string  $model     Model identifier to remember.
     * @param  string  $scope     'project' or 'global' — which config layer to persist in.
     */
    public function setProviderLastModel(string $provider, string $model, string $scope = 'global'): void
    {
        $this->setRaw("kosmokrator.provider_state.{$provider}.last_model", $model, $scope);
    }

    /**
     * @return array<string, mixed> Custom provider definitions keyed by provider ID.
     */
    public function customProviders(): array
    {
        return is_array($this->config->get('relay.providers', []))
            ? $this->config->get('relay.providers', [])
            : [];
    }

    /**
     * @param  string               $providerId  Unique identifier for the custom provider.
     * @param  array<string, mixed> $definition  Provider configuration (url, headers, etc.).
     * @param  string               $scope       'project' or 'global' — which config layer to persist in.
     */
    public function saveCustomProvider(string $providerId, array $definition, string $scope = 'project'): void
    {
        $this->setRaw("relay.providers.{$providerId}", $definition, $scope);

        // Mirror the URL into the Prism provider config so the HTTP client picks it up.
        if (isset($definition['url'])) {
            $this->setRaw("prism.providers.{$providerId}.url", (string) $definition['url'], $scope);
        }
    }

    /**
     * @param  string  $providerId  Custom provider identifier to remove.
     * @param  string  $scope       'project' or 'global' — which config layer to remove from.
     */
    public function deleteCustomProvider(string $providerId, string $scope = 'project'): void
    {
        $this->unsetRaw("relay.providers.{$providerId}", $scope);
        $this->unsetRaw("prism.providers.{$providerId}.url", $scope);
    }

    /**
     * Read a raw config value by dot-path, respecting the project → global → default priority chain.
     *
     * @param  string  $path  Dot-notation config path (e.g. 'kosmokrator.provider_state.openai.last_model').
     * @return mixed The raw value found, or null.
     */
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

    /**
     * Write a raw value to a config layer by dot-path.
     *
     * @param  string  $path   Dot-notation config path.
     * @param  mixed   $value  Value to persist.
     * @param  string  $scope  'project' or 'global'.
     */
    public function setRaw(string $path, mixed $value, string $scope = 'project'): void
    {
        $paths = new SettingsPaths($this->projectRoot);
        [$targetPath, $data] = $this->configTarget($paths, $scope);
        $this->store->set($data, $path, $value);
        $this->store->save($targetPath, $data);
        $this->reloadRepository();
    }

    /**
     * Remove a raw value from a config layer by dot-path.
     *
     * @param  string  $path   Dot-notation config path.
     * @param  string  $scope  'project' or 'global'.
     */
    public function unsetRaw(string $path, string $scope = 'project'): void
    {
        $paths = new SettingsPaths($this->projectRoot);
        [$targetPath, $data] = $this->configTarget($paths, $scope);
        $this->store->unset($data, $path);
        $this->store->save($targetPath, $data);
        $this->reloadRepository();
    }

    /**
     * @return string Absolute path to the global YAML config file.
     */
    public function globalConfigPath(): string
    {
        return (new SettingsPaths($this->projectRoot))->globalWritePath();
    }

    /**
     * @return string|null Absolute path to the project-level YAML config file, or null if no project root is set.
     */
    public function projectConfigPath(): ?string
    {
        return (new SettingsPaths($this->projectRoot))->projectWritePath();
    }

    /**
     * Resolve the file path and current data for the given scope target.
     *
     * @return array{0: string, 1: array<string, mixed>} [file path, parsed YAML data]
     */
    private function configTarget(SettingsPaths $paths, string $scope): array
    {
        if ($scope === 'project' && $paths->projectWritePath() !== null) {
            $path = $paths->projectWritePath();

            return [$path, $this->store->load($path)];
        }

        // Fall back to global when project scope is unavailable.
        $path = $paths->globalWritePath();

        return [$path, $this->store->load($path)];
    }

    /** Re-read all config files and refresh the in-memory repository after a write. */
    private function reloadRepository(): void
    {
        $reloaded = (new ConfigLoader($this->baseConfigPath))->load();

        foreach (['app', 'kosmokrator', 'prism', 'models', 'relay'] as $key) {
            $this->config->set($key, $reloaded->get($key, []));
        }
    }

    /** Coerce a raw input value to the type expected by the setting definition. */
    private function normalizeValue(SettingDefinition $definition, mixed $value): mixed
    {
        // Treat an empty string as a null-clear for numeric fields.
        if ($value === '' && $definition->type === 'number') {
            return null;
        }

        return match ($definition->type) {
            'number' => is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : $value,
            'toggle' => in_array((string) $value, ['1', 'true', 'on', 'yes'], true) ? 'on' : ((string) $value === 'off' ? 'off' : (string) $value),
            default => $value,
        };
    }

    /** Convert a mixed setting value to its string representation. */
    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        if (is_scalar($value) || $value === null) {
            return $value === null ? '' : (string) $value;
        }

        // Complex values (arrays, objects) are JSON-encoded.
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
