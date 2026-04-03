<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\UIManager;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use Psr\Log\LoggerInterface;

/**
 * Creates the subagent infrastructure: orchestrator, model config, factory,
 * and root agent context.
 */
final class SubagentPipelineFactory
{
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly ProviderCatalog $providers,
        private readonly RelayRegistry $registry,
        private readonly ModelCatalog $models,
        private readonly Relay $relay,
        private readonly LoggerInterface $log,
        private readonly array $config,
    ) {}

    /**
     * Build the full subagent pipeline.
     *
     * @param  LlmClientInterface  $llm  Root LLM client (for model/token defaults)
     * @param  ToolRegistry  $toolRegistry  Root tool registry (inherited by subagents)
     * @param  PermissionEvaluator  $permissions  Permission policy
     * @param  UIManager  $ui  UI manager (for cancellation token)
     * @param  ContextPipeline  $contextPipeline  Context components shared with subagents
     * @param  string  $rendererType  Active renderer type ('tui' or 'ansi')
     */
    public function create(
        LlmClientInterface $llm,
        ToolRegistry $toolRegistry,
        PermissionEvaluator $permissions,
        UIManager $ui,
        ContextPipeline $contextPipeline,
        string $rendererType,
    ): SubagentPipeline {
        $agentConfig = $this->config['agent'] ?? [];
        $provider = $agentConfig['default_provider'] ?? 'z';

        // Orchestrator
        $maxDepth = (int) ($this->sessionManager->getSetting('subagent_max_depth')
            ?? $agentConfig['subagent_max_depth'] ?? 3);
        $concurrency = (int) ($this->sessionManager->getSetting('subagent_concurrency')
            ?? $agentConfig['subagent_concurrency'] ?? 10);
        $subagentMaxRetries = (int) ($this->sessionManager->getSetting('subagent_max_retries')
            ?? $agentConfig['subagent_max_retries'] ?? 2);
        $subagentIdleWatchdogSeconds = (int) ($this->sessionManager->getSetting('subagent_idle_watchdog_seconds')
            ?? $agentConfig['subagent_idle_watchdog_seconds'] ?? 900);
        $orchestrator = new SubagentOrchestrator($this->log, $maxDepth, $concurrency, $subagentMaxRetries, $subagentIdleWatchdogSeconds);

        // Root context
        $rootContext = new AgentContext(AgentType::General, 0, $maxDepth, $orchestrator, 'root', '');

        // Model config
        $useAsync = $rendererType === 'tui' && $this->registry->supportsAsync($provider);
        $llmClientClass = $useAsync ? 'async' : 'prism';
        $modelConfig = $this->buildModelConfig($llm, $provider);

        // Factory
        $subagentFactory = new SubagentFactory(
            rootRegistry: $toolRegistry,
            log: $this->log,
            models: $this->models,
            truncator: $contextPipeline->truncator,
            permissions: $permissions,
            rootCancellation: fn () => $ui->getCancellation(),
            llmClientClass: $llmClientClass,
            modelConfig: $modelConfig,
            maxTokens: $llm->getMaxTokens(),
            temperature: $llm->getTemperature(),
            budget: $contextPipeline->budget,
            protectedContextBuilder: $contextPipeline->protectedContextBuilder,
            relay: $this->relay,
        );

        return new SubagentPipeline($orchestrator, $rootContext, $subagentFactory);
    }

    /**
     * Build per-depth model/provider overrides for subagents.
     */
    private function buildModelConfig(LlmClientInterface $llm, string $provider): SubagentModelConfig
    {
        $agentConfig = $this->config['agent'] ?? [];
        $prismConfig = $this->config['prism_providers'] ?? [];

        $subagentProvider = $this->sessionManager->getSetting('subagent_provider')
            ?? $agentConfig['subagent_provider'] ?? null;
        $subagentModel = $this->sessionManager->getSetting('subagent_model')
            ?? $agentConfig['subagent_model'] ?? null;
        $depth2Provider = $this->sessionManager->getSetting('subagent_depth2_provider')
            ?? $agentConfig['subagent_depth2_provider'] ?? null;
        $depth2Model = $this->sessionManager->getSetting('subagent_depth2_model')
            ?? $agentConfig['subagent_depth2_model'] ?? null;

        $subagentApiKey = $subagentProvider !== null && $subagentProvider !== ''
            ? $this->providers->apiKey($subagentProvider) : null;
        $subagentBaseUrl = $subagentProvider !== null && $subagentProvider !== ''
            ? rtrim($this->registry->url($subagentProvider), '/') : null;
        $depth2ApiKey = $depth2Provider !== null && $depth2Provider !== ''
            ? $this->providers->apiKey($depth2Provider) : null;
        $depth2BaseUrl = $depth2Provider !== null && $depth2Provider !== ''
            ? rtrim($this->registry->url($depth2Provider), '/') : null;

        return new SubagentModelConfig(
            defaultProvider: $provider,
            defaultModel: $llm->getModel(),
            defaultApiKey: $prismConfig[$provider]['api_key'] ?? '',
            defaultBaseUrl: rtrim($prismConfig[$provider]['url'] ?? '', '/'),
            subagentProvider: $subagentProvider !== '' ? $subagentProvider : null,
            subagentModel: $subagentModel !== '' ? $subagentModel : null,
            subagentApiKey: $subagentApiKey !== '' ? $subagentApiKey : null,
            subagentBaseUrl: $subagentBaseUrl !== '' ? $subagentBaseUrl : null,
            depth2Provider: $depth2Provider !== '' ? $depth2Provider : null,
            depth2Model: $depth2Model !== '' ? $depth2Model : null,
            depth2ApiKey: $depth2ApiKey !== '' ? $depth2ApiKey : null,
            depth2BaseUrl: $depth2BaseUrl !== '' ? $depth2BaseUrl : null,
        );
    }
}
