<?php

namespace Kosmokrator;

use Dotenv\Dotenv;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application as LaravelApp;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Audio\CompletionSound;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\Codex\SettingsCodexTokenStore;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Session\Database as SessionDatabase;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\Tool\MemorySaveTool;
use Kosmokrator\Session\Tool\MemorySearchTool;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsPaths;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Task\Tool\TaskCreateTool;
use Kosmokrator\Task\Tool\TaskGetTool;
use Kosmokrator\Task\Tool\TaskListTool;
use Kosmokrator\Task\Tool\TaskUpdateTool;
use Kosmokrator\Tool\Coding\ApplyPatchTool;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;
use Kosmokrator\Tool\Coding\Patch\PatchApplier;
use Kosmokrator\Tool\Coding\Patch\PatchParser;
use Kosmokrator\Tool\Coding\ShellKillTool;
use Kosmokrator\Tool\Coding\ShellReadTool;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Coding\ShellStartTool;
use Kosmokrator\Tool\Coding\ShellWriteTool;
use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionConfigParser;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Permission\SessionGrants;
use Kosmokrator\Tool\ToolRegistry;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use OpenCompany\PrismCodex\Codex;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore as CodexTokenStoreContract;
use OpenCompany\PrismRelay\Caching\GeminiCacheStore;
use OpenCompany\PrismRelay\Caching\PromptCacheOrchestrator;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Registry\RelayRegistryBuilder;
use OpenCompany\PrismRelay\Relay;
use OpenCompany\PrismRelay\RelayManager;
use Prism\Prism\PrismManager;
use Prism\Prism\PrismServiceProvider;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Application;
use Symfony\Component\Yaml\Yaml;

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
        $this->registerConfig();
        $this->registerLogger();
        $this->registerDatabase();
        $this->injectSqliteSettings();
        $this->registerCoreServices();
        $this->registerPrism();
        $this->registerFacades();
        $this->registerAgentServices();
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

    /** Create a rotating file logger under ~/.kosmokrator/logs. */
    private function registerLogger(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        $logDir = $home.'/.kosmokrator/logs';

        if (! is_dir($logDir)) {
            mkdir($logDir, 0700, true);
        }

        $logger = new Logger('kosmokrator');
        $logger->pushHandler(new RotatingFileHandler($logDir.'/kosmokrator.log', 7, Logger::DEBUG));

        $this->container->instance('log', $logger);
        $this->container->alias('log', LoggerInterface::class);
        $this->container->alias('log', Logger::class);
    }

    /** Build the config repository from bundled defaults and set up Codex OAuth config. */
    private function registerConfig(): void
    {
        $loader = new ConfigLoader($this->basePath.'/config');
        $config = $loader->load();

        $codexUrl = (string) $config->get('prism.providers.codex.url', 'https://chatgpt.com/backend-api/codex');
        $codexOAuthPort = (int) $config->get('kosmokrator.codex.oauth_port', 9876);

        $this->container->instance('config', $config);
        $this->container->alias('config', Repository::class);

        // Map prism.yaml keys to where Prism expects them
        $config->set('prism', $config->get('prism', []));
        $config->set('codex', [
            'url' => $codexUrl,
            'oauth_port' => $codexOAuthPort,
            'callback_route' => '/auth/codex/callback',
            'table' => 'codex_tokens',
            'id_token_add_organizations' => true,
            'originator' => 'kosmokrator',
            'user_agent' => 'kosmokrator',
        ]);
    }

    /** Register SQLite-backed session/settings databases and Codex OAuth services. */
    private function registerDatabase(): void
    {
        $this->container->singleton(SessionDatabase::class, fn () => new SessionDatabase);
        $this->container->singleton(SettingsRepository::class, fn () => new SettingsRepository(
            $this->container->make(SessionDatabase::class),
        ));
        $this->container->singleton(CodexTokenStoreContract::class, fn () => new SettingsCodexTokenStore(
            $this->container->make(SettingsRepository::class),
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
    }

    /** Inject legacy SQLite-stored preferences and API keys into the config repository. */
    private function injectSqliteSettings(): void
    {
        $config = $this->container->make('config');
        $settings = $this->container->make(SettingsRepository::class);
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
    private function migrateYamlKeys(Repository $config, SettingsRepository $settings): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        $yamlPath = $home.'/.kosmokrator/config.yaml';

        if (! file_exists($yamlPath)) {
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

        // Rewrite YAML without sensitive data
        if ($migrated || ! isset($yaml['providers'])) {
            if (empty($yaml)) {
                @unlink($yamlPath);
            } else {
                file_put_contents($yamlPath, Yaml::dump($yaml, 4, 2));
            }
        }
    }

    /** Bind core infrastructure: paths, events, filesystem, HTTP, settings, relay registry, and provider catalog. */
    private function registerCoreServices(): void
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
            $this->container->make(SettingsRepository::class),
            $this->container->make(CodexTokenStoreContract::class),
        ));
    }

    /** Register the Prism LLM service provider and extend it with relay/codex drivers. */
    private function registerPrism(): void
    {
        // Simulate Laravel's Application interface just enough for PrismServiceProvider
        $this->container->instance('app', $this->container);

        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $app = $this->container;
        $provider = new PrismServiceProvider($app);
        $provider->register();

        $manager = $this->container->make(PrismManager::class);
        $providers = $this->container->make(RelayRegistry::class)->allProviders();
        unset($providers['codex']);
        (new RelayManager($providers))->register($manager);
        $manager->extend('codex', function ($app, array $config) {
            return new Codex(
                oauthService: $this->container->make(CodexOAuthService::class),
                url: $config['url'] ?? 'https://chatgpt.com/backend-api/codex',
                accountId: $config['account_id'] ?? null,
            );
        });
    }

    /** Wire Laravel facades to the container so Http, Config etc. resolve correctly. */
    private function registerFacades(): void
    {
        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $app = $this->container;
        Facade::setFacadeApplication($app);
    }

    /**
     * Register all agent-layer services: LLM clients (sync + async), tool registry,
     * session persistence, and the permission evaluator.
     */
    private function registerAgentServices(): void
    {
        $config = $this->container->make('config');
        $registry = $this->container->make(RelayRegistry::class);
        $providers = $this->container->make(ProviderCatalog::class);

        $log = $this->container->make(LoggerInterface::class);
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        $this->container->singleton(Relay::class, fn () => new Relay(
            promptCacheOrchestrator: new PromptCacheOrchestrator(
                prismManager: $this->container->make(PrismManager::class),
                geminiCacheStore: new GeminiCacheStore($home.'/.kosmokrator/cache/gemini-cache.json'),
            ),
        ));

        $this->container->singleton(PrismService::class, fn () => new RetryableLlmClient(
            new PrismService(
                provider: $config->get('kosmokrator.agent.default_provider', 'anthropic'),
                model: $config->get('kosmokrator.agent.default_model', 'claude-sonnet-4-20250514'),
                systemPrompt: $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.'),
                maxTokens: $config->get('kosmokrator.agent.max_tokens'),
                temperature: $config->get('kosmokrator.agent.temperature', 0.0),
                relay: $this->container->make(Relay::class),
                registry: $this->container->make(RelayRegistry::class),
            ),
            $log,
        ));
        $this->container->singleton(LlmClientInterface::class, fn () => $this->container->make(PrismService::class));

        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $providerUrl = rtrim($registry->url($provider), '/');
        $this->container->singleton(AsyncLlmClient::class, fn () => new RetryableLlmClient(
            new AsyncLlmClient(
                apiKey: $providers->apiKey($provider),
                baseUrl: $providerUrl,
                model: $config->get('kosmokrator.agent.default_model', 'GLM-5.1'),
                systemPrompt: $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.'),
                maxTokens: $config->get('kosmokrator.agent.max_tokens'),
                temperature: $config->get('kosmokrator.agent.temperature', 0.0),
                provider: $provider,
                relay: $this->container->make(Relay::class),
                registry: $this->container->make(RelayRegistry::class),
            ),
            $log,
        ));

        $this->container->singleton(ModelCatalog::class, fn () => new ModelCatalog(
            $config->get('models', []),
            $this->container->make(ProviderMeta::class),
        ));

        $bashTimeout = $config->get('kosmokrator.tools.bash.timeout', 120);
        $shellWaitMs = (int) $config->get('kosmokrator.tools.shell.wait_ms', 100);
        $shellIdleTtl = (int) $config->get('kosmokrator.tools.shell.idle_ttl', 300);

        $this->container->singleton(TaskStore::class);
        $this->container->singleton(PatchParser::class);
        $this->container->singleton(PatchApplier::class, fn () => new PatchApplier(
            $config->get('kosmokrator.tools.blocked_paths', []),
        ));
        $this->container->singleton(ShellSessionManager::class, fn () => new ShellSessionManager(
            $this->container->make(LoggerInterface::class),
            $shellWaitMs,
            $bashTimeout,
            $shellIdleTtl,
        ));

        $this->container->singleton(ToolRegistry::class, function () use ($bashTimeout) {
            $registry = new ToolRegistry;
            $registry->register(new FileReadTool);
            $registry->register(new FileWriteTool);
            $registry->register(new FileEditTool);
            $registry->register(new ApplyPatchTool(
                $this->container->make(PatchParser::class),
                $this->container->make(PatchApplier::class),
            ));
            $registry->register(new GlobTool);
            $registry->register(new GrepTool);
            $registry->register(new BashTool($bashTimeout));
            $registry->register(new ShellStartTool(
                $this->container->make(ShellSessionManager::class),
            ));
            $registry->register(new ShellWriteTool(
                $this->container->make(ShellSessionManager::class),
                $this->container->make(PermissionEvaluator::class),
            ));
            $registry->register(new ShellReadTool(
                $this->container->make(ShellSessionManager::class),
            ));
            $registry->register(new ShellKillTool(
                $this->container->make(ShellSessionManager::class),
            ));

            $taskStore = $this->container->make(TaskStore::class);
            $registry->register(new TaskCreateTool($taskStore));
            $registry->register(new TaskUpdateTool($taskStore));
            $registry->register(new TaskListTool($taskStore));
            $registry->register(new TaskGetTool($taskStore));

            $sessionManager = $this->container->make(SessionManager::class);
            $registry->register(new MemorySaveTool($sessionManager));
            $registry->register(new MemorySearchTool($sessionManager));

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
            configSettings: $this->container->make(SettingsManager::class),
        ));

        $this->container->singleton(SessionGrants::class);
        $this->container->singleton(PermissionEvaluator::class, function () use ($config) {
            $parser = new PermissionConfigParser;
            $parsed = $parser->parse($config);

            $projectRoot = InstructionLoader::gitRoot() ?? getcwd();
            $guardian = new GuardianEvaluator($projectRoot, $parsed['guardian_safe_commands']);
            $defaultMode = PermissionMode::tryFrom($parsed['default_permission_mode']) ?? PermissionMode::Guardian;

            $evaluator = new PermissionEvaluator(
                $parsed['rules'],
                $this->container->make(SessionGrants::class),
                $parsed['blocked_paths'],
                $guardian,
            );
            $evaluator->setPermissionMode($defaultMode);

            return $evaluator;
        });

        // Completion sound — compose music via LLM after each agent response
        $this->container->singleton(CompletionSound::class, function () use ($config) {
            $sessionId = $this->container->make(SessionManager::class)->currentSessionId() ?? 'default';

            // Resolve audio model override — if set, apply to a cloned LLM client
            $llm = $this->container->make(LlmClientInterface::class);
            $audioProvider = $config->get('kosmokrator.agent.audio_provider');
            $audioModel = $config->get('kosmokrator.agent.audio_model');

            if ($audioProvider !== null && $audioProvider !== '') {
                $llm->setProvider($audioProvider);
            }
            if ($audioModel !== null && $audioModel !== '') {
                $llm->setModel($audioModel);
            }

            return new CompletionSound(
                llm: $llm,
                log: $this->container->make(LoggerInterface::class),
                sessionId: $sessionId,
                enabled: $config->get('kosmokrator.audio.completion_sound', false),
                soundfont: str_replace('~', getenv('HOME') ?: sys_get_temp_dir(), $config->get('kosmokrator.audio.soundfont', '~/.kosmokrator/soundfonts/FluidR3_GM.sf2')),
                maxDuration: (int) $config->get('kosmokrator.audio.max_duration', 8),
                maxRetries: (int) $config->get('kosmokrator.audio.max_retries', 1),
                llmTimeoutSeconds: (int) $config->get('kosmokrator.audio.llm_timeout', 60),
            );
        });
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
