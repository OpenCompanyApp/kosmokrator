# Subagent Architecture

This document describes the current shipped subagent system.

KosmoKrator can spawn child agents for parallel research, planning, and delegated work. Each child runs its own agent loop with a narrowed tool set and reports results back through a shared orchestrator.

## Agent Types

| Type | Read | Write | Can spawn |
|------|------|-------|-----------|
| `general` | yes | yes | `general`, `explore`, `plan` |
| `explore` | yes | no | `explore` |
| `plan` | yes | no | `explore` |

Type narrowing is strict. Children can only keep or reduce capabilities relative to their parent.

## Interactive Agent Modes vs Subagent Types

Do not confuse:

- **interactive agent modes**: `Edit`, `Plan`, `Ask`
- **subagent types**: `general`, `explore`, `plan`

Interactive modes shape the parent session tool set. Subagent types shape delegated child sessions.

## Tool Scoping

Current subagent tool sets:

- `general`: `file_read`, `file_write`, `file_edit`, `apply_patch`, `glob`, `grep`, `bash`, `shell_start`, `shell_write`, `shell_read`, `shell_kill`, `subagent`, `memory_search`, `session_search`, `session_read`, `memory_save`, `lua_list_docs`, `lua_search_docs`, `lua_read_doc`, `execute_lua`
- `explore`: `file_read`, `glob`, `grep`, `bash`, `shell_start`, `shell_write`, `shell_read`, `shell_kill`, `subagent`, `memory_search`, `session_search`, `session_read`, `lua_list_docs`, `lua_search_docs`, `lua_read_doc`, `execute_lua`
- `plan`: `file_read`, `glob`, `grep`, `bash`, `shell_start`, `shell_write`, `shell_read`, `shell_kill`, `subagent`, `memory_search`, `session_search`, `session_read`, `lua_list_docs`, `lua_search_docs`, `lua_read_doc`, `execute_lua`

The `subagent` tool is removed automatically once the max depth is reached.

## Execution Modes

The `subagent` tool supports two execution modes:

| Mode | Behavior |
|------|----------|
| `await` | parent waits for the child result and gets it inline as a tool result |
| `background` | parent continues immediately and receives the child result on a later turn |

Background results are collected per parent agent ID so sibling trees do not drain each other's results.

## Orchestration Features

The current orchestrator supports:

- explicit agent IDs
- dependency chains with `depends_on`
- sequential execution groups with `group`
- global concurrency limiting
- retry handling for retryable failures
- cancellation of background agents
- per-agent stats for status, elapsed time, tokens, tool calls, depth, and retries

Dependency behavior:

- a dependent child waits for all listed dependencies
- successful dependency results are injected into the child task
- failed dependencies are injected as marked degraded results instead of aborting the dependent child
- circular dependencies are rejected before execution

## Depth and Concurrency

Default runtime settings:

```yaml
agent:
  subagent_max_depth: 3
  subagent_concurrency: 10
  subagent_max_retries: 2
```

Meaning:

- root session depth is `0`
- children increment depth by `1`
- the default tree allows root → child → grandchild
- concurrency `0` disables the global semaphore and allows unlimited parallel children

## UI and Monitoring

KosmoKrator exposes subagent state through:

- inline spawn/running/batch displays in both renderers
- a live tree in TUI mode
- the `/agents` dashboard for aggregated progress, retries, token usage, and failures

## Implementation References

- `AGENTS.md`
- `src/Tool/Coding/SubagentTool.php`
- `src/Agent/SubagentOrchestrator.php`
- `src/Agent/SubagentFactory.php`
- `src/Agent/AgentContext.php`
