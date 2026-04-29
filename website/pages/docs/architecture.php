<?php
$docTitle = 'Architecture';
$docSlug = 'architecture';
ob_start();
?>
<p class="lead">
    KosmoKrator is a PHP 8.4 application built around a thin-agent-loop
    architecture: a small orchestrator delegates to focused components for
    tool execution, context management, permission checking, and rendering.
    This page walks through the request lifecycle, key source directories,
    the rendering layer, and how the pieces fit together.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="request-lifecycle">Request Lifecycle</h2>

<p>
    Every interaction with KosmoKrator follows the same boot path, from the
    CLI entry point through to the interactive REPL loop. Understanding this
    flow makes it easier to navigate the codebase and extend specific stages.
</p>

<div class="pipeline-flow">
    <div class="pipeline-stage">
        <h4>bin/kosmokrator</h4>
        <p>CLI entry point</p>
        <span class="stage-tag">PHP shebang</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-stage">
        <h4>Kernel</h4>
        <p>Boots DI container, loads config, registers providers</p>
        <span class="stage-tag">Boot</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-stage">
        <h4>AgentCommand</h4>
        <p>Console command: parses CLI flags, validates environment</p>
        <span class="stage-tag">CLI layer</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-stage">
        <h4>AgentSessionBuilder</h4>
        <p>Wires all dependencies: LLM client, tools, renderer, session DB</p>
        <span class="stage-tag">Composition</span>
    </div>
    <div class="pipeline-arrow">&rarr;</div>
    <div class="pipeline-stage">
        <h4>AgentLoop</h4>
        <p>Interactive REPL: user input &rarr; LLM call &rarr; tool calls &rarr; repeat</p>
        <span class="stage-tag">REPL</span>
    </div>
</div>

<p>
    The <code>AgentSessionBuilder</code> is the composition root. It reads the
    merged configuration, instantiates the LLM client, registers all tools,
    creates the session database connection, selects the appropriate renderer,
    and returns an immutable <code>AgentSession</code> value object. The
    <code>AgentLoop</code> then takes over and runs until the user exits.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="key-directories">Key Directories</h2>

<p>
    The source code is organized into focused namespaces under <code>src/</code>.
    Each directory has a clear responsibility:
</p>

