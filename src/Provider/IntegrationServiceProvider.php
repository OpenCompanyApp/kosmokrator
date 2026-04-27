<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\KosmokratorFileStorage;
use Kosmokrator\Integration\KosmokratorLuaToolInvoker;
use Kosmokrator\Integration\Runtime\IntegrationArgumentMapper;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Kosmokrator\Integration\Runtime\IntegrationDocService;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Integration\YamlCredentialResolver;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Lua\LuaSandboxService;
use Kosmokrator\Lua\NativeToolBridge;
use Kosmokrator\Tool\ToolRegistry;
use OpenCompany\IntegrationCore\Contracts\AgentFileStorage;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\LuaToolInvoker;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;
use OpenCompany\IntegrationCore\Lua\LuaDocRenderer;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

class IntegrationServiceProvider extends ServiceProvider
{
    private const DISCOVERABLE_PACKAGE_PREFIXES = [
        'opencompanyapp/integration-',
        'opencompanyapp/ai-tool-',
    ];

    private const REDUNDANT_PACKAGE_REPLACEMENTS = [
        'opencompanyapp/integration-google-calendar' => 'opencompanyapp/integration-google',
        'opencompanyapp/integration-google-contacts' => 'opencompanyapp/integration-google',
        'opencompanyapp/integration-google-docs' => 'opencompanyapp/integration-google',
        'opencompanyapp/integration-google-drive' => 'opencompanyapp/integration-google',
        'opencompanyapp/integration-google-forms' => 'opencompanyapp/integration-google',
        'opencompanyapp/integration-google-search-console' => 'opencompanyapp/integration-google',
        'opencompanyapp/integration-google-tasks' => 'opencompanyapp/integration-google',
    ];

    /** @var array<string, list<string>> */
    private array $localPackagePsr4Prefixes = [];

    private bool $localPackageAutoloaderRegistered = false;

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

        // Lua sandbox (execute fails later if the Lua extension is unavailable).
        $this->container->singleton(LuaSandboxService::class);

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

        $this->container->singleton(IntegrationCatalog::class);
        $this->container->singleton(IntegrationArgumentMapper::class);
        $this->container->singleton(IntegrationDocService::class);

