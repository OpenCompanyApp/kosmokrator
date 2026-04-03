# Core Agent Memory Efficiency Synthesis

**Report Date:** 2026-04-03  
**Agents Consulted:** agent-loop-lifecycle, context-memory-audit, stuck-detection-memory, prompt-engineering-overhead  
**Scope:** RAM efficiency of core agent loop, context management, and prompt construction

---

## Executive Summary

KosmoKrator's core agent loop demonstrates **fundamentally sound memory management** with multiple defensive layers against unbounded growth. The primary memory accumulator — `ConversationHistory::$messages` — grows monotonically but is bounded by three reclamation mechanisms (compaction, pruning, deduplication) that trigger automatically based on context window pressure.

**Critical Finding:** While no memory leaks exist, **prompt construction suffers from systematic caching omissions** that cause redundant work and string bloat on every turn. The most severe inefficiencies are:

1. **Instruction file re-reading** every session (3–5 disk reads, no cache)
2. **Tool schema regeneration** on every subagent spawn (~30–50 object allocations repeated)
3. **Git shell calls** repeated per-turn (`gitRoot()`, `gitBranch()`)
4. **Task tree rendering** with no visible truncation limit

These issues are **independent of conversation history size** and therefore apply constant overhead even to short sessions.

**Severity Distribution:**
- 🔴 Critical: 2 issues (instruction caching, tool schema caching)
- 🟠 High: 2 issues (git shell calls, task tree unbounded)
- 🟡 Medium: 4 issues (prompt splitting, memory formatting, environment detection, string concatenation)
- 🟢 Low: 2 issues (suboptimal thresholds, cleanup timing)

---

## Findings (Severity-Rated)

### 🔴 Critical

#### CRIT-1: Instruction Files Re-Read Every Session (No Cache)
**Files:** `src/Agent/InstructionLoader.php:26-85`

**What:** `InstructionLoader::gather()` reads up to 5 files from disk on every session start:
- `~/.kosmokrator/instructions.md`
- `{git_root}/KOSMOKRATOR.md`
- `{git_root}/.kosmokrator/instructions.md`
- `{git_root}/AGENTS.md`
- `{cwd}/KOSMOKRATOR.md`

**Impact:**
- **Memory:** Each file loaded as a string kept for session lifetime. Large `AGENTS.md` (common in monorepos) can be 10–100 KB.
- **I/O:** 3–5 `file_get_contents()` calls per session; `gitRoot()` uses `shell_exec()` (line 102).
- **Frequency:** Once per session, but sessions are frequent in REPL usage.

**Why it's critical:** This is **pure waste** — instruction files change rarely (user edits or git commits). No technical reason exists to re-read them. Static property cache would eliminate all I/O and string allocation.

**Evidence:** `readFile()` (line 87) has no memoization; `gather()` calls it sequentially every time.

---

#### CRIT-2: Tool Schema Regenerated on Every Subagent Spawn
**Files:** `src/Tool/ToolRegistry.php:67-103`, `src/Agent/SubagentFactory.php:105`

**What:** `ToolRegistry::toPrismTools()` converts each tool to a `PrismTool` object with full parameter schema on every call. Called:
- Once at main `AgentLoop` setup (`AgentSessionBuilder:133`)
- **Once per subagent** (`SubagentFactory:105`) — subagents spawn frequently

**Impact:**
- **Memory:** ~30–50 tools × ~10 parameters each = 300–500 parameter objects per call. Each `PrismTool` + parameter objects ≈ 200–500 bytes → **60–250 KB per subagent** wasted.
- **CPU:** Object allocation + method calls (`withStringParameter()`, etc.) repeated unnecessarily.
- **Frequency:** Every subagent creation (default concurrency 10, depth 3 → potentially 30+ subagents per session).

**Why it's critical:** Tool schemas are **static metadata** — they never change at runtime. Rebuilding them is pure allocation bloat. Subagent memory isolation is good, but this duplicates static data across all subagents.

**Evidence:** `toPrismTool()` (lines 76-103) creates fresh `PrismTool` and calls `->parameters()` on tool to rebuild schema arrays each time.

---

### 🟠 High

#### HIGH-1: Repeated Git Shell Calls Every Turn
**Files:** `src/Agent/ProtectedContextBuilder.php:24-50`, `src/Agent/InstructionLoader.php:102`

