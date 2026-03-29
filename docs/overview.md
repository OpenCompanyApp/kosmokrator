# KosmoKrator — Full Project Overview

KosmoKrator is a CLI coding agent (like Claude Code / OpenCode) built in PHP. It uses Prism-PHP for LLM integration, Symfony Console for the CLI framework, and Symfony TUI for rich terminal rendering. It shares a tool ecosystem with OpenCompany via framework-agnostic Composer packages, and introduces Lua code mode where the LLM writes Lua scripts to orchestrate tool calls instead of using sequential JSON tool_use.

---

## Architecture Decisions

### Single DI Container

Prism-PHP requires `laravel/framework` transitively, which brings Illuminate Container. Rather than running two containers (Illuminate + Symfony DI), KosmoKrator uses **Illuminate Container as the single DI container**. Symfony Console doesn't require Symfony DI — it just needs command instances.

### Prism-PHP, Not laravel/ai

KosmoKrator uses Prism directly for LLM API calls. Laravel AI SDK (`laravel/ai`) is built on top of Prism — it adds agents, queue/broadcast, Eloquent persistence, testing fakes. These are web-app concerns. KosmoKrator builds its own AgentLoop purpose-built for a streaming REPL with TUI rendering.

### YAML Config → Illuminate Config Repository

YAML files are parsed by `symfony/yaml` and loaded into Illuminate's `Config\Repository`, so Prism's `config()` helper works seamlessly while we keep human-friendly YAML. Config merging: defaults → user (`~/.kosmokrator/config.yaml`) → project (`.kosmokrator.yaml`).

### Dual Tool Execution: JSON tool_use + Lua Code Mode

Standard JSON tool_use for simple single-tool calls. Lua code mode for complex multi-step orchestration. The LLM chooses which mode to use. Both coexist.

---

## Current State (What's Built)

Phase 1-4 of the original plan are implemented:

| Component | Status | Location |
|-----------|--------|----------|
| Entry point | Done | `bin/kosmokrator` |
| Kernel (boot + DI) | Done | `src/Kernel.php` |
| Config loader (YAML) | Done | `src/ConfigLoader.php` |
| AgentCommand (REPL) | Done | `src/Command/AgentCommand.php` |
| YAML config files | Done | `config/{app,prism,kosmokrator}.yaml`, `config/themes/default.yaml` |
| RendererInterface | Done | `src/UI/RendererInterface.php` |
| AnsiRenderer + AnsiIntro | Done | `src/UI/Ansi/AnsiRenderer.php`, `src/UI/Ansi/AnsiIntro.php` |
| TuiRenderer | Done | `src/UI/Tui/TuiRenderer.php` |
| UIManager (auto-detects) | Done | `src/UI/UIManager.php` |
| Theme system | Done | `src/UI/Theme.php` |
| PrismService (streaming) | Done | `src/LLM/PrismService.php` |
| AgentLoop (stream + tool loop) | Done | `src/Agent/AgentLoop.php` |
| ConversationHistory | Done | `src/Agent/ConversationHistory.php` |
| Agent events | Done | `src/Agent/Event/*.php` |
| ToolInterface + ToolResult | Done | `src/Tool/ToolInterface.php`, `src/Tool/ToolResult.php` |
| ToolRegistry | Done | `src/Tool/ToolRegistry.php` |
| Coding tools | Done | `src/Tool/Coding/{FileRead,FileWrite,FileEdit,Glob,Grep,Bash}Tool.php` |

### What's Not Built Yet

| Component | Location |
|-----------|----------|
| Lua code mode (sandbox, bridge, doc gen) | `src/Lua/` |
| MCP client | `src/Mcp/` |
| Integration loader (local + hosted) | `src/Integration/` |
| Agent middleware pipeline | `src/Agent/Middleware/` |
| Provider failover | `src/LLM/PrismService.php` (extend) |
| Session persistence | `src/Session/` |
| Twig templates for tool output | `templates/` |

---

## Directory Structure