<table>
    <thead>
        <tr>
            <th>Directory</th>
            <th>Responsibility</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>src/Agent/</code></td>
            <td>
                Agent core: <code>AgentLoop</code>, <code>ToolExecutor</code>,
                <code>ContextManager</code>, <code>StuckDetector</code>,
                <code>ContextCompactor</code>, <code>ContextPruner</code>,
                subagent orchestration, session builder
            </td>
        </tr>
        <tr>
            <td><code>src/LLM/</code></td>
            <td>
                LLM clients: <code>AsyncLlmClient</code>,
                <code>PrismService</code>, <code>RetryableLlmClient</code>,
                provider catalog, model definitions, pricing service
            </td>
        </tr>
        <tr>
            <td><code>src/UI/</code></td>
            <td>
                Terminal rendering: <code>TuiRenderer</code> (Symfony TUI + Revolt
                event loop), <code>AnsiRenderer</code> (pure ANSI + readline),
                <code>HeadlessRenderer</code> for non-interactive output,
                <code>NullRenderer</code> for subagents, <code>Theme</code> for
                colors, diff rendering, conversation display
            </td>
        </tr>
        <tr>
            <td><code>src/Tool/</code></td>
            <td>
                Tool implementations under <code>Coding/</code> (file ops, search,
                bash, subagent, memory), permission system under
                <code>Permission/</code>
            </td>
        </tr>
        <tr>
            <td><code>src/Command/</code></td>
            <td>
                Console commands: <code>AgentCommand</code>,
                <code>SetupCommand</code>, <code>ConfigCommand</code>,
                <code>AuthCommand</code>, update/gateway/integration commands,
                slash commands in <code>Slash/</code>, power commands in
                <code>Power/</code>
            </td>
        </tr>
        <tr>
            <td><code>src/Session/</code></td>
            <td>
                SQLite persistence: sessions, messages, memories, settings
                via <code>Database</code> class
            </td>
        </tr>
        <tr>
            <td><code>src/Task/</code></td>
            <td>
                Task tracking with tool integrations for managing work items
                across agent turns
            </td>
        </tr>
        <tr>
            <td><code>src/Settings/</code></td>
            <td>
                Settings management: <code>SettingsManager</code>,
                <code>SettingsPaths</code>, <code>YamlConfigStore</code>,
                <code>SettingsSchema</code>
            </td>
        </tr>
        <tr>
            <td><code>src/Provider/</code></td>
            <td>
                Service providers for the DI container &mdash; 11 providers
                (Core, Config, Database, LLM, Tool, Session, Agent, Event,
                Integration, Logging, UI) each with <code>register()</code>
                and <code>boot()</code> phases
            </td>
        </tr>
        <tr>
            <td><code>src/Athanor/</code></td>
            <td>
                Reactive signal/subscriber system: <code>Signal</code>,
                <code>Effect</code>, <code>Computed</code>,
                <code>Subscriber</code> for fine-grained reactivity
            </td>
        </tr>
        <tr>
            <td><code>src/Skill/</code></td>
            <td>
                Skill system: <code>Skill</code>, <code>SkillRegistry</code>,
                <code>SkillLoader</code>, <code>SkillDispatcher</code>,
                <code>SkillScope</code> for extensible agent capabilities
            </td>
        </tr>
        <tr>
            <td><code>src/Lua/</code></td>
            <td>
                Lua sandbox and scripting: <code>LuaSandboxService</code>,
                <code>LuaDocService</code>, <code>NativeToolBridge</code>,
                <code>LuaResult</code>
            </td>
        </tr>
        <tr>
            <td><code>src/Integration/</code></td>
            <td>
                Integration management and headless runtime:
                <code>IntegrationManager</code>, <code>IntegrationCatalog</code>,
                <code>IntegrationRuntime</code>, <code>IntegrationArgumentMapper</code>,
                <code>IntegrationDocService</code>, credential resolution, and
                Lua invocation helpers
            </td>
        </tr>
        <tr>
            <td><code>src/Mcp/</code></td>
            <td>
                MCP config compatibility, stdio transport, trust and read/write
                permission checks, headless command runtime, resources/prompts,
                secret resolution, and Lua <code>app.mcp.*</code> bridge
            </td>
        </tr>
        <tr>
            <td><code>src/Audio/</code></td>
            <td>
                Sound effects via <code>CompletionSound</code>
            </td>
        </tr>
        <tr>
            <td><code>src/Update/</code></td>
            <td>
                Self-updater: <code>UpdateChecker</code>,
                <code>SelfUpdater</code>
            </td>
        </tr>
        <tr>
            <td><code>src/UI/Diff/</code></td>
            <td>
                Diff rendering via <code>DiffRenderer</code>
            </td>
        </tr>
        <tr>
            <td><code>src/UI/Highlight/</code></td>
            <td>
                Lua syntax highlighting for inline code display
            </td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> Each namespace is intentionally self-contained.
        Dependencies flow inward: <code>Command</code> depends on
        <code>Agent</code>, which depends on <code>LLM</code>,
        <code>Tool</code>, and <code>UI</code>. The <code>Session</code>
        layer is used by <code>Agent</code> for persistence but has no
        dependency on agent logic itself.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="rendering-layer">Rendering Layer</h2>

<p>
    The UI is built around a composite <code>RendererInterface</code> that
    combines five focused sub-interfaces. Each sub-interface covers one aspect
    of the terminal output, and the composite is implemented by two concrete
    renderers plus a null renderer for testing. The <strong>Revolt event
    loop</strong> is fundamental to KosmoKrator's async architecture &mdash;
    it drives not just the TUI widget rendering but also concurrent LLM
    streaming, parallel tool execution, and non-blocking I/O throughout the
    application.
</p>

<h3 id="renderer-interfaces">Sub-Interfaces</h3>

<table>
    <thead>
        <tr>
            <th>Interface</th>
            <th>Responsibility</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>CoreRendererInterface</code></td>
            <td>Lifecycle events: startup, shutdown, errors, status bar</td>
        </tr>
        <tr>
            <td><code>ToolRendererInterface</code></td>
            <td>Tool call display: formatting tool invocations and their results</td>
        </tr>
        <tr>
            <td><code>DialogRendererInterface</code></td>
            <td>Interactive dialogs: permission prompts, confirmations, settings editor</td>
        </tr>
        <tr>
            <td><code>ConversationRendererInterface</code></td>
            <td>Conversation history replay and session resumption: re-rendering
                the prior message history when a session is resumed</td>
        </tr>
        <tr>
            <td><code>SubagentRendererInterface</code></td>
            <td>Subagent UI: swarm dashboard, progress bars, result injection</td>
        </tr>
    </tbody>