**What:** `ProtectedContextBuilder::build()` calls:
- `InstructionLoader::gitRoot()` — `shell_exec('git rev-parse --show-toplevel')`
- `InstructionLoader::gitBranch()` — `shell_exec('git branch --show-current')`

Every time protected context is built, which is **every turn** (via `ContextManager::buildSystemPrompt()`).

**Impact:**
- **Memory:** Each `shell_exec()` returns a string (path or branch name, ~20–100 bytes). Strings are short-lived but allocated every turn.
- **I/O:** Two subprocess calls per turn. At 100 turns → 200 shell executions. Significant overhead.
- **Latency:** Each call takes ~1–5 ms; cumulative delay noticeable.

**Why it's high:** Git state changes infrequently. Caching with `static ?string` (per-request) or session-scoped property would eliminate all repeated calls. No invalidation needed except on explicit git events (not applicable in agent runtime).

**Evidence:** `gitRoot()` (line 102) and `gitBranch()` (line 57) have no caching; called unconditionally in `build()`.

---

#### HIGH-2: Task Tree Rendering Unbounded
**Files:** `src/Agent/TaskStore.php` (not fully inspected, but referenced in `ContextManager:270`)

**What:** `ContextManager::buildSystemPrompt()` appends `$this->taskStore->renderTree()` to system prompt every turn. No truncation limit observed in codebase.

**Impact:**
- **Memory:** Task tree grows linearly with number of tasks created. Each task adds ~50–200 chars to rendered string.
- **Prompt bloat:** Unbounded task list consumes context window, forcing earlier compaction.
- **Frequency:** Every turn.

**Why it's high:** Long-running sessions with many decomposed tasks could see task tree reach **tens of KB**. This directly competes with conversation history for context space. Should have hard limit (e.g., last 50 tasks, or 10 KB max).

**Evidence:** `renderTree()` call at `ContextManager:270` with no preceding `substr()` or count check.

---

### 🟡 Medium

#### MED-1: PromptFrameBuilder Re-Splits Every Call (No Cache)
**Files:** `src/LLM/PromptFrameBuilder.php:31-77`

**What:** `splitSystemPrompt($prompt)` uses `strpos()` + `substr()` to separate stable/volatile portions. Called downstream by providers that support prompt caching. No result caching.

**Impact:**
- **Memory:** `substr()` creates new string copies (O(n) duplication). For a 5 KB prompt, two allocations per turn.
- **CPU:** String scanning repeated every turn.
- **Frequency:** Every LLM call (every turn).

**Why it's medium:** Prompt size is modest (< 10 KB typical), so memory duplication is small (~10 KB/turn). But it's unnecessary work. Caching split result per unique prompt would eliminate it.

**Evidence:** Static method, no static cache property. `substr()` at lines 42–43, 66 creates new strings.

---

#### MED-2: MemoryInjector::format() Rebuilds Every Turn
**Files:** `src/Agent/MemoryInjector.php:17-109`

**What:** `format()` groups memories by type, truncates each, and `implode()`s. Called every turn in `ContextManager::buildSystemPrompt()`.

**Impact:**
- **Memory:** Creates intermediate arrays (`$sections`, `$lines`) and concatenated string (~1–5 KB typical).
- **CPU:** Looping through memories, truncating, grouping — repeated work.
- **Frequency:** Every turn.

**Why it's medium:** Memory selection (`SessionManager::selectRelevantMemories`) already queries DB each turn, so some reformatting is expected. But formatted blocks could be cached keyed by memory ID set + truncation parameters. Gains modest but free.

**Evidence:** No caching; `implode("\n\n", $sections)` at line 108 creates new string every call.

---

#### MED-3: EnvironmentContext Gathered Once Per Session (No Cross-Session Cache)
**Files:** `src/Agent/EnvironmentContext.php:15-48`

**What:** `gather()` runs `file_exists()` for 10+ project types, reads `composer.json`/`package.json`, gets OS/shell/date. Called once at session start (`AgentSessionBuilder:84-86`).

**Impact:**
- **Memory:** Result string ~200–500 bytes kept for session lifetime.
- **I/O:** Multiple filesystem checks and JSON parsing at session start.
- **Frequency:** Once per session.

**Why it's medium:** Session start is acceptable place, but environment rarely changes during a session. Could be cached globally (static) to skip filesystem checks across sessions. Benefit small but zero cost.