```
kosmokrator/
├── bin/
│   └── kosmokrator                         # Entry point (executable PHP)
│
├── config/
│   ├── app.yaml                            # App name, version, environment
│   ├── prism.yaml                          # LLM providers, API keys, models
│   ├── kosmokrator.yaml                    # Agent config, UI prefs, tool settings
│   └── themes/
│       └── default.yaml                    # Color palette
│
├── src/
│   ├── Kernel.php                          # Boots Illuminate Container + Symfony Console
│   ├── ConfigLoader.php                    # YAML → Illuminate Config Repository
│   │
│   ├── Command/
│   │   └── AgentCommand.php                # Main REPL command
│   │
│   ├── Agent/
│   │   ├── AgentLoop.php                   # Core loop: prompt → LLM → tools → loop
│   │   ├── ConversationHistory.php         # Message history
│   │   ├── Event/
│   │   │   ├── ThinkingEvent.php
│   │   │   ├── StreamChunkEvent.php
│   │   │   ├── ToolCallEvent.php
│   │   │   ├── ToolResultEvent.php
│   │   │   └── ResponseCompleteEvent.php
│   │   └── Middleware/                     # PLANNED
│   │       ├── AgentMiddleware.php         # Interface
│   │       ├── ApprovalMiddleware.php      # User approval for dangerous tools
│   │       ├── CostTrackingMiddleware.php  # Token/cost tracking
│   │       └── AuditMiddleware.php         # Execution logging
│   │
│   ├── LLM/
│   │   └── PrismService.php               # Wraps Prism: streaming, provider failover
│   │
│   ├── Tool/
│   │   ├── ToolInterface.php               # Contract: name, description, parameters, execute
│   │   ├── ToolResult.php                  # Value object: output, success
│   │   ├── ToolRegistry.php                # Collects tools, converts to Prism Tool format
│   │   └── Coding/
│   │       ├── FileReadTool.php
│   │       ├── FileWriteTool.php
│   │       ├── FileEditTool.php
│   │       ├── GlobTool.php
│   │       ├── GrepTool.php
│   │       └── BashTool.php
│   │
│   ├── Lua/                                # PLANNED — Lua code mode
│   │   ├── LuaSandboxService.php           # LuaSandbox PECL wrapper, memory/CPU limits
│   │   ├── LuaBridge.php                   # Routes app.* calls to PHP tool execution
│   │   └── LuaApiDocGenerator.php          # Auto-generates Lua API docs from tool schemas
│   │
│   ├── Mcp/                                # PLANNED — MCP client
│   │   ├── McpClient.php                   # JSON-RPC 2.0 client (stdio + HTTP)
│   │   ├── McpToolProvider.php             # Wraps MCP tools as ToolInterface
│   │   └── McpSchemaTranslator.php         # MCP schema → ToolInterface parameters
│   │
│   ├── Integration/                        # PLANNED — External tool integrations
│   │   ├── IntegrationLoader.php           # Discovers + loads integration packages
│   │   ├── YamlCredentialResolver.php      # Reads ~/.kosmokrator/integrations.yaml
│   │   └── HostedIntegrationProxy.php      # Proxies tool calls to OpenCompany API
│   │
│   ├── UI/
│   │   ├── RendererInterface.php           # Contract for rendering backend
│   │   ├── UIManager.php                   # Selects TUI or ANSI, delegates
│   │   ├── Theme.php                       # Color palette from config/themes/
│   │   ├── Ansi/
│   │   │   ├── AnsiRenderer.php            # ANSI rendering implementation
│   │   │   └── AnsiIntro.php               # Animated ASCII intro
│   │   └── Tui/
│   │       ├── TuiRenderer.php             # Symfony TUI rendering implementation
│   │       ├── KosmokratorStyleSheet.php   # TUI styles
│   │       └── Widget/                     # PLANNED — TUI widget tree
│   │           ├── SessionWidget.php
│   │           ├── PromptWidget.php
│   │           ├── ResponseWidget.php
│   │           ├── ToolCallWidget.php
│   │           └── StatusBarWidget.php
│   │
│   └── Session/                            # PLANNED
│       ├── Session.php                     # Current session state
│       └── SessionStore.php                # Persist to ~/.kosmokrator/sessions/
│
├── templates/                              # PLANNED — Twig templates
│   ├── tool_call.twig
│   ├── tool_result.twig
│   ├── diff.twig
│   └── status.twig
│
├── docs/
│   ├── overview.md                         # This file
│   ├── ecosystem-architecture.md           # Ecosystem vision (Lua + MCP + OpenCompany)
│   ├── integration-refactor-plan.md        # Plan to refactor integration-core packages
│   └── laravel-ai-patterns.md             # Patterns adopted from Laravel AI SDK
│
├── tests/
│   ├── Unit/
│   └── Feature/
│       └── AgentCommandTest.php
│
├── vendor-src/                             # Symfony TUI source (git clone, not committed)
│   └── symfony/
│
├── composer.json
├── phpunit.xml.dist
├── box.json
└── .gitignore
```

