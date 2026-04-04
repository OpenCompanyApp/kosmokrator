# KosmoKrator Ecosystem Architecture

> Status: Proposal. This document outlines a future ecosystem architecture around Lua, MCP, and shared integrations. These capabilities are not fully implemented in the current CLI.

## Overview

KosmoKrator is not just a CLI coding agent — it's a runtime that can host any tool ecosystem via Lua code execution and MCP. It shares a tool ecosystem with OpenCompany, a self-hosted AI collaboration platform.

```
                    opencompanyapp/integration-core
                    (framework-agnostic contracts)
                              │
                    opencompanyapp/integration-*
                    (ClickUp, Google, Plausible, ...)
                              │
              ┌───────────────┼───────────────┐
              │                               │
        OpenCompany                      KosmoKrator
        (web platform)                   (the engine)
              │                               │
         LuaBridge                  ┌─────────┼─────────┐
              │                     │         │         │
         LuaSandbox              CLI       Desktop   (Mobile)
         (PECL ext)           terminal    NativePHP  future
              │               ANSI/TUI    Electron
         MCP Client               │         │
                              LuaBridge  LuaBridge
                                  │         │
                              LuaSandbox LuaSandbox
                                  │         │
                              MCP Client MCP Client
```

KosmoKrator is one engine with multiple surfaces. Tools are written once as Composer packages. OpenCompany is an optional cloud backend for hosted integrations. See `docs/proposals/desktop-app.md` for the desktop surface architecture.

---

## Lua Code Mode

### The Problem with JSON tool_use

Traditional tool calling requires one LLM round-trip per tool invocation. A task like "find all PHP files with TODOs and list them" needs: glob → read file 1 → read file 2 → ... → read file N. That's N+1 round-trips, each costing tokens and latency.

### The Solution: LLM Writes Lua

Instead of N sequential JSON tool_use blocks, the LLM writes a single Lua program:

```lua
local files = app.glob({pattern = "src/**/*.php"})
local results = {}
for _, f in ipairs(files) do
    local content = app.read_file({path = f})
    if content:find("TODO") then
        table.insert(results, f)
    end
end
return results
```

One round-trip. One tool call (`execute_lua`). The LLM gets composability, loops, conditionals, variables — all the things that make code more expressive than structured JSON.

### Evidence This Works

| Source | Finding |
|--------|---------|
| Anthropic engineering blog | 98.7% token reduction vs JSON tool_use |
| Cloudflare Code Mode | 99.9% token reduction for large API surfaces |
| CodeAct (ICML 2024) | 20% higher success rate, 30% fewer turns |
| Anthropic "Code execution with MCP" | Explicitly advocates agents writing code to call MCP tools |

### Why Lua Specifically

- **Designed for embedding**: Smallest footprint of any mainstream scripting language. Built from day one to be embedded in host applications.
- **Easy to sandbox**: Remove `io`, `os`, `debug`, `package`, `loadfile` and the language physically cannot touch the filesystem or network. Only whitelisted functions are available.
- **Simple syntax**: No indentation sensitivity (Python), no prototype chains (JS). LLMs generate valid Lua reliably.
- **Stable**: Lua 5.1 hasn't changed since 2006. The attack surface is well-studied.
- **Familiar**: Config language for Neovim, scripting language for games. LLMs have seen plenty of it in training.

### Runtime: LuaSandbox PECL Extension

The `luasandbox` PECL extension, developed by Wikimedia for MediaWiki's Scribunto module, runs user-supplied Lua on Wikipedia at massive scale. It is purpose-built for untrusted code.

**Security model (whitelist, not blacklist):**
- `setMemoryLimit(int $bytes)` — hard kill on exceed
- `setCPULimit(float $seconds)` — hard kill on exceed (includes PHP callback time)
- `registerLibrary(string $name, array $functions)` — expose specific PHP functions to Lua
- Blocks by default: `dofile()`, `loadfile()`, `io.*`, `os.*`, `debug.*`, `package.*`, `require()`, `load()`, `loadstring()`, `print()`, `string.dump()`, `collectgarbage()`, `coroutine`