</table>

<h3 id="renderer-implementations">Implementations</h3>

<p>
    Two full implementations exist, selected at boot time based on the
    <code>ui.renderer</code> setting and terminal capabilities:
</p>

<ul>
    <li>
        <strong>TuiRenderer</strong> &mdash; Built on Symfony Terminal TUI
        components and the Revolt event loop. Provides a rich, interactive
        interface with widgets, modals, streaming output, and the swarm
        dashboard overlay. Uses <code>KosmokratorStyleSheet</code> for
        widget styling.
    </li>
    <li>
        <strong>AnsiRenderer</strong> &mdash; A pure ANSI escape-code renderer
        backed by PHP's readline extension. Works in any terminal, including
        SSH sessions and CI environments. Uses <code>MarkdownToAnsi</code>
        for rendering markdown in the terminal.
    </li>
</ul>

<p>
    A <strong>UIManager</strong> facade sits in front of the concrete
    renderers. It implements <code>RendererInterface</code> and delegates to
    either <code>TuiRenderer</code> or <code>AnsiRenderer</code> based on the
    active configuration. This is the actual object wired into
    <code>AgentSession</code> &mdash; the rest of the codebase never references
    the concrete renderer classes directly.
</p>

<p>
    Both implementations share the <code>Theme</code> class for color palette
    management and <code>KosmokratorTerminalTheme</code> for syntax
    highlighting of code blocks. When <code>ui.renderer</code> is set to
    <code>auto</code>, KosmoKrator selects <code>TuiRenderer</code> when the
    terminal supports it and falls back to <code>AnsiRenderer</code> otherwise.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Force the ANSI renderer with
        <code>ui.renderer: ansi</code> in your config or the
        <code>--renderer ansi</code> CLI flag. This is useful for SSH sessions
        or terminals where the TUI's widget rendering has issues.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="agent-loop">Agent Loop</h2>

<p>
    The <code>AgentLoop</code> is the heart of KosmoKrator &mdash; a thin
    orchestrator (~860 lines) that manages the REPL cycle and delegates all
    heavy work to specialized components. Its job is to coordinate the flow
    between user input, LLM calls, and tool execution, not to implement
    any of those concerns itself.
</p>

<p>
    The conversation state is held in a <code>ConversationHistory</code>
    object &mdash; the central message list data structure that tracks all
    user messages, assistant responses, tool calls, and tool results across
    the session. It is passed through the context pipeline before each LLM
    call.
</p>

<h3 id="repl-flow">REPL Flow</h3>

<p>
    Each iteration of the loop follows this sequence:
</p>

<ol>
    <li>
        <strong>Read user input</strong> &mdash; The renderer collects a
        message from the user (or resumes from a slash command result).
    </li>
    <li>
        <strong>Pre-flight context check</strong> &mdash; The
        <code>ContextManager</code> estimates token usage and runs the
        context pipeline (truncation, deduplication, pruning, compaction)
        if needed before the LLM call.
    </li>
    <li>
        <strong>LLM call</strong> &mdash; The LLM client sends the system
        prompt and conversation history. The response is streamed back
        through the renderer in real time.
    </li>
    <li>
        <strong>Tool calls</strong> &mdash; If the LLM response contains
        tool calls, the <code>ToolExecutor</code> handles them: permission
        checking, concurrent execution partitioning, subagent spawning, and
        result collection.
    </li>
    <li>
        <strong>Tool results &rarr; repeat</strong> &mdash; Tool results are
        appended to the conversation and the loop returns to step 2 for
        another LLM call. This continues until the LLM produces a response
        with no tool calls (a plain text answer).
    </li>
</ol>

<h3 id="tool-executor">ToolExecutor</h3>

<p>
    The <code>ToolExecutor</code> is responsible for executing tool calls
    returned by the LLM. It handles several concerns that must be coordinated
    per turn:
</p>

<ul>
    <li>
        <strong>Permission checking</strong> &mdash; Each tool call is
        evaluated against the current permission mode (Guardian, Argus, or
        Prometheus). Write operations may require explicit user approval via
        the dialog renderer.
    </li>
    <li>
        <strong>Concurrent execution partitioning</strong> &mdash; Independent
        tool calls are grouped and executed concurrently where possible,
        while maintaining ordering guarantees for dependent calls.
    </li>
    <li>
        <strong>Subagent management</strong> &mdash; Subagent spawn and batch
        operations are routed through the <code>SubagentOrchestrator</code>,
        with UI updates pushed to the renderer's subagent interface.
    </li>