---

## Dependencies

```json
{
    "require": {
        "php": "^8.4",
        "prism-php/prism": "^0.99",
        "symfony/console": "^7.4|^8.0",
        "symfony/event-dispatcher": "^7.4|^8.0",
        "symfony/process": "^7.4|^8.0",
        "symfony/finder": "^7.4|^8.0",
        "symfony/yaml": "^7.4|^8.0",
        "symfony/tui": "dev-tui",
        "revolt/event-loop": "^1.0",
        "twig/twig": "^3.0|^4.0",
        "tempest/highlight": "^2.16",
        "league/commonmark": "^2.9"
    }
}
```

### Future dependencies (when those features land)

| Package | Purpose |
|---------|---------|
| `ext-luasandbox` | Lua code mode runtime (PECL) |
| `php-mcp/client` or `modelcontextprotocol/php-sdk` | MCP client |
| `opencompanyapp/integration-core` | Shared tool contracts |
| `opencompanyapp/integration-*` | Individual tool packages (ClickUp, Google, etc.) |

### Transitive dependencies (via prism-php/prism)

Prism pulls `laravel/framework` v13, which provides:
- `illuminate/container` — DI container (used as single container)
- `illuminate/config` — Config Repository
- `illuminate/events` — Event dispatcher
- `illuminate/http` — HTTP client (used by Prism for API calls)
- `illuminate/support` — Collection, Str, Arr helpers

### TUI installation

Symfony TUI is not on Packagist yet. Install via git clone:

```bash
git clone --branch tui --single-branch https://github.com/fabpot/symfony.git vendor-src/symfony
```

Path repository in `composer.json` symlinks the component. `.gitignore` excludes `vendor-src/`. When TUI ships on Packagist: remove path repo, change to `"symfony/tui": "^1.0"`, delete `vendor-src/`.

---

## Boot Flow

```
bin/kosmokrator
    │
    ├── 1. Require autoloader
    │
    ├── 2. Kernel::boot()
    │      ├── Create Illuminate\Foundation\Application (sets base path)
    │      ├── ConfigLoader: parse YAML → Illuminate Config Repository
    │      │   config/app.yaml        → config('app.*')
    │      │   config/prism.yaml      → config('prism.*')
    │      │   config/kosmokrator.yaml → config('kosmokrator.*')
    │      ├── Register core services (events, filesystem, HTTP client)
    │      ├── Register PrismServiceProvider (binds PrismManager, Prism)
    │      ├── Register custom providers (Z.AI as OpenAI-compatible)
    │      ├── Set Facade root
    │      ├── Register application services:
    │      │   - PrismService (provider, model, system prompt)
    │      │   - ToolRegistry (with all coding tool instances)
    │      └── Build Symfony Console Application
    │
    ├── 3. Register AgentCommand as default command
    │
    └── 4. $console->run()

    AgentCommand::execute()
        │
        ├── Resolve renderer preference (CLI flag or config)
        ├── Create UIManager (auto-detects TUI vs ANSI)
        ├── Render intro (animated or static)
        ├── Create AgentLoop (PrismService + UIManager + ToolRegistry)
        │
        └── REPL loop:
            ├── $input = UIManager::prompt()
            │
            ├── Slash commands: /quit, /exit, /seed, /clear, /reset
            │
            └── AgentLoop::run($input)
                ├── ConversationHistory::addUser($input)
                │
                ├── AGENT LOOP (iterates for tool calls):
                │   ├── UIManager::showThinking()
                │   ├── PrismService::stream(messages, tools)
                │   │   └── Yields Prism StreamEvents
                │   │
                │   ├── For each event:
                │   │   TextDeltaEvent    → UIManager::streamChunk()
                │   │   ToolCallEvent     → collect tool calls
                │   │   ToolResultEvent   → UIManager::showToolResult()
                │   │   StreamEndEvent    → capture finish reason + usage
                │   │   ErrorEvent        → UIManager::showError()
                │   │
                │   ├── If finish reason is ToolCalls:
                │   │   ├── Execute each tool call
                │   │   │   ├── UIManager::showToolCall(name, args)
                │   │   │   ├── Tool::handle(args)
                │   │   │   └── UIManager::showToolResult(name, output, success)
                │   │   ├── Add tool results to conversation
                │   │   └── LOOP (re-call LLM with results)
                │   │
                │   └── No tool calls → break
                │
                ├── UIManager::streamComplete()
                ├── UIManager::showStatus(model, tokens, cost)
                └── ConversationHistory::addAssistant(response)
```

