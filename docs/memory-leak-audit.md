# Memory Leak Audit

Comprehensive audit of the KosmoKrator codebase (131 PHP files, ~21k lines) for memory leaks, resource leaks, and unbounded growth patterns. Covers all subsystems: Agent loop, Subagent orchestrator, LLM/HTTP layer, Tools, TUI/ANSI rendering, Session persistence, and vendor dependencies.

---

## Table of Contents

- [Executive Summary](#executive-summary)
- [Object Reference Map & Cycles](#object-reference-map--cycles)
- [Critical Findings](#critical-findings)
- [High Findings](#high-findings)
- [Medium Findings](#medium-findings)
- [Low Findings](#low-findings)
- [Vendor Library Risks](#vendor-library-risks)
- [Async/Event-Loop Pattern Audit](#asyncevent-loop-pattern-audit)
- [Positive Findings (Clean)](#positive-findings-clean)
- [Recommended Fix Plan](#recommended-fix-plan)

---

## Executive Summary

10 subagents audited every file in `src/` plus key vendor libraries (`amphp/http-client`, `amphp/amp`, `amphp/process`, `prism-php/prism`, `revolt/event-loop`). Findings break down as follows:

| Severity | Count | Summary |
|----------|-------|---------|
| CRITICAL | 8 | Unbounded growth, leaked timers, leaked HTTP connections |
| HIGH | 12 | Circular reference chains, missing destructors, unbounded buffers |
| MEDIUM | 14 | Accumulating caches, soft-deleted data, missing cleanup |
| LOW | 10 | Minor issues, theoretical risks, bounded growth |

The three highest-impact areas are:

1. **SubagentOrchestrator** — failed agents never pruned, `Future` objects with large closures accumulate
2. **HTTP connection pools** — each subagent creates a fresh `AsyncLlmClient` with an unbounded connection pool
3. **TUI timer lifecycle** — timers not cancelled on teardown, pinning the entire renderer object graph

---

## Object Reference Map & Cycles

### Cycle 1: The Agent Loop Cycle (GC-Resistant)

```
AgentLoop ◂──────────────────────────────────────────┐
 │  $agentContext                                     │
 │  $allTools                                         │
 ▼                                                    │
AgentContext ───$orchestrator──▸ SubagentOrchestrator │
 │                                    │               │
 │  (readonly, shared)                │ $agents[] → Future<string>
 │                                    │   │           │
 ▼                                    │   ▼ (fiber closure captures)
SubagentTool ◂──── ToolRegistry ◂────┘   SubagentFactory
 │  $parentContext                        │  $rootRegistry → ToolRegistry
 │  $agentFactory ───────────────────────┘
 └──▸ Closure captures $subagentFactory
      └──▸ creates child AgentLoop
           (child.agentContext → SAME orchestrator)
```

**Pinned by:** Amp EventLoop holds Future refs. Not breakable by PHP GC until Futures complete and `pruneCompleted()` is called.

**Objects in cycle:** 5+ (AgentLoop, AgentContext, SubagentTool, ToolRegistry, SubagentOrchestrator)  
**Estimated pinned memory:** 5–15 KB base + unbounded Future/Stats arrays

### Cycle 2: The TUI Display Cycle (Timer-Pinned)

```
TuiRenderer ◂──────────────────────────────────┐
 │  $subagentDisplay                            │
 │  $animationManager                           │
 ▼                                              │
SubagentDisplayManager ──Closures($this)──▸ TuiRenderer
 │                                              │
 │  $breathColorProvider → $animationManager    │
 │  $renderCallback → $this→flushRender()       │
 │                                              │
 └───◂── TuiAnimationManager ──────────────────┘
       $subagentTickCallback → $subagentDisplay
       $renderCallback → $this→flushRender()
       $refreshTaskBar → $this→refreshTaskBar()
```

**Pinned by:** 4+ `EventLoop::repeat()` timers (30fps breathing, 20fps subagent, 50fps tool-executing, compacting). Not breakable while timers are active.

**Objects in cycle:** 3+ (TuiRenderer, SubagentDisplayManager, TuiAnimationManager, TuiModalManager)  
**Estimated pinned memory:** 20–50 KB + widget tree

### Cycle 3: TuiRenderer ↔ TuiModalManager

```
TuiRenderer → TuiModalManager
TuiModalManager.$renderCallback → Closure($this = TuiRenderer)
TuiModalManager.$forceRenderCallback → Closure($this = TuiRenderer)
```

**Pinned by:** Dashboard timer when active. Otherwise breakable by PHP GC.

### Subagent Tree Spanning Cycle

At depth 2 with 3 concurrent agents, every child references back to the root's `ToolRegistry`:

```
Child AgentLoop → child SubagentTool → $agentFactory Closure → $subagentFactory
  → $subagentFactory.rootRegistry → ROOT ToolRegistry → ROOT SubagentTool
  → ROOT SubagentTool.parentContext → ROOT AgentContext → SubagentOrchestrator
  → SubagentOrchestrator.agents[childId] → Future → child fiber → child AgentLoop
```

For a full depth-2 tree (12 agents), estimated total pinned memory: **400 KB – 3 MB** (dominated by `ConversationHistory`).

---

## Critical Findings

### C1. Fresh HttpClient per Subagent — N Independent Connection Pools

**Files:** `src/Agent/SubagentFactory.php:96–104`, `src/LLM/AsyncLlmClient.php:32`

Each `createAndRunAgent()` creates a new `AsyncLlmClient` → new `HttpClientBuilder::buildDefault()` → new `UnlimitedConnectionPool` (limit: `PHP_INT_MAX`). With 3+ concurrent subagents at depth 2–3, this creates multiple unbounded connection pools, each holding open sockets and TLS state. Never explicitly closed.

**Trigger:** Every subagent spawn.  
**Fix:** Share a single `HttpClient` across all `AsyncLlmClient` instances. Create it once in `SubagentFactory` constructor and inject it. Also bound the pool: `ConnectionLimitingPool::byAuthority(8)`.

---

### C2. ConversationHistory Unbounded in Headless Mode

**File:** `src/Agent/ConversationHistory.php:15`

In headless mode (subagents via `AgentLoop::runHeadless()`), no `ContextCompactor` is passed (`SubagentFactory.php:70`). The only backpressure is `trimOldest()` on overflow errors. Subagents processing many tool calls accumulate hundreds of messages with full tool output.

**Trigger:** Subagents with many tool call rounds.  
**Fix:** Pass a lightweight compactor or implement token-count-based trimming in headless mode.

---

### C3. SubagentOrchestrator — Failed Agent Futures Never Pruned

**File:** `src/Agent/SubagentOrchestrator.php:340`

```php
$terminalStates = ['done' => true, 'cancelled' => true];
```

`pruneCompleted()` only removes `'done'` and `'cancelled'`. Failed agents stay in `$this->agents` (holding `Future` objects with large closures) and `$this->stats` indefinitely.

**Trigger:** Any subagent failure (API errors, context overflows).  
**Fix:** Add `'failed'` to `$terminalStates`. One-line fix.

---

### C4. TUI Teardown Doesn't Cancel Timers

**File:** `src/UI/Tui/TuiRenderer.php:1143–1148`

`teardown()` calls `$this->tui->stop()` but never cancels the breathing timer (`TuiAnimationManager::$thinkingTimerId` at 30fps), the compacting timer, the subagent elapsed timer (`SubagentDisplayManager::$elapsedTimerId` at 20fps), or the tool-executing timer. These `EventLoop::repeat()` timers capture `$this` via closure, pinning the entire TuiRenderer + widget tree in memory.

**Trigger:** Process exit during thinking, tool execution, or while subagents are running.  
**Fix:** Add `TuiAnimationManager::shutdown()` that cancels all timers. Call it + `$subagentDisplay->cleanup()` from `teardown()`.

---

### C5. GrepTool Spawns `which rg` TWICE per Call, No Caching

**File:** `src/Tool/Coding/GrepTool.php:47,52,88–93`

`hasRipgrep()` spawns a new `Process` each call and is invoked twice per `execute()`. In heavy grep sessions, this triples process overhead.

**Trigger:** Every grep invocation.  
**Fix:** Cache: `private ?bool $hasRg = null;` and memoize.

---

### C6. GrepTool Has No Timeout

**File:** `src/Tool/Coding/GrepTool.php:64–67`

Unlike `BashTool`, `GrepTool` has zero timeout protection. A hung grep (network mount, FIFO, massive tree) blocks the agent loop forever.

**Trigger:** Searching slow filesystems or massive directories.  
**Fix:** Add timeout watchdog identical to `BashTool`'s pattern.

---

### C7. BashTool Timer NOT Cancelled on Exception

**File:** `src/Tool/Coding/BashTool.php:71–99`

`EventLoop::cancel($timerId)` at line 84 is only reached on the happy path. If `$process->join()` or `->await()` throws, execution jumps to the catch block and the timer leaks. The timer closure captures `$process`, keeping the Process object alive.

**Trigger:** Exception during process execution.  
**Fix:** Move `EventLoop::cancel($timerId)` to a `finally` block.

---

### C8. Unbounded `buffer()` — Full Command Output in Memory

**Files:** `src/Tool/Coding/BashTool.php:79–80`, `src/Tool/Coding/GrepTool.php:65–66`

`Amp\ByteStream\buffer()` reads entire stdout/stderr into a single string with no size cap. `OutputTruncator` runs after the full string is already in memory. A command producing GBs of output OOMs before truncation kicks in.

**BashTool additional issue:** The progress callback (`ToolExecutor.php:140–142`) passes the entire accumulated buffer to the UI on every chunk, not just the new chunk.

**Trigger:** Bash commands with large output (logs, data files, recursive listings).  
**Fix:** Stream output to a temp file with a configurable size cap, or use a chunked buffer that stops after N bytes and kills the process.

---

## High Findings

### H1. SubagentOrchestrator Has No `__destruct()` — Background Agents Orphaned

**File:** `src/Agent/SubagentOrchestrator.php:20–517`

Has `cancelAll()` but no destructor. If the orchestrator goes out of scope while background agents run, their cancellations are never triggered and futures execute orphaned.

**Fix:** Add `public function __destruct() { $this->cancelAll(); }`.

---

### H2. AgentContext Circular Reference via Orchestrator

**File:** `src/Agent/AgentContext.php:19`

Every `AgentContext` holds a strong reference to the singleton `SubagentOrchestrator`. The orchestrator's `spawnAgent()` async closure captures `$childContext`, which holds the orchestrator. Each subagent level replicates this cycle.

**Fix:** Use `WeakReference` for the orchestrator in `AgentContext`, or extract only scalars into the async closure.

---

### H3. SubagentOrchestrator `$pendingResults` — Orphaned Results Accumulate

**File:** `src/Agent/SubagentOrchestrator.php:31–32`

Background agent results (both success and failure) are stored in `$this->pendingResults[$parentId][$id]`. If a parent crashes or never calls `collectPendingResults()`, these accumulate forever.

**Fix:** Add TTL or size cap to `pendingResults`. Prune orphaned entries in `pruneCompleted()`.

---

### H4. Group Semaphores Never Evicted

**File:** `src/Agent/SubagentOrchestrator.php:381–384`

```php
return $this->groups[$name] ??= new LocalSemaphore(1);
```

Every unique group name creates a `LocalSemaphore` that is never removed.

**Fix:** Clear `$this->groups` in `pruneCompleted()` when no active agents reference a group.

---

### H5. Widget Tree Grows Unboundedly in TUI

**File:** `src/UI/Tui/TuiRenderer.php` (multiple methods)

`$this->conversation` ContainerWidget accumulates every widget ever added. Each `showToolCall()`, `showToolResult()`, `showSubagentSpawn()` adds permanent widgets. Only cleared on explicit `/new` or `/clear`.

**Trigger:** Long sessions with hundreds of tool calls.  
**Fix:** Implement a scrolling window — remove widgets beyond N turns, or collapse old tool results into summary widgets.

---

### H6. Prism Upstream: `StreamState::reset()` Doesn't Clear `thinkingSummaries`

**File:** `vendor/prism-php/prism/src/Streaming/StreamState.php:152,383–403`

`reset()` is called between tool-call turns in multi-step streaming, but `thinkingSummaries` array is never cleared. Grows across turns for models with extended thinking.

**Fix:** Upstream bug report. Patch: add `$this->thinkingSummaries = [];` to `reset()`.

---

### H7. Prism Upstream: New Provider Instance per Request

**File:** `vendor/prism-php/prism/src/PrismManager.php:40–56`

Every `PrismService::chat()` call creates a fresh provider + `PendingRequest` HTTP client. No caching. Causes GC pressure in tight loops.

**Fix:** Cache resolved providers in `PrismManager`, or in `PrismService`.

---

### H8. ConversationHistory Tool Result `args` Not Freed After Pruning

**File:** `src/Agent/ConversationHistory.php:15`

`pruneToolResults()` replaces `result` with a placeholder but leaves `args` intact. Large args (file contents for edits) persist for the entire session.

**Fix:** Null out `args` on pruned/superseded tool results.

---

### H9. SQLite PDO Connection Never Explicitly Closed

**File:** `src/Session/Database.php:9,25`

No `close()` method, no `__destruct()`. WAL journal mode enabled but never checkpointed. WAL file can grow without bound.

**Fix:** Add `close()` method and call from a shutdown handler. Add periodic `PRAGMA wal_checkpoint(TRUNCATE)`.

---

### H10. PrismService Uses No Connection Pooling (Guzzle Path)

**File:** `src/LLM/PrismService.php:113`

PrismService uses Laravel's `Http` facade (Guzzle under the hood), not Amp. Each request creates and tears down a fresh TCP+TLS connection. No connection reuse.

**Fix:** Enable Guzzle connection pooling or share a Guzzle client instance.

---

### H11. Full Message History Loaded on Session Resume

**File:** `src/Session/SessionManager.php:96–106`, `src/Session/MessageRepository.php:53–68`

On resume, `loadActive()` deserializes ALL non-compacted messages into memory via `fetchAll()`. For a long session with thousands of messages containing tool results with full file contents, this causes a significant memory spike.

**Fix:** Implement lazy loading or cursor-based pagination for message history.

---

### H12. `onRetry` Closure Captures UIManager in Singleton

**File:** `src/Agent/AgentSessionBuilder.php:74–78`

`$llm->setOnRetry(function (...) use ($ui) { ... })` captures the UIManager in a closure stored on the `RetryableLlmClient` singleton. Circular retention: container → LLM singleton → closure → UIManager.

**Fix:** Use `WeakReference` for `$ui` inside the closure.

---

## Medium Findings

| # | Finding | File | Fix |
|---|---------|------|-----|
| M1 | `SubagentTool` closure captures entire `SubagentFactory` + ancestor registries | `SubagentFactory.php:56–58` | Extract only config, not `$this` |
| M2 | `streamBuffer` not cleared on interrupted streaming | `AnsiRenderer.php:22,127` | Clear in error handler |
| M3 | `lastToolArgs` holds large strings between tool calls | `TuiRenderer.php:114` | Clear after consuming |
| M4 | GlobTool collects ALL results before truncating to 200 | `GlobTool.php:78–113` | Short-circuit at 200 |
| M5 | `register_shutdown_function` accumulates on repeated animation calls | `AnsiTheogony.php:84` et al | Register once with static flag |
| M6 | Memories accumulate indefinitely (no TTL/count limit) | `Session/MemoryRepository.php` | Add configurable limit + auto-prune |
| M7 | `forProject()` loads all memories without LIMIT | `Session/MemoryRepository.php:40–55` | Add LIMIT clause |
| M8 | Compacted messages flagged but never deleted from DB | `Session/MessageRepository.php:89–95` | Periodic `DELETE WHERE compacted = 1` |
| M9 | WAL file never checkpointed | `Session/Database.php:30` | Periodic `PRAGMA wal_checkpoint(TRUNCATE)` |
| M10 | Compaction stores raw summary as redundant memory | `Agent/ContextManager.php:142–156` | Skip raw summary, store only extracted memories |
| M11 | `OutputTruncator` files accumulate, cleanup only at construction | `Agent/OutputTruncator.php:23` | Call `cleanupOldFiles()` periodically |
| M12 | `FileEditTool` temp file not cleaned on crash | `Tool/Coding/FileEditTool.php:135–178` | Add `@unlink($tmpPath)` in finally block |
| M13 | `FileReadTool` doubles memory for under-threshold files | `Tool/Coding/FileReadTool.php:59` | Use streaming for all files or lower threshold |
| M14 | SubagentDisplayManager old containers never removed from conversation | `SubagentDisplayManager.php:118` | Remove old containers or prune |

---

## Low Findings

| # | Finding | File |
|---|---------|------|
| L1 | `AgentLoop` no `dispose()` method | `AgentLoop.php:21–606` |
| L2 | Kernel singletons held for process lifetime | `Kernel.php:299–341` |
| L3 | `Facade::setFacadeApplication()` static holds container | `Kernel.php:258–259` |
| L4 | `SessionGrants` unbounded growth (bounded by ~15 tool count) | `Tool/Permission/SessionGrants.php` |
| L5 | `hasRipgrep()` process stdout/stderr not consumed | `GrepTool.php:90–92` |
| L6 | Non-timeout BashTool exceptions don't explicitly kill process | `BashTool.php:70–96` |
| L7 | `ToolExecutor` static `BashTool::$progressCallback` not cleared on exception | `ToolExecutor.php:140–150` |
| L8 | `FutureState` unhandled error thrown on GC for unconsumed errored futures | `vendor/amphp/amp` |
| L9 | `resetSessionCost()` doesn't reset history | `AgentLoop.php:489–493` |
| L10 | Event loop `disable()` keeps closure in callbacks array (must use `cancel()` to free) | `vendor/revolt/event-loop` |

---

## Vendor Library Risks

### amphp/http-client

| Risk | Severity | Description |
|------|----------|-------------|
| Unlimited connection pool | **Medium** | `buildDefault()` uses `PHP_INT_MAX` limit. 64-idle-connection eviction only for idle connections, no time-based TTL |
| Connection leak on abandoned response | **High** | If response body not fully consumed and `Response` object kept alive, connection never returns to pool. GC-dependent cleanup via destructor *closes* the connection rather than returning it |
| Reference cycles in cancellation chain | **Medium** | `DeferredCancellation` → `Cancellable` → callbacks → connection → response body → cycle. Requires PHP cycle collector |

### amphp/amp

| Risk | Severity | Description |
|------|----------|-------------|
| `FutureState` unhandled error on GC | **Medium** | Errored Futures that are never consumed (`await()`, `catch()`, or `ignore()`) throw `UnhandledFutureError` from destructor into event loop |
| `DeferredCancellation` destructor auto-cancels | **Low** | Safety feature, but creates unnecessary event loop noise when background agents complete successfully |

### amphp/process

| Risk | Severity | Description |
|------|----------|-------------|
| Pipe buffers on kill | **Low** | OS pipe buffer (~64KB) can block child if full when killed |
| Static WeakMaps | **Low** | Self-cleaning, but stdout/stderr references elsewhere keep `ProcHolder` alive |

### prism-php/prism

| Risk | Severity | Description |
|------|----------|-------------|
| O(N²) message storage in Text handler | **High** | Each `Step` stores full message history. Multi-turn tool calls in Prism's internal loop accumulate quadratically. Not impactful for KosmoKrator since tool calls are driven externally |
| `StreamState::$thinkingSummaries` never cleared by `reset()` | **Low** | Upstream bug. Only freed when handler is discarded |
| PrismManager creates new providers each call | **Low** | No caching but objects are lightweight |

### revolt/event-loop

| Risk | Severity | Description |
|------|----------|-------------|
| Callbacks not freed on `disable()` | **Low** | Must use `cancel()` to free closures from `$callbacks` array |
| Callbacks remain in memory after `stop()` | **Low** | Loop stop doesn't clear `$callbacks`. Timers fire again if loop restarts |

---

## Async/Event-Loop Pattern Audit

### EventLoop::repeat() — 5 Call Sites

| Location | Timer | Cancelled When | Leak Risk |
|----------|-------|----------------|-----------|
| `TuiAnimationManager.php:214` | Compacting (30fps) | `clearCompacting()` | No finally/destructor guard |
| `TuiAnimationManager.php:386` | Breathing (30fps) | `enterTools()`, `enterIdle()` | Relies on phase transition |
| `SubagentDisplayManager.php:203` | Elapsed (20fps) | `stopLoader()` → `cleanup()` | Relies on `enterIdle()` chain |
| `TuiRenderer.php:749` | Tool executing (50fps) | `clearToolExecuting()` | No finally guard |
| `TuiModalManager.php:463` | Dashboard (0.5fps) | After `$suspension->suspend()` returns | Safe |

### EventLoop::delay() — 1 Call Site

| Location | Purpose | Cancelled When | Leak Risk |
|----------|---------|----------------|-----------|
| `BashTool.php:71` | Process timeout | Happy path only (line 84) | **NOT cancelled on exception** |

### Amp\async() — 4 Call Sites

| Location | Captures | Leak Risk |
|----------|----------|-----------|
| `BashTool.php:83` | `$process`, `$progressCb` | Safe — always awaited |
| `BashTool.php:95` | `$process->getStderr()` | Safe |
| `GrepTool.php:65` | `$process->getStdout()` | Safe |
| `GrepTool.php:66` | `$process->getStderr()` | Safe |

### Process::start() — 3 Call Sites

| Location | Timeout | Cleanup | Leak Risk |
|----------|---------|---------|-----------|
| `BashTool.php:70` | Yes (configurable) | `join()` + `kill()` on timeout | Process not killed on non-timeout exception |
| `GrepTool.php:64` | **No timeout** | `join()` | **No timeout, no try/catch** |
| `GrepTool.php:90` | No | `join()` | Stdout/stderr not consumed (minor) |

### DeferredCancellation — 3 Usage Sites

| Location | Cleanup | Leak Risk |
|----------|---------|-----------|
| `SubagentOrchestrator.php:82` | `finally` block + `cancelAll()` | Safe |
| `TuiRenderer.php:515` | Nulled on Idle phase | Safe |
| `TuiAnimationManager.php:304` | Passed through, not owned | Safe |

### LocalSemaphore — 2 Usage Sites

| Location | Release | Leak Risk |
|----------|---------|-----------|
| `SubagentOrchestrator.php:48` (global) | `finally` block | Safe |
| `SubagentOrchestrator.php:383` (groups) | `finally` block | Map never shrinks |

### Suspension::suspend() — 8 Call Sites

All modal methods follow create → suspend → resume → cleanup pattern. All exit paths resume the suspension. **Safe.**

### EventLoop::onSignal() — 0 Call Sites

No custom signal handlers. Safe.

---

## Positive Findings (Clean)

| Area | Assessment |
|------|-----------|
| **No static mutable state in `src/Agent/`** | Grep for `static (private|protected|public) \$` returned zero matches |
| **Event classes** | All 5 event classes are `readonly` value objects. No leak risk |
| **ANSI rendering** | No static mutable state. All buffers reset per operation. Particle arrays are method-local and bounded |
| **Theogony** | 1997 lines but ~1.2KB static data, loaded only on demand. Method-local particle arrays |
| **MarkdownToAnsi** | Properly resets all buffers per `render()`. Highlighter is stateless |
| **DiffRenderer** | All state is method-local. Lazy Highlighter is a single reusable instance |
| **Theme** | Pure static utility, no mutable state |
| **AgentDisplayFormatter/AgentTreeBuilder** | Pure static methods, no instance state |
| **Repositories** | Stateless wrappers around PDO queries, no caching |
| **Kernel container** | No circular dependencies in singleton registrations |
| **Semaphore release** | Always in `finally` blocks with `Lock::__destruct()` as safety net |
| **ToolResultDeduplicator** | Method-local index arrays, GC'd after return |
| **StuckDetector** | Window bounded by `array_slice` to `$windowSize` (default 8) |
| **Modal lifecycle** | All modals properly remove widgets after dismissal |
| **FileWriteTool** | Stateless, no handles |
| **Permission system** | All immutable value objects, `SessionGrants` bounded by tool count |

---

## Recommended Fix Plan

### Priority 1 — High Impact, Easy Fixes

| Fix | Effort | Impact |
|-----|--------|--------|
| Add `'failed'` to `pruneCompleted()` terminal states | Trivial (1 line) | Eliminates C3 — failed agent accumulation |
| Add `__destruct()` to `SubagentOrchestrator` calling `cancelAll()` | Trivial | Eliminates H1 — orphaned background agents |
| Move BashTool `EventLoop::cancel($timerId)` to `finally` block | Small | Eliminates C7 — timer leak on exception |
| Cache `hasRipgrep()` result in GrepTool | Small | Eliminates C5 — 2× extra processes per grep |
| Add timeout watchdog to GrepTool | Small | Eliminates C6 — hung process blocking loop |
| Harden `TuiRenderer::teardown()` to cancel all timers | Small | Eliminates C4 — timer leaks on exit |

### Priority 2 — High Impact, Medium Effort

| Fix | Effort | Impact |
|-----|--------|--------|
| Share single `HttpClient` across subagents, bound pool to 8 | Medium | Eliminates C1 — N connection pools |
| Add `TuiAnimationManager::shutdown()` method | Small | Timer cleanup infrastructure |
| Null out `args` on pruned tool results | Small | Reduces H8 — retained file contents |
| Add periodic WAL checkpoint to Database | Small | Reduces H9 — WAL file growth |

### Priority 3 — Architectural Improvements

| Fix | Effort | Impact |
|-----|--------|--------|
| Implement conversation widget pruning in TUI | Medium | Reduces H5 — widget accumulation |
| Add compaction support in headless mode | Medium | Reduces C2 — unbounded subagent history |
| Use `WeakReference` for orchestrator in `AgentContext` | Medium | Breaks Cycle 1 partially |
| Lazy-load message history on resume | Large | Reduces H11 — full history load spike |
| Add memory count limit with auto-pruning | Medium | Reduces M6/M7 — unbounded memories |
| Delete compacted messages from DB periodically | Small | Reduces M8 — DB bloat |
