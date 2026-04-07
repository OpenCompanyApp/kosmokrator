<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\KosmokratorFileStorage;
use Kosmokrator\Integration\KosmokratorLuaToolInvoker;
use Kosmokrator\Integration\YamlCredentialResolver;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Lua\LuaSandboxService;
use Kosmokrator\Lua\NativeToolBridge;
use Kosmokrator\Tool\ToolRegistry;
use Lua\Sandbox;
use OpenCompany\IntegrationCore\Contracts\AgentFileStorage;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\LuaToolInvoker;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;
use OpenCompany\IntegrationCore\Lua\LuaDocRenderer;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

class IntegrationServiceProvider extends ServiceProvider
{
    public function __construct(
        Container $container,
        private readonly string $basePath,
    ) {
        parent::__construct($container);
    }

    public function register(): void
    {
        // Core registry — singleton so all providers share the same instance
        $this->container->singleton(ToolProviderRegistry::class);

        // Credential resolver — reads from SQLite via SettingsRepositoryInterface
        $this->container->singleton(CredentialResolver::class, YamlCredentialResolver::class);

        // File storage for rendering integrations (mermaid, plantuml, etc.)
        $this->container->singleton(AgentFileStorage::class, KosmokratorFileStorage::class);

        // Lua sandbox (only functional if the Lua extension is loaded)
        if (class_exists(Sandbox::class)) {
            $this->container->singleton(LuaSandboxService::class);
        }

        // Lua doc rendering pipeline
        $this->container->singleton(LuaCatalogBuilder::class);
        $this->container->singleton(LuaDocRenderer::class);
        $this->container->singleton(LuaDocService::class, function (Container $c): LuaDocService {
            return new LuaDocService(
                providers: $c->make(ToolProviderRegistry::class),
                integrationManager: $c->make(IntegrationManager::class),
                catalogBuilder: $c->make(LuaCatalogBuilder::class),
                docRenderer: $c->make(LuaDocRenderer::class),
                nativeToolBridge: new NativeToolBridge(fn () => $c->make(ToolRegistry::class)),
            );
        });

        // Integration manager — orchestrates providers, credentials, permissions
        $this->container->singleton(IntegrationManager::class);

        // Lua tool invoker — bridges LuaBridge calls to integration Tool::execute()
        $this->container->singleton(LuaToolInvoker::class, KosmokratorLuaToolInvoker::class);
    }

    public function boot(): void
    {
        // Discover integration packages from composer.lock and register their providers
        $this->discoverIntegrations();
    }

    private function discoverIntegrations(): void
    {
        $lockPath = $this->basePath.'/composer.lock';
        if (! is_file($lockPath)) {
            return;
        }

        $lock = json_decode(file_get_contents($lockPath), true);
        if (! is_array($lock)) {
            return;
        }

        foreach ($lock['packages'] ?? [] as $package) {
            $name = $package['name'] ?? '';
            if (! str_starts_with($name, 'opencompanyapp/integration-')) {
                continue;
            }

            // Find ServiceProvider from package's extra.laravel.providers
            $providerClasses = $package['extra']['laravel']['providers'] ?? [];

            foreach ($providerClasses as $providerClass) {
                if (! class_exists($providerClass)) {
                    continue;
                }

                try {
                    $provider = new $providerClass($this->container);
                    if (method_exists($provider, 'register')) {
                        $provider->register();
                    }
                    if (method_exists($provider, 'boot')) {
                        $provider->boot();
                    }
                } catch (\Throwable) {
                    // Integration failed to register — skip gracefully.
                    // This can happen if dependencies are missing in CLI context.
                    continue;
                }
            }
        }
    }
}