---

## Tool System

### Built-in Coding Tools

These are the core tools for interacting with the filesystem and shell:

| Tool | Description |
|------|-------------|
| `file_read` | Read file contents (with offset/limit) |
| `file_write` | Create or overwrite files |
| `file_edit` | Surgical string replacement in files |
| `glob` | Find files by glob pattern |
| `grep` | Search file contents with regex |
| `bash` | Execute shell commands (with timeout + blocked commands) |

### ToolInterface

```php
interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function parameters(): array;        // JSON Schema properties
    public function requiredParameters(): array; // Required parameter names
    public function execute(array $args): ToolResult;
}
```

`ToolRegistry` converts these to Prism `Tool` instances for LLM requests. The same parameter schemas will be used to auto-generate Lua API docs for code mode.

### Tool Execution Modes

KosmoKrator supports two parallel modes for tool execution:

#### Mode 1: Standard JSON tool_use

The LLM emits structured `tool_use` blocks. Each tool call is one round-trip:

```
LLM: tool_use(file_read, {path: "src/Kernel.php"})
→ execute → result → LLM
LLM: tool_use(file_edit, {path: "src/Kernel.php", ...})
→ execute → result → LLM
```

Good for simple, single-tool operations. This is what's implemented today.

#### Mode 2: Lua Code Mode

The LLM writes a Lua program that orchestrates multiple tools in a single execution:

```lua
-- Single round-trip replaces 5+ sequential tool_use calls
local files = app.glob({pattern = "src/**/*.php"})
for _, f in ipairs(files) do
    local content = app.file_read({path = f})
    if content:find("TODO") then
        print(f)
    end
end
```

The LLM emits one tool_use call to `execute_lua` with the script. KosmoKrator runs it in a sandboxed Lua environment where registered functions map to tool calls.

**Why Lua:**
- 98.7% token reduction vs sequential JSON tool_use (Anthropic engineering blog)
- 20% higher success rate, 30% fewer turns (CodeAct, ICML 2024)
- Designed for embedding, easy to sandbox, simple syntax for LLMs
- LuaSandbox PECL — battle-tested at Wikipedia scale for untrusted code

**Self-discoverable API — the LLM doesn't need all schemas in the system prompt:**

```lua
docs()                                    -- list all namespaces
docs("app.file_read")                     -- full schema + examples
docs("app.gmail.work")                    -- list tools for integration account
```

**Both modes coexist.** Simple read → tool_use. Complex multi-step → Lua. The LLM decides.

---

## Lua Code Mode Architecture