**Evidence:** No static cache; `file_exists()` calls at lines 18–28 every invocation.

---

#### MED-4: String Concatenation in Loops (ContextCompactor)
**Files:** `src/Agent/ContextCompactor.php:253-294`

**What:** `formatMessages()` builds `$lines` array by looping through messages, then `implode()`s. Capped at 100K chars (`MAX_FORMAT_CHARS`), but still allocates intermediate array.

**Impact:**
- **Memory:** Array of strings + final concatenated string. Peak ~100 KB during compaction.
- **Frequency:** Only during compaction (infrequent).

**Why it's medium:** Compaction already expensive (2 LLM calls). This is a small fraction of total compaction memory spike. Could use `implode()` with generator or `StringBuilder` pattern, but not urgent.

**Evidence:** `$lines[] = ...` loop (lines 253–294) then `implode("\n", $lines)` at line 296.

---

### 🟢 Low

#### LOW-1: Compaction Threshold May Be Too High
**Files:** `src/Agent/ContextCompactor.php:17`, `src/Agent/ContextBudget.php`

**What:** Default `compact_threshold = 60%` of context window. For a 32K context, compaction triggers at ~19K tokens. With typical 1–2 KB messages, that's ~10–20 turns between compactions.

**Impact:**
- **Memory:** History grows larger before compaction, increasing peak memory.
- **Frequency:** Fewer compactions = less LLM cost but more RAM.

**Why it's low:** Configurable via settings. Default is a conservative trade-off. Could be lowered to 50% or made adaptive, but not a bug.

**Evidence:** Default at line 17; used in `shouldCompactHistory()` (`ContextManager:274-279`).

---

#### LOW-2: Subagent Cleanup Only on Parent Turn
**Files:** `src/Agent/SubagentOrchestrator.php:245-258`, `src/Agent/AgentLoop.php:552-557`

**What:** `pruneCompleted()` removes completed subagents from orchestrator arrays. Called only when parent agent processes pending results (once per parent turn).

**Impact:**
- **Memory:** Completed subagent objects ( histories, tool executors, etc.) remain in `$agents`, `$stats`, `$cancellations`, `$globalLocks` until parent's next turn.
- **Window:** Typically one turn delay (~seconds). With 10 concurrent subagents, delay is minor.

**Why it's low:** Cleanup is prompt (next turn). No observed leaks. Could add periodic timer-based cleanup for long-running headless parents, but benefit marginal.

**Evidence:** `pruneCompleted()` called only in `injectPendingBackgroundResults()` (`AgentLoop:552-557`).

---

## Memory Hotspots (file:line + estimates)

### Primary Accumulator

| Hotspot | File:Line | Accumulation | Estimated Size/Turn | Notes |
|---------|-----------|--------------|---------------------|-------|
| `ConversationHistory::$messages` | `src/Agent/ConversationHistory.php:19` | **Monotonic** | 100–500 bytes per message | Primary growth vector. Each turn adds 2–3 messages (user + assistant + tool results). |
| `SubagentOrchestrator::$agents` | `src/Agent/SubagentOrchestrator.php:245` | **Concurrent** | ~1 KB per active subagent | Holds `Future` + `SubagentStats` until parent prunes. |
| `SubagentOrchestrator::$stats` | same | **Concurrent** | ~500 bytes per subagent | Same lifetime as `$agents`. |
| `SubagentOrchestrator::$cancellations` | same | **Concurrent** | ~100 bytes per subagent | Cleared in `finally` block. |
| `SubagentOrchestrator::$globalLocks` | same | **Concurrent** | ~100 bytes per subagent | Released & unset when subagent finishes. |

### Prompt Construction Bloat (Per-Turn)