</ul>

<h3 id="context-manager">ContextManager</h3>

<p>
    The <code>ContextManager</code> runs before each LLM call to ensure the
    conversation fits within the model's context window. It orchestrates the
    full context pipeline:
</p>

<ul>
    <li>
        <strong>Pre-flight token estimation</strong> &mdash; Fast
        character-based estimation of the total token count (system prompt +
        conversation + tool schemas).
    </li>
    <li>
        <strong>Context pipeline</strong> &mdash; Wired together by
        <code>ContextPipeline</code> and <code>ContextPipelineFactory</code>,
        which compose the budget, compactor, pruner,
        <code>ToolResultDeduplicator</code> (removes superseded tool results
        between turns), truncator, and protected context builder into a
        single pass. Progressive reduction runs through deduplication,
        pruning, LLM-based compaction, truncation, and emergency oldest-turn
        trimming. See
        <a href="/docs/context#context-pipeline">Context &amp; Memory</a>
        for full details.
    </li>
    <li>
        <strong>System prompt refresh</strong> &mdash; Rebuilds the system
        prompt each turn to incorporate the latest memories, session recall,
        mode suffix, and active tasks.
    </li>
</ul>

<h3 id="stuck-detector">StuckDetector</h3>

<p>
    The <code>StuckDetector</code> monitors headless subagent loops for
    repetitive behavior. It maintains a rolling window of the last 8 tool
    call signatures and escalates through nudge, final notice, and force
    return stages when a signature repeats 3 or more times. Stuck detection
    only applies to autonomous subagents &mdash; the main interactive agent
    relies on human oversight. See
    <a href="/docs/agents#stuck-detection">Agents &rarr; Stuck Detection</a>
    for the full escalation process.
</p>

<h3 id="event-system">Event System</h3>

<p>
    KosmoKrator uses a lightweight event system in
    <code>src/Agent/Event/</code> to decouple cross-cutting concerns from the
    core agent loop. Events are dispatched at key points during the REPL
    cycle and consumed by listeners:
</p>

<ul>
    <li><strong>StreamChunkEvent</strong> &mdash; fired for each streamed
        token chunk from the LLM</li>
    <li><strong>ThinkingEvent</strong> &mdash; fired when the model emits
        extended thinking content</li>
    <li><strong>ToolCallEvent</strong> &mdash; fired before a tool is
        executed</li>
    <li><strong>ToolResultEvent</strong> &mdash; fired after a tool returns
        its result</li>
    <li><strong>LlmResponseReceived</strong> &mdash; fired when a complete
        LLM response arrives</li>
    <li><strong>MessagePersisted</strong> &mdash; fired after a message is
        saved to the session database</li>
    <li><strong>ResponseCompleteEvent</strong> &mdash; fired when the full
        response cycle (including all tool calls) finishes</li>
    <li><strong>ContextCompacted</strong> &mdash; fired after the context
        pipeline runs compaction</li>
</ul>

<p>
    The primary built-in listener is
    <code>TokenTrackingListener</code>, which aggregates token usage from
    LLM responses for cost tracking and budget enforcement.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="session-persistence">Session Persistence</h2>

<p>
    KosmoKrator stores all session data in a single SQLite database at
    <code>~/.kosmokrator/data/kosmokrator.db</code>. The database is managed
    by the <code>Database</code> class in <code>src/Session/</code> and
    handles:
</p>

<ul>
    <li><strong>Sessions</strong> &mdash; Conversation metadata, model
        configuration, and timestamps for each agent session.</li>
    <li><strong>Messages</strong> &mdash; Full conversation history (user,
        assistant, tool calls, tool results, system messages) serialized
        for session resume.</li>
    <li><strong>Memories</strong> &mdash; Persistent knowledge fragments
        (project facts, user preferences, decisions) with type, class, and
        optional expiration.</li>
    <li><strong>Settings</strong> &mdash; Runtime settings overrides saved
        via the <code>/settings</code> command, taking priority over all
        YAML config files.</li>
</ul>

<p>
    Sessions are auto-saved after each agent turn. You can resume any previous
    session with its full history intact using <code>/resume</code> or the
    <code>--resume</code> CLI flag. The database is created automatically on
    first run.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> All data is stored locally. Nothing is sent to
        external servers except the LLM API calls you configure. You can
        inspect the database directly with any SQLite client.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="configuration-layering">Configuration Layering</h2>