```
┌─────────────┐
│   AgentLoop  │
│              │
│  tool_use:   │
│  execute_lua │
│  {code: ...} │
└──────┬───────┘
       │
┌──────┴───────────────────────────────────────────────────┐
│                    LuaSandboxService                      │
│                                                           │
│  LuaSandbox PECL (Lua 5.1, Wikimedia)                   │
│  ├── setMemoryLimit(32MB)                                │
│  ├── setCPULimit(5s)                                     │
│  ├── Blocked: io, os, debug, package, require, load...   │
│  │                                                       │
│  Registered libraries (whitelist):                       │
│  ├── app.file_read    → FileReadTool::execute()         │
│  ├── app.file_write   → FileWriteTool::execute()        │
│  ├── app.glob         → GlobTool::execute()             │
│  ├── app.bash         → BashTool::execute()             │
│  ├── app.gmail.work.* → integration (local or hosted)   │
│  ├── app.mcp.github.* → MCP server proxy                │
│  ├── docs()           → LuaApiDocGenerator              │
│  └── print()          → capture output                   │
└──────────────────────────────────────────────────────────┘
                         │
                    LuaBridge
                         │
            ┌────────────┼────────────┐
            │            │            │
      Built-in       Integration   MCP Server
      Tools          Packages      Proxy
```

### LuaSandboxService

Manages the Lua runtime. Creates a `LuaSandbox` instance with resource limits, registers tool functions via `registerLibrary()`, executes code, captures output.

### LuaBridge

Routes `app.{namespace}.{tool}()` calls from Lua to PHP:
- Built-in tools: direct `ToolInterface::execute()` call
- Integration packages: resolve via `IntegrationLoader`, check credentials, execute
- MCP tools: proxy via `McpClient::callTool()`

Converts Lua tables ↔ PHP arrays. Logs all bridge calls with execution time.

### LuaApiDocGenerator

Auto-generates function documentation from `ToolInterface::parameters()`. The LLM calls `docs()` inside Lua to discover available functions without bloating the system prompt.

---

## MCP Integration

### KosmoKrator as MCP Client

Connects to external MCP servers, discovers tools via `listTools()`, registers them as Lua functions or standard tool_use definitions.

```
MCP Server (stdio or HTTP)
    │
    ├── listTools() → discover schemas
    └── callTool(name, args) → execute
        │
McpClient (JSON-RPC 2.0)
    │
McpToolProvider (implements ToolInterface per discovered tool)
    │
    ├── Standard tool_use (registered in ToolRegistry)
    └── Lua functions (registered via LuaBridge as app.mcp.{server}.{tool})
```

**Transports:**
- **stdio**: MCP server runs as a child process. Ideal for local CLI tools.
- **HTTP + SSE**: Remote servers. Ideal for hosted OpenCompany.

### KosmoKrator as MCP Server

Exposes built-in tools (file read/write, bash, glob, grep) as an MCP server. Other AI applications (Claude Desktop, IDE extensions) can use KosmoKrator's capabilities.

### Lua + MCP Bridge

MCP tools are registered as Lua functions. The LLM writes Lua that composes MCP calls:

```lua
local issues = app.mcp.github.list_issues({repo = "owner/repo", state = "open"})
for _, issue in ipairs(issues) do
    if issue.labels and issue.labels[1] == "bug" then
        app.clickup.default.create_task({
            name = issue.title,
            description = issue.body,
            list_id = "bug-tracker"
        })
    end
end
```

The LLM doesn't know or care whether a tool is built-in, an MCP server, a local integration, or a hosted OpenCompany proxy. The `app.*` Lua namespace is the universal interface.

---

## Integration Ecosystem

### Shared with OpenCompany

KosmoKrator shares tool packages with OpenCompany via `opencompanyapp/integration-core` — a framework-agnostic package that defines the Tool contract, ToolProvider, and CredentialResolver interfaces. See `docs/integration-refactor-plan.md` for the full migration plan.

```
opencompanyapp/integration-core          (framework-agnostic contracts)
         │
opencompanyapp/integration-*             (tool packages)
├── integration-clickup      (17 tools)
├── integration-google       (10+ tools: Gmail, Calendar, Drive, ...)
├── integration-plausible    (5+ tools)
├── integration-ticktick     (5+ tools)
├── integration-mermaid      (1 tool)
├── integration-plantuml     (1 tool)
├── integration-typst        (1 tool)
├── integration-vegalite     (1 tool)
├── integration-coingecko    (3+ tools)
├── integration-exchangerate (2+ tools)
├── integration-worldbank    (3+ tools)
├── integration-trustmrr     (2+ tools)
└── integration-celestial    (6+ tools)
         │
    ┌────┴────┐
    │         │
OpenCompany  KosmoKrator
(bridge via   (native ToolInterface,
laravel/ai)   direct execute())
```

