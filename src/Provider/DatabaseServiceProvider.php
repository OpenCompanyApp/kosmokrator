<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\Codex\SettingsCodexTokenStore;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsPaths;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore as CodexTokenStoreContract;
use Symfony\Component\Yaml\Yaml;

/**
 * Registers SQLite-backed session/settings databases and Codex OAuth services.
 * Also handles injection of legacy SQLite-stored preferences and one-time
 * migration of API keys from YAML into SQLite.
 */
class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SessionDatabase::class, fn () => new SessionDatabase);
        $this->container->singleton(SettingsRepository::class, fn () => new SettingsRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->alias(SettingsRepository::class, SettingsRepositoryInterface::class);
        $this->container->singleton(CodexTokenStoreContract::class, fn () => new SettingsCodexTokenStore(
            $this->container->make(SettingsRepositoryInterface::class),
        ));
        $this->container->singleton(CodexOAuthService::class, fn () => new CodexOAuthService(
            $this->container->make(CodexTokenStoreContract::class),
            $this->container->make(HttpFactory::class),
        ));
        $this->container->singleton(CodexAuthFlow::class, fn () => new CodexAuthFlow(
            $this->container->make(CodexOAuthService::class),
            $this->container->make(CodexTokenStoreContract::class),
            $this->container->make('config'),
        ));

        // Inject SQLite-stored settings during registration so they're available
        // before RelayRegistry and LLM clients are constructed
        $this->injectSqliteSettings();
    }

    /** Inject legacy SQLite-stored preferences and API keys into the config repository. */
    private function injectSqliteSettings(): void
    {
        $config = $this->container->make('config');
        $settings = $this->container->make(SettingsRepositoryInterface::class);
        $hasExternalConfig = (new SettingsPaths(InstructionLoader::gitRoot() ?? getcwd()))->globalReadPath() !== null
            || (new SettingsPaths(InstructionLoader::gitRoot() ?? getcwd()))->projectReadPath() !== null;

        // Legacy SQLite provider/model preferences only apply when no external config exists yet.
        if (! $hasExternalConfig) {
            $sqliteProvider = $settings->get('global', 'agent.default_provider');
            if ($sqliteProvider !== null) {
                $config->set('kosmokrator.agent.default_provider', $sqliteProvider);
            }

            $sqliteModel = $settings->get('global', 'agent.default_model');
            if ($sqliteModel !== null) {
                $config->set('kosmokrator.agent.default_model', $sqliteModel);
            }
        }

        // API key: env var takes priority, then SQLite
        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $configKey = "prism.providers.{$provider}.api_key";
        if (empty($config->get($configKey))) {
            $sqliteKey = $settings->get('global', "provider.{$provider}.api_key");
            if ($sqliteKey !== null) {
                $config->set($configKey, $sqliteKey);
            }
        }

        // Auto-migrate: if YAML has a key but SQLite doesn't, move it
        $this->migrateYamlKeys($config, $settings);
    }

    /**
     * One-time migration: move API keys from YAML config into SQLite so secrets
     * no longer live on disk in plaintext.
     */
    private function migrateYamlKeys(Repository $config, SettingsRepositoryInterface $settings): void
    {
        // Check one-time flag to avoid running migration every boot
        if ($settings->get('global', 'migration.yaml_keys_migrated') === '1') {
            return;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        $yamlPath = $home.'/.kosmokrator/config.yaml';

        if (! file_exists($yamlPath)) {
            // Mark as migrated even if no YAML file exists
            $settings->set('global', 'migration.yaml_keys_migrated', '1');

            return;
        }

        $yaml = Yaml::parseFile($yamlPath) ?? [];
        $providers = $yaml['providers'] ?? [];
        $migrated = false;

        foreach ($providers as $name => $providerConfig) {
            $key = $providerConfig['api_key'] ?? '';
            if ($key === '' || str_starts_with($key, '${')) {
                continue; // Skip empty or env var placeholders
            }

            // Only migrate if SQLite doesn't already have a key for this provider
            if ($settings->get('global', "provider.{$name}.api_key") === null) {
                $settings->set('global', "provider.{$name}.api_key", $key);
                $config->set("prism.providers.{$name}.api_key", $key);
                $migrated = true;
            }

            // Remove from YAML regardless
            unset($yaml['providers'][$name]['api_key']);
            if (empty($yaml['providers'][$name])) {
                unset($yaml['providers'][$name]);
            }
        }

        if (empty($yaml['providers'])) {
            unset($yaml['providers']);
        }

        // Also migrate provider/model preferences
        if (isset($yaml['agent']['default_provider']) && $settings->get('global', 'agent.default_provider') === null) {
            $settings->set('global', 'agent.default_provider', $yaml['agent']['default_provider']);
        }
        if (isset($yaml['agent']['default_model']) && $settings->get('global', 'agent.default_model') === null) {
            $settings->set('global', 'agent.default_model', $yaml['agent']['default_model']);
        }

        // Rewrite YAML without sensitive data (atomic write)
        if ($migrated || ! isset($yaml['providers'])) {
            if (empty($yaml)) {
                @unlink($yamlPath);
            } else {
                $dir = dirname($yamlPath);
                $tmpPath = $dir.'/'.basename($yamlPath).'.tmp.'.uniqid('', true);
                file_put_contents($tmpPath, Yaml::dump($yaml, 4, 2));
                rename($tmpPath, $yamlPath);
            }
        }

        // Mark migration as complete
        $settings->set('global', 'migration.yaml_keys_migrated', '1');
    }
}