        // Lua tool invoker — bridges LuaBridge calls to integration Tool::execute()
        $this->container->singleton(LuaToolInvoker::class, KosmokratorLuaToolInvoker::class);
        $this->container->singleton(IntegrationRuntime::class);
    }

    public function boot(): void
    {
        // Discover OpenCompany integration packages from composer.lock and register their providers.
        // Support both the newer integration-* prefix and the current ai-tool-* package names.
        $this->discoverIntegrations();
    }

    private function discoverIntegrations(): void
    {
        $discoveredPackages = [];
        $availablePackageNames = [];

        $lockPath = $this->basePath.'/composer.lock';
        if (! is_file($lockPath)) {
            $this->discoverLocalMonorepoIntegrations($discoveredPackages, $availablePackageNames);

            return;
        }

        $lock = json_decode(file_get_contents($lockPath), true);
        if (! is_array($lock)) {
            $this->discoverLocalMonorepoIntegrations($discoveredPackages, $availablePackageNames);

            return;
        }

        foreach ($lock['packages'] ?? [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $availablePackageNames[(string) $package['name']] = true;
            }
        }

        foreach ($this->localMonorepoComposerFiles() as $composerFile) {
            $package = json_decode((string) file_get_contents($composerFile), true);
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $availablePackageNames[(string) $package['name']] = true;
        }

        foreach ($lock['packages'] ?? [] as $package) {
            $this->registerIntegrationPackage($package, $discoveredPackages, null, $availablePackageNames);
        }

        $this->discoverLocalMonorepoIntegrations($discoveredPackages, $availablePackageNames);
    }

    private function isDiscoverableIntegrationPackage(string $name): bool
    {
        foreach (self::DISCOVERABLE_PACKAGE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $discoveredPackages
     * @param  array<string, bool>  $availablePackageNames
     */
    private function registerIntegrationPackage(array $package, array &$discoveredPackages, ?string $packageDir = null, array $availablePackageNames = []): void
    {
        $name = (string) ($package['name'] ?? '');
        if (! $this->isDiscoverableIntegrationPackage($name)) {
            return;
        }

        $canonicalPackage = self::REDUNDANT_PACKAGE_REPLACEMENTS[$name] ?? null;
        if ($canonicalPackage !== null && isset($availablePackageNames[$canonicalPackage])) {
            return;
        }

        // integration-core is already registered by KosmoKrator itself.
        // Rediscovering its Laravel service provider would rebind the shared
        // ToolProviderRegistry and drop integrations loaded earlier in the pass.
        if ($name === 'opencompanyapp/integration-core' || isset($discoveredPackages[$name])) {
            return;
        }

        if ($packageDir !== null) {
            $this->registerLocalPackageAutoload($package, $packageDir);
        }

        $providerClasses = $package['extra']['laravel']['providers'] ?? [];

        foreach ($providerClasses as $providerClass) {
            if (! is_string($providerClass) || ! class_exists($providerClass)) {
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

        $discoveredPackages[$name] = true;
    }

    /**
     * @param  array<string, bool>  $discoveredPackages
     * @param  array<string, bool>  $availablePackageNames
     */
    private function discoverLocalMonorepoIntegrations(array &$discoveredPackages, array $availablePackageNames = []): void
    {
        foreach ($this->localMonorepoComposerFiles() as $composerFile) {
            $package = json_decode((string) file_get_contents($composerFile), true);
            if (! is_array($package)) {
                continue;
            }

            $this->registerIntegrationPackage(
                $package,
                $discoveredPackages,
                dirname($composerFile),
                $availablePackageNames,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function localMonorepoComposerFiles(): array
    {
        $composerFiles = [];

        foreach ($this->localMonorepoPackageRoots() as $packagesRoot) {
            $matches = glob($packagesRoot.'/*/composer.json');
            if ($matches === false) {
                continue;
            }

            foreach ($matches as $match) {
                $composerFiles[] = $match;
            }
        }

        sort($composerFiles, SORT_STRING);

        return $composerFiles;
    }

    private function registerLocalPackageAutoload(array $package, string $packageDir): void
    {
        $autoload = $package['autoload']['psr-4'] ?? null;
        if (! is_array($autoload)) {
            return;
        }

        foreach ($autoload as $prefix => $relativePath) {
            if (! is_string($prefix) || ! is_string($relativePath)) {
                continue;
            }

            $directory = rtrim($packageDir.'/'.$relativePath, '/');
            if (! is_dir($directory)) {
                continue;
            }

            $this->localPackagePsr4Prefixes[$prefix] ??= [];
            if (! in_array($directory, $this->localPackagePsr4Prefixes[$prefix], true)) {
                $this->localPackagePsr4Prefixes[$prefix][] = $directory;
            }
        }

        $this->registerLocalPackageAutoloader();
    }

    private function registerLocalPackageAutoloader(): void
    {
        if ($this->localPackageAutoloaderRegistered) {
            return;
        }

        spl_autoload_register($this->autoloadLocalPackageClass(...), prepend: true);
        $this->localPackageAutoloaderRegistered = true;
    }

    private function autoloadLocalPackageClass(string $class): void
    {
        foreach ($this->localPackagePsr4Prefixes as $prefix => $directories) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            if ($relativeClass === false) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $relativeClass).'.php';

            foreach ($directories as $directory) {
                $file = $directory.'/'.$relativePath;
                if (is_file($file)) {
                    require_once $file;

                    return;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function localMonorepoPackageRoots(): array
    {
        $roots = [];
        $configured = getenv('KOSMOKRATOR_INTEGRATIONS_PATH');
        if (is_string($configured) && $configured !== '') {
            $roots[] = $configured;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (is_string($home) && $home !== '') {
            $roots[] = $home.'/Sites/integrations/packages';
        }

        $normalized = [];
        foreach ($roots as $root) {
            $candidate = is_dir($root.'/packages') ? $root.'/packages' : $root;
            $real = realpath($candidate);
            if ($real === false || ! is_dir($real)) {
                continue;
            }

            if (! in_array($real, $normalized, true)) {
                $normalized[] = $real;
            }
        }

        return $normalized;
    }
}
