# KosmoKrator Overview

KosmoKrator is a terminal coding agent built in PHP. The shipped product today is a CLI application with a dual renderer, an embeddable PHP Agent SDK over headless execution, an ACP stdio server, a headless integrations CLI, a headless MCP CLI, Lua scripting, a tool-driven agent loop, session persistence, context management, slash commands, power commands, a skill system, and a subagent system.

This document is the current-state architecture summary. Proposal and roadmap material lives in `docs/proposals/` and is explicitly labeled there.

## Current Implementation

### Runtime

The runtime entry path is:

```text
bin/kosmokrator
  → Kernel
  → AgentCommand
  → AgentSessionBuilder
  → AgentLoop
```

Key responsibilities:

- `Kernel` boots the Illuminate container, YAML config, logging, Prism provider wiring, SQLite persistence, tools, and commands.
- `AgentSessionBuilder` assembles UI, LLM client, permission evaluator, tool registry, session manager, context management helpers, and subagent infrastructure for an interactive session.
- `AgentLoop` runs the prompt → LLM → tools → LLM loop and handles persistence, mode filtering, context health, and status reporting.

### UI

KosmoKrator ships with renderers behind `RendererInterface`:

- `TuiRenderer` for the interactive Symfony TUI experience
- `AnsiRenderer` for ANSI/readline fallback
- `HeadlessRenderer` for `-p`, JSON, and stream-json command execution
- `NullRenderer` for headless subagent loops (auto-approves permissions)

The shared UI layer also includes diff rendering, theming, terminal notifications, subagent tree formatting, and modal/dialog helpers for settings, approvals, and dashboards.

### Tools and Modes

Built-in tool families:

- Coding tools: `file_read`, `file_write`, `file_edit`, `apply_patch`, `glob`, `grep`, `bash`
- Shell session tools: `shell_start`, `shell_write`, `shell_read`, `shell_kill`
- Coordination tools: `subagent`, `task_create`, `task_update`, `task_get`, `task_list`
- Interactive tools: `ask_user`, `ask_choice`
- Memory/session tools: `memory_save`, `memory_search`, `session_search`, `session_read`
- Lua tools: `lua_list_docs`, `lua_search_docs`, `lua_read_doc`, `execute_lua`

Interactive agent modes:

- `Edit`: full tool access
- `Plan`: read/search/bash/shell/subagent/task/ask/session/Lua tools, but no file mutation tools or memory writes
- `Ask`: read/search/bash/shell/task/ask/session/Lua docs tools, but no file mutation tools, subagents, or Lua execution

Permission modes are separate from agent modes:

- `Guardian`: auto-approve safe reads and safe bash, ask for riskier calls
- `Argus`: ask for approval on governed tool calls
- `Prometheus`: auto-approve governed calls except absolute denies

Blocked paths and blocked command patterns are always enforced.

### Persistence and State

KosmoKrator persists state in SQLite under `~/.kosmokrator/data`:

- Sessions and message history
- Global and project-scoped settings
- Memories and compaction summaries
- Token accounting metadata used for status and resume flows

User-visible session flows include `/sessions`, `/resume`, `/new`, `/compact`, `/memories`, and `/forget`.

### Context Management

The current context pipeline is layered:

- output truncation for oversized tool results
- deduplication of superseded tool results
- pruning of older low-value tool outputs
- LLM-based compaction with optional memory extraction
- oldest-turn trimming as an overflow fallback

This is implemented today. Future context experiments live in `docs/proposals/context-management-strategies.md` and are not part of the shipped behavior unless stated otherwise.

### Subagents

KosmoKrator ships with a working subagent system:

- agent types: `general`, `explore`, `plan`
- dependency chains with `depends_on`
- sequential groups with `group`
- `await` and `background` execution modes
- retry handling for retryable failures
- concurrency limiting
- live tree/dashboard rendering via `/agents`

