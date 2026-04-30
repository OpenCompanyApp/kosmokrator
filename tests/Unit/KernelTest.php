<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kosmokrator\Kernel;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\ToolRegistry;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;

class KernelTest extends TestCase
{
    private static string $basePath;

    private static ?string $originalHome = null;

    private static string $fakeHome;

    private static Kernel $sharedKernel;

    public static function setUpBeforeClass(): void
    {
        self::$basePath = dirname(__DIR__, 2); // project root

        // Isolate HOME so Kernel's logger writes to a temp dir, not real home
        self::$originalHome = getenv('HOME') ?: null;
        self::$fakeHome = sys_get_temp_dir().'/kosmokrator_kernel_test_'.uniqid();
        mkdir(self::$fakeHome.'/.kosmo/logs', 0755, true);
        putenv('HOME='.self::$fakeHome);
        $_ENV['HOME'] = self::$fakeHome;

        self::$sharedKernel = new Kernel(self::$basePath);
        self::$sharedKernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        // Restore HOME
        if (self::$originalHome !== null) {
            putenv('HOME='.self::$originalHome);
            $_ENV['HOME'] = self::$originalHome;
        } else {
            putenv('HOME');
            unset($_ENV['HOME']);
        }

        // Clean up the temp home
        if (is_dir(self::$fakeHome)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::$fakeHome, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
            }
            rmdir(self::$fakeHome);
        }

        // Reset the Laravel container instance
        Container::setInstance(null);
    }

    public function test_boot_sets_up_container(): void
    {
        $this->assertInstanceOf(Container::class, self::$sharedKernel->getContainer());
    }

    public function test_boot_sets_up_console(): void
    {
        $this->assertInstanceOf(Application::class, self::$sharedKernel->getConsole());
    }

    public function test_console_has_app_name_from_config(): void
    {
        // config/app.yaml has: name: KosmoKrator
        $this->assertSame('KosmoKrator', self::$sharedKernel->getConsole()->getName());
    }

    public function test_console_has_version(): void
    {
        // config/app.yaml has: version: git — Kernel falls back to 'dev' if git describe fails
        $version = self::$sharedKernel->getConsole()->getVersion();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }

    public function test_container_has_config_binding(): void
    {
        $config = self::$sharedKernel->getContainer()->make('config');

        $this->assertNotNull($config);
        $this->assertSame('KosmoKrator', $config->get('app.name'));
    }

    public function test_container_has_logger_binding(): void
    {
        $container = self::$sharedKernel->getContainer();

        $log = $container->make('log');
        $this->assertInstanceOf(Logger::class, $log);

        // Also aliased to LoggerInterface
        $this->assertInstanceOf(Logger::class, $container->make(LoggerInterface::class));
    }

    public function test_container_has_events_binding(): void
    {
        $container = self::$sharedKernel->getContainer();

        $events = $container->make('events');
        $this->assertInstanceOf(Dispatcher::class, $events);

        // Also aliased to contract
        $this->assertInstanceOf(DispatcherContract::class, $container->make(DispatcherContract::class));
    }

    public function test_container_has_filesystem_binding(): void
    {
        $files = self::$sharedKernel->getContainer()->make('files');
        $this->assertInstanceOf(Filesystem::class, $files);
    }

    public function test_container_has_http_binding(): void
    {
        $http = self::$sharedKernel->getContainer()->make('http');
        $this->assertInstanceOf(HttpFactory::class, $http);
    }

    public function test_container_has_path_bindings(): void
    {
        $container = self::$sharedKernel->getContainer();

        $this->assertSame(self::$basePath, $container->make('path.base'));
        $this->assertSame(self::$basePath.'/config', $container->make('path.config'));
    }

    public function test_container_has_session_database(): void
    {
        $sessionDb = self::$sharedKernel->getContainer()->make(SessionDatabase::class);
        $this->assertInstanceOf(SessionDatabase::class, $sessionDb);
    }

    public function test_container_has_session_repository(): void
    {
        $repo = self::$sharedKernel->getContainer()->make(SessionRepository::class);
        $this->assertInstanceOf(SessionRepository::class, $repo);
    }

    public function test_container_has_session_manager(): void
    {
        $manager = self::$sharedKernel->getContainer()->make(SessionManager::class);
        $this->assertInstanceOf(SessionManager::class, $manager);
    }

    public function test_container_has_settings_manager(): void
    {
        $manager = self::$sharedKernel->getContainer()->make(SettingsManager::class);
        $this->assertInstanceOf(SettingsManager::class, $manager);
    }

    public function test_container_has_task_store(): void
    {
        $store = self::$sharedKernel->getContainer()->make(TaskStore::class);
        $this->assertInstanceOf(TaskStore::class, $store);
    }

    public function test_container_has_tool_registry(): void
    {
        $registry = self::$sharedKernel->getContainer()->make(ToolRegistry::class);
        $this->assertInstanceOf(ToolRegistry::class, $registry);
    }

    public function test_container_has_model_catalog(): void
    {
        $catalog = self::$sharedKernel->getContainer()->make(ModelCatalog::class);
        $this->assertInstanceOf(ModelCatalog::class, $catalog);
    }

    public function test_container_has_provider_catalog(): void
    {
        $catalog = self::$sharedKernel->getContainer()->make(ProviderCatalog::class);
        $this->assertInstanceOf(ProviderCatalog::class, $catalog);
    }

    public function test_container_binds_llm_client_interface(): void
    {
        $llm = self::$sharedKernel->getContainer()->make(LlmClientInterface::class);
        $this->assertInstanceOf(LlmClientInterface::class, $llm);
    }

    public function test_container_resolves_singletons_consistently(): void
    {
        $container = self::$sharedKernel->getContainer();

        // Singletons should resolve to the same instance
        $db1 = $container->make(SessionDatabase::class);
        $db2 = $container->make(SessionDatabase::class);
        $this->assertSame($db1, $db2);

        $taskStore1 = $container->make(TaskStore::class);
        $taskStore2 = $container->make(TaskStore::class);
        $this->assertSame($taskStore1, $taskStore2);
    }

    public function test_boot_can_be_called_once(): void
    {
        // Use a fresh kernel for this specific test since it tests re-booting
        $kernel = new Kernel(self::$basePath);
        $kernel->boot();
        $kernel->boot();

        $this->assertInstanceOf(Application::class, $kernel->getConsole());
        $this->assertInstanceOf(Container::class, $kernel->getContainer());
    }
}
