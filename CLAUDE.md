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
                                            ├── ToolRegistry → tools (bash, file_read, file_write, file_edit, grep, glob)
                                            └── SubagentOrchestrator → parallel child agents
```

### Key directories

- `src/Agent/` — Agent core: AgentLoop (REPL orchestrator), ToolExecutor, ContextManager, StuckDetector, subagent system
- `src/LLM/` — LLM clients: AsyncLlmClient (Amp HTTP, async), PrismService (Prism PHP, sync), RetryableLlmClient (decorator)
  - **prism-relay boundary**: Provider-specific logic (SSE parsing quirks, `stream_options` support, usage extraction formats, `finish_reason` mapping, error normalization, prompt caching strategies) belongs in the `opencompany/prism-relay` package, NOT in KosmoKrator's `src/LLM/`. KosmoKrator's LLM layer should only contain agent-level orchestration: retry policy, streaming-to-UI bridging, cancellation handling. Provider capabilities (e.g. `supportsStreamUsage()`) are declared in prism-relay's `ProviderCapabilities` and `RelayRegistry`, consumed via the registry in AsyncLlmClient.
- `src/UI/` — Rendering layer with split interface hierarchy
  - `UI/Tui/` — Symfony TUI renderer: TuiRenderer, TuiModalManager (dialogs), TuiAnimationManager (breathing/spinners), SubagentDisplayManager, widgets
  - `UI/Ansi/` — Pure ANSI fallback: AnsiRenderer, MarkdownToAnsi (with Handler/ for table/list extraction), AnsiTableRenderer
  - `UI/Diff/` — Unified diff rendering with word-level highlighting
  - `UI/Theme.php` — Shared color palette, tool icons, context bar
  - `UI/AgentDisplayFormatter.php` — Shared agent display utilities (used by both renderers)
  - `UI/AgentTreeBuilder.php` — Builds agent tree from orchestrator stats
- `src/Tool/` — Tool implementations in `Coding/`, permission system in `Permission/`
- `src/Command/` — AgentCommand (main REPL), SetupCommand, slash commands in `Slash/`
- `src/Session/` — SQLite persistence: sessions, messages, memories, settings
- `src/Task/` — Task tracking system with tool integrations

### Rendering

`RendererInterface` is composed from 5 focused sub-interfaces:
- `CoreRendererInterface` — lifecycle, streaming, status, phase transitions
- `ToolRendererInterface` — tool call/result display, permission prompts
- `DialogRendererInterface` — settings, session picker, plan approval, user questions
- `ConversationRendererInterface` — history clear/replay
- `SubagentRendererInterface` — subagent status, spawn/batch display, dashboard

Two renderers implement the full interface:
- **TuiRenderer** — Interactive Symfony TUI with widgets, Revolt event loop, EditorWidget for multi-line input. Delegates to TuiModalManager (overlay dialogs), TuiAnimationManager (breathing/spinners/phase), and SubagentDisplayManager (subagent lifecycle).
- **AnsiRenderer** — Pure ANSI escape codes, readline input, MarkdownToAnsi for response formatting

Both use `Theme` for colors and `KosmokratorTerminalTheme` for syntax highlighting via tempest/highlight.

### Agent internals

AgentLoop is a thin orchestrator (~570 lines) that delegates to:
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

## CLI Tools

### mcp-cli
- Installed at `~/.local/bin/mcp-cli` — a lightweight CLI for testing and calling MCP servers
- Config: `~/.config/mcp/mcp_servers.json`
- Usage: `mcp-cli` (list all), `mcp-cli info <server>` (details), `mcp-cli call <server> <tool> '<json>'` (call a tool)
- Connected servers: `founder-mode`, `notion`, `vibe_kanban`, `plane`

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
