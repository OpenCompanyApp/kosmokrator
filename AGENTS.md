# KosmoKrator

AI coding agent for the terminal. Mythology-themed CLI built with PHP 8.4, Symfony Console, and a dual renderer (TUI/ANSI).

## Quick Start

```bash
bin/kosmokrator              # Run with auto-detected renderer (TUI if available, ANSI fallback)
bin/kosmokrator --renderer=ansi   # Force ANSI mode
bin/kosmokrator --no-animation    # Skip the animated intro
```

## Architecture

```
bin/kosmokrator → Kernel → AgentCommand → AgentSessionBuilder → AgentLoop (REPL)
                                            ├── ToolExecutor → tools + PermissionEvaluator
                                            ├── ContextManager → compaction, pruning, system prompt
                                            ├── StuckDetector → headless loop convergence
                                            ├── LLM client (AsyncLlmClient or PrismService)
                                            ├── UIManager → TuiRenderer | AnsiRenderer
                                            ├── ToolRegistry → coding, shell, task, memory, session, Lua, and subagent tools
                                            └── SubagentOrchestrator → parallel child agents
```

### Key directories

- `src/Agent/` — Agent core: AgentLoop (REPL orchestrator), ToolExecutor, ContextManager, StuckDetector, subagent system
- `src/LLM/` — LLM clients: AsyncLlmClient (Amp HTTP, async), PrismService (Prism PHP, sync), RetryableLlmClient (decorator)
  - **prism-relay boundary**: Provider-specific logic (SSE parsing quirks, `stream_options` support, usage extraction formats, `finish_reason` mapping, error normalization, prompt caching strategies) belongs in the `opencompany/prism-relay` package, NOT here. KosmoKrator's LLM layer should only contain agent-level orchestration: retry policy, streaming-to-UI bridging, cancellation handling.
- `src/UI/` — Rendering layer with split interface hierarchy
  - `UI/Tui/` — Symfony TUI renderer: TuiRenderer, TuiModalManager (dialogs), TuiAnimationManager (breathing/spinners), SubagentDisplayManager, widgets
  - `UI/Ansi/` — Pure ANSI fallback: AnsiRenderer, MarkdownToAnsi (with Handler/ for table/list extraction), AnsiTableRenderer
  - `UI/Diff/` — Unified diff rendering with word-level highlighting
  - `UI/Theme.php` — Shared color palette, tool icons, context bar
  - `UI/AgentDisplayFormatter.php` — Shared agent display utilities (used by both renderers)
  - `UI/AgentTreeBuilder.php` — Builds agent tree from orchestrator stats
- `src/Tool/` — Tool implementations in `Coding/`, permission system in `Permission/`
- `src/Command/` — AgentCommand (main REPL/headless), SetupCommand, ConfigCommand, AuthCommand, gateway/integration commands, slash commands in `Slash/`, power commands in `Power/`
- `src/Integration/` — OpenCompany integration catalog, runtime, docs, credential resolution, and Lua invocation helpers
- `src/Lua/` — Lua sandbox, docs service, and native tool bridge
- `src/Session/` — SQLite persistence: sessions, messages, memories, settings
- `src/Task/` — Task tracking system with tool integrations

### Rendering

`RendererInterface` is composed from 5 focused sub-interfaces:
- `CoreRendererInterface` — lifecycle, streaming, status, phase transitions
- `ToolRendererInterface` — tool call/result display, permission prompts
- `DialogRendererInterface` — settings, session picker, plan approval, user questions
- `ConversationRendererInterface` — history clear/replay
- `SubagentRendererInterface` — subagent status, spawn/batch display, dashboard

Four renderers implement the full interface:
- **TuiRenderer** — Interactive Symfony TUI with widgets, Revolt event loop, EditorWidget for multi-line input. Delegates to TuiModalManager (overlay dialogs), TuiAnimationManager (breathing/spinners/phase), and SubagentDisplayManager (subagent lifecycle).
- **AnsiRenderer** — Pure ANSI escape codes, readline input, MarkdownToAnsi for response formatting
- **HeadlessRenderer** — Non-interactive stdout/stderr renderer for `-p`, JSON, and stream-json runs
- **NullRenderer** — Silent renderer for subagents and tests

