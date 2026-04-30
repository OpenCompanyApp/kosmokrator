<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\AskChoiceTool;
use Kosmokrator\Tool\AskUserTool;
use Kosmokrator\Tool\Coding\SubagentTool;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\HeadlessRenderer;
use Kosmokrator\UI\OutputFormat;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\UIManager;
use Kosmokrator\Web\Cache\WebTransientCache;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use Psr\Log\LoggerInterface;

/**
 * Builds all components needed for an interactive agent session.
 *
 * Coordinates focused factories (LlmClientFactory, ContextPipelineFactory,
 * SubagentPipelineFactory, SessionSettingsApplier) to wire up the full session.
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

        // Create LLM client (validates auth, selects sync/async)
        $llmFactory = new LlmClientFactory($this->container);
        $llm = $llmFactory->create($ui->getActiveRenderer(), $ui);

        $log = $this->container->make(LoggerInterface::class);
        $provider = $config->get('kosmo.agent.default_provider', 'z');
        $log->info('KosmoKrator started', ['renderer' => $ui->getActiveRenderer(), 'provider' => $provider]);

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

        // Apply persisted settings (temperature, max_tokens, permission_mode, max_retries)
        $kosmoConfig = $config->get('kosmo', []);
        $settingsApplier = new SessionSettingsApplier($sessionManager, $kosmoConfig);
        $settingsApplier->apply($llm, $permissions);

        // Set initial permission mode on UI
        $permMode = $permissions->getPermissionMode();
        $ui->setPermissionMode($permMode->statusLabel(), $permMode->color());

        // Build system prompt
        $baseSystemPrompt = $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.')
            .InstructionLoader::gather()
            .EnvironmentContext::gather();

        // Append Lua integration docs if available
        $baseSystemPrompt .= $this->buildLuaDocsSuffix();
        $baseSystemPrompt .= $this->buildWebToolsSuffix($toolRegistry);

        // Task store
        $taskStore = $this->container->make(TaskStore::class);
        $ui->setTaskStore($taskStore);

        // Context pipeline (budget, compactor, pruner, deduplicator, truncator, protected context)
        $contextFactory = new ContextPipelineFactory($sessionManager, $models, $taskStore, $log, $kosmoConfig);
        $contextPipeline = $contextFactory->create($llm);

        $memoryWarningThreshold = (int) $config->get('kosmo.context.memory_warning_mb', 50) * 1024 * 1024;

        // Event dispatcher for cross-cutting concerns
        $events = $this->container->bound(Dispatcher::class)
            ? $this->container->make(Dispatcher::class)
            : null;

        // Create AgentLoop
        $agentLoop = new AgentLoop(
            $llm, $ui, $log, $baseSystemPrompt, $permissions, $models, $taskStore, $sessionManager,
            $contextPipeline->compactor, $contextPipeline->truncator, $contextPipeline->pruner,
            $contextPipeline->deduplicator, $contextPipeline->budget, $contextPipeline->protectedContextBuilder,
            $memoryWarningThreshold, $events, webCache: $this->container->make(WebTransientCache::class),
        );

        // Subagent pipeline (orchestrator, root context, factory)
        $prismProviders = $config->get('prism.providers', []);
        $subagentConfig = array_merge($kosmoConfig, ['prism_providers' => $prismProviders]);
        $subagentPipelineFactory = new SubagentPipelineFactory(
            $sessionManager,
            $this->container->make(ProviderCatalog::class),
            $this->container->make(RelayRegistry::class),
            $models,
            $this->container->make(Relay::class),
            $log,
            $subagentConfig,
        );
        $subagentPipeline = $subagentPipelineFactory->create(
            $llm, $toolRegistry, $permissions, $ui, $contextPipeline, $ui->getActiveRenderer(),
        );

        // Wire subagent tool into the tool registry
        $toolRegistry->register(new SubagentTool(
            $subagentPipeline->rootContext,
            fn (AgentContext $ctx, string $task) => $subagentPipeline->factory->createAndRunAgent($ctx, $task),
        ));
        $agentLoop->setAgentContext($subagentPipeline->rootContext);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        // Wire live subagent tree for TUI display
        $ui->setAgentTreeProvider(fn () => $agentLoop->buildLiveAgentTree());

        return new AgentSession($ui, $agentLoop, $llm, $permissions, $sessionManager, $subagentPipeline->orchestrator);
    }

    /**
     * Build a headless agent session for non-interactive CLI execution.
     *
     * Creates a HeadlessRenderer instead of UIManager, skips intro/welcome,
     * and optionally skips session persistence.
     *
     * @param  OutputFormat  $format  Output format (text, json, stream-json)
     * @param  array{model?: string, permission_mode?: string, agent_mode?: string, persist_session?: bool, system_prompt?: string, append_system_prompt?: string, max_turns?: int, timeout?: int, renderer?: RendererInterface}  $options  Headless options
     * @return AgentSession All components needed for headless execution
     *
     * @throws \RuntimeException If API key is not configured
     */
    public function buildHeadless(OutputFormat $format = OutputFormat::Text, array $options = []): AgentSession
    {
        $config = $this->container->make('config');

        // Create headless renderer. SDK callers can supply an event/callback
        // renderer while keeping the same headless agent wiring as the CLI.
        $ui = ($options['renderer'] ?? null) instanceof RendererInterface
            ? $options['renderer']
            : new HeadlessRenderer($format);

        // Create LLM client (always use sync/prism for headless)
        $llmFactory = new LlmClientFactory($this->container);
        // Gateway surfaces need the same async-capable LLM path as TUI sessions.
        // Some providers, notably Z.AI coding, work correctly via the async transport
        // but fail on the sync Prism path used for plain ANSI/headless mode.
        $llm = $llmFactory->create('tui', $ui);

        // Apply model override if specified
        if (! empty($options['model'])) {
            $llm->setModel($options['model']);
        }

        $log = $this->container->make(LoggerInterface::class);
        $provider = $config->get('kosmo.agent.default_provider', 'z');
        $log->info('KosmoKrator headless started', ['format' => $format->value, 'provider' => $provider]);

        // Tools and permissions
        $toolRegistry = $this->container->make(ToolRegistry::class);
        $toolRegistry->register(new AskUserTool($ui));
        $toolRegistry->register(new AskChoiceTool($ui));
        $permissions = $this->container->make(PermissionEvaluator::class);
        $models = $this->container->make(ModelCatalog::class);

        // Session manager — optional for headless
        $persistSession = $options['persist_session'] ?? true;
        $sessionManager = $this->container->make(SessionManager::class);
        if ($persistSession) {
            $project = InstructionLoader::gitRoot() ?? getcwd();
            $sessionManager->setProject($project);
        }

        // Apply persisted settings
        $kosmoConfig = $config->get('kosmo', []);
        $settingsApplier = new SessionSettingsApplier($sessionManager, $kosmoConfig);
        $settingsApplier->apply($llm, $permissions);

        // Apply permission mode override (--yolo or --permission-mode)
        if (! empty($options['permission_mode'])) {
            $permMode = PermissionMode::from($options['permission_mode']);
            $permissions->setPermissionMode($permMode);
        }

        // Build system prompt
        $baseSystemPrompt = $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.')
            .InstructionLoader::gather()
            .EnvironmentContext::gather();

        // System prompt overrides
        if (! empty($options['system_prompt'])) {
            $baseSystemPrompt = $options['system_prompt'];
        }
        if (! empty($options['append_system_prompt'])) {
            $baseSystemPrompt .= "\n\n".$options['append_system_prompt'];
        }

        // Append Lua integration docs if available
        $baseSystemPrompt .= $this->buildLuaDocsSuffix();
        $baseSystemPrompt .= $this->buildWebToolsSuffix($toolRegistry);

        // Task store
        $taskStore = $this->container->make(TaskStore::class);

        // Context pipeline
        $contextFactory = new ContextPipelineFactory($sessionManager, $models, $taskStore, $log, $kosmoConfig);
        $contextPipeline = $contextFactory->create($llm);

        $memoryWarningThreshold = (int) $config->get('kosmo.context.memory_warning_mb', 50) * 1024 * 1024;

        // Event dispatcher
        $events = $this->container->bound(Dispatcher::class)
            ? $this->container->make(Dispatcher::class)
            : null;

        // Create AgentLoop
        $agentLoop = new AgentLoop(
            $llm, $ui, $log, $baseSystemPrompt, $permissions, $models, $taskStore,
            $persistSession ? $sessionManager : null,
            $contextPipeline->compactor, $contextPipeline->truncator, $contextPipeline->pruner,
            $contextPipeline->deduplicator, $contextPipeline->budget, $contextPipeline->protectedContextBuilder,
            $memoryWarningThreshold, $events, webCache: $this->container->make(WebTransientCache::class),
        );

        // Apply guardrails
        if (! empty($options['max_turns'])) {
            $agentLoop->setMaxTurns((int) $options['max_turns']);
        }
        if (! empty($options['timeout'])) {
            $agentLoop->setTimeout((int) $options['timeout']);
        }

        // Apply agent mode
        if (! empty($options['agent_mode'])) {
            $agentLoop->setMode(AgentMode::from($options['agent_mode']));
        }

        // Subagent pipeline
        $prismProviders = $config->get('prism.providers', []);
        $subagentConfig = array_merge($kosmoConfig, ['prism_providers' => $prismProviders]);
        $subagentPipelineFactory = new SubagentPipelineFactory(
            $sessionManager,
            $this->container->make(ProviderCatalog::class),
            $this->container->make(RelayRegistry::class),
            $models,
            $this->container->make(Relay::class),
            $log,
            $subagentConfig,
        );
        $subagentPipeline = $subagentPipelineFactory->create(
            $llm, $toolRegistry, $permissions, $ui, $contextPipeline, 'ansi',
        );

        // Wire subagent tool
        $toolRegistry->register(new SubagentTool(
            $subagentPipeline->rootContext,
            fn (AgentContext $ctx, string $task) => $subagentPipeline->factory->createAndRunAgent($ctx, $task),
        ));
        $agentLoop->setAgentContext($subagentPipeline->rootContext);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        return new AgentSession($ui, $agentLoop, $llm, $permissions, $sessionManager, $subagentPipeline->orchestrator);
    }

    /**
     * Build an agent session for a non-terminal surface such as the Telegram gateway.
     *
     * Uses a caller-supplied renderer while keeping normal session persistence,
     * permissions, tool wiring, and subagent support intact.
     *
     * @param  array{model?: string, permission_mode?: string, agent_mode?: string, system_prompt?: string, append_system_prompt?: string, max_turns?: int, timeout?: int}  $options
     */
    public function buildGateway(RendererInterface $ui, array $options = []): AgentSession
    {
        $config = $this->container->make('config');

        $ui->initialize();

        $llmFactory = new LlmClientFactory($this->container);
        // Gateway surfaces need the same async-capable client selection as TUI.
        $llm = $llmFactory->create('tui', $ui);

        if (! empty($options['model'])) {
            $llm->setModel($options['model']);
        }

        $log = $this->container->make(LoggerInterface::class);

        $toolRegistry = $this->container->make(ToolRegistry::class);
        $toolRegistry->register(new AskUserTool($ui));
        $toolRegistry->register(new AskChoiceTool($ui));
        $permissions = $this->container->make(PermissionEvaluator::class);
        $models = $this->container->make(ModelCatalog::class);

        $sessionManager = $this->container->make(SessionManager::class);
        $project = InstructionLoader::gitRoot() ?? getcwd();
        $sessionManager->setProject($project);

        $kosmoConfig = $config->get('kosmo', []);
        $settingsApplier = new SessionSettingsApplier($sessionManager, $kosmoConfig);
        $settingsApplier->apply($llm, $permissions);

        if (! empty($options['permission_mode'])) {
            $permissions->setPermissionMode(PermissionMode::from((string) $options['permission_mode']));
        }

        $baseSystemPrompt = $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.')
            .InstructionLoader::gather()
            .EnvironmentContext::gather();

        if (! empty($options['system_prompt'])) {
            $baseSystemPrompt = (string) $options['system_prompt'];
        }
        if (! empty($options['append_system_prompt'])) {
            $baseSystemPrompt .= "\n\n".(string) $options['append_system_prompt'];
        }

        $baseSystemPrompt .= $this->buildLuaDocsSuffix();
        $baseSystemPrompt .= $this->buildWebToolsSuffix($toolRegistry);

        $taskStore = $this->container->make(TaskStore::class);
        $contextFactory = new ContextPipelineFactory($sessionManager, $models, $taskStore, $log, $kosmoConfig);
        $contextPipeline = $contextFactory->create($llm);
        $memoryWarningThreshold = (int) $config->get('kosmo.context.memory_warning_mb', 50) * 1024 * 1024;
        $events = $this->container->bound(Dispatcher::class)
            ? $this->container->make(Dispatcher::class)
            : null;

        $agentLoop = new AgentLoop(
            $llm, $ui, $log, $baseSystemPrompt, $permissions, $models, $taskStore, $sessionManager,
            $contextPipeline->compactor, $contextPipeline->truncator, $contextPipeline->pruner,
            $contextPipeline->deduplicator, $contextPipeline->budget, $contextPipeline->protectedContextBuilder,
            $memoryWarningThreshold, $events, webCache: $this->container->make(WebTransientCache::class),
        );

        if (! empty($options['max_turns'])) {
            $agentLoop->setMaxTurns((int) $options['max_turns']);
        }

        if (! empty($options['timeout'])) {
            $agentLoop->setTimeout((int) $options['timeout']);
        }

        if (! empty($options['agent_mode'])) {
            $agentLoop->setMode(AgentMode::from((string) $options['agent_mode']));
        }

        $prismProviders = $config->get('prism.providers', []);
        $subagentConfig = array_merge($kosmoConfig, ['prism_providers' => $prismProviders]);
        $subagentPipelineFactory = new SubagentPipelineFactory(
            $sessionManager,
            $this->container->make(ProviderCatalog::class),
            $this->container->make(RelayRegistry::class),
            $models,
            $this->container->make(Relay::class),
            $log,
            $subagentConfig,
        );
        $subagentPipeline = $subagentPipelineFactory->create(
            $llm, $toolRegistry, $permissions, $ui, $contextPipeline, 'ansi',
        );

        $toolRegistry->register(new SubagentTool(
            $subagentPipeline->rootContext,
            fn (AgentContext $ctx, string $task) => $subagentPipeline->factory->createAndRunAgent($ctx, $task),
        ));
        $agentLoop->setAgentContext($subagentPipeline->rootContext);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        return new AgentSession($ui, $agentLoop, $llm, $permissions, $sessionManager, $subagentPipeline->orchestrator);
    }

    /**
     * Build the Lua integration docs suffix for the system prompt.
     */
    private function buildLuaDocsSuffix(): string
    {
        if (! $this->container->bound(LuaDocService::class)) {
            return '';
        }

        try {
            $luaDocService = $this->container->make(LuaDocService::class);
            $summary = $luaDocService->getPromptNamespaceSummary();
            if ($summary === '') {
                return '';
            }

            return "\n\n# Lua Integration Access\n\n"
                .'For complex multi-step operations, use `execute_lua` instead of '
                ."multiple sequential tool calls. Lua runs locally with zero LLM cost per operation.\n\n"
                .'Native tools are also available in Lua: `app.tools.file_read({path=...})`, '
                .'`app.tools.glob({pattern=...})`, `app.tools.grep({pattern=...})`, '
                ."`app.tools.bash({command=...})`, `app.tools.subagent({task=...})`, etc.\n\n"
                .$summary."\n\n"
                .'Use lua_list_docs to discover available namespaces, lua_search_docs to find specific functions, '
                ."and lua_read_doc for detailed parameter docs. Always read docs before writing Lua code.\n\n"
                .'Permission notes: some integration write operations may require approval (ask mode). '
                .'If you get a permission error, ask the user to change the setting in /settings → Integrations.';
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildWebToolsSuffix(ToolRegistry $toolRegistry): string
    {
        if ($toolRegistry->get('web_search') === null && $toolRegistry->get('web_fetch') === null) {
            return '';
        }

        return "\n\n# Web Research\n\n"
            .'Web tools are available: `web_search` for discovery and `web_fetch` for reading pages. '
            .'For large pages, prefer `web_fetch` in `metadata` or `outline` mode first, then fetch only the relevant '
            ."section, match, or chunk.\n\n"
            .'For multi-source research, prefer subagents so different sources can be searched and inspected in parallel. '
            .'Have subagents return concise findings with URLs, then synthesize in the parent agent instead of loading many '
            .'full pages into the main conversation context.';
    }
}
