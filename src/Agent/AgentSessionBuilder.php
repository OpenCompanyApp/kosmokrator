<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Illuminate\Container\Container;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
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
        $apiKey = $config->get("prism.providers.{$provider}.api_key", '');

        if ($apiKey === '' || $apiKey === null) {
            throw new \RuntimeException('No API key configured.');
        }

        $log = $this->container->make(LoggerInterface::class);
        $log->info('KosmoKrator started', ['renderer' => $ui->getActiveRenderer(), 'provider' => $provider]);

        // Create LLM client (async for TUI, sync for ANSI)
        /** @var LlmClientInterface $llm */
        $llm = ($ui->getActiveRenderer() === 'tui')
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

        // Build system prompt: base + memories + instructions + environment
        $memoriesEnabled = ($sessionManager->getSetting('memories') ?? 'on') !== 'off';
        $memories = $memoriesEnabled ? $sessionManager->getMemories() : [];

        $baseSystemPrompt = $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.')
            .MemoryInjector::format($memories)
            .InstructionLoader::gather()
            .EnvironmentContext::gather();

        // Task store
        $taskStore = $this->container->make(TaskStore::class);
        $ui->setTaskStore($taskStore);

        // Context management components
        $autoCompactEnabled = ($sessionManager->getSetting('auto_compact') ?? 'on') !== 'off';
        $compactThreshold = (int) ($sessionManager->getSetting('compact_threshold')
            ?? $config->get('kosmokrator.context.compact_threshold', 60));
        $compactor = $autoCompactEnabled ? new ContextCompactor($llm, $models, $log, $compactThreshold) : null;

        $truncator = new OutputTruncator(
            maxLines: (int) $config->get('kosmokrator.context.max_output_lines', 2000),
            maxBytes: (int) $config->get('kosmokrator.context.max_output_bytes', 50_000),
        );

        $pruneProtect = (int) ($sessionManager->getSetting('prune_protect') ?? $config->get('kosmokrator.context.prune_protect', 40_000));
        $pruneMinSavings = (int) ($sessionManager->getSetting('prune_min_savings') ?? $config->get('kosmokrator.context.prune_min_savings', 20_000));
        $pruner = new ContextPruner($pruneProtect, $pruneMinSavings);
        $deduplicator = new ToolResultDeduplicator;

        $memoryWarningThreshold = (int) $config->get('kosmokrator.context.memory_warning_mb', 50) * 1024 * 1024;

        // Create AgentLoop
        $agentLoop = new AgentLoop($llm, $ui, $log, $baseSystemPrompt, $permissions, $models, $taskStore, $sessionManager, $compactor, $truncator, $pruner, $deduplicator, $memoryWarningThreshold);

        // Subagent system
        $maxDepth = (int) ($sessionManager->getSetting('subagent_max_depth')
            ?? $config->get('kosmokrator.agent.subagent_max_depth', 3));
        $concurrency = (int) ($sessionManager->getSetting('subagent_concurrency')
            ?? $config->get('kosmokrator.agent.subagent_concurrency', 10));
        $subagentMaxRetries = (int) ($sessionManager->getSetting('subagent_max_retries')
            ?? $config->get('kosmokrator.agent.subagent_max_retries', 2));
        $orchestrator = new SubagentOrchestrator($log, $maxDepth, $concurrency, $subagentMaxRetries);
        $rootContext = new AgentContext(AgentType::General, 0, $maxDepth, $orchestrator, 'root', '');

        $llmClientClass = ($ui->getActiveRenderer() === 'tui') ? 'async' : 'prism';
        $subagentFactory = new SubagentFactory(
            rootRegistry: $toolRegistry,
            log: $log,
            models: $models,
            truncator: $truncator,
            permissions: $permissions,
            rootCancellation: fn () => $ui->getCancellation(),
            llmClientClass: $llmClientClass,
            apiKey: $config->get("prism.providers.{$provider}.api_key", ''),
            baseUrl: rtrim($config->get("prism.providers.{$provider}.url", ''), '/'),
            model: $llm->getModel(),
            maxTokens: $llm->getMaxTokens(),
            temperature: $llm->getTemperature(),
            provider: $provider,
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
