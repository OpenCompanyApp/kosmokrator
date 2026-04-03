<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

final class SlashCommandContextTest extends TestCase
{
    private UIManager $ui;
    private AgentLoop $agentLoop;
    private PermissionEvaluator $permissions;
    private SessionManager $sessionManager;
    private LlmClientInterface $llm;
    private TaskStore $taskStore;
    private Repository $config;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $this->ui = $this->createMock(UIManager::class);
        $this->agentLoop = $this->createMock(AgentLoop::class);
        $this->permissions = $this->createMock(PermissionEvaluator::class);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->taskStore = $this->createMock(TaskStore::class);
        $this->config = $this->createMock(Repository::class);
        $this->settings = $this->createMock(SettingsRepository::class);
    }

    private function createContext(mixed ...$overrides): SlashCommandContext
    {
        return new SlashCommandContext(
            ui: $overrides['ui'] ?? $this->ui,
            agentLoop: $overrides['agentLoop'] ?? $this->agentLoop,
            permissions: $overrides['permissions'] ?? $this->permissions,
            sessionManager: $overrides['sessionManager'] ?? $this->sessionManager,
            llm: $overrides['llm'] ?? $this->llm,
            taskStore: $overrides['taskStore'] ?? $this->taskStore,
            config: $overrides['config'] ?? $this->config,
            settings: $overrides['settings'] ?? $this->settings,
        );
    }

    public function testConstructorWithRequiredArgsOnly(): void
    {
        $context = $this->createContext();

        $this->assertInstanceOf(SlashCommandContext::class, $context);
    }

    public function testOptionalArgsDefaultToNull(): void
    {
        $context = $this->createContext();

        $this->assertNull($context->orchestrator);
        $this->assertNull($context->models);
        $this->assertNull($context->providers);
    }

    public function testOptionalArgsCanBeSet(): void
    {
        $orchestrator = $this->createMock(SubagentOrchestrator::class);
        $models = $this->createMock(ModelCatalog::class);

        $context = new SlashCommandContext(
            ui: $this->ui,
            agentLoop: $this->agentLoop,
            permissions: $this->permissions,
            sessionManager: $this->sessionManager,
            llm: $this->llm,
            taskStore: $this->taskStore,
            config: $this->config,
            settings: $this->settings,
            orchestrator: $orchestrator,
            models: $models,
        );

        $this->assertSame($orchestrator, $context->orchestrator);
        $this->assertSame($models, $context->models);
        // ProviderCatalog is final and cannot be mocked; verify it still defaults to null
        $this->assertNull($context->providers);
    }

    public function testAllPropertiesAreAccessible(): void
    {
        $context = $this->createContext();

        $this->assertSame($this->ui, $context->ui);
        $this->assertSame($this->agentLoop, $context->agentLoop);
        $this->assertSame($this->permissions, $context->permissions);
        $this->assertSame($this->sessionManager, $context->sessionManager);
        $this->assertSame($this->llm, $context->llm);
        $this->assertSame($this->taskStore, $context->taskStore);
        $this->assertSame($this->config, $context->config);
        $this->assertSame($this->settings, $context->settings);
    }
}
