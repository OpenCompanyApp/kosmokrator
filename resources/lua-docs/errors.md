# Error Handling

## Error Types

### Integration Permission Errors

When an integration operation requires approval:

```
Integration 'gmail' write requires approval. Ask the user to change the permission in /settings → Integrations.
```

**Resolution**: Ask the user to change the permission setting.

### Integration Access Denied

When an integration operation is explicitly denied:

```
Integration 'gmail' write access denied.
```

**Resolution**: This is a hard block. The user must change the permission to `allow` or `ask`.

### Unknown Function

When calling a function that doesn't exist:

```
Unknown function: app.integrations.plausible.query_statz. Did you mean: query_stats
```

**Resolution**: Check the function name using `lua_read_doc`.

### Timeout

Scripts exceeding the CPU time limit are terminated:

```
Lua timeout error
```

**Resolution**: Simplify the script or increase the `cpuLimit` parameter.

## Error Handling with pcall

```lua
local ok, result = pcall(function()
    return app.integrations.plausible.query_stats({
        site_id = "example.com",
        metrics = {"visitors"},
        date_range = "30d"
    })
end)

if not ok then
    print("Error: " .. tostring(result))
else
    -- Process result
    for _, row in ipairs(result.rows or {}) do
        print(row["event:page"] .. ": " .. tostring(row.visitors) .. " visitors")
    end
end
```

## Common Patterns

### Retry with fallback

```lua
local function safe_call(fn, fallback)
    local ok, result = pcall(fn)
    if ok then return result end
    print("Warning: " .. tostring(result))
    return fallback
end

local data = safe_call(function()
    return app.integrations.plausible.query_stats({
        site_id = "example.com",
        metrics = {"visitors"},
        date_range = "7d"
    })
end, {})
```

### Validate before calling

```lua
local site_id = "example.com"
if type(site_id) ~= "string" or site_id == "" then
    print("Error: site_id is required")
else
    local result = app.integrations.plausible.query_stats({
        site_id = site_id,
        metrics = {"visitors"},
        date_range = "7d"
    })
    print(result)
end
```
