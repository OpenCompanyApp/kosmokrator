# Context Object

In KosmoKrator, Lua scripts run in a sandbox without a `ctx` object. All context is provided via the `app.*` namespace.

## Available Context

- **Working directory**: The project root where KosmoKrator was started
- **Integrations**: Configured via `/settings` → Integrations or `~/.kosmokrator/config.yaml`
- **Credentials**: Stored securely in SQLite, not accessible directly from Lua

## Accessing Project Files

Lua scripts cannot access the filesystem directly. Use KosmoKrator's file tools (`file_read`, `file_write`) instead. Lua is for integration orchestration — calling multiple integration APIs in sequence without per-call LLM overhead.
