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
    AI coding agent for the terminal, headless automation, editors, and apps
</p>

<p align="center">
    <strong><a href="https://kosmokrator.dev">Documentation website: kosmokrator.dev</a></strong>
</p>

<p align="center">
    <img src="https://img.shields.io/badge/php-%3E%3D8.4-8892BF?logo=php&logoColor=white" alt="PHP 8.4+" />
    <img src="https://img.shields.io/github/license/OpenCompanyApp/kosmokrator" alt="License" />
    <img src="https://img.shields.io/github/v/release/OpenCompanyApp/kosmokrator?label=latest" alt="Latest Release" />
</p>

---

KosmoKrator is a mythology-themed AI coding agent with one reusable runtime and several entry points. Use it interactively in the terminal, run it headlessly from scripts and CI, call integrations and MCP servers directly, embed it in PHP through the SDK, or wrap it from non-PHP apps through ACP.

The agent can read, write, and edit files, search your codebase, execute shell commands, run Lua workflows, call OpenCompany integrations, call MCP servers, and spawn parallel subagents. Permission modes, blocked paths, trust checks, sessions, settings, and credentials are shared across the terminal, headless CLI, SDK, and ACP surfaces.

Built with **PHP 8.4+**, **Symfony Console**, **Symfony TUI**, and async streaming via **Amp/Revolt**. The current provider catalog exposes 120+ provider configurations and 4,800+ catalogued models through Prism, provider-native clients, OpenAI-compatible APIs, OAuth, and custom providers.

## Table of Contents

