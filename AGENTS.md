# Agents

KosmoKrator includes a subagent system that spawns child agents for parallel work. This document covers the architecture, type hierarchy, and orchestration model.

## Agent Types

| Type | Capabilities | Can Spawn |
|------|-------------|-----------|
| **General** | Full tool access: read, write, edit, bash, subagent | General, Explore, Plan |
| **Explore** | Read-only: file_read, glob, grep, bash, subagent | Explore only |
| **Plan** | Read-only: file_read, glob, grep, bash, subagent | Explore only |

Types enforce permission narrowing — a General agent can spawn any type, but an Explore agent can only spawn more Explore agents. This prevents privilege escalation down the tree.

## Agent Modes

Modes control what the interactive user can do (orthogonal to agent types):

| Mode | Tools Available | Use Case |
|------|----------------|----------|
| **Edit** | All tools + task/ask tools | Default — full coding access |
| **Plan** | Read-only + subagent + task/ask | Research and plan without writes |
| **Ask** | Read-only + bash + task/ask | Answer questions using file context |

Switch modes with `/edit`, `/plan`, or `/ask`.

## Subagent System

### Key Classes

```
SubagentOrchestrator        Manages concurrency, dependencies, retries, stats
SubagentFactory             Creates isolated AgentLoop instances for child agents
SubagentTool                LLM-callable tool that triggers agent spawning
AgentContext                Immutable context passed down the agent tree (depth, type, cancellation)
SubagentStats               Per-agent metrics (status, tokens, tool calls, elapsed time)
```

### Spawning Flow

1. LLM calls the `subagent` tool with `type`, `task`, and optional `id`, `depends_on`, `group`
2. `SubagentTool` validates the request against `AgentContext` (depth limit, allowed child types)
3. `SubagentOrchestrator.spawnAgent()` handles the lifecycle:
   - Waits for dependencies (if `depends_on` specified)
   - Acquires concurrency semaphore (default: 3 concurrent agents)
   - Acquires group semaphore (if `group` specified — sequential within group)
   - Executes via `SubagentFactory.createAndRunAgent()` with retry logic
   - Stores result and updates stats

### Dependency Resolution

Agents can declare dependencies on other agents via `depends_on: ["agent_id_1", "agent_id_2"]`. The orchestrator:
- Waits for all dependencies to complete before starting the agent
- Injects dependency results into the agent's task prompt
- Detects circular dependencies via DFS before spawning (throws on cycles)

### Retry Policy

Failed agents are retried up to `max_retries` times (default: 2) with exponential backoff and jitter. Auth errors (401, 403) are never retried. The retry loop is integrated with stats tracking (`status: retrying`, retry counter).

### Concurrency Control

- **Global semaphore**: Limits total concurrent agents (configurable via `kosmokrator.agents.concurrency`)
- **Group semaphore**: Agents with the same `group` value run sequentially
- **Max depth**: Limits agent tree depth (default: 3, configurable via `kosmokrator.agents.max_depth`)

### Stuck Detection

Headless (subagent) loops use `StuckDetector` to prevent infinite tool call loops:

1. Maintains a rolling window of 8 tool call signatures
2. If the latest signature appears 3+ times: **nudge** — injects a system message asking the agent to consolidate
3. After 2 more turns still stuck: **final notice** — stronger instruction to stop
4. After 2 more turns: **force return** — terminates the agent and returns the last response

The detector resets when the agent starts making diverse tool calls again.

## Display System

### TUI Mode

Subagent display is managed by `SubagentDisplayManager`:
- `showSpawn()` — shows which agents were spawned (tree widget)
- `showRunning()` — starts elapsed timer with done count
- `refreshTree()` — updates live tree with per-agent status icons (running, done, failed, waiting)
- `showBatch()` — shows completed results with stats

All widgets are placed inside a wrapper `ContainerWidget` so they stay inline at the spawn position (not pushed to the bottom by subsequent conversation widgets).

The breathing animation timer (owned by `TuiAnimationManager`) delegates tree refresh to `SubagentDisplayManager.tickTreeRefresh()` every ~0.5s.

### ANSI Mode

Uses `AgentDisplayFormatter` (shared static utilities) for consistent formatting:
- `formatAgentLabel()` — colored type + id + task preview
- `formatElapsed()` — human-readable duration ("42s", "1m 30s")
- `formatAgentStats()` — elapsed + tool count
- `renderChildTree()` — box-drawing tree with status icons

### Swarm Dashboard

The `/agents` command shows a live dashboard (`SwarmDashboardWidget` in TUI, `formatDashboard()` in ANSI) with:
- Progress bar and completion percentage
- Active agents with per-agent progress bars
- Resource usage (tokens, cost, rate, ETA)
- Failure summary with retry stats
- Breakdown by agent type

## Configuration

In `config/kosmokrator.yaml`:

```yaml
kosmokrator:
  agents:
    max_depth: 3          # Maximum agent tree depth
    concurrency: 3        # Maximum concurrent agents
    max_retries: 2        # Retry attempts for failed agents
```
