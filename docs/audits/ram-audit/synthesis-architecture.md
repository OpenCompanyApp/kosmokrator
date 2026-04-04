# Architecture Memory Efficiency Report

**Project:** KosmoKrator — AI coding agent for the terminal  
**Audit Scope:** Subagent orchestration, event propagation, service container, task tracking, memory repository patterns  
**Date:** 2026-04-03  
**Status:** Phase 1 Synthesis

---

## Executive Summary

This report synthesizes RAM efficiency findings from five Phase 1 audit agents covering core architectural subsystems. The analysis reveals **critical memory inefficiencies** in two areas: **subagent orchestration** (unbounded retention of completed agent data) and **memory repository** (unbounded database fetches). The **task tracking system** shows moderate issues with unbounded growth and high-frequency re-renders. The **event system** is exemplary — minimal overhead, tiny payloads, single listener. The **service container** pattern avoids per-subagent bootstrapping but has minor duplication of stateless components.

**Overall Risk Assessment:** 🔴 **HIGH** — Two critical leaks can cause unbounded RAM growth in long-running sessions; one high-risk database pattern loads all memories on every LLM round.

**Key Metrics:**
- **Subagent orchestrator:** Retains completed agent futures & stats indefinitely; group semaphores accumulate; background results held until manual collection.
- **Memory selection:** Fetches entire `memories` table on every context rebuild (O(N) per LLM round), scores all in-memory, then discards — repeated 3–4× per user turn.
- **Task system:** Full tree re-render at 30fps in TUI mode; no eviction policy; stale dependency edges retained after task removal.
- **Event system:** ~28 bytes per dispatched event; single listener; negligible overhead.

---

## Findings (Severity)

### 🔴 Critical

| # | Component | Issue | Impact | File:Line |
|---|-----------|-------|--------|-----------|
| C1 | SubagentOrchestrator | Completed agent futures & stats retained indefinitely; `pruneCompleted()` never auto-called | Unbounded RAM growth with agent count; each entry ~200–500 bytes + future closure overhead | `src/Agent/SubagentOrchestrator.php:392-409` (prune exists but not invoked) |
| C2 | SubagentOrchestrator | Group semaphores (`$groups`) created per unique group name, never removed | Unbounded growth if group names are dynamic (e.g., per-task groups) | `src/Agent/SubagentOrchestrator.php:471` |
| C3 | SubagentOrchestrator | Background agent results in `$pendingResults` cleared only via explicit `collectPendingResults()` | Accumulates if parent never collects; each result string can be KBs | `src/Agent/SubagentOrchestrator.php:??` |
| C4 | MemoryRepository | `forProject()` loads **all** memory rows into PHP on every call (no LIMIT) | With 10k memories: 100–500 MB per fetch; called on every LLM round (3–4×/turn) | `src/Session/MemoryRepository.php:65-88` |
| C5 | TaskStore | No task eviction policy; tasks accumulate until manual `/tasks clear` or REPL prompt | Unbounded growth; each task ~200–300 bytes + edge arrays | `src/Task/TaskStore.php:14-356` |
| C6 | TaskStore | `clearTerminal()` / `clearAll()` do **not** purge stale IDs from other tasks' `blockedBy`/`blocks` arrays | Memory leak: dangling references accumulate across clear cycles | `src/Task/TaskStore.php:??` |

### 🟠 High

| # | Component | Issue | Impact | File:Line |
|---|-----------|-------|--------|-----------|
| H1 | MemorySelector | Re-scores entire memory set on every LLM round; no caching | O(N log N) repeated work; with 1000 memories, ~10k comparisons per round × 3–4 rounds/turn | `src/Agent/MemorySelector.php:29-38` |
| H2 | TaskStore | TUI task bar re-renders full tree at ~30fps during active phases (every 33ms) | 3,000+ node visits/sec for 100 tasks; high allocation/GC pressure | `src/UI/Tui/TuiCoreRenderer.php:643-681`, `src/UI/Tui/TuiAnimationManager.php:378-420` |
| H3 | Database | Missing indexes on `memories` table: `memory_class`, `type`, `(pinned, created_at)`, `expires_at` | Full table scans for every `forProject()` and `search()`; CPU + memory pressure | `src/Session/Database.php:128` |
| H4 | TaskStore | Bidirectional edge storage duplicates every dependency (2× memory) | ~50% edge memory overhead vs central adjacency list | `src/Task/TaskStore.php:62-84` |

