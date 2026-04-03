<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kosmokrator\LLM\ProviderAuthService;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\UI\AgentDisplayFormatter;
use Kosmokrator\UI\AgentTreeBuilder;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore as CodexTokenStoreContract;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Registry\RelayRegistryBuilder;

/**
 * Binds core infrastructure: paths, events, filesystem, HTTP, settings,
 * relay registry, and provider catalog.
 */
class CoreServiceProvider extends ServiceProvider
{
    public function __construct(
        Container $container,
        private readonly string $basePath,
    ) {
        parent::__construct($container);
    }

    public function register(): void
    {
        // App instance bindings that Prism/Laravel expects
        $this->container->instance('path.base', $this->basePath);
        $this->container->instance('path.config', $this->basePath.'/config');

        // Events dispatcher (needed by Laravel internals)
        $this->container->singleton('events', fn () => new Dispatcher($this->container));
        $this->container->alias('events', \Illuminate\Contracts\Events\Dispatcher::class);

        // Filesystem
        $this->container->singleton('files', fn () => new Filesystem);

        // HTTP client factory (used by Prism via Http facade)
        $this->container->singleton('http', fn () => new HttpFactory);
        $this->container->singleton(SettingsSchema::class, fn () => new SettingsSchema);
        $this->container->singleton(YamlConfigStore::class, fn () => new YamlConfigStore);
        $this->container->singleton(SettingsManager::class, fn () => new SettingsManager(
            config: $this->container->make('config'),
            schema: $this->container->make(SettingsSchema::class),
            store: $this->container->make(YamlConfigStore::class),
            baseConfigPath: $this->basePath.'/config',
        ));
        $this->container->singleton(RelayRegistryBuilder::class, fn () => new RelayRegistryBuilder(
            configDir: $this->basePath.'/vendor/opencompanyapp/prism-relay/config',
        ));
        $this->container->singleton(RelayRegistry::class, function () {
            $config = $this->container->make('config');
            $relayOverrides = is_array($config->get('relay.providers', []))
                ? $config->get('relay.providers', [])
                : [];
            $prismProviders = is_array($config->get('prism.providers', []))
                ? $config->get('prism.providers', [])
                : [];

            foreach ($prismProviders as $provider => $providerConfig) {
                if (! is_array($providerConfig) || ! isset($providerConfig['url']) || ! is_string($providerConfig['url'])) {
                    continue;
                }

                $relayOverrides[$provider] ??= [];
                $relayOverrides[$provider]['url'] = $providerConfig['url'];
            }

            return $this->container->make(RelayRegistryBuilder::class)->build($relayOverrides);
        });
        $this->container->singleton(ProviderMeta::class, fn () => new ProviderMeta(
            $this->container->make(RelayRegistry::class),
        ));
        $this->container->singleton(ProviderCatalog::class, fn () => new ProviderCatalog(
            $this->container->make(ProviderMeta::class),
            $this->container->make(RelayRegistry::class),
            $this->container->make('config'),
            $this->container->make(SettingsRepositoryInterface::class),
            $this->container->make(CodexTokenStoreContract::class),
        ));
        $this->container->singleton(ProviderAuthService::class, fn () => new ProviderAuthService(
            $this->container->make(ProviderCatalog::class),
            $this->container->make(SettingsRepositoryInterface::class),
            $this->container->make('config'),
            $this->container->make(CodexTokenStoreContract::class),
        ));

        // UI display utilities (stateless singletons)
        $this->container->singleton(AgentDisplayFormatter::class, fn () => new AgentDisplayFormatter);
        $this->container->singleton(AgentTreeBuilder::class, fn () => new AgentTreeBuilder);
    }
}
