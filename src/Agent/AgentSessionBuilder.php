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
        $ui = new UIManager($rendererPref);
        $ui->initialize();
        $ui->renderIntro($animated);
        $ui->showWelcome();

        $session = $this->assembleSession(
            ui: $ui,
            llmRenderer: $ui->getActiveRenderer(),
            options: [],
            persistSession: true,
            exposeTaskStore: true,
            subagentRenderer: $ui->getActiveRenderer(),
            logMessage: 'KosmoKrator started',
            logContext: ['renderer' => $ui->getActiveRenderer()],
        );

        $ui->setAgentTreeProvider(fn () => $session->agentLoop->buildLiveAgentTree());

        return $session;
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
        $ui = ($options['renderer'] ?? null) instanceof RendererInterface
            ? $options['renderer']
            : new HeadlessRenderer($format);

        return $this->assembleSession(
            ui: $ui,
            llmRenderer: 'tui',
            options: $options,
            persistSession: (bool) ($options['persist_session'] ?? true),
            exposeTaskStore: false,
            subagentRenderer: 'ansi',
            logMessage: 'KosmoKrator headless started',
            logContext: ['format' => $format->value],
        );
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
        $ui->initialize();

        return $this->assembleSession(
            ui: $ui,
            llmRenderer: 'tui',
            options: $options,
            persistSession: true,
            exposeTaskStore: false,
            subagentRenderer: 'ansi',
        );
    }

    /**
     * Shared runtime assembly for terminal, headless, SDK, ACP, and gateway surfaces.
     *
     * @param  array{model?: string, permission_mode?: string, agent_mode?: string, system_prompt?: string, append_system_prompt?: string, max_turns?: int, timeout?: int}  $options
     * @param  array<string, mixed>  $logContext
     */
    private function assembleSession(
        RendererInterface $ui,
        string $llmRenderer,
        array $options,
        bool $persistSession,
        bool $exposeTaskStore,
        string $subagentRenderer,
        ?string $logMessage = null,
        array $logContext = [],
    ): AgentSession {
        $config = $this->container->make('config');
        $log = $this->container->make(LoggerInterface::class);
        $provider = $config->get('kosmo.agent.default_provider', 'z');
        if ($logMessage !== null) {
            $log->info($logMessage, ['provider' => $provider, ...$logContext]);
        }

        $llm = (new LlmClientFactory($this->container))->create($llmRenderer, $ui);
        if (! empty($options['model'])) {
            $llm->setModel((string) $options['model']);
        }

        $toolRegistry = $this->container->make(ToolRegistry::class);
        $toolRegistry->register(new AskUserTool($ui));
        $toolRegistry->register(new AskChoiceTool($ui));
        $permissions = $this->container->make(PermissionEvaluator::class);
        $models = $this->container->make(ModelCatalog::class);

        $sessionManager = $this->container->make(SessionManager::class);
        if ($persistSession) {
            $sessionManager->setProject(InstructionLoader::gitRoot() ?? getcwd());
        }

        $kosmoConfig = $config->get('kosmo', []);
        (new SessionSettingsApplier($sessionManager, $kosmoConfig))->apply($llm, $permissions);
        if (! empty($options['permission_mode'])) {
            $permissions->setPermissionMode(PermissionMode::from((string) $options['permission_mode']));
        }

        $permMode = $permissions->getPermissionMode();
        $ui->setPermissionMode($permMode->statusLabel(), $permMode->color());

        $baseSystemPrompt = $this->systemPrompt($toolRegistry, $options);
        $taskStore = $this->container->make(TaskStore::class);
        if ($exposeTaskStore) {
            $ui->setTaskStore($taskStore);
        }

        $contextPipeline = (new ContextPipelineFactory($sessionManager, $models, $taskStore, $log, $kosmoConfig))->create($llm);
        $events = $this->container->bound(Dispatcher::class)
            ? $this->container->make(Dispatcher::class)
            : null;

        $agentLoop = new AgentLoop(
            $llm,
            $ui,
            $log,
            $baseSystemPrompt,
            $permissions,
            $models,
            $taskStore,
            $persistSession ? $sessionManager : null,
            $contextPipeline->compactor,
            $contextPipeline->truncator,
            $contextPipeline->pruner,
            $contextPipeline->deduplicator,
            $contextPipeline->budget,
            $contextPipeline->protectedContextBuilder,
            (int) $config->get('kosmo.context.memory_warning_mb', 50) * 1024 * 1024,
            $events,
            webCache: $this->container->make(WebTransientCache::class),
        );

        $this->applyRuntimeOptions($agentLoop, $options);

        $subagentPipeline = (new SubagentPipelineFactory(
            $sessionManager,
            $this->container->make(ProviderCatalog::class),
            $this->container->make(RelayRegistry::class),
            $models,
            $this->container->make(Relay::class),
            $log,
            array_merge($kosmoConfig, ['prism_providers' => $config->get('prism.providers', [])]),
        ))->create($llm, $toolRegistry, $permissions, $ui, $contextPipeline, $subagentRenderer);

        $toolRegistry->register(new SubagentTool(
            $subagentPipeline->rootContext,
            fn (AgentContext $ctx, string $task) => $subagentPipeline->factory->createAndRunAgent($ctx, $task),
        ));
        $agentLoop->setAgentContext($subagentPipeline->rootContext);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        return new AgentSession($ui, $agentLoop, $llm, $permissions, $sessionManager, $subagentPipeline->orchestrator);
    }

    /**
     * @param  array{system_prompt?: string, append_system_prompt?: string}  $options
     */
    private function systemPrompt(ToolRegistry $toolRegistry, array $options): string
    {
        $config = $this->container->make('config');
        $prompt = ! empty($options['system_prompt'])
            ? (string) $options['system_prompt']
            : $config->get('kosmo.agent.system_prompt', 'You are a helpful coding assistant.')
                .InstructionLoader::gather()
                .EnvironmentContext::gather();

        if (! empty($options['append_system_prompt'])) {
            $prompt .= "\n\n".(string) $options['append_system_prompt'];
        }

        return $prompt.$this->buildLuaDocsSuffix().$this->buildWebToolsSuffix($toolRegistry);
    }

    /**
     * @param  array{max_turns?: int, timeout?: int, agent_mode?: string}  $options
     */
    private function applyRuntimeOptions(AgentLoop $agentLoop, array $options): void
    {
        if (! empty($options['max_turns'])) {
            $agentLoop->setMaxTurns((int) $options['max_turns']);
        }

        if (! empty($options['timeout'])) {
            $agentLoop->setTimeout((int) $options['timeout']);
        }

        if (! empty($options['agent_mode'])) {
            $agentLoop->setMode(AgentMode::from((string) $options['agent_mode']));
        }
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