### 🟡 Medium

| # | Component | Issue | Impact | File:Line |
|---|-----------|-------|--------|-----------|
| M1 | SubagentFactory | Stateless `ContextPruner` & `ToolResultDeduplicator` instantiated per subagent unnecessarily | Minor per-agent overhead (~negligible but wasteful) | `src/Agent/SubagentFactory.php:90-103` |
| M2 | SubagentOrchestrator | `$stats->dependsOn` arrays grow O(N) but not pruned | Small but unbounded; ~8 bytes per parent ID × N | `src/Agent/SubagentStats.php:??` |
| M3 | TaskStore | `roots()` and `children()` scan entire task set each call (O(n)) | Inefficient for large n; could be indexed | `src/Task/TaskStore.php:??` |
| M4 | MemoryRepository | `fetchAll()` used everywhere — entire result set materialized even if only a few rows needed | Memory spike for large queries; streaming not used | `src/Session/MemoryRepository.php:??` |
| M5 | Event system | 5 unused event classes (ResponseCompleteEvent, StreamChunkEvent, etc.) | Code bloat only; zero runtime cost | `src/Agent/Event/*.php` |

### 🟢 Low / Informational

| # | Component | Note | Impact |
|---|-----------|------|--------|
| L1 | TaskStore | `toDetail()` JSON-encodes metadata; could be large if metadata contains big structures | Only when explicitly called |
| L2 | TaskStore | Subject truncation only in ANSI render; plain text shows full subject | Minor display inconsistency |
| L3 | Event system | `TokenTrackingListener` state persists session-wide; integers could overflow in theory (practically impossible) | None |
| L4 | SubagentOrchestrator | `totalTokens()` iterates all stats on demand — O(n) but acceptable | None |

---

## Memory Hotspots (file:line + estimates)

### Subagent Orchestration (`src/Agent/`)

| Hotspot | File:Line | Estimate | Notes |
|---------|-----------|----------|-------|
| Completed agent futures array | `SubagentOrchestrator.php:??` | ~500 bytes/agent + closure capture | Grows unbounded; primary leak |
| Completed stats array | `SubagentOrchestrator.php:??` | ~300–500 bytes/agent | Mirrors `$agents` |
| Group semaphores | `SubagentOrchestrator.php:471` | ~100–200 bytes/group | Accumulates with unique group names |
| Pending background results | `SubagentOrchestrator.php:??` | Size of result string (KB) per background agent | Held until parent collects |
| Per-agent ConversationHistory | `AgentLoop.php:??` | Grows with message count; ~100–1000+ bytes/message | Freed when AgentLoop GC'd (if future not retained) |
| Per-agent LLM client | `SubagentFactory.php:??` | ~few KB (HTTP client, listeners) | New per subagent; intentional isolation |

### Event System (`src/Agent/Event/`, `src/Kernel.php`)

| Hotspot | File:Line | Estimate | Notes |
|---------|-----------|----------|-------|
| Dispatched event objects | `AgentLoop.php:184,213,245,344,401,462,816,829` | ~28 bytes/event | 8–9 events per typical run; negligible |
| TokenTrackingListener state | `Listener/TokenTrackingListener.php:??` | 4× int = 32 bytes + object header | Accumulates counts only; no per-event storage |

### Task Tracking (`src/Task/`, `src/UI/`)

