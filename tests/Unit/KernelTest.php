<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\Kernel;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;

class KernelTest extends TestCase
{
    private string $basePath;

    private ?string $originalHome = null;

    protected function setUp(): void
    {
        $this->basePath = dirname(__DIR__, 2); // project root

        // Isolate HOME so Kernel's logger writes to a temp dir, not real home
        $this->originalHome = getenv('HOME') ?: null;
        $fakeHome = sys_get_temp_dir().'/kosmokrator_kernel_test_'.uniqid();
        mkdir($fakeHome.'/.kosmokrator/logs', 0755, true);
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;
    }

    protected function tearDown(): void
    {
        // Restore HOME
        if ($this->originalHome !== null) {
            putenv("HOME={$this->originalHome}");
            $_ENV['HOME'] = $this->originalHome;
        } else {
            putenv('HOME');
            unset($_ENV['HOME']);
        }

        // Clean up the temp home
        $home = getenv('HOME') ?: '';
        if (str_contains($home, 'kosmokrator_kernel_test_')) {
            $this->removeDir($home);
        }

        // Reset the Laravel container instance so tests don't pollute each other
        Container::setInstance(null);
    }

    public function test_boot_sets_up_container(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $container = $kernel->getContainer();

        $this->assertInstanceOf(Container::class, $container);
    }

    public function test_boot_sets_up_console(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $console = $kernel->getConsole();

        $this->assertInstanceOf(Application::class, $console);
    }

    public function test_console_has_app_name_from_config(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $console = $kernel->getConsole();

        // config/app.yaml has: name: KosmoKrator
        $this->assertSame('KosmoKrator', $console->getName());
    }

    public function test_console_has_version(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $console = $kernel->getConsole();

        // config/app.yaml has: version: git — Kernel falls back to 'dev' if git describe fails
        $version = $console->getVersion();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }

    public function test_container_has_config_binding(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $config = $kernel->getContainer()->make('config');

        $this->assertNotNull($config);
        $this->assertSame('KosmoKrator', $config->get('app.name'));
    }

    public function test_container_has_logger_binding(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $container = $kernel->getContainer();

        $log = $container->make('log');
        $this->assertInstanceOf(Logger::class, $log);

        // Also aliased to LoggerInterface
        $this->assertInstanceOf(Logger::class, $container->make(LoggerInterface::class));
    }

    public function test_container_has_events_binding(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $container = $kernel->getContainer();

        $events = $container->make('events');
        $this->assertInstanceOf(Dispatcher::class, $events);

        // Also aliased to contract
        $this->assertInstanceOf(DispatcherContract::class, $container->make(DispatcherContract::class));
    }

    public function test_container_has_filesystem_binding(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $files = $kernel->getContainer()->make('files');
        $this->assertInstanceOf(Filesystem::class, $files);
    }

    public function test_container_has_http_binding(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $http = $kernel->getContainer()->make('http');
        $this->assertInstanceOf(HttpFactory::class, $http);
    }

    public function test_container_has_path_bindings(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $container = $kernel->getContainer();

        $this->assertSame($this->basePath, $container->make('path.base'));
        $this->assertSame($this->basePath.'/config', $container->make('path.config'));
    }

    public function test_container_has_session_database(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $sessionDb = $kernel->getContainer()->make(SessionDatabase::class);
        $this->assertInstanceOf(SessionDatabase::class, $sessionDb);
    }

    public function test_container_has_session_repository(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $repo = $kernel->getContainer()->make(SessionRepository::class);
        $this->assertInstanceOf(SessionRepository::class, $repo);
    }

    public function test_container_has_session_manager(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $manager = $kernel->getContainer()->make(SessionManager::class);
        $this->assertInstanceOf(SessionManager::class, $manager);
    }

    public function test_container_has_settings_manager(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $manager = $kernel->getContainer()->make(SettingsManager::class);
        $this->assertInstanceOf(SettingsManager::class, $manager);
    }

    public function test_container_has_task_store(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $store = $kernel->getContainer()->make(TaskStore::class);
        $this->assertInstanceOf(TaskStore::class, $store);
    }

    public function test_container_has_tool_registry(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $registry = $kernel->getContainer()->make(ToolRegistry::class);
        $this->assertInstanceOf(ToolRegistry::class, $registry);
    }

    public function test_container_has_model_catalog(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $catalog = $kernel->getContainer()->make(ModelCatalog::class);
        $this->assertInstanceOf(ModelCatalog::class, $catalog);
    }

    public function test_container_has_provider_catalog(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $catalog = $kernel->getContainer()->make(ProviderCatalog::class);
        $this->assertInstanceOf(ProviderCatalog::class, $catalog);
    }

    public function test_container_binds_llm_client_interface(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $llm = $kernel->getContainer()->make(LlmClientInterface::class);
        $this->assertInstanceOf(LlmClientInterface::class, $llm);
    }

    public function test_container_resolves_singletons_consistently(): void
    {
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        $container = $kernel->getContainer();

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
        $kernel = new Kernel($this->basePath);
        $kernel->boot();

        // Calling boot again should work (overwrite container/console)
        $kernel->boot();

        $this->assertInstanceOf(Application::class, $kernel->getConsole());
        $this->assertInstanceOf(Container::class, $kernel->getContainer());
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
