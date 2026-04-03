<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\LLM\AsyncLlmClient;
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
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Permission\SessionGrants;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\UIManager;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AgentSessionBuilderTest extends TestCase
{
    private ?string $originalHome = null;

    private string $fakeHome;

    protected function setUp(): void
    {
        $this->originalHome = getenv('HOME') ?: null;
        $this->fakeHome = sys_get_temp_dir().'/kosmokrator_builder_test_'.uniqid();
        mkdir($this->fakeHome.'/.kosmokrator/logs', 0755, true);
        putenv("HOME={$this->fakeHome}");
        $_ENV['HOME'] = $this->fakeHome;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== null) {
            putenv("HOME={$this->originalHome}");
            $_ENV['HOME'] = $this->originalHome;
        } else {
            putenv('HOME');
            unset($_ENV['HOME']);
        }

        $home = getenv('HOME') ?: '';
        if (str_contains($home, 'kosmokrator_builder_test_')) {
            $this->removeDir($home);
        }

        Container::setInstance(null);
    }

    public function test_constructor_accepts_container(): void
    {
        $builder = new AgentSessionBuilder(new Container);

        $this->assertInstanceOf(AgentSessionBuilder::class, $builder);
    }

    public function test_build_throws_runtime_exception_when_api_key_is_empty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No API key configured');

        $container = $this->makeWiredContainer(provider: 'openai', apiKey: '');

        $builder = new AgentSessionBuilder($container);
        $builder->build('ansi', false);
    }

    public function test_build_throws_runtime_exception_when_oauth_not_configured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex is not authenticated');

        // RelayRegistry must include 'codex' so ProviderCatalog resolves its auth mode as 'oauth'
        $relayRegistry = new RelayRegistry([
            'codex' => [
                'url' => 'https://chatgpt.com/backend-api/codex',
                'models' => [
                    'gpt-5-codex' => ['display_name' => 'GPT-5 Codex', 'context' => 128000],
                ],
            ],
        ]);

        $container = $this->makeWiredContainer(
            provider: 'codex',
            apiKey: '',
            codexConfigured: false,
            relayRegistry: $relayRegistry,
        );

        $builder = new AgentSessionBuilder($container);
        $builder->build('ansi', false);
    }

    public function test_build_returns_agent_session_with_all_components(): void
    {
        $container = $this->makeWiredContainer();

        $builder = new AgentSessionBuilder($container);
        $session = $builder->build('ansi', false);

        $this->assertInstanceOf(AgentSession::class, $session);
        $this->assertInstanceOf(UIManager::class, $session->ui);
        $this->assertInstanceOf(AgentLoop::class, $session->agentLoop);
        $this->assertInstanceOf(LlmClientInterface::class, $session->llm);
        $this->assertInstanceOf(PermissionEvaluator::class, $session->permissions);
        $this->assertInstanceOf(SessionManager::class, $session->sessionManager);
        $this->assertInstanceOf(SubagentOrchestrator::class, $session->orchestrator);
    }

    public function test_build_uses_ansi_renderer_for_ansi_preference(): void
    {
        $container = $this->makeWiredContainer();

        $builder = new AgentSessionBuilder($container);
        $session = $builder->build('ansi', false);

        $this->assertSame('ansi', $session->ui->getActiveRenderer());
    }

    public function test_build_with_animated_true_returns_valid_session(): void
    {
        $container = $this->makeWiredContainer();

        $builder = new AgentSessionBuilder($container);
        $session = $builder->build('ansi', true);

        $this->assertInstanceOf(AgentSession::class, $session);
    }

    public function test_build_with_animated_false_returns_valid_session(): void
    {
        $container = $this->makeWiredContainer();

        $builder = new AgentSessionBuilder($container);
        $session = $builder->build('ansi', false);

        $this->assertInstanceOf(AgentSession::class, $session);
    }

    public function test_build_returns_session_with_non_null_orchestrator(): void
    {
        $container = $this->makeWiredContainer();

        $builder = new AgentSessionBuilder($container);
        $session = $builder->build('ansi', false);

        $this->assertNotNull($session->orchestrator);
        $this->assertInstanceOf(SubagentOrchestrator::class, $session->orchestrator);
    }

    public function test_build_wires_retry_callback_when_llm_is_retryable(): void
    {
        $innerLlm = $this->makeLlmStub();
        $retryable = new RetryableLlmClient($innerLlm, new NullLogger);

        $container = $this->makeWiredContainer(llm: $retryable);

        $builder = new AgentSessionBuilder($container);
        $session = $builder->build('ansi', false);

        $this->assertInstanceOf(AgentSession::class, $session);
    }

    public function test_build_applies_persisted_temperature_from_session(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getModel')->willReturn('test-model');
        $llm->method('getMaxTokens')->willReturn(4096);
        $llm->method('getTemperature')->willReturn(0.0);
        $llm->method('getProvider')->willReturn('test-provider');
        $llm->expects($this->once())->method('setTemperature')->with(0.7);

        // Inject setting at global scope so it survives project scope change
        $container = $this->makeWiredContainer(llm: $llm, globalSettings: ['temperature' => '0.7']);

        $builder = new AgentSessionBuilder($container);
        $builder->build('ansi', false);
    }

    public function test_build_applies_persisted_max_tokens_from_session(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getModel')->willReturn('test-model');
        $llm->method('getMaxTokens')->willReturn(4096);
        $llm->method('getTemperature')->willReturn(0.0);
        $llm->method('getProvider')->willReturn('test-provider');
        $llm->expects($this->once())->method('setMaxTokens')->with(8192);

        $container = $this->makeWiredContainer(llm: $llm, globalSettings: ['max_tokens' => '8192']);

        $builder = new AgentSessionBuilder($container);
        $builder->build('ansi', false);
    }

    public function test_build_applies_persisted_permission_mode_from_session(): void
    {
        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('getPermissionMode')->willReturn(PermissionMode::Guardian);
        $permissions->expects($this->once())
            ->method('setPermissionMode')
            ->with($this->equalTo(PermissionMode::Prometheus));

        $container = $this->makeWiredContainer(
            permissions: $permissions,
            globalSettings: ['permission_mode' => 'prometheus'],
        );

        $builder = new AgentSessionBuilder($container);
        $builder->build('ansi', false);
    }

    public function test_build_applies_auto_approve_backward_compat(): void
    {
        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('getPermissionMode')->willReturn(PermissionMode::Guardian);
        $permissions->expects($this->once())
            ->method('setPermissionMode')
            ->with($this->equalTo(PermissionMode::Prometheus));

        $container = $this->makeWiredContainer(
            permissions: $permissions,
            globalSettings: ['auto_approve' => 'on'],
        );

        $builder = new AgentSessionBuilder($container);
        $builder->build('ansi', false);
    }

    public function test_build_does_not_set_permission_mode_when_none_persisted(): void
    {
        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('getPermissionMode')->willReturn(PermissionMode::Guardian);
        $permissions->expects($this->never())->method('setPermissionMode');

        $container = $this->makeWiredContainer(permissions: $permissions);

        $builder = new AgentSessionBuilder($container);
        $builder->build('ansi', false);
    }

    public function test_build_uses_sync_client_for_ansi_renderer(): void
    {
        $syncLlm = $this->makeLlmStub();
        $asyncLlm = $this->makeLlmStub();

        $container = $this->makeWiredContainer(llm: $syncLlm, asyncLlm: $asyncLlm);

        $builder = new AgentSessionBuilder($container);
        $session = $builder->build('ansi', false);

        // ANSI renderer → should use PrismService (sync), not AsyncLlmClient
        $this->assertSame($syncLlm, $session->llm);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a fully-wired container with real or stub dependencies.
     *
     * @param array<string, string> $globalSettings Settings to pre-populate in the global scope
     */
    private function makeWiredContainer(
        ?LlmClientInterface $llm = null,
        ?LlmClientInterface $asyncLlm = null,
        string $provider = 'ollama',
        string $apiKey = 'unused-for-ollama',
        bool $codexConfigured = true,
        ?PermissionEvaluator $permissions = null,
        array $globalSettings = [],
        ?RelayRegistry $relayRegistry = null,
    ): Container {
        $container = new Container;

        // Config
        $config = new ConfigRepository([
            'kosmokrator' => [
                'agent' => [
                    'default_provider' => $provider,
                    'system_prompt' => 'You are a helpful coding assistant.',
                    'max_retries' => 0,
                    'subagent_max_depth' => 2,
                    'subagent_concurrency' => 5,
                    'subagent_max_retries' => 1,
                    'subagent_watchdog_seconds' => 60,
                    'subagent_watchdog_rounds' => 10,
                ],
                'context' => [
                    'compact_threshold' => 60,
                    'reserve_output_tokens' => 16_000,
                    'warning_buffer_tokens' => 24_000,
                    'auto_compact_buffer_tokens' => 12_000,
                    'blocking_buffer_tokens' => 3_000,
                    'max_output_lines' => 2000,
                    'max_output_bytes' => 50_000,
                    'prune_protect' => 40_000,
                    'prune_min_savings' => 20_000,
                    'memory_warning_mb' => 50,
                ],
            ],
            'prism' => [
                'providers' => [
                    $provider => [
                        'api_key' => $apiKey,
                        'url' => 'https://api.test.example.com',
                    ],
                ],
            ],
            'models' => [],
        ]);
        $container->instance('config', $config);

        // RelayRegistry
        $relayRegistry ??= new RelayRegistry([]);
        $container->instance(RelayRegistry::class, $relayRegistry);

        // ProviderCatalog (final class — must construct a real instance)
        $providerMeta = new ProviderMeta($relayRegistry);
        $sessionDb = new SessionDatabase;
        $settingsRepo = new SettingsRepository($sessionDb);

        // Inject API key into settings for api_key auth providers
        if ($apiKey !== '' && $apiKey !== null) {
            $settingsRepo->set('global', "provider.{$provider}.api_key", $apiKey);
        }

        // Inject global settings for tests that verify settings application
        foreach ($globalSettings as $key => $value) {
            $settingsRepo->set('global', $key, $value);
        }

        $codexTokenStore = $this->createStub(CodexTokenStore::class);
        $providerCatalog = new ProviderCatalog($providerMeta, $relayRegistry, $config, $settingsRepo, $codexTokenStore);
        $container->instance(ProviderCatalog::class, $providerCatalog);

        // LLM clients
        $llm ??= $this->makeLlmStub();
        $container->instance(PrismService::class, $llm);
        $container->instance(AsyncLlmClient::class, $asyncLlm ?? $llm);

        // Logger
        $container->instance(LoggerInterface::class, new NullLogger);

        // Session infrastructure — use the same settingsRepo so global settings survive
        $sessionRepository = new SessionRepository($sessionDb);
        $messageRepository = new MessageRepository($sessionDb);
        $memoryRepository = new MemoryRepository($sessionDb);
        $sessionManager = new SessionManager(
            $sessionRepository,
            $messageRepository,
            $settingsRepo,
            $memoryRepository,
            new NullLogger,
        );
        $container->instance(SessionManager::class, $sessionManager);

        // Task store
        $container->instance(TaskStore::class, new TaskStore);

        // Model catalog
        $container->instance(ModelCatalog::class, new ModelCatalog([], $providerMeta));

        // Permissions
        $permissions ??= new PermissionEvaluator([], new SessionGrants);
        $container->instance(PermissionEvaluator::class, $permissions);

        // Tool registry
        $container->instance(ToolRegistry::class, new ToolRegistry);

        // Relay
        $container->instance(Relay::class, $this->createStub(Relay::class));

        // CodexOAuthService
        $codexOAuth = $this->createStub(CodexOAuthService::class);
        $codexOAuth->method('isConfigured')->willReturn($codexConfigured);
        $container->instance(CodexOAuthService::class, $codexOAuth);

        return $container;
    }

    private function makeLlmStub(): LlmClientInterface
    {
        $llm = $this->createStub(LlmClientInterface::class);
        $llm->method('getModel')->willReturn('test-model');
        $llm->method('getMaxTokens')->willReturn(4096);
        $llm->method('getTemperature')->willReturn(0.0);
        $llm->method('getProvider')->willReturn('test-provider');

        return $llm;
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
