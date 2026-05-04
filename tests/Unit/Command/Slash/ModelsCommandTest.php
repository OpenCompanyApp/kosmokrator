<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Amp\Cancellation;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Command\Slash\ModelsCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\NullRenderer;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use PHPUnit\Framework\TestCase;

final class ModelsCommandTest extends TestCase
{
    private string $projectDir;

    private string $homeDir;

    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir().'/kosmokrator-models-project-'.bin2hex(random_bytes(4));
        $this->homeDir = sys_get_temp_dir().'/kosmokrator-models-home-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir, 0777, true);
        mkdir($this->homeDir.'/.kosmo', 0777, true);
        $this->originalHome = (string) getenv('HOME');
        putenv("HOME={$this->homeDir}");
    }

    protected function tearDown(): void
    {
        putenv("HOME={$this->originalHome}");
        $this->removeDirectory($this->projectDir);
        $this->removeDirectory($this->homeDir);
        parent::tearDown();
    }

    public function test_execute_switches_runtime_and_persists_recent_selection(): void
    {
        [$container, $catalog, $settingsManager, $settingsRepo] = $this->makeEnvironment();
        $renderer = new RecordingRenderer;
        $llm = new FakeLlmClient('z', 'GLM-5.1');

        $modelCatalog = $this->createMock(ModelCatalog::class);
        $modelCatalog->method('contextWindow')
            ->with('gpt-5.4')
            ->willReturn(400000);

        $ctx = $this->makeContext($renderer, $llm, $settingsRepo, $catalog, $modelCatalog);

        $command = new ModelsCommand($container);
        $command->execute('openai:gpt-5.4', $ctx);

        $this->assertSame('openai', $llm->getProvider());
        $this->assertSame('gpt-5.4', $llm->getModel());
        $this->assertSame('https://api.openai.com/v1', $llm->baseUrl);
        $this->assertSame('openai-test-key', $llm->apiKey);
        $this->assertSame('openai', $settingsManager->get('agent.default_provider'));
        $this->assertSame('gpt-5.4', $settingsManager->get('agent.default_model'));
        $this->assertSame('gpt-5.4', $settingsManager->getProviderLastModel('openai'));
        $this->assertSame(['provider' => 'openai', 'model' => 'gpt-5.4', 'maxContext' => 400000], $renderer->lastRefresh);
        $this->assertStringContainsString('Switched to OpenAI · gpt-5.4.', end($renderer->notices));

        $recentModels = json_decode((string) $settingsRepo->get('global', 'kosmo.model_switcher.recent_models'), true, 512, JSON_THROW_ON_ERROR);
        $recentProviders = json_decode((string) $settingsRepo->get('global', 'kosmo.model_switcher.recent_providers'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([['provider' => 'openai', 'model' => 'gpt-5.4']], $recentModels);
        $this->assertSame(['openai'], $recentProviders);
    }

    public function test_execute_without_args_shows_curated_sections_in_order(): void
    {
        [$container, $catalog, , $settingsRepo] = $this->makeEnvironment();
        $renderer = new RecordingRenderer('dismissed');
        $llm = new FakeLlmClient('openai', 'gpt-5.4');

        $settingsRepo->set('global', 'kosmo.model_switcher.recent_models', (string) json_encode([
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
            ['provider' => 'openai', 'model' => 'gpt-5.4'],
        ], JSON_THROW_ON_ERROR));
        $settingsRepo->set('global', 'kosmo.model_switcher.recent_providers', (string) json_encode([
            'anthropic',
            'openai',
        ], JSON_THROW_ON_ERROR));

        $ctx = $this->makeContext(
            $renderer,
            $llm,
            $settingsRepo,
            $catalog,
            $this->createStub(ModelCatalog::class),
        );

        $command = new ModelsCommand($container);
        $command->execute('', $ctx);

        $notice = end($renderer->notices);
        self::assertIsString($notice);
        $this->assertStringContainsString('Current: OpenAI · gpt-5.4', $notice);
        $this->assertStringContainsString('Recent used models:', $notice);
        $this->assertStringContainsString('Anthropic · claude-sonnet-4-20250514', $notice);
        $this->assertStringContainsString('Current provider:', $notice);
        $this->assertStringContainsString('Recent provider:', $notice);
        $this->assertStringContainsString('Full provider and model inventory stays in /settings.', $notice);
        $this->assertLessThan(
            strpos($notice, 'Current provider:'),
            strpos($notice, 'Recent used models:'),
        );
        $this->assertLessThan(
            strpos($notice, 'Recent provider:'),
            strpos($notice, 'Current provider:'),
        );
    }

    public function test_execute_provider_only_uses_recent_model_for_that_provider(): void
    {
        [$container, $catalog, $settingsManager, $settingsRepo] = $this->makeEnvironment();
        $renderer = new RecordingRenderer;
        $llm = new FakeLlmClient('z', 'GLM-5.1');

        $settingsRepo->set('global', 'kosmo.model_switcher.recent_models', (string) json_encode([
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
        ], JSON_THROW_ON_ERROR));
        $settingsRepo->set('global', 'kosmo.model_switcher.recent_providers', (string) json_encode([
            'anthropic',
        ], JSON_THROW_ON_ERROR));

        $modelCatalog = $this->createMock(ModelCatalog::class);
        $modelCatalog->method('contextWindow')
            ->with('claude-sonnet-4-20250514')
            ->willReturn(200000);

        $ctx = $this->makeContext($renderer, $llm, $settingsRepo, $catalog, $modelCatalog);

        $command = new ModelsCommand($container);
        $command->execute('anthropic', $ctx);

        $this->assertSame('anthropic', $llm->getProvider());
        $this->assertSame('claude-sonnet-4-20250514', $llm->getModel());
        $this->assertSame('anthropic-test-key', $llm->apiKey);
        $this->assertSame('anthropic', $settingsManager->get('agent.default_provider'));
        $this->assertSame('claude-sonnet-4-20250514', $settingsManager->get('agent.default_model'));
    }

    /**
     * @return array{0: Container, 1: ProviderCatalog, 2: SettingsManager, 3: InMemorySettingsRepository}
     */
    private function makeEnvironment(): array
    {
        $config = new Repository([
            'kosmo' => [
                'agent' => [
                    'default_provider' => 'z',
                    'default_model' => 'GLM-5.1',
                ],
            ],
        ]);
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
                    'GLM-5.1' => ['display_name' => 'GLM-5.1', 'context' => 200000, 'max_output' => 8192],
                    'GLM-4.5-Air' => ['display_name' => 'GLM-4.5 Air', 'context' => 128000, 'max_output' => 8192],
                ],
            ],
            'openai' => [
                'label' => 'OpenAI',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-5.4',
                'models' => [
                    'gpt-5.4' => ['display_name' => 'GPT-5.4', 'context' => 400000, 'max_output' => 128000],
                    'gpt-5.4-mini' => ['display_name' => 'GPT-5.4 Mini', 'context' => 128000, 'max_output' => 32768],
                ],
            ],
            'anthropic' => [
                'label' => 'Anthropic',
                'auth' => 'api_key',
                'driver' => 'anthropic',
                'url' => 'https://api.anthropic.com',
                'default_model' => 'claude-sonnet-4-20250514',
                'models' => [
                    'claude-sonnet-4-20250514' => ['display_name' => 'Claude Sonnet 4', 'context' => 200000, 'max_output' => 8192],
                    'claude-opus-4-20250514' => ['display_name' => 'Claude Opus 4', 'context' => 200000, 'max_output' => 8192],
                ],
            ],
        ]);

        $settingsRepo = new InMemorySettingsRepository([
            'global' => [
                'provider.z.api_key' => 'z-test-key',
                'provider.openai.api_key' => 'openai-test-key',
                'provider.anthropic.api_key' => 'anthropic-test-key',
            ],
        ]);

        $codexTokens = $this->createStub(CodexTokenStore::class);
        $codexTokens->method('current')->willReturn(null);

        $providerCatalog = new ProviderCatalog(
            new ProviderMeta($registry),
            $registry,
            $config,
            $settingsRepo,
            $codexTokens,
        );

        $container = new Container;
        $container->instance(SettingsManager::class, $settingsManager);
        $container->instance(RelayRegistry::class, $registry);

        return [$container, $providerCatalog, $settingsManager, $settingsRepo];
    }

    private function makeContext(
        RecordingRenderer $renderer,
        FakeLlmClient $llm,
        InMemorySettingsRepository $settingsRepo,
        ProviderCatalog $providerCatalog,
        ModelCatalog $modelCatalog,
    ): SlashCommandContext {
        $sessionManager = $this->createStub(SessionManager::class);
        $sessionManager->method('getProject')->willReturn($this->projectDir);

        return new SlashCommandContext(
            ui: $renderer,
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $sessionManager,
            llm: $llm,
            taskStore: $this->createStub(TaskStore::class),
            config: new Repository([]),
            settings: $settingsRepo,
            providers: $providerCatalog,
            models: $modelCatalog,
        );
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
            } elseif (file_exists($child)) {
                unlink($child);
            }
        }

        rmdir($path);
    }
}

