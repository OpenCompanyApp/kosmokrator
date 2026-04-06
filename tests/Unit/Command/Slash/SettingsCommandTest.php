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
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\UIManager;
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

        $settingsRepository = $this->createMock(SettingsRepository::class);
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

        $agentLoop = $this->createMock(AgentLoop::class);
        $agentLoop->method('getMode')->willReturn(AgentMode::Edit);
        $agentLoop->method('getCompactor')->willReturn(null);
        $agentLoop->method('getPruner')->willReturn(null);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('getPermissionMode')->willReturn(PermissionMode::Guardian);

        $sessionManager = $this->createMock(SessionManager::class);
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