<p>
    KosmoKrator merges configuration from three YAML sources in order of
    increasing priority. Later layers override earlier ones on a per-key basis:
</p>

<table>
    <thead>
        <tr>
            <th>Priority</th>
            <th>Source</th>
            <th>Location</th>
            <th>Purpose</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>1 (lowest)</td>
            <td>Bundled defaults</td>
            <td><code>config/kosmokrator.yaml</code></td>
            <td>Sane baseline defaults shipped with the application</td>
        </tr>
        <tr>
            <td>2</td>
            <td>User global</td>
            <td><code>~/.kosmokrator/config.yaml</code></td>
            <td>Personal overrides: API keys, preferred model, theme</td>
        </tr>
        <tr>
            <td>3</td>
            <td>Project-local</td>
            <td><code>.kosmokrator.yaml</code> or <code>.kosmokrator/config.yaml</code></td>
            <td>Per-project overrides: different model, plan mode default</td>
        </tr>
        <tr>
            <td>4 (highest)</td>
            <td>Runtime settings</td>
            <td>SQLite database</td>
            <td>Changed via <code>/settings</code> during a session</td>
        </tr>
    </tbody>
</table>

<p>
    Environment variables can be referenced in any YAML file using the
    <code>${VAR_NAME}</code> syntax. This is the recommended way to provide
    API keys and other secrets. Additionally, a <code>.env</code> file in
    the project root is loaded automatically by the Kernel via
    <code>Dotenv</code> during bootstrap (<code>Kernel.php:94-96</code>),
    making those variables available throughout the application.
</p>

<p>
    For a complete reference of every setting, see the
    <a href="/docs/configuration">Configuration</a> page.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="dependency-injection">Dependency Injection</h2>

<p>
    KosmoKrator uses the <strong>Illuminate Container</strong> with a service
    provider pattern. The <code>Kernel</code> (see
    <code>src/Kernel.php</code>) bootstraps the container in two phases:
</p>

<ol>
    <li>
        <strong>register()</strong> &mdash; Each provider registers bindings
        (interfaces &rarr; concretes, singletons, factory closures) into the
        container without resolving anything.
    </li>
    <li>
        <strong>boot()</strong> &mdash; After all providers have registered,
        each is booted. This is where providers can resolve dependencies from
        the container and perform initialization that requires other services.
    </li>
</ol>

<p>
    There are 12 service providers in <code>src/Provider/</code>:
    <code>CoreServiceProvider</code>, <code>ConfigServiceProvider</code>,
    <code>DatabaseServiceProvider</code>, <code>LlmServiceProvider</code>,
    <code>ToolServiceProvider</code>, <code>SessionServiceProvider</code>,
    <code>AgentServiceProvider</code>, <code>EventServiceProvider</code>,
    <code>IntegrationServiceProvider</code>, <code>McpServiceProvider</code>,
    <code>LoggingServiceProvider</code>, and
    <code>UiServiceProvider</code>.
    The Kernel also loads <code>.env</code> variables via
    <code>Dotenv</code> before booting providers
    (<code>Kernel.php:94-96</code>).
</p>

<p>
    The <code>AgentSessionBuilder</code> then acts as the composition root
    for each agent session: it reads the merged configuration, resolves
    components from the container, and returns an immutable
    <code>AgentSession</code> value object (a PHP 8.4
    <code>readonly class</code>). The design avoids circular dependencies
    by ensuring that classes communicate through return values and closures
    rather than holding mutual references.
</p>

<pre><code>Kernel.php
  &rarr; loads .env via Dotenv
  &rarr; register() on all providers (Illuminate Container bindings)
  &rarr; boot() on all providers (resolve &amp; initialize)

AgentSessionBuilder
  &rarr; resolves LlmClient, ToolExecutor, ContextManager, Renderer, Database from container
  &rarr; wires them into AgentSession
  &rarr; AgentLoop receives AgentSession and orchestrates the REPL</code></pre>

<p>
    This approach keeps the object graph simple and testable. Each component
    has a clear interface and can be replaced or mocked independently. The
    <code>HeadlessRenderer</code>, for example, implements the full
    <code>RendererInterface</code> while emitting text, JSON, or NDJSON for
    non-interactive runs. <code>NullRenderer</code> implements the same
    interface while producing no output for subagents and tests.
</p>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';
