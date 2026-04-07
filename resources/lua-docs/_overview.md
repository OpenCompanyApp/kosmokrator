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

Available native tools: `file_read`, `file_write`, `file_edit`, `apply_patch`, `glob`, `grep`, `bash`, `shell_start`, `shell_write`, `shell_read`, `shell_kill`, `memory_save`, `memory_search`.

**Note:** Write tools (`file_write`, `file_edit`, `apply_patch`, `bash`) are subject to the same permission rules as when called directly.

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
