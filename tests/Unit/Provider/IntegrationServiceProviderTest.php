<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Provider\IntegrationServiceProvider;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use OpenCompany\IntegrationCore\Support\ToolResult;
use PHPUnit\Framework\TestCase;

class IntegrationServiceProviderTest extends TestCase
{
    public function test_discovers_both_current_and_legacy_integration_package_prefixes(): void
    {
        $basePath = sys_get_temp_dir().'/kosmokrator-integration-provider-test-'.bin2hex(random_bytes(8));
        mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/composer.lock', json_encode([
            'packages' => [
                [
                    'name' => 'opencompanyapp/integration-example',
                    'extra' => [
                        'laravel' => [
                            'providers' => [IntegrationPrefixTestProvider::class],
                        ],
                    ],
                ],
                [
                    'name' => 'opencompanyapp/ai-tool-example',
                    'extra' => [
                        'laravel' => [
                            'providers' => [LegacyPrefixTestProvider::class],
                        ],
                    ],
                ],
                [
                    'name' => 'opencompanyapp/integration-core',
                    'extra' => [
                        'laravel' => [
                            'providers' => [IntegrationCoreLikeTestProvider::class],
                        ],
                    ],
                ],
                [
                    'name' => 'vendor/not-an-integration',
                    'extra' => [
                        'laravel' => [
                            'providers' => [IgnoredPackageTestProvider::class],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $container = new Container;
        $provider = new IntegrationServiceProvider($container, $basePath);
        $provider->register();
        $provider->boot();

        $registry = $container->make(ToolProviderRegistry::class);

        $this->assertTrue($registry->has('integration-prefix'));
        $this->assertTrue($registry->has('legacy-prefix'));
        $this->assertFalse($registry->has('integration-core-like'));
        $this->assertFalse($registry->has('ignored-package'));

        unlink($basePath.'/composer.lock');
        rmdir($basePath);
    }

    public function test_discovers_local_monorepo_packages_via_configured_path(): void
    {
        $basePath = sys_get_temp_dir().'/kosmokrator-local-integrations-test-'.bin2hex(random_bytes(8));
        $packagesPath = $basePath.'/packages';
        $packagePath = $packagesPath.'/example';
        $srcPath = $packagePath.'/src';

        mkdir($srcPath, 0777, true);
        file_put_contents($basePath.'/composer.lock', json_encode(['packages' => []], JSON_THROW_ON_ERROR));
        file_put_contents($packagePath.'/composer.json', json_encode([
            'name' => 'opencompanyapp/integration-example-local',
            'autoload' => [
                'psr-4' => [
                    'IntegrationExample\\' => 'src/',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'providers' => ['IntegrationExample\\ExampleServiceProvider'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($srcPath.'/ExampleServiceProvider.php', <<<'PHP'
<?php

namespace IntegrationExample;

use Illuminate\Support\ServiceProvider;
use Kosmokrator\Tests\Unit\Provider\DummyToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

class ExampleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->bound(ToolProviderRegistry::class)) {
            $this->app->make(ToolProviderRegistry::class)->register(new DummyToolProvider('local-monorepo'));
        }
    }
}
PHP);

        putenv('KOSMOKRATOR_INTEGRATIONS_PATH='.$packagesPath);

        $container = new Container;
        $provider = new IntegrationServiceProvider($container, $basePath);
        $provider->register();
        $provider->boot();

        $registry = $container->make(ToolProviderRegistry::class);
        $this->assertTrue($registry->has('local-monorepo'));

        putenv('KOSMOKRATOR_INTEGRATIONS_PATH');
        unlink($srcPath.'/ExampleServiceProvider.php');
        unlink($packagePath.'/composer.json');
        unlink($basePath.'/composer.lock');
        rmdir($srcPath);
        rmdir($packagePath);
        rmdir($packagesPath);
        rmdir($basePath);
    }

    public function test_skips_redundant_google_subpackages_when_canonical_google_package_exists(): void
    {
        $basePath = sys_get_temp_dir().'/kosmokrator-google-dedupe-test-'.bin2hex(random_bytes(8));
        mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/composer.lock', json_encode([
            'packages' => [
                [
                    'name' => 'opencompanyapp/integration-google',
                    'extra' => [
                        'laravel' => [
                            'providers' => [CanonicalGoogleTestProvider::class],
                        ],
                    ],
                ],
                [
                    'name' => 'opencompanyapp/integration-google-docs',
                    'extra' => [
                        'laravel' => [
                            'providers' => [LegacyGoogleDocsTestProvider::class],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $container = new Container;
        $provider = new IntegrationServiceProvider($container, $basePath);
        $provider->register();
        $provider->boot();

        $registry = $container->make(ToolProviderRegistry::class);

        $this->assertTrue($registry->has('google_docs'));
        $this->assertFalse($registry->has('google-docs'));

        unlink($basePath.'/composer.lock');
        rmdir($basePath);
    }
}

final class IntegrationPrefixTestProvider
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        $this->container->make(ToolProviderRegistry::class)->register(new DummyToolProvider('integration-prefix'));
    }
}

final class LegacyPrefixTestProvider
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        $this->container->make(ToolProviderRegistry::class)->register(new DummyToolProvider('legacy-prefix'));
    }
}

final class IgnoredPackageTestProvider
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        $this->container->make(ToolProviderRegistry::class)->register(new DummyToolProvider('ignored-package'));
    }
}

final class IntegrationCoreLikeTestProvider
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        $this->container->make(ToolProviderRegistry::class)->register(new DummyToolProvider('integration-core-like'));
    }
}

final class CanonicalGoogleTestProvider
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        $this->container->make(ToolProviderRegistry::class)->register(new DummyToolProvider('google_docs'));
    }
}

final class LegacyGoogleDocsTestProvider
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        $this->container->make(ToolProviderRegistry::class)->register(new DummyToolProvider('google-docs'));
    }
}

final class DummyToolProvider implements ToolProvider
{
    public function __construct(private readonly string $appName) {}

    public function appName(): string
    {
        return $this->appName;
    }

    public function appMeta(): array
    {
        return [
            'label' => ucfirst(str_replace('-', ' ', $this->appName)),
            'description' => 'Test integration provider',
            'icon' => 'ph:puzzle-piece',
        ];
    }

    public function tools(): array
    {
        return [];
    }

    public function isIntegration(): bool
    {
        return true;
    }

    public function createTool(string $class, array $context = []): Tool
    {
        return new DummyTool;
    }

    public function luaDocsPath(): ?string
    {
        return null;
    }

    public function credentialFields(): array
    {
        return [];
    }
}

final class DummyTool implements Tool
{
    public function name(): string
    {
        return 'dummy_tool';
    }

    public function description(): string
    {
        return 'Dummy tool';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        return ToolResult::success([]);
    }
}