Only what you explicitly register is available. Everything else is inaccessible.

### Self-Discoverable API

The LLM doesn't need every tool schema in its system prompt. Instead:

```lua
-- LLM can discover what's available at runtime
local all = docs()                              -- list all namespaces
local gmail = docs("app.gmail.work")            -- list tools for this account
local detail = docs("app.gmail.work.send_message")  -- full schema + examples
```

API docs are auto-generated from tool schemas by `LuaApiDocGenerator`. This keeps the system prompt small while giving the LLM access to arbitrarily large tool surfaces.

### Fallback to Standard tool_use

Lua code mode is not all-or-nothing. Simple single-tool calls can still use standard JSON tool_use. The LLM chooses: quick read → `tool_use`, complex multi-step → `execute_lua`. Both paths coexist.

---

## MCP Integration

### KosmoKrator as MCP Client

KosmoKrator connects to external MCP servers, discovers their tools, and makes them available to the LLM — either as standard tool_use or as Lua functions in the sandbox.

```
MCP Server (external)
    │
    ├── listTools() → discover available tools
    │
    └── callTool(name, args) → execute and return result
        │
KosmoKrator MCP Client
    │
    ├── Register as Lua functions: app.mcp.{server}.{tool}()
    │
    └── Or expose as standard tool_use definitions
```

**Transport options:**
- **stdio**: MCP server runs as a child process (ideal for local tools)
- **HTTP + SSE**: Remote MCP servers (ideal for hosted OpenCompany tools)

**PHP MCP client options:**
- `modelcontextprotocol/php-sdk` — official, maintained by PHP Foundation + Symfony
- `php-mcp/client` — fluent builder, sync facade
- `swisnl/mcp-client` — SSE, stdio, streamable HTTP

### KosmoKrator as MCP Server

KosmoKrator can also expose its own tools (file read/write, bash, glob, grep, git) as an MCP server. This allows other AI applications (Claude Desktop, IDE extensions, other agents) to use KosmoKrator's capabilities.

### Lua + MCP Bridge

The key innovation: MCP tools are registered as Lua functions in the sandbox. The LLM writes Lua that calls MCP tools, composes results, and handles logic — all in a single execution:

```
LLM writes Lua code
    → KosmoKrator's Lua sandbox executes it
        → Lua calls app.mcp.github.list_issues({repo = "..."})
            → KosmoKrator routes to GitHub MCP server
            → Result returns to Lua as a table
        → Lua filters, transforms, calls more tools
    → Final result returns to the LLM
```

The LLM doesn't know or care whether a tool is local, an MCP server, or a hosted OpenCompany integration. The Lua namespace is the universal interface.

---

## OpenCompany Tool Ecosystem

### Existing Tool Packages

OpenCompany has 15+ AI tool packages as standalone Composer packages under the `opencompanyapp` vendor:

| Package | Tools | Description |
|---------|-------|-------------|
| `ai-tool-clickup` | 17 | Tasks, lists, folders, docs, time tracking, chat |
| `ai-tool-google` | 10+ | Calendar, Gmail, Drive, Contacts, Sheets, Search Console, Tasks, Analytics, Docs, Forms |
| `ai-tool-plausible` | 5+ | Web analytics queries, realtime visitors, sites, goals |
| `ai-tool-ticktick` | 5+ | Task management, projects, priorities |
| `ai-tool-mermaid` | 1 | Diagram rendering (flowcharts, sequences, ER, Gantt, etc.) |
| `ai-tool-plantuml` | 1 | UML diagram rendering |
| `ai-tool-typst` | 1 | Document typesetting |
| `ai-tool-vegalite` | 1 | Data visualization / charts |
| `ai-tool-coingecko` | 3+ | Cryptocurrency market data |
| `ai-tool-exchangerate` | 2+ | Currency conversion (340+ currencies) |
| `ai-tool-worldbank` | 3+ | Economic indicators for 200+ countries |
| `ai-tool-trustmrr` | 2+ | Startup revenue/MRR data |
| `ai-tool-celestial` | 6+ | Moon phases, sunrise/sunset, planet positions, eclipses |

