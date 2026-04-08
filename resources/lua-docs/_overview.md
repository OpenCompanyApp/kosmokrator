# Lua Scripting API Overview

Lua scripts in KosmoKrator run in a sandboxed environment with access to integrations through the `app.*` namespace. Scripts are lightweight alternatives to multiple sequential LLM calls — ideal for complex multi-step operations.

## Namespace Structure

```
app.integrations.{name}.*                    — Default account
app.integrations.{name}.default.*            — Explicit default account
app.integrations.{name}.{account}.*          — Named account
app.tools.{tool_name}(args)                  — Native KosmoKrator tools
```

Available namespaces depend on which integrations you have configured. Use `lua_list_docs` to see what's available.

## Native Tools

KosmoKrator's built-in tools are available in the `app.tools` namespace:

```lua
-- Read a file
local content = app.tools.file_read({path = "src/Kernel.php"})
print(content)

-- Search code
local matches = app.tools.grep({pattern = "function boot", path = "src/"})
print(matches)

-- List files
local files = app.tools.glob({pattern = "src/**/*.php"})
print(files)

-- Run a shell command
local output = app.tools.bash({command = "git status --short"})
print(output)
```

Available native tools: `file_read`, `file_write`, `file_edit`, `apply_patch`, `glob`, `grep`, `bash`, `shell_start`, `shell_write`, `shell_read`, `shell_kill`, `task_create`, `task_update`, `task_list`, `task_get`, `memory_save`, `memory_search`, `subagent`.

**Note:** Write tools (`file_write`, `file_edit`, `apply_patch`, `bash`) are subject to the same permission rules as when called directly.

## Subagent Tool

The `subagent` tool spawns child agents that run their own autonomous tool loops. It supports two calling conventions:

### Single Agent

```lua
-- Spawn one explore agent (blocks until complete)
local result = app.tools.subagent({
  task = "Find all files using the AgentContext class",
  type = "explore",          -- "explore" (default), "plan", or "general"
  id = "my_agent",           -- optional, for depends_on references
})
print(result)
```

### Batch — Parallel Agents

Pass `agents` (array) instead of `task`. All agents run concurrently via the Amp event loop:

```lua
local result = app.tools.subagent({
  agents = {
    {task = "Explore the routing module", id = "router"},
    {task = "Explore the auth module",    id = "auth"},
    {task = "Explore the database layer", id = "db"},
  }
})
print(result)  -- results for all agents, keyed by id
```

Each agent spec supports: `task` (required), `type`, `id`, `depends_on`, `group`.

### Mode: await vs background

The `mode` parameter controls when results are available:

| Mode | Single | Batch |
|------|--------|-------|
| `"await"` (default) | Blocks until agent completes, returns result | Blocks until **all** agents complete, returns all results |
| `"background"` | Returns immediately, result collected by main agent loop later | Returns immediately, **all** results collected by main agent loop later |

```lua
-- Background: fire-and-forget (results NOT available in Lua)
app.tools.subagent({
  mode = "background",
  task = "Run the full test suite",
  type = "general",
})

-- Background batch: spawn 3 agents, return immediately
app.tools.subagent({
  mode = "background",
  agents = {
    {task = "Run tests",  id = "t1", type = "general"},
    {task = "Run linter", id = "t2", type = "general"},
  }
})
```

**Important:** Background results are collected by the main agent loop after the Lua script returns — they are never available to Lua code. Use `await` mode if you need results within the script.

### Dependencies (depends_on)

An agent can wait for other agents to finish before starting. Their results are injected into the waiting agent's task prompt:

```lua
app.tools.subagent({
  agents = {
    {task = "List all API endpoints",          id = "endpoints"},
    {task = "Check auth coverage on endpoints", id = "coverage", depends_on = {"endpoints"}},
  }
})
-- "coverage" waits for "endpoints" to finish, then receives its output
```

Works in both single (reference IDs from earlier calls in the same session) and batch modes.

### Sequential Groups (group)

Agents with the same `group` value run **one at a time** (sequentially within the group). Agents in different groups (or no group) run concurrently:

