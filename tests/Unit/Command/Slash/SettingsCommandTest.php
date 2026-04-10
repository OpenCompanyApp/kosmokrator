<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Amp\Cancellation;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Command\Slash\SettingsCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\YamlCredentialResolver;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\UIManager;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use PHPUnit\Framework\TestCase;

final class SettingsCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir().'/kosmokrator-settings-test-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function test_execute_switches_runtime_provider_and_model_and_refreshes_ui(): void
    {
        $config = new Repository([]);
        $schema = new SettingsSchema;
        $settingsManager = new SettingsManager(
            config: $config,
            schema: $schema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );

        $registry = new RelayRegistry([
            'z' => [
                'label' => 'Z.AI',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'url' => 'https://z.example/v1',
                'default_model' => 'GLM-5.1',
                'models' => [
                    'GLM-5.1' => [
                        'display_name' => 'GLM-5.1',
                        'context' => 200000,
                        'max_output' => 8192,
                    ],
                ],
            ],
            'openai' => [
                'label' => 'OpenAI',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-5.4',
                'models' => [
                    'gpt-5.4' => [
                        'display_name' => 'GPT-5.4',
                        'context' => 400000,
                        'max_output' => 128000,
                    ],
                ],
            ],
        ]);

        $settingsRepository = $this->createStub(SettingsRepository::class);
        $settingsRepository->method('get')->willReturnMap([
            ['global', 'provider.z.api_key', 'z-key'],
            ['global', 'provider.openai.api_key', 'openai-key'],
        ]);

        $codexTokens = $this->createStub(CodexTokenStore::class);
        $codexTokens->method('current')->willReturn(null);

        $providerCatalog = new ProviderCatalog(
            new ProviderMeta($registry),
            $registry,
            $config,
            $settingsRepository,
            $codexTokens,
        );

        $container = new Container;
        $container->instance(SettingsSchema::class, $schema);
        $container->instance(SettingsManager::class, $settingsManager);
        $container->instance(RelayRegistry::class, $registry);

        $modelCatalog = $this->createMock(ModelCatalog::class);
        $modelCatalog->method('contextWindow')
            ->with('gpt-5.4')
            ->willReturn(400000);
        $container->instance(ModelCatalog::class, $modelCatalog);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showSettings')
            ->willReturn([
                'scope' => 'project',
                'changes' => [
                    'agent.default_provider' => 'openai',
                    'agent.default_model' => 'gpt-5.4',
                ],
                'custom_provider' => null,
                'delete_custom_provider' => '',
            ]);
        $ui->expects($this->once())
            ->method('refreshRuntimeSelection')
            ->with('openai', 'gpt-5.4', 400000);

        $agentLoop = $this->createStub(AgentLoop::class);
        $agentLoop->method('getMode')->willReturn(AgentMode::Edit);
        $agentLoop->method('getCompactor')->willReturn(null);
        $agentLoop->method('getPruner')->willReturn(null);

        $permissions = $this->createStub(PermissionEvaluator::class);
        $permissions->method('getPermissionMode')->willReturn(PermissionMode::Guardian);

        $sessionManager = $this->createStub(SessionManager::class);
        $sessionManager->method('getProject')->willReturn($this->projectDir);

        $llm = new class implements LlmClientInterface
        {
            public string $provider = 'z';

            public string $model = 'GLM-5.1';

            public string $apiKey = 'z-key';

            public string $baseUrl = 'https://z.example/v1';

            public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
            {
                throw new \RuntimeException('not used');
            }

            public function setSystemPrompt(string $prompt): void {}

            public function getProvider(): string
            {
                return $this->provider;
            }

            public function setProvider(string $provider): void
            {
                $this->provider = $provider;
            }

            public function getModel(): string
            {
                return $this->model;
            }

            public function setModel(string $model): void
            {
                $this->model = $model;
            }

            public function getTemperature(): int|float|null
            {
                return 0.0;
            }

            public function setTemperature(int|float|null $temperature): void {}

            public function getMaxTokens(): ?int
            {
                return null;
            }

            public function setMaxTokens(?int $maxTokens): void {}

            public function getReasoningEffort(): string
            {
                return 'off';
            }

            public function setReasoningEffort(string $effort): void {}

            public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): \Generator
            {
                yield from [];
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function setApiKey(string $apiKey): void
            {
                $this->apiKey = $apiKey;
            }

            public function setBaseUrl(string $baseUrl): void
            {
                $this->baseUrl = $baseUrl;
            }
        };

        $ctx = new SlashCommandContext(
            ui: $ui,
            agentLoop: $agentLoop,
            permissions: $permissions,
            sessionManager: $sessionManager,
            llm: $llm,
            taskStore: $this->createStub(TaskStore::class),
            config: $config,
            settings: $settingsRepository,
            providers: $providerCatalog,
            models: $modelCatalog,
        );

        $command = new SettingsCommand($container);
        $command->execute('', $ctx);

        $this->assertSame('openai', $llm->getProvider());
        $this->assertSame('gpt-5.4', $llm->getModel());
        $this->assertSame('openai-key', $llm->apiKey);
        $this->assertSame('https://api.openai.com/v1', $llm->baseUrl);
        $this->assertFileExists($this->projectDir.'/.kosmokrator/config.yaml');
    }

    public function test_settings_view_includes_integration_credentials_and_project_sources(): void
    {
        $config = new Repository([]);
        $schema = new SettingsSchema;
        $settingsManager = new SettingsManager(
            config: $config,
            schema: $schema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );
        $settingsManager->setProjectRoot($this->projectDir);
        $settingsManager->setRaw('integrations.github.enabled', true, 'project');
        $settingsManager->setRaw('integrations.github.permissions.read', 'deny', 'project');

        $settingsRepository = $this->memorySettingsRepository([
            'global' => [
                'integration.github.accounts.default.api_key' => 'ghp_secret_1234567890',
            ],
        ]);

        $registry = new ToolProviderRegistry;
        $registry->register($this->fakeIntegrationProvider());

        $container = $this->baseSettingsContainer($schema, $settingsManager, $settingsRepository, $registry);
        $providerCatalog = $this->providerCatalog($config, $settingsRepository);
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showSettings')
            ->with($this->callback(function (array $view): bool {
                $categories = $view['categories'] ?? [];
                $integrations = null;
                foreach ($categories as $category) {
                    if (($category['id'] ?? '') === 'integrations') {
                        $integrations = $category;
                        break;
                    }
                }

                $this->assertNotNull($integrations);
                $fields = $integrations['fields'] ?? [];

                $fieldById = [];
                foreach ($fields as $field) {
                    $fieldById[$field['id']] = $field;
                }

                $this->assertArrayHasKey('integration.github._summary', $fieldById);
                $this->assertArrayHasKey('integration.github.enabled', $fieldById);
                $this->assertArrayHasKey('integration.github.permissions.read', $fieldById);
                $this->assertArrayHasKey('integration.github.credential.api_key', $fieldById);
                $this->assertArrayHasKey('integration.github.credential_action', $fieldById);

                $this->assertSame('project', $fieldById['integration.github.enabled']['source']);
                $this->assertSame('project', $fieldById['integration.github.permissions.read']['source']);
                $this->assertSame('ghp_…7890', $fieldById['integration.github.credential.api_key']['value']);

                $integrationMeta = $view['integrations_by_id']['github'] ?? null;
                $this->assertIsArray($integrationMeta);
                $this->assertTrue($integrationMeta['configured']);
                $this->assertSame('allow', $integrationMeta['write_permission']);

                return true;
            }))
            ->willReturn([]);

        $ctx = $this->baseContext(
            ui: $ui,
            config: $config,
            settings: $settingsRepository,
            providers: $providerCatalog,
            models: $this->createStub(ModelCatalog::class),
        );

        $command = new SettingsCommand($container);
        $command->execute('', $ctx);
    }

    public function test_execute_stores_and_clears_integration_credentials(): void
    {
        $config = new Repository([]);
        $schema = new SettingsSchema;
        $settingsManager = new SettingsManager(
            config: $config,
            schema: $schema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );
        $settingsManager->setProjectRoot($this->projectDir);

        $settingsRepository = $this->memorySettingsRepository([
            'global' => [
                'provider.z.api_key' => 'z-key',
            ],
        ]);

        $registry = new ToolProviderRegistry;
        $registry->register($this->fakeIntegrationProvider());

        $container = $this->baseSettingsContainer($schema, $settingsManager, $settingsRepository, $registry);
        $providerCatalog = $this->providerCatalog($config, $settingsRepository);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->exactly(2))
            ->method('showSettings')
            ->willReturnOnConsecutiveCalls(
                [
                    'scope' => 'project',
                    'changes' => [
                        'integration.github.credential.api_key' => 'ghp_new_secret',
                        'integration.github.credential.base_url' => 'https://api.github.example',
                    ],
                    'custom_provider' => null,
                    'delete_custom_provider' => '',
                ],
                [
                    'scope' => 'project',
                    'changes' => [
                        'integration.github.credential_action' => 'clear_saved',
                    ],
                    'custom_provider' => null,
                    'delete_custom_provider' => '',
                ],
            );
        $ui->expects($this->exactly(2))
            ->method('showNotice');

        $ctx = $this->baseContext(
            ui: $ui,
            config: $config,
            settings: $settingsRepository,
            providers: $providerCatalog,
            models: $this->createStub(ModelCatalog::class),
        );

        $command = new SettingsCommand($container);
        $command->execute('', $ctx);
        $command->execute('', $ctx);

        $this->assertSame(
            [
                ['scope' => 'global', 'key' => 'integration.github.accounts', 'value' => json_encode(['default' => true])],
                ['scope' => 'global', 'key' => 'integration.github.accounts.default.api_key', 'value' => 'ghp_new_secret'],
                ['scope' => 'global', 'key' => 'integration.github.accounts.default.base_url', 'value' => 'https://api.github.example'],
            ],
            $settingsRepository->setCalls,
        );
        $this->assertSame(
            [
                ['scope' => 'global', 'key' => 'integration.github.accounts.default.api_key'],
                ['scope' => 'global', 'key' => 'integration.github.accounts.default.base_url'],
                ['scope' => 'global', 'key' => 'integration.github.accounts'],
            ],
            $settingsRepository->deleteCalls,
        );
    }

    public function test_settings_view_uses_human_name_when_provider_label_is_keyword_list(): void
    {
        $config = new Repository([]);
        $schema = new SettingsSchema;
        $settingsManager = new SettingsManager(
            config: $config,
            schema: $schema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );
        $settingsManager->setProjectRoot($this->projectDir);

        $settingsRepository = $this->memorySettingsRepository([
            'global' => [
                'provider.z.api_key' => 'z-key',
            ],
        ]);

        $registry = new ToolProviderRegistry;
        $registry->register(new ExchangeRateToolProvider);

        $container = $this->baseSettingsContainer($schema, $settingsManager, $settingsRepository, $registry);
        $providerCatalog = $this->providerCatalog($config, $settingsRepository);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showSettings')
            ->with($this->callback(function (array $view): bool {
                $integration = $view['integrations_by_id']['exchangerate'] ?? null;
                $this->assertIsArray($integration);
                $this->assertSame('Exchange Rate', $integration['name']);
                $this->assertSame('Exchange Rate', $integration['label']);
                $this->assertSame('Currency exchange rates', $integration['description']);

                return true;
            }))
            ->willReturn([]);

        $ctx = $this->baseContext(
            ui: $ui,
            config: $config,
            settings: $settingsRepository,
            providers: $providerCatalog,
            models: $this->createStub(ModelCatalog::class),
        );

        $command = new SettingsCommand($container);
        $command->execute('', $ctx);
    }

    private function providerCatalog(Repository $config, SettingsRepositoryInterface $settingsRepository): ProviderCatalog
    {
        $registry = new RelayRegistry([
            'z' => [
                'label' => 'Z.AI',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'url' => 'https://z.example/v1',
                'default_model' => 'GLM-5.1',
                'models' => [
                    'GLM-5.1' => [
                        'display_name' => 'GLM-5.1',
                        'context' => 200000,
                        'max_output' => 8192,
                    ],
                ],
            ],
        ]);

        $codexTokens = $this->createStub(CodexTokenStore::class);
        $codexTokens->method('current')->willReturn(null);

        return new ProviderCatalog(
            new ProviderMeta($registry),
            $registry,
            $config,
            $settingsRepository,
            $codexTokens,
        );
    }

    private function baseSettingsContainer(
        SettingsSchema $schema,
        SettingsManager $settingsManager,
        SettingsRepositoryInterface $settingsRepository,
        ToolProviderRegistry $registry,
    ): Container {
        $container = new Container;
        $container->instance(SettingsSchema::class, $schema);
        $container->instance(SettingsManager::class, $settingsManager);
        $container->instance(SettingsRepositoryInterface::class, $settingsRepository);
        $container->instance(ToolProviderRegistry::class, $registry);
        $container->instance(IntegrationManager::class, new IntegrationManager(
            providers: $registry,
            settings: $settingsManager,
            credentials: new YamlCredentialResolver($settingsRepository),
        ));
        $container->instance(RelayRegistry::class, new RelayRegistry([
            'z' => [
                'label' => 'Z.AI',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'url' => 'https://z.example/v1',
                'default_model' => 'GLM-5.1',
                'models' => [
                    'GLM-5.1' => [
                        'display_name' => 'GLM-5.1',
                        'context' => 200000,
                        'max_output' => 8192,
                    ],
                ],
            ],
        ]));

        return $container;
    }

    private function baseContext(
        UIManager $ui,
        Repository $config,
        SettingsRepositoryInterface $settings,
        ProviderCatalog $providers,
        ModelCatalog $models,
    ): SlashCommandContext {
        $agentLoop = $this->createStub(AgentLoop::class);
        $agentLoop->method('getMode')->willReturn(AgentMode::Edit);
        $agentLoop->method('getCompactor')->willReturn(null);
        $agentLoop->method('getPruner')->willReturn(null);

        $permissions = $this->createStub(PermissionEvaluator::class);
        $permissions->method('getPermissionMode')->willReturn(PermissionMode::Guardian);

        $sessionManager = $this->createStub(SessionManager::class);
        $sessionManager->method('getProject')->willReturn($this->projectDir);

        $llm = new class implements LlmClientInterface
        {
            public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
            {
                throw new \RuntimeException('not used');
            }

            public function setSystemPrompt(string $prompt): void {}

            public function getProvider(): string
            {
                return 'z';
            }

            public function setProvider(string $provider): void {}

            public function getModel(): string
            {
                return 'GLM-5.1';
            }

            public function setModel(string $model): void {}

            public function getTemperature(): int|float|null
            {
                return 0.0;
            }

            public function setTemperature(int|float|null $temperature): void {}

            public function getMaxTokens(): ?int
            {
                return null;
            }

            public function setMaxTokens(?int $maxTokens): void {}

            public function getReasoningEffort(): string
            {
                return 'off';
            }

            public function setReasoningEffort(string $effort): void {}

            public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): \Generator
            {
                yield from [];
            }

            public function supportsStreaming(): bool
            {
                return false;
            }
        };

        return new SlashCommandContext(
            ui: $ui,
            agentLoop: $agentLoop,
            permissions: $permissions,
            sessionManager: $sessionManager,
            llm: $llm,
            taskStore: $this->createStub(TaskStore::class),
            config: $config,
            settings: $settings,
            providers: $providers,
            models: $models,
        );
    }

    private function fakeIntegrationProvider(): ToolProvider
    {
        return new class implements ToolProvider
        {
            public function appName(): string
            {
                return 'github';
            }

            public function appMeta(): array
            {
                return [
                    'label' => 'GitHub',
                    'description' => 'GitHub repository and issue access',
                    'icon' => 'ph:github-logo',
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
                throw new \RuntimeException('not used');
            }

            public function luaDocsPath(): ?string
            {
                return null;
            }

            public function credentialFields(): array
            {
                return [
                    [
                        'key' => 'api_key',
                        'type' => 'secret',
                        'label' => 'API Key',
                        'required' => true,
                        'placeholder' => 'ghp_...',
                    ],
                    [
                        'key' => 'base_url',
                        'type' => 'url',
                        'label' => 'Base URL',
                        'required' => false,
                        'placeholder' => 'https://api.github.com',
                    ],
                ];
            }
        };
    }

    private function memorySettingsRepository(array $seed = []): SettingsRepositoryInterface
    {
        return new class($seed) implements SettingsRepositoryInterface
        {
            /** @var array<string, array<string, string>> */
            public array $data;

            /** @var list<array{scope: string, key: string, value: string}> */
            public array $setCalls = [];

            /** @var list<array{scope: string, key: string}> */
            public array $deleteCalls = [];

            public function __construct(array $seed)
            {
                $this->data = $seed;
            }

            public function get(string $scope, string $key): ?string
            {
                return $this->data[$scope][$key] ?? null;
            }

            public function set(string $scope, string $key, string $value): void
            {
                $this->setCalls[] = ['scope' => $scope, 'key' => $key, 'value' => $value];
                $this->data[$scope][$key] = $value;
            }

            public function all(string $scope): array
            {
                return $this->data[$scope] ?? [];
            }

            public function delete(string $scope, string $key): void
            {
                $this->deleteCalls[] = ['scope' => $scope, 'key' => $key];
                unset($this->data[$scope][$key]);
            }

            public function resolve(string $key, string $projectScope): ?string
            {
                return $this->data[$projectScope][$key] ?? $this->data['global'][$key] ?? null;
            }
        };
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}

final class ExchangeRateToolProvider implements ToolProvider
{
    public function appName(): string
    {
        return 'exchangerate';
    }

    public function appMeta(): array
    {
        return [
            'label' => 'currency, exchange rate, forex, conversion, USD, EUR, crypto',
            'description' => 'Currency exchange rates',
            'icon' => 'ph:currency-circle-dollar',
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
        throw new \RuntimeException('not used');
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