| Hotspot | File:Line | Estimate | Notes |
|---------|-----------|----------|-------|
| Task objects array | `TaskStore.php:14` | ~200–300 bytes/task + edges | Unbounded; no eviction |
| Edge arrays (blockedBy/blocks) | `Task.php:??` | ~8 bytes/edge × 2 (bidirectional) | Duplicate storage; stale IDs never purged |
| TUI task bar render buffer | `TuiCoreRenderer.php:643-681` | Full tree string + ANSI codes | Rebuilt every 33ms; ~10–100 KB per render depending on tree size |
| Full tree render (per call) | `TaskStore.php:174-186, 219-287` | O(n) string allocation | Called on every task tool and TUI refresh |

### Memory Repository (`src/Session/`)

| Hotspot | File:Line | Estimate | Notes |
|---------|-----------|----------|-------|
| `forProject()` result set | `MemoryRepository.php:65-88` | **All rows** — 10k memories = 100–500 MB | Called on every LLM round via `SessionManager::getMemories()` |
| In-memory memory array (during selection) | `SessionManager.php:276-281` | Full memory set duplicated in PHP array | Held during `MemorySelector::select()` sort |
| `usort()` temporary arrays | `MemorySelector.php:29-38` | O(N) additional zvals | Sorting overhead doubles memory footprint temporarily |
| Uncapped search result formatting | `MemorySearchTool.php:104` | Full content of each memory echoed | Limited to 20 results but each could be large |

---

## Architectural Concerns

### 1. Subagent Orchestration: Lifecycle & Retention Policy

**Current design:** The `SubagentOrchestrator` acts as a global registry for all agents spawned in a session. It stores:
- `$agents`: Future objects keyed by agent ID
- `$stats`: SubagentStats objects keyed by agent ID
- `$pendingResults`: Background results keyed by parent ID
- `$groups`: Semaphore objects keyed by group name

**Concern:** No automatic cleanup. The orchestrator lives for the entire session. Completed agents are never pruned unless some external code calls `pruneCompleted()`. In practice, this never happens automatically. This turns the orchestrator into an **unbounded accumulation vector**.

**Why it matters:** In a long-running session with many subagent spawns (e.g., iterative planning, recursive decomposition), the `$agents` and `$stats` arrays grow linearly. While each entry is small, the cumulative effect over hours/days can be tens of MB. More importantly, the `$pendingResults` for background agents can hold large output strings indefinitely.

**Secondary concern:** Group semaphores are created on first use and never destroyed. If group names are dynamic (e.g., per-task or per-context), this creates another unbounded array.

**Pattern assessment:** The orchestrator is a **global mutable registry** with no TTL, no weak references, no size limits. This is a classic memory leak pattern.

---

### 2. Memory Selection: N+1 Fetch & Repeated Scoring

**Current design:** Every time the LLM is called (3–4 times per user turn due to tool calls), the system:
1. Calls `SessionManager::getMemories()` → `MemoryRepository::forProject()` → `SELECT * FROM memories` (no LIMIT, no filters pushed down)
2. Fetches **all** memory rows into PHP (could be thousands)
3. Scores each memory against the current query using `MemorySelector::select()` (O(N log N) sort)
4. Takes top 6 and injects into context
5. Discards the full set until next round

**Concern:** This is an **N+1 query problem** compounded by **repeated full-table scans and in-memory sorts**. With 1000 memories, each round loads 1000 rows, scores them, and throws them away — repeated 3–4 times per turn. That's 3000–4000 full scans per user interaction.

**Why it matters:** RAM spikes from loading all rows; CPU waste from repeated scoring; no caching. As memory count grows, latency and memory pressure grow superlinearly due to sort.

**Pattern assessment:** Anti-pattern: **fetch-all-then-score-in-application** instead of **filter-score-limit in database**. The database is perfectly capable of sorting and limiting if scoring is expressed as an ORDER BY expression.

---

### 3. Task System: In-Memory Graph with No Eviction