### Dual-Mode: Local vs Hosted

Every integration can run in two modes, transparent to the LLM:

**Local mode**: Tool package runs in KosmoKrator's process. Credentials stored in `~/.kosmokrator/integrations.yaml`. API calls go directly to the external service.

```
KosmoKrator → ClickUpService → ClickUp API
```

**Hosted mode**: Tool runs on the user's OpenCompany instance. KosmoKrator sends requests to OpenCompany's API, which proxies to the external service. Credentials managed in OpenCompany's encrypted storage.

```
KosmoKrator → OpenCompany API → ClickUpService → ClickUp API
```

Hosted mode is effectively MCP over HTTP. Benefits:
- Use already-configured OpenCompany integrations from KosmoKrator
- No local OAuth flows or credential management
- OpenCompany handles token refresh, rate limiting, credential rotation

### Multi-Account Support

Users can configure multiple accounts per provider, each with a user-defined alias:

```yaml
# ~/.kosmokrator/integrations.yaml

gmail:
  work:
    mode: local
    credentials:
      client_id: "..."
      client_secret: "..."
  personal:
    mode: hosted
    opencompany_key: "sk-..."
    account_id: "acc_abc123"

clickup:
  default:
    mode: local
    credentials:
      api_token: "..."
  freelance:
    mode: local
    credentials:
      api_token: "..."
```

**Lua namespace**: `app.{provider}.{alias}.{tool}`

```lua
app.gmail.work.send_message({to = "cto@company.com", subject = "..."})
app.gmail.personal.list_messages({query = "is:unread"})
app.clickup.default.create_task({list_id = "...", name = "..."})
app.clickup.freelance.get_tasks({list_id = "..."})
```

### Setup Flow

```
$ kosmokrator integrations add gmail

? Alias for this account: work
? Mode: (local / hosted)
  > local

? Client ID: xxxxxxxx
? Client Secret: xxxxxxxx
? Starting OAuth flow... (opens browser)
✓ Gmail "work" configured.

Lua namespace: app.gmail.work.*
Available tools: send_message, list_messages, search_messages, ...
```

---

## Agent Middleware Pipeline

Cross-cutting concerns are modeled as middleware around tool execution (pattern from laravel/ai, adapted for CLI):

```php
interface AgentMiddleware
{
    public function handle(AgentContext $context, callable $next): mixed;
}
```

Pipeline runs around each tool execution in `AgentLoop`:

```php
$pipeline = array_reduce(
    array_reverse($this->middleware),
    fn ($next, $mw) => fn ($ctx) => $mw->handle($ctx, $next),
    fn ($ctx) => $this->executeTool($ctx)
);
$result = $pipeline($context);
```

### Planned Middleware

| Middleware | Purpose |
|-----------|---------|
| `ApprovalMiddleware` | Prompt user before executing dangerous tools (bash, file_write, file_edit) |
| `CostTrackingMiddleware` | Track token usage and cost per request |
| `RateLimitMiddleware` | Token bucket rate limiting for API calls |
| `AuditMiddleware` | Log all tool executions with timing |
| `SandboxMiddleware` | Enforce blocked commands, path restrictions |

Config in `kosmokrator.yaml`:

```yaml
tools:
  approval_required:
    - file_write
    - file_edit
    - bash
  bash:
    timeout: 120
    blocked_commands:
      - "rm -rf /"
```

---

## Provider Failover

`PrismService` wraps Prism with automatic failover across LLM providers:

```yaml
# kosmokrator.yaml
agent:
  providers:
    - provider: anthropic
      model: claude-sonnet-4-20250514
    - provider: openai
      model: gpt-4.1
    - provider: ollama
      model: llama3.3
```

On rate limit (429), server error (5xx), or timeout, automatically retries with the next provider. `AgentLoop` is unaware of failover — it calls `PrismService::stream()` and gets responses.

---

## UI System

### RendererInterface