See `AGENTS.md` and `docs/architecture/subagent-architecture.md` for implementation details.

### Headless Integrations, MCP, and Lua

KosmoKrator also exposes OpenCompany integration packages without starting an
agent session:

- `integrations:list`, `integrations:status`, `integrations:search`, `integrations:docs`, `integrations:schema`, `integrations:examples`
- `integrations:call provider.function` for direct calls
- dynamic shortcuts like `integrations:plane list_issues`
- `integrations:lua` for multi-step Lua workflows over `app.integrations.*` and `app.mcp.*`

KosmoKrator also reads portable MCP config:

- project `.mcp.json` with top-level `mcpServers`
- compatibility reads for `.vscode/mcp.json` and `.cursor/mcp.json` with top-level `servers`
- global `~/.kosmokrator/mcp.json`
- `mcp:list`, `mcp:add`, `mcp:trust`, `mcp:tools`, `mcp:schema`, `mcp:call`, dynamic `mcp:<server>` shortcuts, `mcp:lua`, resource/prompt commands, and MCP secret commands
- MCP servers are exposed to Lua under `app.mcp.*`, not registered as native model tools

The same runtime is available inside agent Lua through `execute_lua`, with
documentation discovery via `lua_list_docs`, `lua_search_docs`, and
`lua_read_doc`.

### Agent SDK

KosmoKrator exposes the headless runtime as a PHP SDK under `Kosmokrator\Sdk`.

- `AgentBuilder` is the stable entry point for embedding `kosmokrator -p` behavior in PHP applications.
- `Agent::collect()` executes one headless task and returns `AgentResult`.
- `Agent::stream()` returns the event sequence for a run, while `CallbackRenderer` receives events during execution for WebSocket/custom UI surfaces.
- SDK runs use `AgentSessionBuilder::buildHeadless()` with an SDK renderer, so model/mode/permission overrides, sessions, Lua, integrations, MCP, context management, subagents, max turns, timeout, and stuck detection share the CLI headless path.
- `Sdk\Config\ProviderConfigurator`, `IntegrationConfigurator`, `McpConfigurator`, and `SecretConfigurator` provide programmatic equivalents to the headless configuration commands.
- `Agent::integrations()` and `Agent::mcp()` expose the same direct call and Lua runtimes used by `integrations:*` and `mcp:*`.
- `Agent::close()` explicitly releases runtime clients for long-lived workers that use direct SDK helpers.

### ACP

KosmoKrator ships an Agent Client Protocol stdio server:

- `kosmokrator acp` starts newline-delimited JSON-RPC over stdin/stdout for editors and IDEs.
- ACP sessions are normal persisted KosmoKrator sessions and can be resumed from either ACP clients or the terminal CLI.
- ACP prompt turns use the same `AgentLoop`, permission evaluator, tool registry, Lua runtime, integrations, MCP runtime, memory, tasks, and subagent infrastructure as the terminal UI.
- Guardian and Argus permission prompts are bridged to ACP `session/request_permission`; Prometheus remains autonomous while hard policy denies still apply.
- Client-provided stdio `mcpServers` are runtime-only session overlays and are not written to project `.mcp.json`.
- Supported base ACP methods include initialize, authenticate, session new/load/resume/list/prompt/cancel/close, mode switching, model switching, and config option updates.
- The server advertises `kosmokratorCapabilities` and emits `kosmokrator/*` extension notifications for native UI wrappers: phase changes, text/thinking deltas, tool lifecycle, permission lifecycle, runtime changes, usage, subagent spawn/tree/dashboard/completion, integration events, MCP events, and errors.
- Direct extension methods expose the same headless runtime surfaces to non-PHP clients: runtime settings, provider configuration, integration configuration/list/describe/call, MCP configuration/server/tool/schema/call, and `kosmokrator/lua/execute`.

### Key Directories