**Current design:** Tasks are stored in a simple associative array (`TaskStore::$tasks`). There is:
- No persistence (tasks lost on restart)
- No eviction policy (only manual `/tasks clear` or REPL-triggered `clearTerminal()`)
- No pagination or depth limits
- Bidirectional edge storage (duplicate arrays)
- Full tree re-render on every task operation and at 30fps in TUI

**Concern:** The task system is designed for **small-scale, short-lived sessions**. For complex multi-agent workflows generating 100+ tasks, memory and CPU usage become excessive due to:
- O(n) full scans for `roots()`, `children()`, `renderTree()`
- O(n²) worst-case rendering if many blockers per task
- 30fps re-renders = thousands of node visits/sec
- Stale edge references never cleaned up on task removal

**Why it matters:** KosmoKrator is meant for complex coding tasks that may generate many subtasks. The current implementation does not scale.

**Pattern assessment:** In-memory graph with linear scans is acceptable for <50 nodes but needs indexing/eviction for production-scale use.

---

### 4. Event System: Minimalist & Efficient

**Current design:** Events are small, immutable DTOs. Only 3 events are actually dispatched (carrying aggregated metrics). Dispatcher has a single listener (`TokenTrackingListener`). Dispatch is synchronous, immediate.

**Assessment:** This is **architecturally sound**. No buffering, no async overhead, no payload duplication. The event system is a non-issue from a RAM perspective.

**Minor note:** 5 unused event classes exist but are dead code — harmless but could be removed for cleanliness.

---

### 5. Service Container: Factory Pattern Avoids Per-Agent Bootstrapping

**Current design:** `SubagentFactory` receives shared services via constructor (ToolRegistry, ModelCatalog, etc.). It constructs a fresh `AgentLoop` per subagent but passes shared services. No per-agent service container is created.

**Assessment:** This is **efficient**. Avoids the overhead of a full DI container per subagent. The object graph is lean.

**Minor duplication:** `ContextPruner` and `ToolResultDeduplicator` are stateless but instantiated per `AgentLoop`. They could be shared singletons injected once into the factory.

---

## Recommendations

### Immediate (Priority 1 — Critical Leaks)

#### R1. Auto-prune completed subagents
- **Where:** `SubagentOrchestrator`
- **What:** Call `pruneCompleted()` automatically after each agent finishes or via a periodic timer (e.g., every 10 completions).
- **Alternative:** Use `WeakReference` for `$agents` entries if parent might still await results; but explicit prune is simpler.
- **Impact:** Prevents unbounded growth of `$agents`, `$stats`, `$pendingResults`.

#### R2. Clean up group semaphores
- **Where:** `SubagentOrchestrator`
- **What:** Track reference count per group; when the last agent in a group completes, `unset($this->groups[$group])`.
- **Impact:** Prevents semaphore accumulation from dynamic group names.

#### R3. Auto-collect background results on parent completion
- **Where:** `SubagentOrchestrator::runAgent()` (where background mode is handled)
- **What:** When a parent agent finishes, automatically call `collectPendingResults($parentId)` to free result strings.
- **Impact:** Prevents large result strings from lingering.

#### R4. Fix unbounded memory fetch
- **Where:** `MemoryRepository::forProject()` and `SessionManager::getMemories()`
- **What:** Replace `SELECT *` with a **LIMIT** or **cursor-based streaming** for full scans. Better: push scoring into SQL.
- **Short-term:** Add `? LIMIT 1000` to `forProject()` to cap rows; log warning if truncated.
- **Long-term:** Implement SQL-based scoring: `SELECT *, (CASE ...) AS score FROM memories WHERE … ORDER BY score DESC LIMIT 6`.
- **Impact:** Reduces per-round RAM from O(all memories) to O(selected memories).

#### R5. Add task eviction policy
- **Where:** `TaskStore`
- **What:** Add configurable `max_tasks` (e.g., 100) with LRU eviction. When adding a task exceeds limit, remove oldest non-terminal tasks.
- **Alternative:** Auto-clear completed tasks after each tool call (not just at REPL prompt).
- **Impact:** Bounds task memory; prevents unbounded accumulation.

