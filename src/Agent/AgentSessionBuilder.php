<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Illuminate\Container\Container;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\AskChoiceTool;
use Kosmokrator\Tool\AskUserTool;
use Kosmokrator\Tool\Coding\SubagentTool;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\UIManager;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use Psr\Log\LoggerInterface;

/**
 * Builds all components needed for an interactive agent session.
 *
 * Extracts the complex initialization logic from AgentCommand into a
 * testable builder that wires up LLM clients, permissions, tools,
 * and subagent infrastructure.
 */
final class AgentSessionBuilder
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Build a fully-wired agent session.
     *
     * @param  string  $rendererPref  Renderer preference ('tui', 'ansi', or 'auto')
     * @param  bool  $animated  Whether to show the animated intro
     * @return AgentSession All components needed for the REPL
     *
     * @throws \RuntimeException If API key is not configured
     */
    public function build(string $rendererPref, bool $animated): AgentSession
    {
        $config = $this->container->make('config');

        // Initialize UI
        $ui = new UIManager($rendererPref);
        $ui->initialize();
        $ui->renderIntro($animated);
        $ui->showWelcome();

        // Validate API key
        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $providers = $this->container->make(ProviderCatalog::class);
        $authMode = $providers->authMode($provider);

        if ($authMode === 'oauth') {
            $oauth = $this->container->make(CodexOAuthService::class);
            if (! $oauth->isConfigured()) {
                throw new \RuntimeException('Codex is not authenticated. Run `kosmokrator codex:login`.');
            }
        } elseif ($authMode === 'api_key') {
            $apiKey = $providers->apiKey($provider);

            if ($apiKey === '' || $apiKey === null) {
                throw new \RuntimeException("No API key configured for {$provider}.");
            }
        }

        $log = $this->container->make(LoggerInterface::class);
        $log->info('KosmoKrator started', ['renderer' => $ui->getActiveRenderer(), 'provider' => $provider]);

        // Create LLM client (async for TUI, sync for ANSI)
        $registry = $this->container->make(RelayRegistry::class);
        $useAsyncClient = $ui->getActiveRenderer() === 'tui' && $registry->supportsAsync($provider);

        /** @var LlmClientInterface $llm */
        $llm = $useAsyncClient
            ? $this->container->make(AsyncLlmClient::class)
            : $this->container->make(PrismService::class);

        // Wire retry UI notification
        if ($llm instanceof RetryableLlmClient) {
            $llm->setOnRetry(function (int $attempt, float $delay, string $reason) use ($ui) {
                $delaySec = (int) ceil($delay);
                $ui->showNotice("⟳ Retrying in {$delaySec}s (attempt {$attempt})");
            });
        }

        // Tools and permissions
        $toolRegistry = $this->container->make(ToolRegistry::class);
        $toolRegistry->register(new AskUserTool($ui));
        $toolRegistry->register(new AskChoiceTool($ui));
        $permissions = $this->container->make(PermissionEvaluator::class);
        $models = $this->container->make(ModelCatalog::class);

        // Session manager and project scope
        $sessionManager = $this->container->make(SessionManager::class);
        $project = InstructionLoader::gitRoot() ?? getcwd();
        $sessionManager->setProject($project);

        // Load persisted settings
        $this->applyPersistedSettings($sessionManager, $llm, $permissions);

        // Wire configurable max retries from settings/config
        if ($llm instanceof RetryableLlmClient) {
            $maxRetries = (int) ($sessionManager->getSetting('max_retries')
                ?? $config->get('kosmokrator.agent.max_retries', 0));
            $llm->setMaxAttempts($maxRetries);
        }

        // Set initial permission mode on UI
        $permMode = $permissions->getPermissionMode();
        $ui->setPermissionMode($permMode->statusLabel(), $permMode->color());

        // Build system prompt: base + instructions + environment. Memories are selected dynamically per turn.
        $baseSystemPrompt = $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.')
            .InstructionLoader::gather()
            .EnvironmentContext::gather();

        // Task store
        $taskStore = $this->container->make(TaskStore::class);
        $ui->setTaskStore($taskStore);

        // Context management components
        $autoCompactEnabled = ($sessionManager->getSetting('auto_compact') ?? 'on') !== 'off';
        $compactThreshold = (int) ($sessionManager->getSetting('compact_threshold')
            ?? $config->get('kosmokrator.context.compact_threshold', 60));
        $contextBudget = new ContextBudget(
            models: $models,
            reserveOutputTokens: (int) ($sessionManager->getSetting('context_reserve_output_tokens')
                ?? $config->get('kosmokrator.context.reserve_output_tokens', 16_000)),
            warningBufferTokens: (int) ($sessionManager->getSetting('context_warning_buffer_tokens')
                ?? $config->get('kosmokrator.context.warning_buffer_tokens', 24_000)),
            autoCompactBufferTokens: (int) ($sessionManager->getSetting('context_auto_compact_buffer_tokens')
                ?? $config->get('kosmokrator.context.auto_compact_buffer_tokens', 12_000)),
            blockingBufferTokens: (int) ($sessionManager->getSetting('context_blocking_buffer_tokens')
                ?? $config->get('kosmokrator.context.blocking_buffer_tokens', 3_000)),
        );
        $compactor = $autoCompactEnabled ? new ContextCompactor($llm, $models, $log, $compactThreshold, $contextBudget) : null;

        $truncator = new OutputTruncator(
            maxLines: (int) $config->get('kosmokrator.context.max_output_lines', 2000),
            maxBytes: (int) $config->get('kosmokrator.context.max_output_bytes', 50_000),
        );

        $pruneProtect = (int) ($sessionManager->getSetting('prune_protect') ?? $config->get('kosmokrator.context.prune_protect', 40_000));
        $pruneMinSavings = (int) ($sessionManager->getSetting('prune_min_savings') ?? $config->get('kosmokrator.context.prune_min_savings', 20_000));
        $pruner = new ContextPruner($pruneProtect, $pruneMinSavings);
        $deduplicator = new ToolResultDeduplicator;
        $protectedContextBuilder = new ProtectedContextBuilder($taskStore);

        $memoryWarningThreshold = (int) $config->get('kosmokrator.context.memory_warning_mb', 50) * 1024 * 1024;

        // Create AgentLoop
        $agentLoop = new AgentLoop($llm, $ui, $log, $baseSystemPrompt, $permissions, $models, $taskStore, $sessionManager, $compactor, $truncator, $pruner, $deduplicator, $contextBudget, $protectedContextBuilder, $memoryWarningThreshold);

        // Subagent system
        $maxDepth = (int) ($sessionManager->getSetting('subagent_max_depth')
            ?? $config->get('kosmokrator.agent.subagent_max_depth', 3));
        $concurrency = (int) ($sessionManager->getSetting('subagent_concurrency')
            ?? $config->get('kosmokrator.agent.subagent_concurrency', 10));
        $subagentMaxRetries = (int) ($sessionManager->getSetting('subagent_max_retries')
            ?? $config->get('kosmokrator.agent.subagent_max_retries', 2));
        $subagentIdleWatchdogSeconds = (int) ($sessionManager->getSetting('subagent_idle_watchdog_seconds')
            ?? $config->get('kosmokrator.agent.subagent_idle_watchdog_seconds', 900));
        $orchestrator = new SubagentOrchestrator($log, $maxDepth, $concurrency, $subagentMaxRetries, $subagentIdleWatchdogSeconds);
        $rootContext = new AgentContext(AgentType::General, 0, $maxDepth, $orchestrator, 'root', '');

        $llmClientClass = $useAsyncClient ? 'async' : 'prism';

        // Resolve per-depth model overrides for subagents
        $subagentProvider = $sessionManager->getSetting('subagent_provider')
            ?? $config->get('kosmokrator.agent.subagent_provider');
        $subagentModel = $sessionManager->getSetting('subagent_model')
            ?? $config->get('kosmokrator.agent.subagent_model');
        $depth2Provider = $sessionManager->getSetting('subagent_depth2_provider')
            ?? $config->get('kosmokrator.agent.subagent_depth2_provider');
        $depth2Model = $sessionManager->getSetting('subagent_depth2_model')
            ?? $config->get('kosmokrator.agent.subagent_depth2_model');

        $subagentApiKey = $subagentProvider !== null && $subagentProvider !== ''
            ? $providers->apiKey($subagentProvider) : null;
        $subagentBaseUrl = $subagentProvider !== null && $subagentProvider !== ''
            ? rtrim($registry->url($subagentProvider), '/') : null;
        $depth2ApiKey = $depth2Provider !== null && $depth2Provider !== ''
            ? $providers->apiKey($depth2Provider) : null;
        $depth2BaseUrl = $depth2Provider !== null && $depth2Provider !== ''
            ? rtrim($registry->url($depth2Provider), '/') : null;

        $modelConfig = new SubagentModelConfig(
            defaultProvider: $provider,
            defaultModel: $llm->getModel(),
            defaultApiKey: $config->get("prism.providers.{$provider}.api_key", ''),
            defaultBaseUrl: rtrim($config->get("prism.providers.{$provider}.url", ''), '/'),
            subagentProvider: $subagentProvider !== '' ? $subagentProvider : null,
            subagentModel: $subagentModel !== '' ? $subagentModel : null,
            subagentApiKey: $subagentApiKey !== '' ? $subagentApiKey : null,
            subagentBaseUrl: $subagentBaseUrl !== '' ? $subagentBaseUrl : null,
            depth2Provider: $depth2Provider !== '' ? $depth2Provider : null,
            depth2Model: $depth2Model !== '' ? $depth2Model : null,
            depth2ApiKey: $depth2ApiKey !== '' ? $depth2ApiKey : null,
            depth2BaseUrl: $depth2BaseUrl !== '' ? $depth2BaseUrl : null,
        );

        $subagentFactory = new SubagentFactory(
            rootRegistry: $toolRegistry,
            log: $log,
            models: $models,
            truncator: $truncator,
            permissions: $permissions,
            rootCancellation: fn () => $ui->getCancellation(),
            llmClientClass: $llmClientClass,
            modelConfig: $modelConfig,
            maxTokens: $llm->getMaxTokens(),
            temperature: $llm->getTemperature(),
            budget: $contextBudget,
            protectedContextBuilder: $protectedContextBuilder,
            relay: $this->container->make(Relay::class),
        );

        $toolRegistry->register(new SubagentTool(
            $rootContext,
            fn (AgentContext $ctx, string $task) => $subagentFactory->createAndRunAgent($ctx, $task),
        ));
        $agentLoop->setAgentContext($rootContext);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        // Wire live subagent tree for TUI display
        $ui->setAgentTreeProvider(fn () => $agentLoop->buildLiveAgentTree());

        return new AgentSession($ui, $agentLoop, $llm, $permissions, $sessionManager, $orchestrator);
    }

    /**
     * Apply persisted settings from session storage to LLM and permissions.
     *
     * @param  SessionManager  $sm  Session manager with stored settings
     * @param  LlmClientInterface  $llm  LLM client to configure
     * @param  PermissionEvaluator  $permissions  Permission evaluator to configure
     */
    private function applyPersistedSettings(SessionManager $sm, LlmClientInterface $llm, PermissionEvaluator $permissions): void
    {
        $temp = $sm->getSetting('temperature');
        if ($temp !== null) {
            $llm->setTemperature((float) $temp);
        }

        $maxTokens = $sm->getSetting('max_tokens');
        if ($maxTokens !== null) {
            $llm->setMaxTokens((int) $maxTokens);
        }

        $permMode = $sm->getSetting('permission_mode');
        if ($permMode !== null) {
            $mode = PermissionMode::tryFrom($permMode);
            if ($mode !== null) {
                $permissions->setPermissionMode($mode);
            }
        } else {
            // Backward compat: old auto_approve setting
            $autoApprove = $sm->getSetting('auto_approve');
            if ($autoApprove === 'on') {
                $permissions->setPermissionMode(PermissionMode::Prometheus);
            }
        }
    }
}
