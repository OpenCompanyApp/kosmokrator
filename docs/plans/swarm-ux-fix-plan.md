# Swarm UX and Operability Fix Plan

> Status: Plan for review.
> Scope: Make long-running subagent swarms smoother, more observable, and more reliable
> without changing the intentional huge-swarm and long/infinite retry design.

## Goal

KosmoKrator already has the right large-swarm primitives: nested subagents, dependency DAGs, background mode, global and group concurrency, retry handling, live TUI display, and `/agents`.

The biggest gap is operability. A swarm that can run for hours or days needs to be easy to monitor, interrupt, inspect, and resume from partial state. This plan prioritizes the fixes that make the current runtime feel reliable first, then builds toward day-scale persistence.

## Non-Goals

- Do not remove long-running or infinite-style retry behavior.
- Do not impose small hard timeouts on subagents.
- Do not replace the current orchestrator with a different architecture in one rewrite.
- Do not start with full process resurrection before the swarm state model is persisted.

## Phase 1: Active-State Correctness

### Problem

`hasRunningBackgroundAgents()` currently treats only `running` agents as active. In a real swarm, agents can still be live while they are:

- waiting on dependencies
- queued behind the global semaphore
- queued behind a group semaphore
- retrying after a transient failure

If those states are ignored, the REPL can stop auto-waiting while the swarm still has pending work.

### Fix

- Replace the narrow active check with a broader method, for example `hasActiveBackgroundAgents()`.
- Treat these statuses as active:
  - `running`
  - `retrying`
  - `queued`
  - `queued_global`
  - `waiting`
- Treat these statuses as terminal:
  - `completed`
  - `failed`
  - `cancelled`
- Update `AgentLoop` and `AgentCommand` to wait while there are active background agents or pending results.

### Expected Impact

The UI will keep tracking a swarm while work is still genuinely alive. This should make background swarms feel continuous instead of disappearing when agents are queued or retrying.

### Risk

The REPL may remain in the background-agent wait loop longer than before. That is acceptable as long as typed user input still interrupts the wait loop.

## Phase 2: Concurrency Slot Yield/Reclaim Correctness

### Problem

The current `yieldSlot()` and `reclaimSlot()` flow appears inconsistent. `yieldSlot()` releases and unsets an agent's global semaphore lock, but `reclaimSlot()` can return early because the lock no longer exists.

That makes concurrency accounting hard to reason about when parent agents wait on children.

### Fix

- Track whether an agent has yielded its global slot separately from whether it currently holds a lock.
- Ensure `yieldSlot($agentId)` releases the lock once and records that the agent yielded.
- Ensure `reclaimSlot($agentId)` can reacquire after a previous yield.
- Add tests with a low global concurrency limit where a parent waits on child agents.

### Expected Impact

Concurrency behavior will match the configured limit more reliably, and dashboard state will better reflect actual scheduler pressure.

### Risk

Changing semaphore behavior can expose latent deadlocks or over-serialization. The tests should cover parent/child waiting paths before changing larger swarm behavior.

## Phase 3: Visible Background Completions

### Problem

Background results are injected into the conversation history, but the TUI display path can visually hide them because background batch entries are filtered out of `showBatch()`.

For long-running swarms, users need an obvious signal when an agent completes.

### Fix

- Add a compact visible completion event for background agents.
- Show:
  - agent id
  - status
  - elapsed time
  - tool count
  - short result preview
- Avoid dumping full result bodies into the TUI widget.
- Either stop filtering all background entries in `showBatch()` or add a dedicated renderer method such as `showBackgroundCompletions()`.
- Add an ANSI equivalent so both renderers expose the same information.

### Expected Impact

Users can see progress and completions without opening the dashboard constantly. Background work becomes visible without flooding the main conversation.

### Risk

Large swarms can produce noisy completion events. The TUI should keep them compact and should eventually group bursts of completions.

## Phase 4: Stable Agent Identity

### Problem

When the model omits an agent id, `SubagentTool` generates one internally. Some UI paths still inspect the original tool args, so generated IDs may not be consistently visible in trees, batches, and result summaries.

### Fix

- Normalize agent specs before spawning.
- Write generated IDs back into the effective spec.
- Return the final assigned ID in subagent tool results.
- Make `ToolExecutor` use the assigned ID instead of only reading the original tool args.
- Keep a stable machine ID separate from any future display name or task name.

### Expected Impact