```lua
app.tools.subagent({
  agents = {
    -- These two run sequentially (same group)
    {task = "Write tests for Auth", id = "t1", type = "general", group = "writer"},
    {task = "Write tests for DB",   id = "t2", type = "general", group = "writer"},
    -- These two run concurrently with each other and with the writer group
    {task = "Explore API docs",     id = "r1", type = "explore"},
    {task = "Explore config",       id = "r2", type = "explore"},
  }
})
```

### Resource Limits

Batch agents can exceed the default Lua CPU limit (30s). Pass higher limits to `execute_lua`:

```lua
-- This Lua call allows up to 5 minutes CPU / 64 MB for the entire script
-- (adjust based on how many agents and how complex their tasks are)
```

## Blocking Behavior

Lua execution is **synchronous** — every `app.tools.*` call blocks until it completes:

- `app.tools.subagent({task=...})` — blocks until agent finishes. A loop of these runs agents **sequentially**.
- `app.tools.subagent({mode="background", task=...})` — returns immediately, but results are **not available to Lua**.
- `app.tools.subagent({agents=...})` — spawns all concurrently, blocks until all finish, returns all results. This is the way to get **parallelism from Lua**.

## Quick Start

```lua
-- Query analytics (default account)
local result = app.integrations.plausible.query_stats({
    site_id = "example.com",
    metrics = {"visitors", "pageviews"},
    date_range = "30d"
})
print(result)

-- Get cryptocurrency prices
local price = app.integrations.coingecko.get_price({
    ids = {"bitcoin", "ethereum"},
    vs_currencies = {"usd"}
})
print(price)
```

## Multi-Account Usage

If you have multiple accounts configured for an integration, use account-specific namespaces:

```lua
-- Default account (always available)
app.integrations.gmail.send_email({...})

-- Explicit default (portable across setups)
app.integrations.gmail.default.send_email({...})

-- Named accounts
app.integrations.gmail.work.send_email({...})
app.integrations.gmail.personal.send_email({...})
```

All functions are identical across accounts — only the credentials differ. Each account has its own API key, URL, etc. configured via `/settings` → Integrations.

Example — query two different Plausible instances:

```lua
-- Work analytics
local work_stats = app.integrations.plausible.work.query_stats({
    site_id = "company.com",
    metrics = {"visitors"},
    date_range = "7d"
})

-- Personal analytics
local personal_stats = app.integrations.plausible.personal.query_stats({
    site_id = "myblog.com",
    metrics = {"visitors"},
    date_range = "7d"
})

print("Work visitors: " .. tostring(work_stats.results.visitors))
print("Personal visitors: " .. tostring(personal_stats.results.visitors))
```

## Sandbox Limits

| Limit | Default |
|-------|---------|
| CPU time | 30 seconds |
| Memory | 32 MB |
| Network | None (use `app.*` for API calls) |
| File system | None |
| OS access | None |
| Module loading | None (`require`, `loadfile`, `dofile` are not available) |

## Built-in Globals

### `print(...)`

Prints values to the output. Tables are automatically serialized to a readable format:

```lua
local result = app.integrations.plausible.list_sites()
print(result)
-- {
--   sites: [
--     {
--       domain: "example.com"
--     }
--   ]
-- }
```

### `dump(value)`

Prints a table's contents and returns the value (useful for chaining):

```lua
local sites = dump(app.integrations.plausible.list_sites())
-- prints the table contents, then continues with sites as a variable
```

## Return Values

All `app.*` functions return Lua tables (objects/arrays) on success. On failure, they raise an error. Use `pcall` for error handling:

```lua
local ok, result = pcall(function()
    return app.integrations.plausible.query_stats({
        site_id = "example.com",
        metrics = {"visitors"},
        date_range = "7d"
    })
end)

if not ok then
    print("Error: " .. tostring(result))
end
```

## Permission Notes

Some integration operations may require approval (ask mode). If you get a permission error like:

```
Integration 'gmail' write requires approval. Ask the user to change the permission in /settings → Integrations.
```

Ask the user to change the integration permission in settings. Permissions are configured per integration and per operation type (read/write).