| Hotspot | File:Line | Allocation | Estimated Size | Frequency | Cache? |
|---------|-----------|------------|----------------|-----------|--------|
| `InstructionLoader::gather()` | `src/Agent/InstructionLoader.php:26-85` | 3–5 file reads + string concat | 2–50 KB (depends on AGENTS.md) | Once/session | ❌ |
| `ToolRegistry::toPrismTools()` | `src/Tool/ToolRegistry.php:67-103` | 300–500 objects (PrismTool + params) | 60–250 KB | Per subagent spawn | ❌ |
| `ProtectedContextBuilder::build()` (git calls) | `src/Agent/ProtectedContextBuilder.php:24-50` | 2 `shell_exec()` strings | ~200 bytes | Every turn | ❌ |
| `TaskStore::renderTree()` | `src/Agent/TaskStore.php` (ref: `ContextManager:270`) | Recursive string build | ~1–10 KB (unbounded) | Every turn | ❌ |
| `PromptFrameBuilder::splitSystemPrompt()` | `src/LLM/PromptFrameBuilder.php:31-77` | 2 `substr()` copies | ~5–10 KB | Every LLM call | ❌ |
| `MemoryInjector::format()` | `src/Agent/MemoryInjector.php:17-109` | Array + `implode` | ~1–5 KB | Every turn | ❌ |
| `EnvironmentContext::gather()` | `src/Agent/EnvironmentContext.php:15-48` | FS checks + JSON parse | ~200–500 bytes | Once/session | ❌ |

### Temporary Spikes (Transient)

| Hotspot | File:Line | Spike Size | Duration | Reclaimed |
|---------|-----------|------------|----------|-----------|
| Compaction formatted transcript | `src/Agent/ContextCompactor.php:233-275` | Up to 100 KB string | During 2 LLM calls (seconds) | Yes (after apply) |
| CompactionPlan object | `src/Agent/ContextCompactor.php:104-160` | ~10–50 KB (new Message objects) | Brief | Yes |
| Deduplication indexes | `src/Agent/ToolResultDeduplicator.php:28-108` | O(n) where n = tool result messages | Per tool round | Yes |
| Pruning candidates array | `src/Agent/ContextPruner.php:37-104` | O(n) | Per prune | Yes |

---

## Convergence Issues

### Issue 1: Compaction Threshold vs. Prompt Bloat
**Interaction:** The `context.compact_threshold` (default 60%) determines when history compaction triggers. However, **prompt construction bloat** (unbounded task tree, no instruction caching) inflates the **base system prompt size**, reducing effective context window for conversation history. This causes **earlier compaction triggers** than necessary, increasing LLM call frequency.

**Root cause:** Base prompt is rebuilt every turn with redundant data. A 50 KB base prompt (large AGENTS.md + unbounded tasks) leaves less room for history, causing compaction at ~15K tokens instead of ~19K.

**Impact:** More frequent compactions → more LLM calls → higher cost + temporary memory spikes.

---

### Issue 2: Subagent Memory Multiplication via Tool Schema Duplication
**Interaction:** Each subagent gets its own `AgentLoop` with fresh `ToolRegistry::toPrismTools()` call. With 10 concurrent subagents and depth 3, **tool schema objects are duplicated 30+ times** in memory simultaneously.

**Root cause:** Tool schemas are static metadata but treated as per-instance data. No shared cache in `ToolRegistry`.

**Impact:** 60–250 KB × 30 = **1.8–7.5 MB** of duplicated schema objects in memory during peak concurrency. Not catastrophic but wasteful.

---

### Issue 3: Git Shell Calls Accumulate Latency, Not Memory
**Interaction:** While git calls don't cause memory leaks, their **per-turn execution** adds cumulative latency. In long sessions (100+ turns), 200 shell calls can add **200–1000 ms** of overhead. This is a **performance convergence issue** — the design assumes git state is needed every turn, but it's quasi-static.

**Root cause:** No caching of git root/branch. `ProtectedContextBuilder` rebuilds every turn.

**Impact:** Degraded user experience; perceived slowness.

---

### Issue 4: Task Tree Growth Accelerates Context Pressure
**Interaction:** `TaskStore::renderTree()` output grows with each decomposed task. Unbounded growth means:
- System prompt size increases over session lifetime
- Context window fills faster → more frequent compaction
- Compaction replaces older history, but task tree itself is **never pruned**

**Root cause:** No truncation logic for task tree rendering. All tasks forever included.

**Impact:** Long sessions with many subtasks see **progressive prompt bloat** that never recedes, even after history compaction. Eventually dominates context window.

---

## Recommendations

### Priority 1 (Immediate — High Impact, Low Effort)

#### REC-1: Cache InstructionLoader::gather() Result
**Target:** `src/Agent/InstructionLoader.php:26-85`

**Change:** Add `static ?string $cached = null` to `gather()`. On first call, read files and store. Subsequent calls return cached string.

**Impact:**
- Eliminates 3–5 disk reads per session
- Saves 2–50 KB string allocations per session
- Zero risk — instruction files rarely change during runtime