```php
interface RendererInterface
{
    public function initialize(): void;
    public function renderIntro(bool $animated): void;
    public function prompt(): string;
    public function showThinking(): void;
    public function streamChunk(string $text): void;
    public function streamComplete(): void;
    public function showToolCall(string $name, array $args): void;
    public function showToolResult(string $name, string $output, bool $success): void;
    public function showError(string $message): void;
    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost): void;
    public function teardown(): void;
}
```

### Two Implementations

**AnsiRenderer** (current default): Raw ANSI escape codes. Synchronous. Works everywhere. Includes animated ASCII intro, colored tool output, streaming text display, mock session demo (`/seed`).

**TuiRenderer** (Symfony TUI): Widget-based rendering with Revolt event loop. Non-blocking — user can type while LLM streams. Planned widget tree:

```
SessionWidget (vertical layout)
├── ResponseWidget (streaming markdown, syntax highlighted)
├── ToolCallWidget (dynamic, added per tool call)
├── StatusBarWidget (model, tokens, cost)
└── PromptWidget (user input, focused)
```

### UIManager

Auto-detects TUI availability and delegates:

```php
class UIManager implements RendererInterface
{
    // preference: 'auto' | 'tui' | 'ansi'
    // auto → TUI if available, else ANSI
}
```

Override via CLI: `--renderer=ansi` or `--renderer=tui`.

### Theme System

Colors loaded from `config/themes/default.yaml`:

```yaml
colors:
  primary: [255, 60, 40]        # Red (logo, prompt gem)
  accent: [255, 200, 80]        # Gold (titles)
  success: [80, 220, 100]       # Green (created, passed)
  warning: [255, 200, 80]       # Yellow (thinking)
  error: [255, 80, 60]          # Red (errors)
  info: [100, 200, 255]         # Cyan (search, read, bash)
  link: [80, 140, 255]          # Blue (file paths)
  code: [200, 120, 255]         # Magenta (syntax)
```

---

## Config System

### Files

| File | Scope | Contains |
|------|-------|----------|
| `config/app.yaml` | App identity | name, version, environment |
| `config/prism.yaml` | LLM providers | API keys, URLs, timeout |
| `config/kosmokrator.yaml` | Agent behavior | provider, model, system prompt, tools, UI, sessions |
| `config/themes/default.yaml` | UI | Color palette |
| `~/.kosmokrator/config.yaml` | User overrides | Overrides any kosmokrator.yaml key |
| `.kosmokrator.yaml` | Project overrides | Per-project overrides (in cwd) |
| `~/.kosmokrator/integrations.yaml` | Integrations | Provider accounts, credentials, mode |

### Merge Order

```
config/kosmokrator.yaml (defaults)
    ↓ deep merge
~/.kosmokrator/config.yaml (user)
    ↓ deep merge
.kosmokrator.yaml (project)
```

### Environment Variables

`${ENV_VAR}` placeholders in YAML are resolved from environment:

```yaml
providers:
  anthropic:
    api_key: ${ANTHROPIC_API_KEY}
```

---

## Slash Commands

| Command | Action |
|---------|--------|
| `/quit`, `/exit`, `/q` | Exit KosmoKrator |
| `/seed` | Run mock session demo (AnsiRenderer only) |
| `/clear` | Clear terminal |
| `/reset` | Clear conversation history |
| `/model` | Switch LLM provider/model (planned) |

---

## Layer Dependencies

```
┌──────────────────────────────────────────────────────────┐
│ Command Layer (AgentCommand)                              │
│ Depends on: UI, Agent                                    │
└───────────────────────┬──────────────────────────────────┘
                        │
            ┌───────────┼───────────┐
            │           │           │
    ┌───────┴──────┐ ┌──┴──────┐ ┌──┴──────────────┐
    │   UI Layer   │ │  Agent  │ │  Events flow     │
    │ Renderer,    │ │  Loop,  │ │  Agent → UI via  │
    │ Theme,       │ │  Conv.  │ │  direct calls    │
    │ Widgets      │ │  History│ │                  │
    └──────────────┘ └──┬──────┘ └─────────────────┘
                        │
                ┌───────┼───────┐
                │               │
         ┌──────┴──────┐ ┌─────┴────────┐
         │  LLM Layer  │ │  Tool Layer   │
         │ PrismService│ │ ToolRegistry  │
         │             │ │ ToolInterface │
         └──────┬──────┘ │ Lua Bridge   │
                │        │ MCP Client   │
         prism-php/prism │ Integrations │
                │        └──────────────┘
       illuminate/*
       (transitive)
```