| Directory | Purpose |
|-----------|---------|
| `src/Agent/` | Agent core: AgentLoop, ToolExecutor, ContextManager, StuckDetector, subagent system, events |
| `src/LLM/` | LLM clients: AsyncLlmClient, PrismService, RetryableLlmClient, model catalog, pricing |
| `src/UI/` | Rendering: TuiRenderer, AnsiRenderer, HeadlessRenderer, NullRenderer, diff rendering, theming |
| `src/Tool/` | Tool implementations and permission system |
| `src/Command/` | AgentCommand, SetupCommand, ConfigCommand, AuthCommand, UpdateCommand, gateway commands, integration commands, slash commands, power commands |
| `src/Command/Slash/` | 22 interactive slash commands (`/edit`, `/compact`, `/settings`, etc.) |
| `src/Command/Power/` | 22 power commands (`:autopilot`, `:review`, `:team`, `:unleash`, etc.) |
| `src/Command/Integration/` | Headless integration CLI commands and dynamic provider shortcuts |
| `src/Command/Mcp/` | Headless MCP CLI commands and dynamic server shortcuts |
| `src/Command/Web/` | Optional web provider CLI commands for search, fetch/extract, crawl, provider setup, and diagnostics |
| `src/Command/Gateway/` | Gateway configuration/status commands such as Telegram headless setup |
| `src/Sdk/` | Stable embeddable PHP SDK over headless execution: AgentBuilder, Agent, events, renderers, config helpers |
| `src/Integration/` | Integration catalog, runtime, credential resolution, command argument coercion, Lua invoker |
| `src/Mcp/` | MCP config store, stdio client, catalog, trust/permissions, secrets, runtime, Lua invoker |
| `src/Lua/` | Lua sandbox service, documentation registry, native tool bridge |
| `src/Web/` | Web provider abstractions, fetch/search/crawl providers, safety guards, extraction, cache |
| `src/Gateway/` | Telegram gateway runtime, routing, approval/pending input stores, gateway renderers |
| `src/Session/` | SQLite persistence: sessions, messages, memories, settings |
| `src/Task/` | Task tracking with tree structure and dependency enforcement |
| `src/Skill/` | Skill system: YAML-based custom prompts with `$skillname` dispatch |
| `src/Settings/` | Layered settings resolution (project → global → default) |
| `src/Provider/` | Service providers for DI container wiring (12 providers) |
| `src/Update/` | Self-updater with GitHub release checking |
| `src/Audio/` | Completion sounds (LLM-composed MIDI per session) |

## What Is Not Implemented

These are still proposal or future-work areas, not shipped runtime features:

- Lua code mode as a dedicated interactive agent mode (Lua integration scripting is shipped)
- Streamable HTTP MCP transport (stdio MCP is shipped)
- desktop app surface
- provider failover across multiple backends in the main runtime

Documents that discuss these topics are design docs in `docs/proposals/`, not current feature docs.

## Configuration

Config is loaded in layers, with later layers overriding earlier ones:

1. bundled defaults in `config/*.yaml`
2. user config in `~/.kosmokrator/config.yaml`
3. project config in `.kosmokrator.yaml`

Important config areas:

- `config/prism.yaml` for provider endpoints and API keys
- `config/models.yaml` for model metadata such as context windows and pricing
- `config/kosmokrator.yaml` for agent behavior, permission defaults, UI settings, and context thresholds

Environment variables in YAML are expanded using `${VAR_NAME}`.

## Documentation Map

See [docs/README.md](../README.md) for the full documentation index.

Current-truth docs:

- `README.md`: installation, usage, and high-level architecture
- `AGENTS.md`: subagent architecture and orchestration model
- `docs/architecture/permission-modes.md`: agent-mode and permission-mode behavior
- `docs/architecture/subagent-architecture.md`: current subagent behavior and configuration

Proposal and reference material lives in `docs/proposals/`. Historical audits live in `docs/audits/`.