**Effort:** 5 minutes. Add 2 lines.

---

#### REC-2: Cache ToolRegistry::toPrismTools() Result
**Target:** `src/Tool/ToolRegistry.php:67-103`

**Change:** Add private `?array $cachedPrismTools = null`. In `toPrismTools()`, check cache; if null, build and store. Invalidate only when `register()`/`unregister()` called (rare).

**Impact:**
- Saves 60–250 KB per subagent spawn
- With 30 subagents/session → **1.8–7.5 MB saved**
- Reduces object allocation churn

**Effort:** 10 minutes. Add cache property + check.

---

#### REC-3: Cache Git Shell Calls
**Target:** `src/Agent/InstructionLoader.php:102` (gitRoot), `src/Agent/ProtectedContextBuilder.php:57` (gitBranch)

**Change:** Add `static ?string $cachedRoot` and `static ?string $cachedBranch` to respective methods. Cache result for lifetime of request.

**Impact:**
- Eliminates 2 shell execs per turn
- At 100 turns → 200 fewer subprocesses
- Saves ~200 bytes × 100 = 20 KB (small) but latency gain significant

**Effort:** 5 minutes per method.

---

#### REC-4: Truncate Task Tree Rendering
**Target:** `src/Agent/TaskStore::renderTree()` (need to locate file)

**Change:** Add configurable limit: e.g., `max_tasks: 50` or `max_chars: 10240`. Truncate oldest tasks first. Return `"... truncated N tasks"` note.

**Impact:**
- Bounds system prompt growth from task tree
- Prevents unbounded context consumption
- Forces user to `/compact` or complete tasks to make room

**Effort:** 15–30 minutes (need to inspect `TaskStore` implementation).

---

### Priority 2 (Medium-Term — Moderate Impact)

#### REC-5: Implement PromptFrameBuilder Split Cache
**Target:** `src/LLM/PromptFrameBuilder.php:31-77`

**Change:** Add static `array $cache = []` keyed by `md5($prompt)`. Store `['prefix' => ..., 'volatile' => ...]`. Reuse if prompt unchanged.

**Impact:**
- Saves 2 `substr()` allocations per LLM call
- Modest memory savings (~5–10 KB/turn)
- Reduces CPU for string ops

**Effort:** 10 minutes.

---

#### REC-6: Cache EnvironmentContext::gather()
**Target:** `src/Agent/EnvironmentContext.php:15-48`

**Change:** Convert `gather()` to instance method with private `?string $cached = null`. Build once per `EnvironmentContext` object (created once per session already). Already per-session, but still avoids repeated FS checks within same gather call if called multiple times.

**Impact:** Negligible (already once/session), but cleans up pattern.

**Effort:** 5 minutes.

---

#### REC-7: Batch Memory Extraction During Compaction
**Target:** `src/Agent/ContextCompactor.php:189-224`

**Change:** Track last extraction turn/timestamp. Skip extraction if recent (e.g., within 5 turns or < 100 new messages). Or batch: only extract if `count($history->newMessagesSinceLastExtraction) > 20`.

**Impact:**
- Reduces compaction LLM calls from 2 → 1 in many cases
- Saves cost + temporary memory spike from extraction response
- Minor risk of missing some memories, but memories are cumulative and idempotent

**Effort:** 20–30 minutes (need to track state in `ContextCompactor`).

---

#### REC-8: Periodic Subagent Cleanup for Headless Agents
**Target:** `src/Agent/SubagentOrchestrator.php:245-258`

**Change:** Add timer-based cleanup (e.g., every 30 seconds) in addition to on-demand in `injectPendingBackgroundResults()`. Use `EventLoop` repeat callback.

**Impact:**
- Frees subagent memory sooner in long-running headless sessions where parent may not call `injectPending...` frequently
- Minor improvement; current cleanup is already timely for interactive use

**Effort:** 15 minutes.

---

### Priority 3 (Long-Term — Architectural)

#### REC-9: Implement Shared Tool Schema Registry
**Target:** `src/Tool/ToolRegistry.php` + tool classes

**Change:** Each tool class defines `static ?PrismTool $schemaCache`. First call to `toPrismTool()` builds and stores. `ToolRegistry::toPrismTools()` returns these shared instances (or clones if mutability concerns).

**Impact:**
- Eliminates all tool schema duplication across subagents
- Could save **5–10 MB** in sessions with many subagents
- Clean separation of static metadata