#### R6. Purge stale dependency edges
- **Where:** `TaskStore::clearTerminal()` and `TaskStore::clearAll()`
- **What:** After removing tasks, walk all remaining tasks and filter `blockedBy`/`blocks` arrays to remove IDs not in `$this->tasks`.
- **Impact:** Prevents stale ID accumulation; reduces array bloat over time.

---

### High Priority (Priority 2 — Performance & Scaling)

#### R7. Debounce TUI task bar refresh
- **Where:** `TuiAnimationManager` (breathing timer) and `TuiCoreRenderer::refreshTaskBar()`
- **What:** Reduce refresh rate from 30fps (33ms) to 5–10fps (100–200ms) during breathing animation. Use dirty flag: only re-render if task tree changed.
- **Impact:** Cuts node visits/sec by 3–6×; reduces allocation/GC pressure.

#### R8. Add database indexes for memories
- **Where:** `src/Session/Database.php` (migration/schema)
- **What:** Add composite index:
  ```sql
  CREATE INDEX idx_memories_lookup ON memories(project, memory_class, type, expires_at, pinned DESC, created_at DESC);
  ```
- Also add single-column indexes on `memory_class` and `type` if composite not feasible.
- **Impact:** Speeds up `forProject()` and `search()`; reduces rows scanned → less memory loaded.

#### R9. Cache memory selection per turn
- **Where:** `ContextManager`
- **What:** Add property `$memoryCache = []` keyed by query string; populate on first `selectRelevantMemories()` call per LLM round; reuse for subsequent calls within same round.
- **Impact:** Avoids re-scoring same memories multiple times per turn (3–4× reduction).

#### R10. Centralize edge storage (optional)
- **Where:** `TaskStore`
- **What:** Replace per-task `blockedBy`/`blocks` arrays with a central adjacency map: `$edges = ['blocks' => ['from' => ['to1', 'to2']], 'blockedBy' => …]`. Derive per-task views on demand or maintain denormalized caches.
- **Impact:** ~50% edge memory reduction; easier cleanup; but adds complexity.

---

### Medium Priority (Priority 3 — Cleanup & Minor Gains)

#### R11. Share stateless components
- **Where:** `SubagentFactory`
- **What:** Instantiate `ContextPruner` and `ToolResultDeduplicator` once as private properties; pass to each `AgentLoop`.
- **Impact:** Negligible RAM savings; reduces per-agent object count.

#### R12. Implement auxiliary indexes for tasks
- **Where:** `TaskStore`
- **What:** Maintain `parentId => [childIds]` map updated on `add()`/`update()`. Makes `children()` O(1) and `roots()` O(1) with `parentId === null` index.
- **Impact:** Faster queries; minor RAM overhead for index arrays.

#### R13. Remove unused event classes
- **Where:** `src/Agent/Event/`
- **What:** Delete `ResponseCompleteEvent`, `StreamChunkEvent`, `ThinkingEvent`, `ToolCallEvent`, `ToolResultEvent` if truly unused.
- **Impact:** Code cleanliness only; zero runtime effect.

#### R14. Add full-text search (FTS5) for memories
- **Where:** Database schema
- **What:** Create virtual table `memories_fts` on `(title, content)`; rewrite `search()` to use `MATCH`.
- **Impact:** Faster text search; allows index-based lookup instead of full scan.

---

### Long-term / Exploratory

#### R15. Memory repository pagination API
- Design a `MemoryRepository::getRecent(int $limit, int $offset)` for UI browsing, separate from `forProject()` which should be for context injection only.

#### R16. Task tree depth limiting
- Add config `max_task_depth` (e.g., 5); deeper tasks are truncated or rejected.

