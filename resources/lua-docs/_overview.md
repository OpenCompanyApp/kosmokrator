# Lua Scripting API Overview

Lua scripts in KosmoKrator run in a sandboxed environment with access to integrations through the `app.*` namespace. Scripts are lightweight alternatives to multiple sequential LLM calls — ideal for complex multi-step operations.

## Namespace Structure

```
app.integrations.{name}.*                    — Default account
app.integrations.{name}.default.*            — Explicit default account
app.integrations.{name}.{account}.*          — Named account
app.tools.{tool_name}(args)                  — Native KosmoKrator tools
json.decode(string) / json.encode(value)     — JSON parsing and serialization
regex.match(s, p) / regex.match_all(s, p)   — PCRE regex matching
regex.gsub(s, p, r)                          — PCRE regex substitution
```

Available namespaces depend on which integrations you have configured. Use `lua_list_docs` to see what's available.

## Native Tools

KosmoKrator's built-in tools are available in the `app.tools` namespace. **All native tools return a structured table** with at least `output` (string) and `success` (bool). Some tools include additional fields:

```lua
-- Read a file (returns {output, success})
local result = app.tools.file_read({path = "src/Kernel.php"})
print(result.output)

-- Search code (returns {output, success})
local result = app.tools.grep({pattern = "function boot", path = "src/"})
print(result.output)

-- List files (returns {output, success})
local result = app.tools.glob({pattern = "src/**/*.php"})
print(result.output)

-- Run a shell command (returns {output, success, stdout, stderr, exit_code})
local result = app.tools.bash({command = "git status --short"})
print(result.exit_code)   -- 0 on success
print(result.stdout)      -- raw stdout
print(result.stderr)      -- raw stderr
print(result.output)      -- combined stdout + stderr + "Exit code: N"
```

Available native tools: `file_read`, `file_write`, `file_edit`, `apply_patch`, `glob`, `grep`, `bash`, `shell_start`, `shell_write`, `shell_read`, `shell_kill`, `task_create`, `task_update`, `task_list`, `task_get`, `memory_save`, `memory_search`, `subagent`.

**Note:** Write tools (`file_write`, `file_edit`, `apply_patch`, `bash`) are subject to the same permission rules as when called directly.

### Bash Structured Results

`app.tools.bash()` returns the most detailed structure:

| Field | Type | Description |
|-------|------|-------------|
| `output` | string | Full combined output (stdout + stderr + "Exit code: N") — same format as the LLM sees |
| `success` | bool | `true` if exit code is 0 |
| `stdout` | string | Raw stdout capture |
| `stderr` | string | Raw stderr capture |
| `exit_code` | number | Process exit code |

This lets you access stdout/stderr separately and check the exit code programmatically, instead of parsing the combined string:

```lua
local r = app.tools.bash({command = "jq '.' package.json"})
if r.success then
    local data = json.decode(r.stdout)
    print("Package: " .. data.name .. " v" .. data.version)
else
    print("Failed: " .. r.stderr)
end
```

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
print(result.output)
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
print(result.output)  -- results for all agents
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

-- Named accounts
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

### `json.decode(string)`

Parses a JSON string into a Lua table. Uses PHP's `json_decode` under the hood, so it handles all standard JSON types including nested objects and arrays.

```lua
-- Parse JSON from a bash command
local r = app.tools.bash({command = "cat package.json"})
local pkg = json.decode(r.stdout)
print(pkg.name, pkg.version)

-- Parse JSON from a string literal
local data = json.decode('{"items": [1, 2, 3]}')
print(data.items[1])  -- 1

-- Decode array of objects (e.g. one JSON per line from a script)
local lines = {}
for line in r.stdout:gmatch("[^\r\n]+") do
    if line ~= "" then
        table.insert(lines, json.decode(line))
    end
end
```

Raises an error on invalid JSON. Use `pcall` for error handling:

```lua
local ok, data = pcall(json.decode, raw_string)
if not ok then
    print("Invalid JSON: " .. tostring(data))
end
```

### `json.encode(value)`

Serializes a Lua table (or any value) to a JSON string. Produces pretty-printed output with unescaped Unicode.

```lua
print(json.encode({name = "test", count = 42}))
-- {
--     "name": "test",
--     "count": 42
-- }
```

### `regex.match(subject, pattern [, flags])`

Tests whether `subject` matches the PCRE `pattern`. Returns a table of captures on match, or `nil` on no match.

```lua
local m = regex.match("hello world 42", "(\\w+) (\\d+)")
-- m = {"world 42", "world", "42"}  (full match, then captures)

local m = regex.match("no digits here", "\\d+")
-- m = nil
```

Supports all PCRE features (lookaheads, non-greedy quantifiers, Unicode properties, named groups, etc.) that Lua's built-in patterns lack:

```lua
-- Named capture groups
local m = regex.match("price: $19.99", "(?P<currency>\\$)(?P<amount>[\\d.]+)")
-- m = {"$19.99", "$", "19.99"}

-- Lookahead
local m = regex.match("foo bar", "\\w+(?= bar)")
-- m = {"foo"}
```

### `regex.match_all(subject, pattern [, flags])`

Returns all matches of `pattern` in `subject`. Default flag behavior (`PREG_PATTERN_ORDER`) returns captures grouped by group index.

```lua
local matches = regex.match_all("foo123bar456baz", "(\\d+)")
-- matches = {{"123", "456"}, {"123", "456"}}
-- matches[1] = all full matches, matches[2] = first capture group, etc.
```

### `regex.gsub(subject, pattern, replacement [, limit])`

Replaces all occurrences of `pattern` in `subject` with `replacement`. Returns the resulting string.

```lua
local cleaned = regex.gsub("  hello   world  ", "\\s+", " ")
-- cleaned = " hello world "

-- With limit
local s = regex.gsub("aaa", "a", "b", 2)
-- s = "bba"
```

Supports PCRE backreferences in the replacement string (`$1`, `$2`, etc.).

## Return Values

### Integration calls (`app.integrations.*`)

Return Lua tables on success. Raise an error on failure. Use `pcall` for error handling:

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

### Native tool calls (`app.tools.*`)

Always return a structured table `{output = string, success = bool, ...}`. Never throw — check `success` instead:

```lua
local result = app.tools.bash({command = "git status"})
if result.success then
    print("Output: " .. result.output)
else
    print("Failed: " .. result.output)
end
```

## Permission Notes

Some integration operations may require approval (ask mode). If you get a permission error like:

```
Integration 'gmail' write requires approval. Ask the user to change the permission in /settings → Integrations.
```

Ask the user to change the integration permission in settings. Permissions are configured per integration and per operation type (read/write).
