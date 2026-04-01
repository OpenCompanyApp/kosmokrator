<p align="center">

```
██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗ ████████╗ ██████╗ ██████╗
██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗╚══██╔══╝██╔═══██╗██╔══██╗
█████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   ██║   ██║   ██║██████╔╝
██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║   ██║   ██║   ██║██╔══██╗
██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║   ██║   ╚██████╔╝██║  ██║
╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝
```

</p>

<p align="center">
    AI coding agent for the terminal
</p>

<p align="center">
    <img src="https://img.shields.io/packagist/php-v/kosmokrator/kosmokrator.svg?label=php" alt="PHP Version" />
    <img src="https://img.shields.io/packagist/l/kosmokrator/kosmokrator.svg" alt="License" />
</p>

---

KosmoKrator is a mythology-themed AI coding agent that runs in your terminal. It reads, writes, and edits files, searches your codebase, and executes shell commands — all with a permission system that keeps you in control.

Built with **PHP 8.4**, **Symfony Console**, and a dual renderer (interactive TUI / ANSI fallback). Supports multiple LLM providers via OpenAI-compatible APIs.

## Prerequisites

- **PHP 8.4+** with the `pcntl`, `posix`, and `mbstring` extensions
- **Composer 2.x**
- A terminal with ANSI color support (TUI mode requires a modern terminal like iTerm2, Kitty, or Windows Terminal)

## Quick Start

```bash
# Clone and install
git clone https://github.com/opencompany/kosmokrator.git
cd kosmokrator
composer install

# Configure your API key and provider
bin/kosmokrator setup

# Run
bin/kosmokrator                     # Auto-detects renderer (TUI if available, ANSI fallback)
bin/kosmokrator --renderer=ansi     # Force ANSI mode
bin/kosmokrator --no-animation      # Skip the animated intro
```

## Slash Commands

| Command | Description |
|---------|-------------|
| `/edit` | Full tool access — read, write, edit, search, bash |
| `/plan` | Read-only — explore and produce a detailed plan |
| `/ask` | Read-only — answer questions using file context |
| `/guardian` | Heuristic auto-approve for safe commands |
| `/argus` | Ask permission for every tool call |
| `/prometheus` | Auto-approve all tools until next prompt |
| `/agents` | Show live swarm dashboard with progress and stats |
| `/settings` | Configure model, provider, temperature |
| `/sessions` | List recent sessions |
| `/resume` | Resume a previous session |
| `/compact` | Force context compaction |
| `/new` | Start a new session |
| `/clear` | Clear the screen |
| `/quit` | Exit KosmoKrator |

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

- `src/Agent/` — Agent core: AgentLoop (REPL orchestrator), ToolExecutor, ContextManager, StuckDetector, subagent system (orchestrator, factory, context)
- `src/LLM/` — LLM clients: AsyncLlmClient (Amp HTTP, async), PrismService (Prism PHP, sync), RetryableLlmClient (decorator)
- `src/UI/` — Rendering layer with split interface hierarchy (`RendererInterface` extends 5 focused sub-interfaces)
  - `UI/Tui/` — Interactive Symfony TUI: TuiRenderer + TuiModalManager (dialogs) + TuiAnimationManager (breathing/spinners) + SubagentDisplayManager + widgets
  - `UI/Ansi/` — Pure ANSI fallback: AnsiRenderer, MarkdownToAnsi (with Handler/ for table/list state), AnsiTableRenderer
  - `UI/Diff/` — Unified diff rendering with syntax highlighting and word-level changes
  - `UI/Theme.php` — Shared color palette, tool icons, context bar
- `src/Tool/` — Tool implementations (`Coding/`) and permission system (`Permission/`)
- `src/Command/` — AgentCommand (main REPL), SetupCommand, slash commands in `Slash/`
- `src/Session/` — SQLite persistence: sessions, messages, memories, settings
- `src/Task/` — Task tracking system with tool integrations

### Supported providers

Configured via `bin/kosmokrator setup` or `~/.kosmokrator/config.yaml`:

| Provider | Default model | Env var |
|----------|--------------|---------|
| Z.AI | GLM-5.1 | `ZAI_API_KEY` |
| Anthropic | Claude Sonnet 4 | `ANTHROPIC_API_KEY` |
| OpenAI | GPT-4o | `OPENAI_API_KEY` |
| Ollama | (local) | — |

Any OpenAI-compatible API can be added to `config/prism.yaml`.

### Permission system

Three permission modes control tool approval:

| Mode | Behavior |
|------|----------|
| **Guardian** (default) | Heuristic auto-approve for safe commands (git, ls, composer); ask for writes and unknown bash |
| **Argus** | Ask permission for every tool call |
| **Prometheus** | Auto-approve everything until next prompt |

Tools in `approval_required` (default: `file_write`, `file_edit`, `bash`) prompt before execution. Bash commands matching `blocked_commands` patterns are always denied. Session grants persist for the session duration.

### Subagent system

KosmoKrator can spawn parallel child agents for complex tasks. Agents form a tree with permission narrowing — a General agent can spawn Explore or Plan agents, but not vice versa.

Features: dependency chains (`depends_on`), sequential groups, automatic retries with backoff, stuck detection, live progress tree, and a swarm dashboard (`/agents`).

See [AGENTS.md](AGENTS.md) for full documentation.

### Rendering

`RendererInterface` is composed from 5 focused sub-interfaces (Core, Tool, Dialog, Conversation, Subagent):

- **TuiRenderer** — Full interactive TUI: editor widget with multi-line input, collapsible tool results, syntax highlighting, progress bar, slash command autocomplete, and message queuing during thinking. Delegates to TuiModalManager (overlay dialogs), TuiAnimationManager (breathing animation/spinners/phase transitions), and SubagentDisplayManager (subagent lifecycle display).
- **AnsiRenderer** — Pure ANSI fallback: readline input, CommonMark→ANSI markdown rendering, tempest/highlight syntax coloring

Both renderers share `Theme` for a consistent mythology-themed aesthetic — planetary tool icons, cosmic spinner animations, and mythological thinking phrases.

### Context management

Long conversations are managed automatically:
- **Compaction** — LLM-based summarization of older messages, with durable memory extraction
- **Pruning** — removes superseded tool results (e.g., old file reads replaced by edits)
- **Deduplication** — detects and removes duplicate/stale tool outputs
- **Trimming** — emergency fallback that drops oldest messages when context overflows

## Configuration

Config is loaded in layers (later overrides earlier):

1. `config/*.yaml` — bundled defaults
2. `~/.kosmokrator/config.yaml` — user config
3. `.kosmokrator.yaml` — project config (in working directory)

Environment variables (`${VAR_NAME}`) are resolved in all YAML files.

### Agent configuration

```yaml
kosmokrator:
  agents:
    max_depth: 3          # Maximum agent tree depth
    concurrency: 3        # Maximum concurrent agents
    max_retries: 2        # Retry attempts for failed agents
```

## Development

```bash
composer install
php vendor/bin/phpunit          # Run tests (~780 tests)
php vendor/bin/pint             # Code style (Laravel Pint)
```

## Conventions

- PSR-4: `Kosmokrator\` → `src/`
- `declare(strict_types=1)` everywhere
- Agent runs until the LLM signals completion (no hard tool round limit)
- Tool approval required for mutating operations (configurable)
- Markdown responses rendered with league/commonmark + tempest/highlight
- Extracted classes communicate via return values and closures — no circular dependencies

## License

MIT