#### R17. Benchmark suite completion
- Create the three benchmark scripts referenced in Phase 1 reports:
  - `docs/ram-audit/benchmarks/subagent-memory.php`
  - `docs/ram-audit/benchmarks/event-memory.php` (already created)
  - `docs/ram-audit/benchmarks/task-memory.php`
  - `docs/ram-audit/benchmarks/memory-memory.php`
- Use them to validate fixes and track regressions.

---

## Implementation Roadmap (Suggested Order)

| Phase | Targets | Expected RAM Reduction |
|-------|---------|------------------------|
| 1 | R1, R2, R3 (subagent leaks) | Stops unbounded growth; ~500 bytes/agent saved after completion |
| 2 | R4, R8 (memory fetch + indexes) | Per-round RAM from O(N) to O(1); 100–500 MB saved for 10k memories |
| 3 | R5, R6, R7 (task eviction + edge cleanup + TUI debounce) | Bounds task memory; 30fps → 5fps = 6× fewer renders |
| 4 | R9 (memory caching) | 3–4× fewer scorings per turn; CPU savings |
| 5 | R10, R11, R12, R13 (optimizations) | Minor gains; code quality |
| 6 | R14, R15, R16 (FTS, pagination, depth limit) | Scalability improvements |

---

## Conclusion

KosmoKrator's architecture is **generally sound** but suffers from two **critical unbounded-growth vectors**:
1. Subagent orchestrator retains completed agent data indefinitely.
2. Memory repository loads all memories on every LLM round.

The **task system** also requires **bounded eviction** and **render throttling** to scale. The **event system** is exemplary. The **service container** pattern is efficient with minor duplication opportunities.

**Immediate action** on R1–R4 will prevent RAM exhaustion in long-running or memory-intensive sessions. Subsequent phases (R5–R9) will improve performance and scalability. The benchmark suite should be completed to quantify improvements and guard against regressions.

---

## Appendix: Files Analyzed

### Subagent Orchestration
- `src/Agent/SubagentOrchestrator.php`
- `src/Agent/SubagentFactory.php`
- `src/Agent/SubagentStats.php`
- `src/Agent/SubagentPipeline.php`
- `src/Agent/SubagentPipelineFactory.php`
- `src/Agent/SubagentModelConfig.php`
- `src/Agent/StuckDetector.php`
- `src/Agent/AgentLoop.php`
- `src/Agent/ConversationHistory.php`
- `src/Agent/ContextManager.php`
- `src/Agent/ContextCompactor.php`
- `src/Agent/ContextPruner.php`
- `src/Agent/ToolResultDeduplicator.php`

### Event System
- `src/Agent/Event/*.php` (8 events)
- `src/Kernel.php`
- `src/Provider/EventServiceProvider.php`
- `src/Agent/Listener/TokenTrackingListener.php`

### Task Tracking
- `src/Task/Task.php`
- `src/Task/TaskStore.php`
- `src/Task/TaskStatus.php`
- `src/Task/Tool/TaskCreateTool.php`
- `src/Task/Tool/TaskGetTool.php`
- `src/Task/Tool/TaskListTool.php`
- `src/Task/Tool/TaskUpdateTool.php`
- `src/UI/Tui/TuiCoreRenderer.php`
- `src/UI/Tui/TuiAnimationManager.php`
- `src/UI/Ansi/AnsiCoreRenderer.php`
- `src/Command/AgentCommand.php`
- `src/Agent/ContextManager.php`

### Memory Repository
- `src/Session/MemoryRepository.php`
- `src/Session/SessionManager.php`
- `src/Session/Tool/MemorySaveTool.php`
- `src/Session/Tool/MemorySearchTool.php`
- `src/Agent/MemorySelector.php`
- `src/Agent/MemoryInjector.php`
- `src/Session/SettingsRepository.php`
- `src/Session/Database.php`

---

**Report generated from Phase 1 agent findings.**  
**Next step:** Implement Priority 1 recommendations and validate with benchmark suite.
