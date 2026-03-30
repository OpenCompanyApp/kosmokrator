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
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Task\Tool\TaskCreateTool;
use Kosmokrator\Task\Tool\TaskGetTool;
use Kosmokrator\Task\Tool\TaskListTool;
use Kosmokrator\Task\Tool\TaskUpdateTool;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Tool\Permission\PermissionConfigParser;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\SessionGrants;
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
        $this->registerDatabase();
        $this->injectSqliteSettings();
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

    private function registerDatabase(): void
    {
        $this->container->singleton(SessionDatabase::class, fn () => new SessionDatabase());
        $this->container->singleton(SettingsRepository::class, fn () => new SettingsRepository(
            $this->container->make(SessionDatabase::class),
        ));
    }

    private function injectSqliteSettings(): void
    {
        $config = $this->container->make('config');
        $settings = $this->container->make(SettingsRepository::class);

        // Provider and model from SQLite override YAML defaults (env vars already resolved)
        $sqliteProvider = $settings->get('global', 'agent.default_provider');
        if ($sqliteProvider !== null) {
            $config->set('kosmokrator.agent.default_provider', $sqliteProvider);
        }

        $sqliteModel = $settings->get('global', 'agent.default_model');
        if ($sqliteModel !== null) {
            $config->set('kosmokrator.agent.default_model', $sqliteModel);
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

    private function migrateYamlKeys(Repository $config, SettingsRepository $settings): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        $yamlPath = $home . '/.kosmokrator/config.yaml';

        if (! file_exists($yamlPath)) {
            return;
        }

        $yaml = \Symfony\Component\Yaml\Yaml::parseFile($yamlPath) ?? [];
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

        // Rewrite YAML without sensitive data
        if ($migrated || ! isset($yaml['providers'])) {
            if (empty($yaml)) {
                @unlink($yamlPath);
            } else {
                file_put_contents($yamlPath, \Symfony\Component\Yaml\Yaml::dump($yaml, 4, 2));
            }
        }
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

        // Register z-api as alias of z provider (same class, different config/URL)
        $this->container->make(\Prism\Prism\PrismManager::class)->extend(
            'z-api',
            fn ($app, array $config) => new \Prism\Prism\Providers\Z\Z(
                apiKey: $config['api_key'] ?? '',
                url: $config['url'] ?? 'https://open.bigmodel.cn/api/paas/v4',
            ),
        );
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
            maxTokens: $config->get('kosmokrator.agent.max_tokens'),
            temperature: $config->get('kosmokrator.agent.temperature', 0.0),
        ));

        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $this->container->singleton(AsyncLlmClient::class, fn () => new AsyncLlmClient(
            apiKey: $config->get("prism.providers.{$provider}.api_key", ''),
            baseUrl: rtrim($config->get("prism.providers.{$provider}.url", ''), '/'),
            model: $config->get('kosmokrator.agent.default_model', 'GLM-5.1'),
            systemPrompt: $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.'),
            maxTokens: $config->get('kosmokrator.agent.max_tokens'),
            temperature: $config->get('kosmokrator.agent.temperature', 0.0),
            provider: $provider,
        ));

        $this->container->singleton(ModelCatalog::class, fn () => new ModelCatalog(
            $config->get('models', []),
        ));

        $bashTimeout = $config->get('kosmokrator.tools.bash.timeout', 120);

        $this->container->singleton(TaskStore::class);

        $this->container->singleton(ToolRegistry::class, function () use ($bashTimeout) {
            $registry = new ToolRegistry();
            $registry->register(new FileReadTool());
            $registry->register(new FileWriteTool());
            $registry->register(new FileEditTool());
            $registry->register(new GlobTool());
            $registry->register(new GrepTool());
            $registry->register(new BashTool($bashTimeout));

            $taskStore = $this->container->make(TaskStore::class);
            $registry->register(new TaskCreateTool($taskStore));
            $registry->register(new TaskUpdateTool($taskStore));
            $registry->register(new TaskListTool($taskStore));
            $registry->register(new TaskGetTool($taskStore));

            return $registry;
        });

        // Session persistence (Database + SettingsRepository registered earlier in registerDatabase)
        $this->container->singleton(SessionRepository::class, fn () => new SessionRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->singleton(MessageRepository::class, fn () => new MessageRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->singleton(MemoryRepository::class, fn () => new MemoryRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->singleton(SessionManager::class, fn () => new SessionManager(
            sessions: $this->container->make(SessionRepository::class),
            messages: $this->container->make(MessageRepository::class),
            settings: $this->container->make(SettingsRepository::class),
            memories: $this->container->make(MemoryRepository::class),
            log: $this->container->make(LoggerInterface::class),
        ));

        $this->container->singleton(SessionGrants::class);
        $this->container->singleton(PermissionEvaluator::class, function () use ($config) {
            $parser = new PermissionConfigParser();
            $rules = $parser->parse($config);

            return new PermissionEvaluator($rules, $this->container->make(SessionGrants::class));
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