Both use `Theme` for colors and `KosmokratorTerminalTheme` for syntax highlighting via tempest/highlight.

### Agent internals

AgentLoop is a thin orchestrator that delegates to:
- **ToolExecutor** — permission checking, concurrent tool execution partitioning, subagent spawn/batch UI
- **ContextManager** — pre-flight context window checks, LLM-based compaction, system prompt refresh
- **StuckDetector** — rolling-window repetition detection for headless subagent loops (nudge → final notice → force return)

Session setup is handled by **AgentSessionBuilder**, which wires all dependencies (LLM client, permissions, tools, subagent infrastructure) and returns an **AgentSession** value object.

## Development

```bash
composer install
php vendor/bin/phpunit
php vendor/bin/pint             # Code style (Laravel Pint)
```

### Config

Config loaded from `config/kosmokrator.yaml`, overridable via `~/.kosmokrator/config.yaml` or `.kosmokrator.yaml` in the working directory.

`README.md`, `docs/architecture/overview.md`, `docs/architecture/permission-modes.md`, and `AGENTS.md` are the main current-truth docs. Files in `docs/proposals/` are design notes. Actionable backlog is tracked in Plane, not in repo audit/todo docs.

## MCP CLI

- MCP CLI is installed at `~/.local/bin/mcp-cli`.
- Config is at `~/.config/mcp/mcp_servers.json`.
- Common usage:
- `mcp-cli`
- `mcp-cli info <server>`
- `mcp-cli call <server> <tool> '<json>'`
- Connected servers currently include:
- `founder-mode`
- `notion`
- `vibe_kanban`
- `plane`

### Building a PHAR

```bash
php vendor/bin/box compile      # Uses box.json config
```

## Conventions