**Rules:**
- UI never imports Agent/LLM/Tool — it receives data through RendererInterface calls
- Agent depends on LLM and Tool via interfaces only
- Tool implementations are self-contained
- LLM layer wraps Prism — no other layer imports Prism classes directly
- Lua bridge and MCP client live in the Tool layer
- Integration packages are loaded dynamically, not imported statically

---

## Implementation Phases

### Done

| Phase | What | Status |
|-------|------|--------|
| 1 | Skeleton + boot (Kernel, ConfigLoader, entry point, YAML config) | Done |
| 2 | UI layer (RendererInterface, AnsiRenderer, AnsiIntro, TuiRenderer, UIManager, Theme) | Done |
| 3 | LLM integration (PrismService, AgentLoop, ConversationHistory, events, streaming) | Done |
| 4 | Coding tools (FileRead, FileWrite, FileEdit, Glob, Grep, Bash, ToolRegistry) | Done |

### Next

| Phase | What | Depends On |
|-------|------|-----------|
| 5 | Agent middleware pipeline (approval, cost tracking, audit) | — |
| 6 | Provider failover in PrismService | — |
| 7 | Session persistence (SessionStore → ~/.kosmokrator/sessions/) | — |
| 8 | Lua code mode (LuaSandboxService, LuaBridge, LuaApiDocGenerator) | ext-luasandbox |
| 9 | MCP client (McpClient, McpToolProvider, schema translation) | — |
| 10 | Integration loader + credential resolver (local mode) | integration-core refactor |
| 11 | Hosted integration proxy (OpenCompany API) | integration-core refactor |
| 12 | Multi-account credential scoping | Phase 10 |
| 13 | TUI widgets (SessionWidget, PromptWidget, ResponseWidget, etc.) | — |
| 14 | Markdown rendering + syntax highlighting in responses | — |
| 15 | PHAR build (box compile) | — |

Phases 5-7 are independent quality-of-life improvements.
Phase 8 (Lua) is the major architectural addition.
Phases 9-12 depend on `integration-core` being refactored (separate effort, see `docs/integration-refactor-plan.md`).
Phases 13-15 are polish.

---

## Ecosystem Vision

```
┌─────────────────────────────────────────────────────────┐
│                        LLM Layer                         │
│  Prism-PHP → Anthropic, OpenAI, Ollama, ...             │
│  Provider failover, streaming                           │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────┴────────────────────────────────┐
│                      Agent Loop                          │
│  Conversation history, middleware pipeline               │
└────────────────────────┬────────────────────────────────┘
                         │
          ┌──────────────┼──────────────┐
          │              │              │
  ┌───────┴──────┐ ┌────┴─────┐ ┌──────┴──────┐
  │  Standard    │ │   Lua    │ │    MCP      │
  │  tool_use   │ │  Code    │ │   Client    │
  │  (JSON)     │ │  Mode    │ │             │
  └───────┬─────┘ └────┬─────┘ └──────┬──────┘
          │             │              │
          └──────────┬──┘              │
                     │                 │
  ┌──────────────────┴─────────────────┴──────────────────┐
  │                    Tool Layer                           │
  │                                                        │
  │  Built-in (read, write, bash, glob, grep)             │
  │                                                        │
  │  Integrations (opencompanyapp/integration-*)           │
  │    ├── local mode → direct API calls                   │
  │    └── hosted mode → OpenCompany API proxy             │
  │                                                        │
  │  MCP servers (external, discovered at runtime)         │
  │                                                        │
  │  All accessible via: app.{provider}.{alias}.{tool}()  │
  └───────────────────────────────────────────────────────┘
```

**Write tools once, run anywhere.** Tool packages work in OpenCompany (web), KosmoKrator (CLI), or any future PHP application. The Lua bridge + MCP client are the universal adapters. Every tool added to either platform is immediately available in the other.
