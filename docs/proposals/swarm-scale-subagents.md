# Swarm-Scale Subagent Architecture

> Status: Proposal — based on testing the Lua + subagent integration (2026-04-08).
> The current system works well for 3–5 agents. This document covers what changes
> are needed for swarm-scale usage (100–3000+ agents).

## Current State

The Lua subagent API supports single, batch, background, dependency chains, sequential groups, and all three agent types (explore, plan, general). All core functionality was verified working:

- Single agent spawn with await/background modes
- Batch spawn with parallel execution
- `depends_on` dependency chains with result injection
- Sequential `group` execution
- Input validation (missing task, invalid type, max depth, duplicate IDs)
- Auto-generated IDs
- Combination with other Lua native tools

## Issues Found

### 1. Results are unstructured text — can't distinguish success from error in Lua

**Priority: High**

`NativeToolBridge` catches exceptions and returns them as `__error` strings. The Lua wrapper returns the string instead of throwing. This means `pcall` always returns `ok=true` — there is no programmatic way to distinguish success from failure.

```lua
-- Both return strings via pcall with ok=true
local ok, result = pcall(function()
  return app.tools.subagent({task = "real task", type = "explore"})
end)
-- ok = true, result = "...agent output..."

local ok2, result2 = pcall(function()
  return app.tools.subagent({task = "x", type = "invalid_type"})
end)
-- ok2 = true, result2 = "Invalid agent type: 'invalid_type'. Valid: ..."
```

**Fix**: Return a structured table from the Lua bridge:

```lua
local result = app.tools.subagent({task = "...", type = "explore"})
-- result.success = true
-- result.output = "..."
-- result.error = nil
-- result.tokens = 450
-- result.elapsed = 12.3
```

This benefits all scales, not just swarms. Without it, Lua code must string-match against error messages.

### 2. Duplicate ID in batch partially spawns orphaned agent

**Priority: Medium**

When a batch contains duplicate agent IDs, `handleBatch` spawns agents one-by-one in a loop. The first agent starts running, then the second duplicate throws from `spawnAgent()`. The first agent's future is orphaned — it runs to completion wasting tokens with no consumer.

**Fix**: Pre-validate all IDs for uniqueness in the validation loop (before any `spawnAgent` calls), alongside the existing task/type validation.

### 3. Return value displays as `["string"]` JSON array

**Priority: Low**

When Lua code does `return result` after a subagent call, the ExecuteLuaTool formats it as `Return value: ["...the string..."]` — a JSON array wrapping the string. The PHP Lua extension bridges the string back as a single-element array. Works correctly via `print()`, just looks odd in the raw return value display.

## Swarm-Scale Design

The items below form one coherent feature set — the "swarm mode" — not separate bugs. They are only needed when launching 100+ structurally-similar agents.

### 4. Map/template spawning

**Priority: Medium** (blocks swarm use)

For structurally identical tasks with different parameters (e.g., 3K tax treaty lookups), enumerating each task string is impractical:

```lua
-- Current: must enumerate every task
app.tools.subagent({agents = {
  {task = "Research the tax treaty between US and Germany..."},
  {task = "Research the tax treaty between US and France..."},
  -- 2,998 more
}})
```

Proposed: define a template once, provide a data table:

```lua
app.tools.subagent({
  template = "Research the tax treaty between {a} and {b}. Report: withholding rates, PE threshold, special provisions",
  inputs = {
    {a = "US", b = "DE"},
    {a = "US", b = "FR"},
    {a = "US", b = "UK"},
  }
})
```

Benefits:
- **Compression** — 3K treaty pairs are a few KB of tabular data instead of MB of repeated strings
- **Programmatic generation** — inputs can be built from CSV, loops, or other tool output
- **LLM efficiency** — the orchestrating LLM outputs the template once + the data table once, instead of generating 3K slightly-different JSON objects

### 5. Partial failure handling

**Priority: Medium** (needed for reliability at scale)

Current `await` mode either succeeds entirely or throws `Batch execution failed`. At swarm scale, individual failures are noise — one agent out of 3K hitting an API error is not a swarm failure.

Proposed: per-agent status in the result table:

```lua
local result = app.tools.subagent({template = "...", inputs = inputs})
for id, r in pairs(result.results) do
  if not r.success then
    retry_queue[#retry_queue + 1] = id
  end
end
```

Optional: `max_failure_rate` or `max_failures` budget — "abort the swarm if more than 10% fail."

### 6. Fire → poll → collect pattern

**Priority: Low** (background mode works for current use)

Current background mode fires agents and delivers results to the main agent loop later — Lua never sees them. For swarms, you want incremental collection:

```lua
-- Fire
local swarm = app.tools.subagent({
  mode = "fire",
  template = "...",
  inputs = load_csv("countries.csv"),
  concurrency = 20,
})
-- swarm = { id = "swarm-7", total = 3000, status = "running" }

-- Poll
local status = app.tools.subagent_poll({swarm = swarm.id})
-- { completed = 1847, failed = 23, running = 130, pending = 1000 }

-- Collect incrementally (not all at once)
local batch = app.tools.subagent_collect({swarm = swarm.id, limit = 100})
for id, r in pairs(batch) do
  save_to_db(r.output)
end
```

Key insight: at swarm scale, you never hold all results in memory simultaneously. Stream them out as they complete.

### 7. Per-swarm concurrency control

**Priority: Low** (global semaphore works until you deliberately launch 100+ agents)

The global semaphore (default: 10 concurrent) is shared across all subagents. A deliberate 3K-agent research job needs its own concurrency budget without competing with or blocking other work.

```lua
app.tools.subagent({
  template = "...",
  inputs = inputs,
  concurrency = 20,  -- this swarm gets 20 slots
})
```

### 8. Structured output contracts

**Priority: Low** (agents can be prompted to return JSON today)

Optional hint that tells agents to structure their response:

```lua
app.tools.subagent({
  template = "Research tax treaty {a}-{b}. Return: withholding, PE threshold, notes",
  output_format = "json",
  inputs = {...}
})
```

Turns 3K research summaries into 3K rows of parseable data — ready for aggregation, comparison, export.

## Priority Summary

| # | Issue | Priority | Scales affected |
|---|-------|----------|-----------------|
| 1 | Structured results (`{success, output, error}`) | **High** | All |
| 2 | Duplicate ID orphaned agent | **Medium** | All (bug) |
| 3 | Return value display format | **Low** | Cosmetic |
| 4 | Template/map spawning | **Medium** | Swarm only |
| 5 | Partial failure handling | **Medium** | Swarm only |
| 6 | Fire → poll → collect | **Low** | Swarm only |
| 7 | Per-swarm concurrency | **Low** | Swarm only |
| 8 | Structured output contracts | **Low** | Swarm only |

Items 4–8 are a single feature branch ("swarm mode"), not separate bugs.
Items 1–2 are worth fixing independently, regardless of swarm work.