- PSR-4 autoloading: `Kosmokrator\` → `src/`
- Strict types everywhere
- No tool round limits — agent runs until the LLM finishes
- Tool approval required for: file_write, file_edit, bash (configurable in `config/kosmokrator.yaml`)
- Mythology-themed UI: planetary symbols for tool icons, mythological thinking phrases, cosmic spinner animations
- ANSI renderer uses league/commonmark for markdown parsing + tempest/highlight for code blocks
- TUI renderer uses Symfony TUI's MarkdownWidget with custom stylesheet
- Extracted classes communicate via return values and closures, not back-references — no circular dependencies
- Static utility classes (AgentDisplayFormatter, AgentTreeBuilder, PathResolver, SessionFormatter) are stateless and side-effect-free
- Prefer concise PHPDoc where it materially clarifies intent, array shapes, or non-obvious behavior

## Agents

KosmoKrator includes a subagent system that spawns child agents for parallel work. This section covers the architecture, type hierarchy, and orchestration model.

### Agent Types

| Type | Capabilities | Can Spawn |
|------|-------------|-----------|
| **General** | Full delegated tool access: read, write, edit, patch, bash/shell, subagent, memory/session, Lua | General, Explore, Plan |
| **Explore** | Read-only plus bash/shell, subagent, memory/session search, Lua integration scripting | Explore only |
| **Plan** | Read-only plus bash/shell, subagent, memory/session search, Lua integration scripting | Explore only |

Types enforce permission narrowing — a General agent can spawn any type, but an Explore agent can only spawn more Explore agents. This prevents privilege escalation down the tree.

### Agent Modes

Modes control what the interactive user can do (orthogonal to agent types):

| Mode | Tools Available | Use Case |
|------|----------------|----------|
| **Edit** | All top-level tools + task/ask tools | Default — full coding access |
| **Plan** | Read-only + bash/shell + subagent + task/ask + memory/session + Lua | Research and plan without writes |
| **Ask** | Read-only + bash/shell + task/ask + memory/session + Lua docs | Answer questions using file context |

Switch modes with `/edit`, `/plan`, or `/ask`.

### Subagent System

#### Key Classes

```
SubagentOrchestrator        Manages concurrency, dependencies, retries, stats
SubagentFactory             Creates isolated AgentLoop instances for child agents
SubagentTool                LLM-callable tool that triggers agent spawning
AgentContext                Immutable context passed down the agent tree (depth, type, cancellation)
SubagentStats               Per-agent metrics (status, tokens, tool calls, elapsed time)
```

#### Spawning Flow

1. LLM calls the `subagent` tool with `type`, `task`, and optional `id`, `depends_on`, `group`
2. `SubagentTool` validates the request against `AgentContext` (depth limit, allowed child types)
3. `SubagentOrchestrator.spawnAgent()` handles the lifecycle:
   - Waits for dependencies (if `depends_on` specified)
   - Acquires concurrency semaphore (default: 10 concurrent agents)
   - Acquires group semaphore (if `group` specified — sequential within group)
   - Executes via `SubagentFactory.createAndRunAgent()` with retry logic
   - Stores result and updates stats

#### Dependency Resolution

Agents can declare dependencies on other agents via `depends_on: ["agent_id_1", "agent_id_2"]`. The orchestrator:
- Waits for all dependencies to complete before starting the agent
- Injects dependency results into the agent's task prompt
- Detects circular dependencies via DFS before spawning (throws on cycles)

#### Retry Policy

Failed agents are retried up to `max_retries` times (default: 2) with exponential backoff and jitter. Auth errors (401, 403) are never retried. The retry loop is integrated with stats tracking (`status: retrying`, retry counter).

#### Concurrency Control

- **Global semaphore**: Limits total concurrent agents (configurable via `agent.subagent_concurrency`)
- **Group semaphore**: Agents with the same `group` value run sequentially
- **Max depth**: Limits agent tree depth (default: 3, configurable via `agent.subagent_max_depth`)

#### Runtime Notes

- `await` subagents block the parent tool call until completion.
- `background` subagents return immediately and inject results back into the parent on the next LLM turn.
- Parent and child agents share the same orchestrator tree, but child tool access is narrowed by agent type.
- The interactive `/agents` command renders aggregated swarm status from orchestrator stats.

#### Stuck Detection

Headless (subagent) loops use `StuckDetector` to prevent infinite tool call loops:

1. Maintains a rolling window of 8 tool call signatures
2. If the latest signature appears 3+ times: **nudge** — injects a system message asking the agent to consolidate
3. After 2 more turns still stuck: **final notice** — stronger instruction to stop
4. After 2 more turns: **force return** — terminates the agent and returns the last response

The detector resets when the agent starts making diverse tool calls again.

### Display System

#### TUI Mode

Subagent display is managed by `SubagentDisplayManager`:
- `showSpawn()` — shows which agents were spawned (tree widget)
- `showRunning()` — starts elapsed timer with done count
- `refreshTree()` — updates live tree with per-agent status icons (running, done, failed, waiting)
- `showBatch()` — shows completed results with stats

All widgets are placed inside a wrapper `ContainerWidget` so they stay inline at the spawn position (not pushed to the bottom by subsequent conversation widgets).

The breathing animation timer (owned by `TuiAnimationManager`) delegates tree refresh to `SubagentDisplayManager.tickTreeRefresh()` every ~0.5s.

#### ANSI Mode

Uses `AgentDisplayFormatter` (shared static utilities) for consistent formatting:
- `formatAgentLabel()` — colored type + id + task preview
- `formatElapsed()` — human-readable duration ("42s", "1m 30s")
- `formatAgentStats()` — elapsed + tool count
- `renderChildTree()` — box-drawing tree with status icons

#### Swarm Dashboard

The `/agents` command shows a live dashboard (`SwarmDashboardWidget` in TUI, `formatDashboard()` in ANSI) with:
- Progress bar and completion percentage
- Active agents with per-agent progress bars
- Resource usage (tokens, cost, rate, ETA)
- Failure summary with retry stats
- Breakdown by agent type

### Configuration

In `config/kosmokrator.yaml`:

```yaml
agent:
  subagent_max_depth: 3
  subagent_concurrency: 10
  subagent_max_retries: 2
```