### Current Architecture Problem

Today, every tool implements `Laravel\Ai\Contracts\Tool` — a hard dependency on laravel/ai:

```
ai-tool-clickup → integration-core → laravel/ai
```

This means KosmoKrator (which uses Prism directly, not laravel/ai) cannot use these packages without pulling in the full Laravel AI SDK.

**However**, the actual business logic in each package (e.g., `ClickUpService`, `PlausibleService`) is framework-agnostic. The laravel/ai coupling is only in the thin tool wrapper layer (schema definition + handle method).

### Refactored Architecture (Option C)

Split `integration-core` into two packages:

```
opencompanyapp/integration-core          (framework-agnostic)
├── Contracts/
│   ├── Tool                             ← OWN interface, not laravel/ai's
│   │   ├── name(): string
│   │   ├── description(): string
│   │   ├── parameters(): array          ← JSON Schema array
│   │   └── execute(array $args): ToolResult
│   ├── ToolProvider
│   ├── CredentialResolver
│   ├── ConfigurableIntegration
│   ├── AgentFileStorage
│   └── ProvidesLuaDocs
├── Support/
│   ├── ToolResult                       ← Value object
│   ├── ConfigCredentialResolver
│   └── ToolProviderRegistry
└── composer.json                        ← NO laravel/ai dependency
```

No bridge package needed. Vendor package tools are Lua-only — they're never passed to the laravel/ai agent loop. Built-in tools (tasks, system, agents, memory, lua) still implement `Laravel\Ai\Contracts\Tool` directly. `LuaBridge` and `getToolCatalog()` use a dual-dispatch `instanceof` check to handle both tool types.

**Result:**
- All tool packages depend only on `integration-core` (no laravel/ai)
- OpenCompany's built-in tools keep their `Laravel\Ai\Contracts\Tool` implementation
- KosmoKrator uses the tools natively through its own `ToolInterface`
- Tool packages become truly framework-agnostic

---

## Dual-Mode Integrations: Local vs Hosted

Users can run tool integrations in two modes:

### Local Mode

The tool package runs inside KosmoKrator's process. Credentials are stored locally. API calls go directly from the user's machine to the external service.

```
KosmoKrator → ClickUpService → ClickUp API
```

### Hosted Mode (OpenCompany)

The tool runs on the user's OpenCompany instance. KosmoKrator sends requests to OpenCompany's API, which proxies to the external service. Credentials are managed in OpenCompany's encrypted storage.

```
KosmoKrator → OpenCompany API → ClickUpService → ClickUp API
```

Hosted mode is effectively MCP over HTTP — OpenCompany acts as an MCP server for its configured integrations. This means:

- Users who already have OpenCompany with configured integrations can use them from KosmoKrator immediately
- No need to re-enter credentials or set up OAuth flows locally
- OpenCompany handles token refresh, rate limiting, and credential rotation
- KosmoKrator just needs an API key for the OpenCompany instance

### From the Lua bridge perspective, both modes are identical

```lua
-- User doesn't know or care whether this is local or hosted
app.gmail.work.send_message({
    to = "team@example.com",
    subject = "Deploy complete",
    body = "All tests passed."
})
```

The credential resolver and transport layer handle the routing transparently.

---

## Multi-Account Support

Users can configure multiple accounts for the same provider. Each account gets a user-defined alias that becomes its namespace.

### Configuration

```yaml
# ~/.kosmokrator/integrations.yaml

gmail:
  work:
    mode: local
    credentials:
      client_id: "..."
      client_secret: "..."
      refresh_token: "..."
  personal:
    mode: hosted
    opencompany_key: "sk-..."
    account_id: "acc_abc123"

clickup:
  default:
    mode: local
    credentials:
      api_token: "..."

clickup:
  freelance:
    mode: local
    credentials:
      api_token: "..."  # different workspace
```

