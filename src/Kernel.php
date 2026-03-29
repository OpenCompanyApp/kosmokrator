<?php

namespace Kosmokrator;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application as LaravelApp;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;
use Kosmokrator\Tool\ToolRegistry;
use Dotenv\Dotenv;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Prism\Prism\PrismServiceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;

class Kernel
{
    private Container $container;

    private Application $console;

    public function __construct(
        private readonly string $basePath,
    ) {}

    public function boot(): void
    {
        $this->container = new LaravelApp($this->basePath);
        Container::setInstance($this->container);

        $this->loadEnv();
        $this->registerConfig();
        $this->registerLogger();
        $this->registerCoreServices();
        $this->registerPrism();
        $this->registerFacades();
        $this->registerAgentServices();
        $this->buildConsole();
    }

    public function getConsole(): Application
    {
        return $this->console;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    private function loadEnv(): void
    {
        if (file_exists($this->basePath . '/.env')) {
            Dotenv::createImmutable($this->basePath)->safeLoad();
        }
    }

    private function registerLogger(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        $logDir = $home . '/.kosmokrator/logs';

        if (! is_dir($logDir)) {
            mkdir($logDir, 0700, true);
        }

        $logger = new Logger('kosmokrator');
        $logger->pushHandler(new RotatingFileHandler($logDir . '/kosmokrator.log', 7, Logger::DEBUG));

        $this->container->instance('log', $logger);
        $this->container->alias('log', LoggerInterface::class);
        $this->container->alias('log', Logger::class);
    }

    private function registerConfig(): void
    {
        $loader = new ConfigLoader($this->basePath . '/config');
        $config = $loader->load();

        $this->container->instance('config', $config);
        $this->container->alias('config', Repository::class);

        // Map prism.yaml keys to where Prism expects them
        $config->set('prism', $config->get('prism', []));
    }

    private function registerCoreServices(): void
    {
        // App instance bindings that Prism/Laravel expects
        $this->container->instance('path.base', $this->basePath);
        $this->container->instance('path.config', $this->basePath . '/config');

        // Events dispatcher (needed by Laravel internals)
        $this->container->singleton('events', fn () => new Dispatcher($this->container));
        $this->container->alias('events', \Illuminate\Contracts\Events\Dispatcher::class);

        // Filesystem
        $this->container->singleton('files', fn () => new Filesystem());

        // HTTP client factory (used by Prism via Http facade)
        $this->container->singleton('http', fn () => new HttpFactory());
    }

    private function registerPrism(): void
    {
        // Simulate Laravel's Application interface just enough for PrismServiceProvider
        $this->container->instance('app', $this->container);

        $provider = new PrismServiceProvider($this->container);
        $provider->register();
    }

    private function registerFacades(): void
    {
        Facade::setFacadeApplication($this->container);
    }

    private function registerAgentServices(): void
    {
        $config = $this->container->make('config');

        $this->container->singleton(PrismService::class, fn () => new PrismService(
            provider: $config->get('kosmokrator.agent.default_provider', 'anthropic'),
            model: $config->get('kosmokrator.agent.default_model', 'claude-sonnet-4-20250514'),
            systemPrompt: $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.'),
            maxTokens: $config->get('kosmokrator.agent.max_tokens', 8192),
            temperature: $config->get('kosmokrator.agent.temperature', 0.0),
        ));

        $bashTimeout = $config->get('kosmokrator.tools.bash.timeout', 120);

        $this->container->singleton(ToolRegistry::class, function () use ($bashTimeout) {
            $registry = new ToolRegistry();
            $registry->register(new FileReadTool());
            $registry->register(new FileWriteTool());
            $registry->register(new FileEditTool());
            $registry->register(new GlobTool());
            $registry->register(new GrepTool());
            $registry->register(new BashTool($bashTimeout));

            return $registry;
        });
    }

    private function buildConsole(): void
    {
        $appName = $this->container->make('config')->get('app.name', 'KosmoKrator');
        $version = $this->resolveVersion();

        $this->console = new Application($appName, $version);
    }

    private function resolveVersion(): string
    {
        $versionConfig = $this->container->make('config')->get('app.version', 'dev');

        if ($versionConfig === 'git') {
            $version = trim((string) shell_exec('git describe --tags --always 2>/dev/null'));

            return $version !== '' ? $version : 'dev';
        }

        return $versionConfig;
    }
}