Every spawned agent can be correlated across the live tree, `/agents`, background result injection, logs, and future persisted state.

### Risk

Low. This should mostly clarify existing behavior.

## Phase 5: Better Live Status Model

### Problem

`SubagentStats` gives useful high-level state, but it does not explain enough for day-scale operation. A dashboard should answer why an agent is waiting, what it last did, and whether it is healthy.

### Fix

Extend or reliably populate stats fields such as:

- `lastActivityAt`
- `lastTool`
- `lastMessagePreview`
- `queueReason`
- `retryDelayUntil` or `nextRetryAt`
- `provider`
- `model`
- `group`
- `outputPreview`

Update stats from the orchestrator and tool execution path when agents start, wait, retry, execute tools, and complete.

### Expected Impact

The dashboard can show meaningful states like:

- running `bash`
- waiting for dependency `audit-3`
- queued behind global concurrency
- retrying in 42 seconds
- idle for 8 minutes

### Risk

The stats object can become a dumping ground. Keep it focused on fields that improve operator decisions.

## Phase 6: Scalable Tree Rendering

### Problem

The current tree builder recursively scans all stats for each node. That is acceptable for small trees, but it becomes expensive for large swarms.

Also, rendering thousands of rows inline is not useful.

### Fix

- Build a `parentId => children[]` index once per render.
- Render the most relevant agents first:
  - running
  - retrying
  - failed
  - waiting or queued
  - recently completed
- Add row caps for inline trees.
- Collapse large sibling groups into summaries.

### Expected Impact

Tree refreshes stay smooth under large swarms, and the displayed tree becomes easier to scan.

### Risk

Collapsed displays can hide useful details. The full `/agents` detail view should remain available for inspection.

## Phase 7: `/agents` Detail UX

### Problem

`/agents` is useful as a summary, but large swarms need an operating console: filter, inspect, search, and understand individual agents without flooding the main thread.

### Fix

Add a richer TUI dashboard/detail view with:

- status filters: active, failed, waiting, completed
- search by id, task, or group
- selected-agent detail
- dependency list
- retry count
- current or last tool
- last activity
- result preview
- keyboard navigation

Keep the first version simple and read-only. Control actions can come after the state model is solid.

### Expected Impact

Users can inspect large swarms directly instead of waiting for summaries or reading raw logs.

### Risk

This touches TUI complexity. It should build on the existing `SwarmDashboardWidget` instead of creating a parallel UI stack.

## Phase 8: Minimal Durable Swarm Metadata

### Problem

The orchestrator is currently mostly in-memory. For swarms that can run for days, losing visibility after a restart or crash is unacceptable.

Full resumable execution is a larger feature. The first durable step should persist metadata and status transitions.

### Fix

Persist:

- agent id
- parent id
- root session id
- type
- mode
- group
- dependencies
- task or task preview
- status transitions
- attempts and retry count
- timestamps
- model and provider
- final summary or result preview
- pending result delivery state

Do not attempt full process resurrection in this phase.

### Expected Impact

The system gains historical visibility and a foundation for future resume support. `/agents` can show useful context after restart even before live-agent resurrection exists.

### Risk

Adds schema and lifecycle complexity. Keep the first schema narrow and append-friendly.

## Phase 9: Output Spooling

### Problem

Large subagent outputs should not live entirely in memory or be injected wholesale into parent context. Huge swarms need output files or blobs plus previews.

### Fix

- Write full subagent outputs to per-agent files or session-backed blobs.
- Store output references in swarm metadata.
- Keep only concise summaries/previews in memory and parent context.
- Let the dashboard show tail previews.

### Expected Impact

Memory use and context bloat are reduced, and completed agent outputs remain inspectable.

### Risk

Output retention needs clear cleanup rules. Tie cleanup to session retention or an explicit swarm artifact directory.

## Recommended Implementation Order

1. Active-state correctness.
2. Concurrency slot yield/reclaim correctness.
3. Visible background completions.
4. Stable generated agent IDs.
5. Expanded status/progress fields.
6. Scalable tree rendering.
7. Better `/agents` detail UX.
8. Minimal durable swarm metadata.
9. Output spooling.

## Later Work

These are valuable, but should come after the state and visibility foundations:

- parent-to-child message sending
- pause/resume/close controls
- foregrounding a background agent
- file claims or write-scope coordination for sibling `general` agents
- optional per-agent worktrees for high-risk write-heavy swarms
- provider/model concurrency budgets
- full resumable execution after process restart