### Lua Namespace

The namespace pattern is `app.{provider}.{alias}.{tool}`:

```lua
-- Two Gmail accounts
app.gmail.work.send_message({to = "cto@company.com", ...})
app.gmail.personal.list_messages({query = "is:unread"})

-- Two ClickUp workspaces
app.clickup.default.create_task({list_id = "...", name = "..."})
app.clickup.freelance.get_tasks({list_id = "..."})
```

### Architecture

The `ToolProvider` yields multiple named instances instead of a flat tool list. Each instance carries:

- **Alias**: user-defined label (`work`, `personal`, `freelance`)
- **Mode**: `local` or `hosted`
- **Credential scope**: isolated credentials per instance
- **Endpoint**: direct API URL (local) or OpenCompany API URL (hosted)

```php
// CredentialResolver is scoped to the instance
$resolver->get('gmail:work', 'client_id');      // local credentials
$resolver->get('gmail:personal', 'api_token');  // proxied to OpenCompany
```

The Lua bridge registers functions per instance:

```php
$sandbox->registerLibrary('app.gmail.work', [
    'send_message' => fn($args) => $this->execute('gmail', 'work', 'send_message', $args),
    'list_messages' => fn($args) => $this->execute('gmail', 'work', 'list_messages', $args),
]);

$sandbox->registerLibrary('app.gmail.personal', [
    'send_message' => fn($args) => $this->execute('gmail', 'personal', 'send_message', $args),
    'list_messages' => fn($args) => $this->execute('gmail', 'personal', 'list_messages', $args),
]);
```

### Setup Flow

When a user wants to add an integration:

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

Or for hosted mode:

```
$ kosmokrator integrations add gmail

? Alias for this account: personal
? Mode: (local / hosted)
  > hosted

? OpenCompany API key: sk-xxxxxxxx
? Select account from OpenCompany:
  1. personal@gmail.com (Gmail)
  2. work@company.com (Gmail)
  > 1
✓ Gmail "personal" configured (hosted via OpenCompany).

Lua namespace: app.gmail.personal.*
```

---

## Putting It All Together

### The Full Stack

```
┌─────────────────────────────────────────────────────────┐
│                        LLM Layer                         │
│  Prism-PHP → Anthropic, OpenAI, Ollama, ...             │
│  Provider failover, streaming, tool_use + Lua code mode │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────┴────────────────────────────────┐
│                      Agent Loop                          │
│  Conversation history, middleware pipeline,              │
│  event dispatch (thinking, streaming, tool calls)        │
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
  │  Built-in (read, write, bash, glob, grep, git)        │
  │           │                                            │
  │  Integrations (opencompanyapp/ai-tool-*)              │
  │    ├── local mode → direct API calls                   │
  │    └── hosted mode → OpenCompany API proxy             │
  │           │                                            │
  │  MCP servers (external, discovered at runtime)         │
  │           │                                            │
  │  All accessible via: app.{provider}.{alias}.{tool}()  │
  └───────────────────────────────────────────────────────┘
```

### What Makes This Powerful

1. **Universal namespace**: Every tool — built-in, Composer package, MCP server, local or hosted — lives under `app.*` in Lua. The LLM has one consistent interface.

2. **Write once, run anywhere**: Tool packages are framework-agnostic Composer packages. They work in OpenCompany (web), KosmoKrator (CLI), or any future PHP application.

3. **Progressive complexity**: Simple tasks use standard tool_use. Complex orchestration uses Lua code mode. Users don't need to know the difference.

4. **Ecosystem network effect**: Every tool added to OpenCompany is immediately available in KosmoKrator, and vice versa. MCP servers from the broader community plug in through the same Lua namespace.

5. **Cost optimization**: Lua scripts execute at zero LLM cost. Repetitive or deterministic workflows (daily reports, scheduled syncs) run as pure Lua after initial AI authoring.