- [Installation](#installation)
- [CLI Usage](#cli-usage)
- [Headless Agent CLI](#headless-agent-cli)
- [Headless Configuration](#headless-configuration)
- [ACP Server](#acp-server)
- [Agent SDK](#agent-sdk)
- [Integration CLI](#integration-cli)
- [MCP CLI](#mcp-cli)
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
- [Changelog & Releases](#changelog--releases)
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
kosmokrator                         # Auto-detect renderer: TUI when available, ANSI fallback
kosmokrator --renderer=tui          # Force TUI mode
kosmokrator --renderer=ansi         # Force ANSI mode
kosmokrator --no-animation          # Skip the animated intro
kosmokrator --resume                # Resume the last session for the current project
kosmokrator --session <id>          # Resume a specific session by ID or prefix
```

The installed binary and `bin/kosmokrator` from a source checkout expose the same command surface.

## Headless Agent CLI

Passing a prompt enables non-interactive execution. Use `-p`/`--print` when you want KosmoKrator to complete the task and exit:

```bash
kosmokrator -p "Explain the routing in this repository"
kosmokrator -p "Run the test suite and fix the failing tests" --mode edit --permission-mode guardian
kosmokrator -p "Review this branch" --mode plan --output-format json
kosmokrator -p "Summarize the architecture" --mode ask --output-format stream-json --no-session
```

Useful headless options:

| Option | Purpose |
|--------|---------|
| `-p`, `--print` | Run one prompt and exit |
| `-o`, `--output-format` | `text`, `json`, or `stream-json` |
| `-m`, `--model` | Override the configured default model |
| `--mode` | `edit`, `plan`, or `ask` |
| `--permission-mode` | `guardian`, `argus`, or `prometheus` |
| `--yolo` | Alias for `--permission-mode prometheus` |
| `--max-turns` | Cap agentic turns |
| `--timeout` | Cap wall-clock runtime in seconds |
| `--continue` | Continue the most recent session |
| `--session` | Resume a specific persisted session |
| `--system-prompt` | Replace the system prompt |
| `--append-system-prompt` | Append extra system instructions |
| `--no-session` | Disable session persistence for this run |

Headless mode uses `HeadlessRenderer` and the same `AgentSessionBuilder`/`AgentLoop` path as the interactive terminal. Tool permissions still apply. If a headless run cannot ask an approval question, choose a permission mode that matches the automation context.

## Headless Configuration

Everything needed to run KosmoKrator without the interactive setup can be configured from the CLI:

```bash
kosmokrator setup                         # Interactive provider/model wizard
printf %s "$OPENAI_API_KEY" | kosmokrator setup --provider openai \
  --model gpt-5.4-mini --api-key-stdin --global --json

kosmokrator config show --json            # Inspect resolved configuration
kosmokrator config paths --json           # Show global/project config paths
kosmokrator settings:list --json          # Discover configurable settings
kosmokrator settings:set agent.mode plan --global --json
kosmokrator settings:options agent.default_model --provider openai --json
kosmokrator settings:doctor --json        # Diagnose headless readiness

kosmokrator providers:list --json
printf %s "$OPENAI_API_KEY" | \
  kosmokrator providers:configure openai --api-key-stdin --model gpt-5.4-mini --global --json
kosmokrator providers:configure codex --device --global --json
kosmokrator providers:custom:upsert local_ai --url http://127.0.0.1:11434/v1 --model qwen3:32b --json

printf %s "$OPENAI_API_KEY" | \
  kosmokrator secrets:set provider.openai.api_key --stdin --json

printf %s "$TELEGRAM_BOT_TOKEN" | \
  kosmokrator gateway:telegram:configure --token-stdin --enabled on --json
kosmokrator gateway:telegram:status --json
kosmokrator codex:login                   # ChatGPT OAuth for the Codex provider
```

The `settings:*`, `providers:*`, `secrets:*`, `gateway:telegram:*`, `integrations:*`, `mcp:*`, and `web:*` commands are designed for agents and scripts: they provide discovery helpers, stable JSON output, stdin secret entry, and non-zero exit codes on failures.

## ACP Server

KosmoKrator can run as an ACP (Agent Client Protocol) server for editors and
IDEs that speak ACP:

```bash
kosmokrator acp
kosmokrator acp --cwd /path/to/project --mode edit --permission-mode guardian
kosmokrator acp --yolo
```

ACP mode uses newline-delimited JSON-RPC over stdio. Stdout is reserved for
protocol frames; diagnostics go to stderr. It reuses normal KosmoKrator
provider credentials, sessions, permissions, Lua, integrations, MCP, memory,
tasks, and subagents.

Supported ACP surface: initialize, new/load/resume/list/close sessions, prompt
turns, cancellation, streamed assistant chunks, tool call updates, permission
requests, `session/set_config_option`, and legacy `session/set_model` for
clients that still use it. ACP `mcpServers` are added as runtime-only
session MCP overlays and exposed through `app.mcp.*` without writing project
`.mcp.json`.

KosmoKrator also advertises `kosmokratorCapabilities` and emits namespaced
`kosmokrator/*` notifications for wrappers that need the terminal-grade model:
phase changes, text/thinking deltas, tool lifecycle, permission lifecycle,
usage, runtime changes, subagent spawn/tree/dashboard/completion, integration
events, MCP events, and errors. Direct runtime JSON-RPC methods are available
for non-PHP apps:

```text
kosmokrator/runtime/set
kosmokrator/settings/set
kosmokrator/providers/configure
kosmokrator/integrations/list
kosmokrator/integrations/describe
kosmokrator/integrations/configure
kosmokrator/integrations/call
kosmokrator/mcp/list_servers
kosmokrator/mcp/list_tools
kosmokrator/mcp/schema
kosmokrator/mcp/add_stdio_server
kosmokrator/mcp/set_secret
kosmokrator/mcp/call_tool
kosmokrator/lua/execute
```

Those methods require a session ID so project root, permissions, session MCP
overlays, credentials, and Lua namespaces match the active agent.

Example editor configuration:

```json
{
  "agent_servers": {
    "kosmokrator": {
      "command": "kosmokrator",
      "args": ["acp"]
    }
  }
}
```

## Agent SDK

KosmoKrator can also be embedded as a PHP library. The public SDK lives under
`Kosmokrator\Sdk\*` and uses the same runtime path as `kosmokrator -p`:

```php
use Kosmokrator\Sdk\AgentBuilder;

$result = AgentBuilder::create()
    ->forProject('/path/to/project')
    ->withMode('edit')
    ->withPermissionMode('guardian')
    ->withMaxTurns(20)
    ->build()
    ->collect('Fix the failing tests');

echo $result->text;
```

The SDK supports the headless CLI feature set: model/mode/permission overrides,
sessions and resume, system prompt overrides, max turns, timeout, Lua code mode,
integrations, MCP runtime overlays, subagents, callbacks, and programmatic
provider/integration/MCP configuration helpers.

## Integration CLI

KosmoKrator can run as a unified headless CLI for OpenCompany integrations. Use direct commands for single calls and Lua for multi-step workflows. The currently bundled command shortcuts include ClickUp, CoinGecko, Plane, and Plausible; the runtime is catalog-driven, so additional integration packages can expose the same command shape.

```bash
kosmokrator integrations:list --json
kosmokrator integrations:status --json
kosmokrator integrations:doctor clickup --json
kosmokrator integrations:fields clickup --json
kosmokrator integrations:configure clickup --set api_key="$CLICKUP_API_KEY" --enable --read=allow --write=ask --json
kosmokrator integrations:configure plane --account work --set api_key="$PLANE_API_KEY" --enable --json
kosmokrator integrations:search "create clickup task" --json
kosmokrator integrations:docs clickup
kosmokrator integrations:docs clickup.create_task
kosmokrator integrations:schema clickup.create_task

kosmokrator integrations:call clickup.create_task --list-id=123 --name="Ship it" --dry-run
kosmokrator integrations:call clickup.create_task --list-id=123 --name="Ship it" --force
kosmokrator integrations:clickup create_task --list-id=123 --name="Ship it"

kosmokrator integrations:lua --eval 'print(docs.read("clickup.create_task"))'
kosmokrator integrations:lua workflow.lua --force --json
```

Discovery commands expose credentials, activation, CLI compatibility, auth strategy, required runtime binaries, operations, and input schemas. Integrations that cannot be configured fully from the CLI are still listed because future proxy/web flows can support them.

Credentials support account aliases through `--account`, so one provider can have multiple independently configured identities. Headless calls still follow each provider function's read/write permission policy; `--force` bypasses only that integration permission policy for one trusted automation call.

## MCP CLI

KosmoKrator can call MCP servers headlessly and from Lua without registering them as native model tools. It reads the common project `.mcp.json` `mcpServers` shape, plus VS Code/Cursor `servers` files and `~/.kosmokrator/mcp.json`.

Runtime execution is stdio-first. Remote HTTP servers can be used through a stdio bridge such as `mcp-remote` until native Streamable HTTP execution is added.

```bash
kosmokrator mcp:list --json
kosmokrator mcp:add github --project --type=stdio \
  --command=github-mcp-server --env GITHUB_TOKEN --read=allow --write=ask --json
kosmokrator mcp:trust github --project --json
kosmokrator mcp:tools github --json
kosmokrator mcp:schema github.search_repositories --json

kosmokrator mcp:call github.search_repositories --query="kosmokrator" --json
kosmokrator mcp:github search_repositories --query="kosmokrator" --json
kosmokrator mcp:resources github --json
kosmokrator mcp:prompts github --json
kosmokrator mcp:lua workflow.lua --json
```

MCP project servers must be trusted before normal discovery/execution because
they can start local commands. MCP read/write policy is configured separately
under `mcp.*`; `--force` bypasses MCP trust and MCP read/write policy for one
trusted automation call. Agent-side `execute_lua`, `integrations:lua`, and
`mcp:lua` expose configured servers as `app.mcp.*`.

## Agent Modes

Three agent modes control the tool families available to the model. They are separate from permission modes: `edit` can still ask for approval in Guardian/Argus, and `ask` can still run safe read/search commands. Switch modes interactively with `/edit`, `/plan`, and `/ask`, or headlessly with `--mode`.

| Mode | Tools Available | Use Case |
|------|-----------------|----------|
| **Edit** (default) | Full coding tools, shell sessions, Lua execution, subagents, tasks, memories, integrations, MCP through Lua | Implementing features and fixing bugs |
| **Plan** | Read/search/bash/shell/subagent/task/ask/session/Lua docs tools; no file mutation or memory writes | Explore code and produce an implementation plan |
| **Ask** | Read/search/bash/shell/task/ask/session/Lua docs tools; no file mutation, subagents, or Lua execution | Answer questions with file context |

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
| `/prometheus` | Auto-approve governed tool prompts until next prompt; hard denies still apply |

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
| `kosmokrator update` | Check for updates and update based on install method |
| `/feedback <text>` | Submit feedback or a bug report as a GitHub issue (requires `gh` CLI) |
| `/tasks-clear` | Remove all tasks |
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

### Lua

| Tool | Description |
|------|-------------|
| **lua_list_docs** | List Lua and integration documentation pages available to the agent. |
| **lua_search_docs** | Search Lua and integration docs by topic or function name. |
| **lua_read_doc** | Read a specific Lua docs page before writing a script. |
| **execute_lua** | Run Lua with `app.integrations.*`, `app.mcp.*`, and approved native helper access. |

### Web

| Tool | Description |
|------|-------------|
| **web_search** | Search the web through an optional configured web provider. |
| **web_fetch_external** | Fetch a URL through an optional configured external web provider. |
| **web_extract** | Extract a URL through an optional configured external web provider. |
| **web_crawl** | Crawl a site through an optional configured web provider. |

### Memory & Tasks

| Tool | Description |
|------|-------------|
| **memory_search** | Search saved memories by type and text. |
| **memory_save** | Create or update a persistent memory (project, user, or decision). |
| **session_search** | Browse recent sessions or search prior session history for this project. |
| **session_read** | Read a prior session transcript by ID or unique prefix. |
| **task_create** | Create tasks with status tracking. Supports batch creation. |
| **task_update** | Update task status, description, or dependencies. |
| **task_get** | Retrieve a task by ID with full details. |
| **task_list** | List all tasks with status and blocked-by info. |

## Permission System

Three permission modes control tool approval. Governed native tools (`file_write`, `file_edit`, `apply_patch`, `bash`, `shell_start`, `shell_write`, `execute_lua`) go through permission checks. Integration and MCP calls also have read/write policies in headless CLI, Lua, SDK, and ACP flows.

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
- **web_search**, **web_fetch_external**, **web_extract**, **web_crawl** — run only when the relevant web provider setting is enabled

Safe command patterns include: `git`, `ls`, `pwd`, `cat`, `head`, `tail`, `wc`, `find`, `which`, `echo`, `diff`, `php vendor/bin/phpunit`, `php vendor/bin/pint`, `composer`, `npm`, `npx`, `node`, `python`, `cargo`, `go`, `make`.

### Always Enforced

Regardless of permission mode:

- **Blocked paths** are always denied: `*.env`, `.git/*`, `*.pem`, `*id_rsa*`, `*id_ed25519*`, `*.key`
- **Blocked bash patterns** are always denied
- **MCP project trust** is required before normal MCP discovery/execution
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
| **Provider Setup** | Per-provider credential management, custom provider definitions |
| **Auth** | OAuth/device auth state such as Codex login |
| **Agent** | Default mode (edit/plan/ask), temperature, max output tokens, max retries |
| **Permissions** | Default permission mode (guardian/argus/prometheus) |
| **Context & Memory** | Auto compact, compact threshold, token buffer thresholds, prune settings, memories toggle |
| **Gateway** | Telegram gateway enablement and token configuration |
| **Integrations** | Integration activation, credential fields, account aliases, and read/write policy |
| **MCP** | MCP server config, project trust, server secrets, and read/write policy |
| **Web** | Optional web search/fetch/crawl provider configuration |
| **Subagents** | Subagent provider/model overrides (depth 1 and depth 2+), max depth, concurrency, retries, idle watchdog |
| **Advanced** | Lower-level runtime flags and diagnostics |
| **Audio** | Completion sound, soundfont path, composition timeout, max duration, retries |

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

Other runtime state is stored separately:

| Path | Purpose |
|------|---------|
| `~/.kosmokrator/data/` | SQLite sessions, messages, memories, settings, and token accounting |
| `~/.kosmokrator/mcp.json` | Global MCP servers in portable `mcpServers` format |
| `.mcp.json` | Project MCP servers in the common `mcpServers` shape |
| `.vscode/mcp.json`, `.cursor/mcp.json` | Compatibility reads for editor `servers` MCP config |
| Integration credential config | Global/project activation and account aliases managed by `integrations:configure` |

### Key Configuration Options

```yaml
kosmokrator:
  agent:
    default_provider: z
    default_model: glm-5.1
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
bin/kosmokrator
  → Kernel
  → AgentCommand | AcpCommand | integration/mcp/config commands | SDK entry points
  → AgentSessionBuilder
  → AgentLoop
      ├── ToolExecutor → tools + PermissionEvaluator
      ├── ContextManager → compaction, pruning, system prompt
      ├── StuckDetector → headless loop convergence
      ├── LLM client → AsyncLlmClient | PrismService | RetryableLlmClient
      ├── Renderer → TuiRenderer | AnsiRenderer | HeadlessRenderer | ACP/SDK renderers
      ├── ToolRegistry → coding + shell + web + Lua + coordination tools
      ├── IntegrationRuntime → OpenCompany integrations + app.integrations.*
      ├── McpRuntime → stdio MCP + app.mcp.*
      └── SubagentOrchestrator → parallel child agents
```

### Key Directories

| Directory | Purpose |
|-----------|---------|
| `src/Agent/` | Agent core — AgentLoop (REPL), ToolExecutor, ContextManager, StuckDetector, SubagentOrchestrator, SubagentFactory |
| `src/LLM/` | LLM clients — AsyncLlmClient (Amp HTTP, async streaming), PrismService (Prism PHP, sync), RetryableLlmClient (decorator), ModelCatalog, ProviderCatalog |
| `src/UI/Tui/` | Symfony TUI renderer — TuiRenderer, TuiModalManager, TuiAnimationManager, SubagentDisplayManager, widgets |
| `src/UI/Ansi/` | ANSI fallback renderer — AnsiRenderer, MarkdownToAnsi, AnsiTableRenderer |
| `src/UI/HeadlessRenderer.php` | Non-interactive renderer for `-p`, JSON, and stream-json output |
| `src/UI/Diff/` | Unified diff rendering with word-level highlighting |
| `src/Tool/Coding/` | Tool implementations — file, bash, shell, grep, glob, subagent |
| `src/Tool/Permission/` | Permission system — PermissionEvaluator, PermissionMode, Guardian rules |
| `src/Command/` | CLI commands — AgentCommand, AcpCommand, SetupCommand, ConfigCommand, AuthCommand, UpdateCommand, settings, providers, secrets, gateway, integrations, MCP, web |
| `src/Sdk/` | Stable embeddable PHP API over the headless runtime: AgentBuilder, Agent, events, renderers, and configuration helpers |
| `src/Acp/` | Agent Client Protocol stdio server, JSON-RPC transport, session manager, and ACP renderer |
| `src/Command/Slash/` | In-session slash commands |
| `src/Integration/` | Headless OpenCompany integration runtime, catalog, credential resolution, CLI argument mapping |
| `src/Mcp/` | MCP config compatibility, stdio client, permission/trust checks, headless runtime, and Lua bridge |
| `src/Lua/` | Lua sandbox, documentation service, and native tool bridge |
| `src/Session/` | SQLite persistence — sessions, messages, memories, settings |
| `src/Task/` | Task tracking system with tool integrations |
| `src/Audio/` | Completion sound composition and playback |
| `src/Settings/` | Settings schema and multi-layer resolution |

### LLM Clients

KosmoKrator uses two LLM client implementations:

- **AsyncLlmClient** — non-blocking HTTP via Amp. Used for async-capable runtime paths, including TUI and headless surfaces that need OpenAI-compatible streaming behavior. Supports streaming, prompt caching, and tool calling.
- **PrismService** — synchronous client via Prism PHP SDK. Used for ANSI mode or providers with native Prism drivers when that path is selected.

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

## Changelog & Releases

`CHANGELOG.md` is the source of truth for release notes. Add user-facing changes under `## [Unreleased]` as part of the same PR that changes behavior, commands, providers, packaging, docs surfaces, or runtime behavior.

The website changelog is generated from the root changelog during the Astro build:

```bash
cd website
npm run sync:changelog
npm run build
```

Release helpers:

```bash
php bin/changelog check --base-ref=origin/main
php bin/changelog prepare 0.7.2
php bin/changelog extract v0.7.2
```

The release workflow uses `bin/changelog extract "$GITHUB_REF_NAME"` as the GitHub Release body, so tags must have a matching changelog section.

### Conventions

- PSR-4 autoloading: `Kosmokrator\` maps to `src/`
- `declare(strict_types=1)` everywhere
- Agent runs until the LLM signals completion — no hard tool round limit
- Extracted classes communicate via return values and closures — no circular dependencies
- Static utility classes are stateless and side-effect-free
- Markdown responses rendered with league/commonmark and tempest/highlight

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=OpenCompanyApp/kosmokrator&type=Date)](https://star-history.com/#OpenCompanyApp/kosmokrator&Date)

## Documentation

The full documentation site is available at **[https://kosmokrator.dev](https://kosmokrator.dev)**.

## License

MIT