final class InMemorySettingsRepository implements SettingsRepositoryInterface
{
    /**
     * @param  array<string, array<string, string>>  $data
     */
    public function __construct(private array $data = []) {}

    public function get(string $scope, string $key): ?string
    {
        return $this->data[$scope][$key] ?? null;
    }

    public function set(string $scope, string $key, string $value): void
    {
        $this->data[$scope] ??= [];
        $this->data[$scope][$key] = $value;
    }

    public function all(string $scope): array
    {
        return $this->data[$scope] ?? [];
    }

    public function delete(string $scope, string $key): void
    {
        unset($this->data[$scope][$key]);
    }

    public function resolve(string $key, string $projectScope): ?string
    {
        return $this->data[$projectScope][$key] ?? $this->data['global'][$key] ?? null;
    }
}

final class RecordingRenderer extends NullRenderer
{
    /** @var list<string> */
    public array $notices = [];

    /** @var array{provider: string, model: string, maxContext: int}|null */
    public ?array $lastRefresh = null;

    public function __construct(
        private readonly string $choice = 'dismissed',
    ) {}

    public function showNotice(string $message): void
    {
        $this->notices[] = $message;
    }

    public function askChoice(string $question, array $choices): string
    {
        return $this->choice;
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $this->lastRefresh = [
            'provider' => $provider,
            'model' => $model,
            'maxContext' => $maxContext,
        ];
    }
}

final class FakeLlmClient implements LlmClientInterface
{
    public string $apiKey = '';

    public string $baseUrl = '';

    public function __construct(
        private string $provider,
        private string $model,
    ) {}

    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        throw new \RuntimeException('not used');
    }

    public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): \Generator
    {
        yield from [];
    }

    public function supportsStreaming(): bool
    {
        return true;
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
        return null;
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

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }
}
