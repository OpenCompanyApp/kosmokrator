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
    <img src="https://img.shields.io/badge/php-%3E%3D8.4-8892BF?logo=php&logoColor=white" alt="PHP 8.4+" />
    <img src="https://img.shields.io/github/license/OpenCompanyApp/kosmokrator" alt="License" />
    <img src="https://img.shields.io/github/v/release/OpenCompanyApp/kosmokrator?label=latest" alt="Latest Release" />
</p>

---

KosmoKrator is a mythology-themed AI coding agent that runs in your terminal. It reads, writes, and edits files, searches your codebase, executes shell commands, and spawns parallel subagents — all with a permission system that keeps you in control.

Built with **PHP 8.4**, **Symfony Console**, **Symfony TUI**, and async streaming via **Amp/Revolt**. Supports 20+ LLM providers through OpenAI-compatible APIs and Prism PHP.

## Table of Contents

- [Installation](#installation)
- [CLI Usage](#cli-usage)
- [Agent Modes](#agent-modes)
- [Slash Commands](#slash-commands)
- [Tools](#tools)
- [Permission System](#permission-system)
- [Subagent System](#subagent-system)
- [Context Management](#context-management)
- [Providers](#providers)
- [Settings](#settings)
- [Configuration Files](#configuration-files)
- [Sessions & Persistence](#sessions--persistence)
- [Completion Sounds](#completion-sounds)
- [Rendering](#rendering)
- [Architecture](#architecture)
- [Development](#development)
- [License](#license)

## Installation

### Quick install (recommended)

Auto-detects your OS and architecture:

```bash
curl -fsSL https://raw.githubusercontent.com/OpenCompanyApp/kosmokrator/main/install.sh | bash
```

### Manual download

Pick the binary for your platform — no PHP required:

```bash
# macOS (Apple Silicon)
sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-macos-aarch64 \
  -o /usr/local/bin/kosmokrator && sudo chmod +x /usr/local/bin/kosmokrator

# macOS (Intel)
sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-macos-x86_64 \
  -o /usr/local/bin/kosmokrator && sudo chmod +x /usr/local/bin/kosmokrator

# Linux (x86_64)
sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-linux-x86_64 \
  -o /usr/local/bin/kosmokrator && sudo chmod +x /usr/local/bin/kosmokrator

# Linux (ARM)
sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-linux-aarch64 \
  -o /usr/local/bin/kosmokrator && sudo chmod +x /usr/local/bin/kosmokrator
```

### PHAR (requires PHP 8.4+)

If you already have PHP installed, the PHAR is smaller (~5MB vs ~25MB):

```bash
sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator.phar \
  -o /usr/local/bin/kosmokrator && sudo chmod +x /usr/local/bin/kosmokrator
```

### From source

```bash
git clone https://github.com/OpenCompanyApp/kosmokrator.git
cd kosmokrator
composer install
bin/kosmokrator setup
```

Requires PHP 8.4+ with `pcntl`, `posix`, and `mbstring` extensions, and Composer 2.x.

### Getting started

```bash
kosmokrator setup    # First run — select provider and enter API key
kosmokrator          # Start the agent
```

TUI mode requires a modern terminal (iTerm2, Kitty, Ghostty, Windows Terminal, etc.). Falls back to ANSI mode automatically on simpler terminals.

## CLI Usage

```bash
bin/kosmokrator                         # Auto-detect renderer (TUI if available, ANSI fallback)
bin/kosmokrator --renderer=tui          # Force TUI mode
bin/kosmokrator --renderer=ansi         # Force ANSI mode
bin/kosmokrator --no-animation          # Skip the animated intro
bin/kosmokrator --resume                # Resume the last session for the current project
bin/kosmokrator --session <id>          # Resume a specific session by ID or prefix
```

Other commands:

```bash
bin/kosmokrator setup                   # First-run provider/model wizard
bin/kosmokrator auth status [provider]  # Check authentication status
bin/kosmokrator auth login <provider>   # Authenticate (--api-key or --device for OAuth)
bin/kosmokrator auth logout <provider>  # Clear stored credentials
```

## Agent Modes

Three modes control what the agent is allowed to do. Switch modes at any time with slash commands.

| Mode | Tools Available | Use Case |
|------|-----------------|----------|
| **Edit** (default) | Full access — read, write, edit, patch, bash, subagents | Implementing features, fixing bugs |
| **Plan** | Read-only — file_read, glob, grep, bash | Explore code and produce a plan before committing |
| **Ask** | Read-only — same as Plan | Answer questions with file context |

## Slash Commands

Type these at the prompt during a session.

**Modes**

| Command | Description |
|---------|-------------|
| `/edit` | Switch to Edit mode (full tool access) |
| `/plan` | Switch to Plan mode (read-only exploration) |
| `/ask` | Switch to Ask mode (Q&A with file context) |

**Permissions**

| Command | Description |
|---------|-------------|
| `/guardian` | Heuristic auto-approve for safe commands (default) |
| `/argus` | Ask permission for every tool call |
| `/prometheus` | Auto-approve all tools until next prompt |

**Session Management**

| Command | Description |
|---------|-------------|
| `/new` | Clear conversation and start a new session |
| `/sessions` | List recent sessions |
| `/resume` | Resume a previous session |
| `/rename [name]` | Rename the current session |

**Context & Memory**

| Command | Description |
|---------|-------------|
| `/compact` | Force context compaction now |
| `/memories` | List stored memories |
| `/forget <id>` | Delete a memory by ID |

**Utilities**

| Command | Description |
|---------|-------------|
| `/settings` | Open the settings workspace |
| `/agents` | Show the live subagent swarm dashboard |
| `/update` | Check for updates and self-update |
| `/feedback <text>` | Submit feedback or a bug report as a GitHub issue (requires `gh` CLI) |
| `/tasks clear` | Remove all tasks |
| `/clear` | Clear the terminal screen |
| `/theogony` | Replay the mythological intro animation |
| `/quit` | Exit KosmoKrator |

## Tools

KosmoKrator provides the LLM with a set of tools for interacting with your codebase and environment.

### File Operations

| Tool | Description |
|------|-------------|
| **file_read** | Read file contents with line numbers. Supports offset/limit for partial reads. Caches unchanged content to avoid redundant context. |
| **file_write** | Write entire files (new or complete overwrite). Creates missing parent directories. |
| **file_edit** | Targeted find-and-replace edits within a file. |
| **apply_patch** | Apply unified diff patches. Multi-file and multi-hunk support. |

### Search

| Tool | Description |
|------|-------------|
| **grep** | Regex-powered code search (ripgrep-style). Supports file type/glob filters, content/file/count output modes, multiline matching. |
| **glob** | Fast file pattern matching (`**/*.ts`, `src/**/*.php`). Returns results sorted by modification time. |

### Shell

| Tool | Description |
|------|-------------|
| **bash** | Execute a shell command. Streams output in real time. Configurable timeout (default 120s). |
| **shell_start** | Start a persistent interactive shell session. Returns a session ID. |
| **shell_write** | Send input to a running shell session. |
| **shell_read** | Read output from a shell session. |
| **shell_kill** | Terminate a shell session. |

### Coordination

| Tool | Description |
|------|-------------|
| **subagent** | Spawn a parallel child agent with its own context window. Supports agent types, dependency chains, sequential groups, and concurrency control. |

### Interaction

| Tool | Description |
|------|-------------|
| **ask_user** | Ask the user a question and wait for a response. |
| **ask_choice** | Present a choice to the user with optional visual mockups. |

### Memory & Tasks

| Tool | Description |
|------|-------------|
| **memory_search** | Search saved memories by type and text. |
| **memory_save** | Create or update a persistent memory (project, user, or decision). |
| **task_create** | Create tasks with status tracking. Supports batch creation. |
| **task_update** | Update task status, description, or dependencies. |
| **task_get** | Retrieve a task by ID with full details. |
| **task_list** | List all tasks with status and blocked-by info. |

## Permission System

Three permission modes control tool approval. Mutating tools (`file_write`, `file_edit`, `apply_patch`, `bash`, `shell_start`, `shell_write`) always go through permission checks.

| Mode | Symbol | Behavior |
|------|--------|----------|
| **Guardian** (default) | ◈ | Auto-approve known-safe operations. Ask for writes and unknown commands. |
| **Argus** | ◉ | Ask permission for every governed tool call. |
| **Prometheus** | ⚡ | Auto-approve all governed calls. Deny rules still enforced. |

### Guardian Heuristics

Guardian auto-approves tool calls that match its safety rules:

- **file_read**, **glob**, **grep** — always auto-approved
- **file_write**, **file_edit** — auto-approved when the target is inside the project root
- **bash** — auto-approved when the command matches a known safe pattern AND contains no shell operators (`;`, `&&`, `||`, `|`, redirects, substitutions, newlines)

Safe command patterns include: `git`, `ls`, `pwd`, `cat`, `head`, `tail`, `wc`, `find`, `which`, `echo`, `diff`, `php vendor/bin/phpunit`, `php vendor/bin/pint`, `composer`, `npm`, `npx`, `node`, `python`, `cargo`, `go`, `make`.

### Always Enforced

Regardless of permission mode:

- **Blocked paths** are always denied: `*.env`, `.git/*`, `*.pem`, `*id_rsa*`, `*id_ed25519*`, `*.key`
- **Blocked bash patterns** are always denied
- **Session grants** persist for the duration of the session — approve once, the tool is approved until you close

See [docs/architecture/permission-modes.md](docs/architecture/permission-modes.md) for the full evaluation order and interaction with agent modes.

## Subagent System

KosmoKrator can spawn parallel child agents, each with their own context window, to handle complex multi-part tasks.

### Agent Types

| Type | Can Write | Can Spawn | Purpose |
|------|-----------|-----------|---------|
| **General** | Yes | General, Explore, Plan | Full coding access |
| **Explore** | No | Explore | Read-only research and search |
| **Plan** | No | Explore | Read-only planning and analysis |

Permissions only narrow downward — a General agent can spawn any type, but an Explore agent can only spawn other Explore agents.

### Features

- **Dependency chains** — agents can depend on other agents and wait for their results
- **Sequential groups** — run a batch of agents one at a time in order
- **Parallel execution** — up to 10 concurrent agents (configurable)
- **Automatic retries** — failed agents retry with exponential backoff (configurable)
- **Stuck detection** — headless agents are monitored for repetitive tool call patterns. Three-stage escalation: nudge, final notice, force return.
- **Per-depth model overrides** — run cheaper/faster models at subagent depths (configured via settings)
- **Live dashboard** — view progress, resource usage, and failures with `/agents`

See [AGENTS.md](AGENTS.md) for full documentation.

## Context Management

Long conversations are managed automatically through a multi-stage pipeline:

1. **Deduplication** — detects and removes redundant tool outputs (e.g., reading the same file twice)
2. **Pruning** — removes superseded tool results (e.g., an old file_read replaced by a file_edit of the same file). Protects recent results (configurable `prune_protect` threshold).
3. **Compaction** — LLM-based summarization of older messages into a concise working memory. Extracts durable memories during compaction. Auto-triggers when context usage crosses the `auto_compact_buffer_tokens` threshold.
4. **Trimming** — emergency fallback that drops the oldest messages when context still overflows after compaction

### Token Budgets

Context management uses a budget model with configurable thresholds:

| Setting | Default | Purpose |
|---------|---------|---------|
| `reserve_output_tokens` | 16,000 | Headroom reserved for the assistant response |
| `warning_buffer_tokens` | 24,000 | Show warnings when remaining input drops below this |
| `auto_compact_buffer_tokens` | 12,000 | Trigger auto-compaction when remaining input drops below this |
| `blocking_buffer_tokens` | 3,000 | Hard stop to prevent overrunning the model context window |

## Providers

Built-in provider configurations are defined in `config/prism.yaml`. KosmoKrator supports:

| Provider | ID(s) | Auth |
|----------|-------|------|
| Anthropic | `anthropic` | API key |
| OpenAI | `openai` | API key |
| Codex (ChatGPT) | `codex` | OAuth (browser/device login) |
| Zhipu AI | `z`, `z-api` | API key |
| Moonshot (Kimi) | `kimi`, `kimi-coding` | API key |
| Xiaomi MiMo | `mimo`, `mimo-api` | API key / token plan |
| Google Gemini | `gemini` | API key |
| DeepSeek | `deepseek` | API key |
| Groq | `groq` | API key |
| Mistral | `mistral` | API key |
| xAI (Grok) | `xai` | API key |
| OpenRouter | `openrouter` | API key |
| Perplexity | `perplexity` | API key |
| MiniMax | `minimax`, `minimax-cn` | API key |
| StepFun | `stepfun`, `stepfun-plan` | API key |
| Ollama | `ollama` | None (local) |

### Custom Providers

You can define custom providers in the settings workspace (`/settings` > Provider Setup) or directly in YAML config:

```yaml
relay:
  providers:
    my-provider:
      label: My Provider
      driver: openai-compatible
      url: https://my-api.example.com/v1
      auth: api_key
      default_model: my-model
      models:
        my-model:
          display_name: My Model
          context: 128000
          max_output: 8192
```

## Settings

Open the settings workspace with `/settings` during a session. Settings are organized into categories:

| Category | Key Settings |
|----------|-------------|
| **General** | Renderer (auto/tui/ansi), theme, intro animation |
| **Models** | Default provider and model. Browse providers and select via the models browser. |
| **Subagents** | Subagent provider/model overrides (depth 1 and depth 2+), max depth, concurrency, retries, idle watchdog |
| **Agent** | Default mode (edit/plan/ask), temperature, max output tokens, max retries |
| **Permissions** | Default permission mode (guardian/argus/prometheus) |
| **Context & Memory** | Auto compact, compact threshold, token buffer thresholds, prune settings, memories toggle |
| **Audio** | Completion sound, soundfont path, composition timeout, max duration, retries |
| **Provider Setup** | Per-provider credential management, custom provider definitions |

### Scopes

Settings can be saved at two scopes:

- **Project** — applies only when KosmoKrator runs in the current working directory. Stored in `.kosmokrator.yaml`.
- **Global** — applies everywhere. Stored in `~/.kosmokrator/config.yaml`.

Project settings override global settings which override built-in defaults.

### Per-Depth Model Overrides

You can assign different models at each agent depth:

- **Main agent** (depth 0) — configured via the default provider/model
- **Subagents** (depth 1) — optional override via subagent provider/model
- **Sub-subagents** (depth 2+) — optional override via sub-subagent provider/model

Each level cascades: depth 2+ falls back to depth 1, which falls back to the main agent defaults.

## Configuration Files

Configuration is loaded in layers (later overrides earlier):

1. `config/kosmokrator.yaml` — bundled defaults
2. `config/prism.yaml` — bundled provider definitions
3. `~/.kosmokrator/config.yaml` — user overrides
4. `.kosmokrator.yaml` — project overrides (in working directory)

Environment variables (`${VAR_NAME}`) are resolved in all YAML files.

### Key Configuration Options

```yaml
kosmokrator:
  agent:
    default_provider: z
    default_model: GLM-5.1
    temperature: 0.0
    max_retries: 0
    subagent_max_depth: 3
    subagent_concurrency: 10
    subagent_max_retries: 2
    subagent_idle_watchdog_seconds: 900

  ui:
    renderer: auto          # auto | tui | ansi
    intro_animated: true

  tools:
    default_permission_mode: guardian
    approval_required:
      - file_write
      - file_edit
      - apply_patch
      - bash
      - shell_start
      - shell_write
    bash:
      timeout: 120
    blocked_paths:
      - "*.env"
      - ".git/*"
      - "*.pem"
      - "*id_rsa*"
      - "*id_ed25519*"
      - "*.key"

  context:
    reserve_output_tokens: 16000
    warning_buffer_tokens: 24000
    auto_compact_buffer_tokens: 12000
    blocking_buffer_tokens: 3000
    compact_threshold: 60

  audio:
    completion_sound: true
    soundfont: ~/.kosmokrator/soundfonts/FluidR3_GM.sf2
    llm_timeout: 60
    max_duration: 8
    max_retries: 1
```

## Sessions & Persistence

KosmoKrator persists state in SQLite under `~/.kosmokrator/data/`:

- **Sessions** — full conversation history with token usage tracking. Auto-titled from the first user message.
- **Messages** — every message in each session, with role, content, and token counts.
- **Memories** — typed memories (project, user, decision) with retention classes (durable, working, priority), optional expiration, and pinning.
- **Settings** — global and project-scoped settings.

Use `/sessions` to list recent sessions, `/resume` to continue a previous session, and `--resume` or `--session <id>` from the command line.

## Completion Sounds

KosmoKrator can compose and play a short musical piece after each agent response. The music reflects the outcome of the task.

- **Outcome classification** — the final message is analyzed to determine the mood: success (fanfare), tests passed (upbeat), tests failed (interrupted drop), failure (minor/descending), question (questioning), and more.
- **Per-session instrument** — each session gets a unique MIDI instrument (piano, vibraphone, guitar, violin, harp, trumpet, flute, etc.) based on a hash of the session ID.
- **LLM-composed** — the LLM generates a Python MIDI script with validation and safety checks. Falls back to hand-crafted scripts if generation fails.
- **Non-blocking** — composition and playback run in a background PHP process. The REPL is never blocked.
- **Requires** — Python 3 with `midiutil`, and `fluidsynth` with a SoundFont (`.sf2`) file.

Enable via `/settings` or set `audio.completion_sound: true` in config.

## Rendering

KosmoKrator has a dual renderer architecture. `RendererInterface` is composed from 5 focused sub-interfaces: Core, Tool, Dialog, Conversation, and Subagent.

### TUI Renderer

The default interactive renderer built on Symfony TUI:

- Full-screen layout with conversation history, status bar, and editor widget
- Multi-line input with Shift+Enter / Alt+Enter
- Slash command autocomplete with Tab
- Collapsible tool results with syntax highlighting
- Overlay dialogs for settings, session picker, plan approval, and permission prompts
- Animated breathing effect and cosmic spinners during thinking
- Live subagent progress tree
- Task bar with context usage
- Context progress bar with token counts and cost

### ANSI Renderer

A pure ANSI escape code fallback for simpler terminals:

- Readline-based input
- CommonMark-to-ANSI markdown rendering via league/commonmark
- Syntax-highlighted code blocks via tempest/highlight
- Inline status updates

Both renderers share `Theme` for a consistent mythology-themed aesthetic — planetary tool icons (☽ ☉ ♅ ⚡︎ ⊛ ✧), cosmic spinner animations, and mythological thinking phrases.

## Architecture

```
bin/kosmokrator → Kernel → AgentCommand → AgentSessionBuilder → AgentLoop (REPL)
                                            ├── ToolExecutor → tools + PermissionEvaluator
                                            ├── ContextManager → compaction, pruning, system prompt
                                            ├── StuckDetector → headless loop convergence
                                            ├── LLM client (AsyncLlmClient or PrismService)
                                            ├── UIManager → TuiRenderer | AnsiRenderer
                                            ├── ToolRegistry → all tools
                                            └── SubagentOrchestrator → parallel child agents
```

### Key Directories

| Directory | Purpose |
|-----------|---------|
| `src/Agent/` | Agent core — AgentLoop (REPL), ToolExecutor, ContextManager, StuckDetector, SubagentOrchestrator, SubagentFactory |
| `src/LLM/` | LLM clients — AsyncLlmClient (Amp HTTP, async streaming), PrismService (Prism PHP, sync), RetryableLlmClient (decorator), ModelCatalog, ProviderCatalog |
| `src/UI/Tui/` | Symfony TUI renderer — TuiRenderer, TuiModalManager, TuiAnimationManager, SubagentDisplayManager, widgets |
| `src/UI/Ansi/` | ANSI fallback renderer — AnsiRenderer, MarkdownToAnsi, AnsiTableRenderer |
| `src/UI/Diff/` | Unified diff rendering with word-level highlighting |
| `src/Tool/Coding/` | Tool implementations — file, bash, shell, grep, glob, subagent |
| `src/Tool/Permission/` | Permission system — PermissionEvaluator, PermissionMode, Guardian rules |
| `src/Command/` | CLI commands — AgentCommand, SetupCommand, AuthCommand |
| `src/Command/Slash/` | In-session slash commands |
| `src/Session/` | SQLite persistence — sessions, messages, memories, settings |
| `src/Task/` | Task tracking system with tool integrations |
| `src/Audio/` | Completion sound composition and playback |
| `src/Settings/` | Settings schema and multi-layer resolution |

### LLM Clients

KosmoKrator uses two LLM client implementations:

- **AsyncLlmClient** — non-blocking HTTP via Amp. Used in TUI mode for providers with OpenAI-compatible APIs. Supports streaming, prompt caching, and tool calling.
- **PrismService** — synchronous client via Prism PHP SDK. Used in ANSI mode or for providers with native Prism drivers (e.g., Anthropic).

Both are wrapped in `RetryableLlmClient` for automatic retry with exponential backoff on transient errors (429, 5xx).

## Development

```bash
composer install
php vendor/bin/phpunit              # Run tests
php vendor/bin/pint                 # Code style (Laravel Pint)
php vendor/bin/phpstan analyse      # Static analysis
```

### Building a PHAR

```bash
php vendor/bin/box compile          # Uses box.json config
```

### Conventions

- PSR-4 autoloading: `Kosmokrator\` maps to `src/`
- `declare(strict_types=1)` everywhere
- Agent runs until the LLM signals completion — no hard tool round limit
- Extracted classes communicate via return values and closures — no circular dependencies
- Static utility classes are stateless and side-effect-free
- Markdown responses rendered with league/commonmark and tempest/highlight

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=OpenCompanyApp/kosmokrator&type=Date)](https://star-history.com/#OpenCompanyApp/kosmokrator&Date)

## License

MIT
