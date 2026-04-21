<?php

declare(strict_types=1);

namespace Kosmokrator;

use Dotenv\Dotenv;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application as LaravelApp;
use Illuminate\Support\Facades\Facade;
use Kosmokrator\Provider\AgentServiceProvider;
use Kosmokrator\Provider\ConfigServiceProvider;
use Kosmokrator\Provider\CoreServiceProvider;
use Kosmokrator\Provider\DatabaseServiceProvider;
use Kosmokrator\Provider\EventServiceProvider;
use Kosmokrator\Provider\IntegrationServiceProvider;
use Kosmokrator\Provider\LlmServiceProvider;
use Kosmokrator\Provider\LoggingServiceProvider;
use Kosmokrator\Provider\SessionServiceProvider;
use Kosmokrator\Provider\ToolServiceProvider;
use Kosmokrator\Provider\WebServiceProvider;
use Revolt\EventLoop;
use Symfony\Component\Console\Application;

/**
 * Application kernel — wires the Laravel DI container and orchestrates the full
 * boot sequence: env, config, database, LLM/Prism, tool registry, and console.
 * This is the single entry point invoked by the bin/kosmokrator script.
 */
class Kernel
{
    private Container $container;

    private Application $console;

    /** @param string $basePath Root directory of the KosmoKrator installation (contains config/, vendor/) */
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Run the full boot sequence. Must be called once before using getConsole() or getContainer().
     */
    public function boot(): void
    {
        $this->container = new LaravelApp($this->basePath);
        Container::setInstance($this->container);

        $this->loadEnv();

        // Order matters: config first, then logging, database, core infra, LLM, tools, session
        $providers = [
            new ConfigServiceProvider($this->container, $this->basePath),
            new LoggingServiceProvider($this->container),
            new DatabaseServiceProvider($this->container),
            new CoreServiceProvider($this->container, $this->basePath),
            new LlmServiceProvider($this->container),
            new IntegrationServiceProvider($this->container, $this->basePath),
            new WebServiceProvider($this->container),
            new ToolServiceProvider($this->container),
            new SessionServiceProvider($this->container),
            new EventServiceProvider($this->container),
            new AgentServiceProvider($this->container),
        ];

        // Register phase: all bindings
        foreach ($providers as $provider) {
            $provider->register();
        }

        // Boot phase: post-registration hooks (e.g. SQLite settings injection)
        foreach ($providers as $provider) {
            $provider->boot();
        }

        $this->registerFacades();
        $this->buildConsole();
        $this->registerRevoltErrorHandler();
    }

    /** @return Application The Symfony console application ready to run */
    public function getConsole(): Application
    {
        return $this->console;
    }

    /** @return Container The initialized Laravel IoC container */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /** Load .env file from the base path if it exists. */
    private function loadEnv(): void
    {
        if (file_exists($this->basePath.'/.env')) {
            Dotenv::createImmutable($this->basePath)->safeLoad();
        }
    }

    /** Wire Laravel facades to the container so Http, Config etc. resolve correctly. */
    private function registerFacades(): void
    {
        /** @var Container $app */
        $app = $this->container;
        Facade::setFacadeApplication($app);
    }

    /** Instantiate the Symfony console application with the configured name and version. */
    private function buildConsole(): void
    {
        $appName = $this->container->make('config')->get('app.name', 'KosmoKrator');
        $version = $this->resolveVersion();

        $this->console = new Application($appName, $version);
    }

    /** Resolve the app version — either a static string or a git tag/describe. */
    private function resolveVersion(): string
    {
        $versionConfig = $this->container->make('config')->get('app.version', 'dev');

        if ($versionConfig === 'git') {
            $version = trim((string) shell_exec('git describe --tags --always 2>/dev/null'));

            return $version !== '' ? $version : 'dev';
        }

        return $versionConfig;
    }

    /**
     * Register a Revolt error handler to gracefully handle exceptions thrown
     * inside event loop callbacks (e.g. stream read assertions during shutdown).
     *
     * Without this, Amp\ByteStream\ReadableResourceStream assertions about
     * fclose'd resources become UncaughtThrowable fatalities that crash the
     * process with a stack trace — even though the process was already exiting.
     */
    private function registerRevoltErrorHandler(): void
    {
        EventLoop::setErrorHandler(function (\Throwable $e): void {
            // Silently ignore shutdown-time stream errors from Amp processes
            if ($e instanceof \AssertionError && str_contains($e->getMessage(), 'fclose')) {
                return;
            }

            throw $e;
        });
    }
}