**Effort:** 1–2 hours (need to ensure PrismTool objects are immutable or cloned).

---

#### REC-10: Incremental Prompt Assembly Cache
**Target:** `src/Agent/ContextManager.php:257-289` (buildSystemPrompt)

**Change:** Introduce `PromptCache` object that stores:
- Stable base prompt (instructions + environment)
- Tool schemas (shared reference)
- Mode suffix (constant)
- Only rebuild volatile parts (memories, task tree) each turn

**Impact:**
- Reduces per-turn string allocations from ~10–50 KB to ~2–5 KB
- Eliminates repeated `implode()` of static parts
- Significant for long sessions

**Effort:** 2–3 hours (design + implementation).

---

#### REC-11: Task Tree Segmentation & Archival
**Target:** `src/Agent/TaskStore.php`

**Change:** Split tasks into "active" (last N) and "archived" (summarized). Render only active. Archive old tasks via compaction-like process.

**Impact:**
- Prevents unbounded task tree growth
- Keeps system prompt size bounded
- Aligns with history compaction philosophy

**Effort:** 2–3 hours.

---

#### REC-12: Benchmark Suite Activation
**Target:** `docs/ram-audit/benchmarks/agent-loop-memory.php` (provided in agent-loop-lifecycle)

**Action:** Create and run benchmark to establish baseline memory growth curves. Test with:
- 100 turns, 3 tools/turn, compaction on/off
- 500 turns, 5 tools/turn
- 1000 turns, 0 tools (pure chat)

**Impact:** Quantifies actual memory behavior; validates fixes.

**Effort:** 10 minutes to create file + run benchmarks.

---

## Summary Table

| Category | Issue | Severity | Est. Savings (per session) | Effort | Priority |
|----------|-------|----------|----------------------------|--------|----------|
| Prompt bloat | Instruction file caching | 🔴 Critical | 2–50 KB + I/O | 5 min | P1 |
| Prompt bloat | Tool schema caching | 🔴 Critical | 1.8–7.5 MB | 10 min | P1 |
| Prompt bloat | Git shell call caching | 🟠 High | 200 ms latency | 5 min | P1 |
| Prompt bloat | Task tree truncation | 🟠 High | 1–10 KB/turn bounded | 30 min | P1 |
| Prompt bloat | Prompt split cache | 🟡 Medium | 5–10 KB/turn | 10 min | P2 |
| Prompt bloat | Memory formatter cache | 🟡 Medium | 1–3 KB/turn | 10 min | P2 |
| Compaction | Batch memory extraction | 🟡 Medium | 1 LLM call / 5 turns | 30 min | P2 |
| Subagents | Periodic cleanup | 🟢 Low | ~1 KB/subagent sooner | 15 min | P2 |
| Architecture | Shared tool schemas | 🟢 Long-term | 5–10 MB total | 2 hrs | P3 |
| Architecture | Incremental prompt cache | 🟢 Long-term | 5–20 KB/turn | 3 hrs | P3 |
| Architecture | Task segmentation | 🟢 Long-term | Bounded prompt | 3 hrs | P3 |

**Total immediate win (P1):** ~2–8 MB saved + significant latency reduction + bounded prompt growth. **Effort: ~1 hour.**

---

## Conclusion

KosmoKrator's memory management is **structurally sound** — history growth is bounded by automatic compaction/pruning, subagents are isolated, and no leaks exist. However, **prompt construction inefficiencies** represent a **systematic, repeatable waste** of memory and CPU that affects every session regardless of size.

The four critical/high issues (instruction caching, tool schema caching, git calls, task tree truncation) are **low-hanging fruit** offering immediate 2–8 MB savings per session with < 1 hour total implementation time. These should be addressed in the next sprint.

Longer-term architectural improvements (shared schemas, incremental prompt cache) offer further gains but require more careful design.

**Next steps:**
1. Implement Priority 1 recommendations (REC-1 through REC-4)
2. Create and run benchmark suite to quantify baseline and improvement
3. Monitor production memory logs; consider lowering `compact_threshold` to 50% after prompt bloat fixes
4. Explore Priority 2 if memory pressure persists in long-running sessions

---

*Report generated from synthesis of agent-loop-lifecycle, context-memory-audit, stuck-detection-memory, and prompt-engineering-overhead Phase 1 agents.*
