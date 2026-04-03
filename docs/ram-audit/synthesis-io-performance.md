# I/O Memory Efficiency Report

## Executive Summary

**Overall Rating: GOOD** with **3 moderate-risk** and **2 low-risk** memory concerns identified.

The system demonstrates strong engineering for memory efficiency: streaming I/O, constant-memory algorithms, and bounded result sets. The primary risks are **cache unboundedness** and **orphaned background result accumulation** under failure scenarios.

**Key Findings:**
- FileReadTool maintains an unbounded read cache that grows across process lifetime
- BashTool buffers entire command output in memory before truncation
- Subagent background results can orphan if parent crashes
- GlobTool and GrepTool use eager evaluation with intermediate array creation
- Shell session management is sound with proper idle cleanup
- OutputTruncator uses spill-to-disk strategy effectively (but post-facto)

---

## Findings (Severity)

### Medium Risk

#### F1: FileReadTool Unbounded Cache
- **File:** `src/Tool/Coding/FileReadTool.php:21,70-72,103-104`
- **Issue:** `$readCache` array grows unbounded across process lifetime; no eviction policy
- **Impact:** Hundreds of MB in long-running sessions with many file reads (e.g., codebase exploration)
- **Current state:** Cache stores only boolean flags, minimizing per-entry footprint; FileReadTool is a singleton in ToolRegistry

#### F2: BashTool Full Output Buffering
- **File:** `src/Tool/Coding/BashTool.php:96-108`
- **Issue:** Stdout and stderr fully buffered in memory via `buffer()` before OutputTruncator runs
- **Impact:** Commands producing >100 MB output will spike RAM; no streaming to disk or early truncation
- **Current mitigation:** OutputTruncator caps at 2000 lines / 50 KB but runs **after** tool returns (ToolExecutor line 300-302)

#### S1: Subagent PendingResults Orphaned
- **File:** `src/Agent/SubagentOrchestrator.php:34,420`
- **Issue:** `$pendingResults[parentId]` never cleared if parent agent crashes or exits without calling `collectPendingResults()`
- **Impact:** Results (strings, potentially KB–MB each) accumulate per background subagent over time
- **Current state:** Documented in `docs/memory-leak-audit.md` as known issue; `pruneCompleted()` does not touch `$pendingResults`

#### S3: Failed Agents Not Pruned
- **File:** `src/Agent/SubagentOrchestrator.php:394-399`
- **Issue:** `pruneCompleted()` only removes `'done'` and `'cancelled'` agents; `'failed'` agents remain forever
- **Impact:** `Future` objects hold closure references → entire agent context retained → potential MB-scale leaks

#### G1: GlobTool Intermediate Array Buildup
- **File:** `src/Tool/Coding/GlobTool.php:93-99`
- **Issue:** `array_merge()` inside recursion loops creates O(n²) intermediate arrays for deep directory trees
- **Impact:** Temporary memory spikes during glob operations on nested structures; 10k files in nested tree → ~10 MB temporary
- **Current mitigation:** Result set capped at 200 files after full sort/deduplication (lines 59-62)

#### G2: GrepTool Pre-Truncation Buffering
- **File:** `src/Tool/Coding/GrepTool.php:68`
- **Issue:** `buffer($process->getStdout())` reads entire output into string before applying `--max-count=50` or 100-line cap
- **Impact:** Large result sets (10k+ matches) held fully in memory despite output limits; 10k matches × 200 bytes = 2 MB
- **Current mitigation:** ripgrep's `--max-count=50` limits per-file matches; final `array_slice` caps at 100 lines (line 92)

### Low Risk

#### F3: FileEditTool Temp File Leaks
- **File:** `src/Tool/Coding/FileEditTool.php:179`
- **Issue:** Orphaned `*.tmp.<pid>` files if process crashes mid-write; no shutdown cleanup registered
- **Impact:** Filesystem accumulation, not RAM; requires manual cleanup or TTL-based reaping

#### S2: Subagent Groups Semaphore Map Never Cleared
- **File:** `src/Agent/SubagentOrchestrator.php:28,469`
- **Issue:** `$groups` array accumulates `LocalSemaphore` objects per unique group name; never removed even after group empties
- **Impact:** Minor memory growth per unique group name (~few hundred bytes each); problematic if group names are dynamic (e.g., per-task IDs)

#### G3: GlobTool Eager Sort Before Cap
- **File:** `src/Tool/Coding/GlobTool.php:59-62`
- **Issue:** `sort()` and `array_unique()` applied to full result set before 200-file cap
- **Impact:** Wasted CPU/memory sorting thousands of paths only to discard most; temporary O(n) overhead

#### G4: GlobTool Unlimited Recursion Depth
- **File:** `src/Tool/Coding/GlobTool.php:globStar()`
- **Issue:** No depth limit; symlink loops could cause infinite recursion
- **Impact:** Potential hang or memory exhaustion in pathological directory structures

#### G5: No Pattern Compilation Caching
- **Files:** `src/Tool/Coding/GlobTool.php`, `src/Tool/Coding/GrepTool.php`
- **Issue:** Patterns re-compiled on every invocation; no shared cache
- **Impact:** Minor CPU overhead; no direct memory impact

---

## Memory Hotspots (file:line + estimates)

### High-Impact Hotspots

| File:Line | Component | Memory Profile | Estimate |
|-----------|-----------|----------------|----------|
| `FileReadTool.php:21` | `$readCache` array | Unbounded growth; one boolean entry per distinct `(path,mtim,offset,limit)` | 1k entries ≈ 10 KB; 100k entries ≈ 1 MB; 1M entries ≈ 10 MB |
| `BashTool.php:96-107` | `$buf` accumulation | O(command output size) before truncation; repeated concatenation in progress callback | 100 MB output → 100 MB RAM spike |
| `SubagentOrchestrator.php:34` | `$pendingResults` | Accumulates per-parent if not collected; each result string KB–MB | 100 background agents × 100 KB = 10 MB per orphaned parent |
| `GlobTool.php:93-99` | Recursion intermediates | O(n²) temporary arrays during deep `array_merge()` loops | 10k files in nested tree → ~10 MB temporary |
| `GrepTool.php:68` | `buffer()` output | Full stdout before any limit applied | 10k matches × 200 bytes = 2 MB buffer |

### Moderate-Impact Hotspots

| File:Line | Component | Memory Profile | Estimate |
|-----------|-----------|----------------|----------|
| `FileEditTool.php:136-183` | Temp file streaming | 64 KB chunks via `stream_copy_to_stream()`; constant memory | Negligible |
| `ShellSession.php:18-137` | `$buffer` string | Grows monotonically per session; drained via `readUnread()` but retained until session kill | 1 MB per active long-running session |
| `SubagentOrchestrator.php:28` | `$groups` map | One `LocalSemaphore` object per unique group name (~few hundred bytes) | 100 groups × 500 bytes = 50 KB |

---

## I/O Bottlenecks

### 1. Tool Execution Buffering

**BashTool** (`src/Tool/Coding/BashTool.php:96-108`) and **GrepTool** (`src/Tool/Coding/GrepTool.php:68`) both use `Amp\Process\Process` with `buffer()` to read entire stdout/stderr into memory before any processing. This creates a **synchronization point** where all output must be held in RAM.

- **Current caps:** OutputTruncator (2000 lines / 50 KB) runs post-facto in `ToolExecutor.php:300-302`
- **Bottleneck:** Large outputs (logs, dumps, binary data) cause RAM spikes before truncation
- **Severity:** Medium — affects any tool executing external commands

### 2. Large File Handling

**FileReadTool** (`src/Tool/Coding/FileReadTool.php:75-82,117-149`) implements smart thresholding:
- **< 10 MB:** `file()` loads entire file → O(file size) memory (acceptable for intended use)
- **≥ 10 MB:** `fopen()` + `fgets()` loop → O(64 KB buffer + line) constant memory ✓

**FileWriteTool** (`src/Tool/Coding/FileWriteTool.php:57`) holds entire content string in memory once — acceptable for <10 MB writes.

**FileEditTool** (`src/Tool/Coding/FileEditTool.php:81-183`) uses 64 KB chunks and atomic `rename()` — excellent constant-memory algorithm ✓

### 3. Shell Session Lifecycle

**ShellSession** (`src/Tool/Coding/ShellSession.php:18-137`) buffers all output in `$buffer` string with no eviction. However:

- **Cleanup:** `ShellSessionManager::cleanupIdleSessions()` (line 49) removes sessions where `isDrained()` (exit + no unread output) after 300s TTL
- **Assessment:** ✅ Bounded by idle timeout; no unbounded accumulation
- **Caveat:** Long-running sessions with continuous output can accumulate MBs until drained or killed

### 4. File Search Memory (Glob/Grep)

**GlobTool** (`src/Tool/Coding/GlobTool.php:52-101`):
- Uses native `glob()` (eager array, not iterator)
- Custom `globStar()` recursion with `array_merge()` creates intermediate arrays
- Full `sort()` + `array_unique()` before 200-file cap
- **Bottleneck:** O(n) temporary memory for full match set; O(n²) intermediates in deep recursion

**GrepTool** (`src/Tool/Coding/GrepTool.php:73-78`):
- Same eager `buffer()` pattern as BashTool
- ripgrep `--max-count=50` and final 100-line slice are **process-level** and **post-processing** limits respectively
- **Bottleneck:** Entire output held in memory before limits applied

---

## Recommendations

### Priority 1 (Address in next sprint)

1. **FileReadTool Cache Eviction (F1)**
   - Add LRU eviction with configurable max entries (e.g., 1000)
   - Or add TTL (e.g., 1 hour)
   - Consider per-AgentContext cache instead of singleton
   - **Files:** `src/Tool/Coding/FileReadTool.php:21`

2. **BashTool/GrepTool Streaming Output (F2, G2)**
   - Stream stdout/stderr directly to `OutputTruncator` during read loop, applying line/byte limits incrementally
   - Or add `stream_to_file` parameter for outputs >1 MB
   - Enforce per-command output limit with early process kill
   - **Files:** `src/Tool/Coding/BashTool.php:96-108`, `src/Tool/Coding/GrepTool.php:68`

3. **SubagentOrchestrator PendingResults Cleanup (S1)**
   - Add TTL (e.g., 1 hour) to `$pendingResults` entries with timestamp
   - Or prune `$pendingResults[parentId]` when all agents for that parent reach terminal state
   - **Files:** `src/Agent/SubagentOrchestrator.php:34,420`

4. **Include Failed Agents in Pruning (S3)**
   - Add `'failed'` to `$terminalStates` in `pruneCompleted()`
   - **Files:** `src/Agent/SubagentOrchestrator.php:394`

### Priority 2 (Next quarter)

5. **GlobTool Optimization (G1, G3, G4)**
   - Apply 200-file cap earlier in recursion to avoid building full array
   - Replace `array_merge()` with generator-based yielding to eliminate intermediate arrays
   - Add recursion depth limit (e.g., 20) to prevent symlink loops
   - **Files:** `src/Tool/Coding/GlobTool.php:52-101`

6. **GrepTool Streaming (G2)**
   - Process ripgrep/grep output line-by-line as it arrives, writing directly to OutputTruncator stream
   - Avoid full `buffer()` call; use `onRead()` callback with incremental processing
   - **Files:** `src/Tool/Coding/GrepTool.php:68-78`

7. **FileEditTool Temp File Cleanup (F3)**
   - Register `register_shutdown_function()` to cleanup orphaned `*.tmp.*` files matching pattern
   - Or switch to `tmpfile()` + stream wrapper for automatic cleanup
   - **Files:** `src/Tool/Coding/FileEditTool.php:179`

8. **Subagent Groups Cleanup (S2)**
   - Clear `$groups[groupName]` when semaphore count reaches 0 and no pending agents
   - Use `WeakMap` if PHP 8.4+ for automatic cleanup
   - **Files:** `src/Agent/SubagentOrchestrator.php:28,469`

### Priority 3 (Nice to have)

9. **Pattern Compilation Cache (G5)**
   - Implement shared cache for glob patterns and grep regex (e.g., `SplObjectStorage` or `WeakMap`)
   - Cache key: pattern string + flags
   - **Files:** `src/Tool/Coding/GlobTool.php`, `src/Tool/Coding/GrepTool.php`

10. **Benchmark Suite**
    - Create `docs/ram-audit/benchmarks/tool-memory.php` with scenarios:
      - Concurrent tool execution: 10 / 50 / 100 parallel no-op tools
      - Large file read/write: 10 MB, 50 MB, 100 MB
      - Glob on 10,000 files (simulated tree)
      - Grep on 10,000 files with 5000 matches
    - Use `memory_get_peak_usage(true)` before/after, median of 5 runs
    - **Path:** `docs/ram-audit/benchmarks/tool-memory.php`

---

**Report generated from Phase 1 agent findings:**
- `tool-execution-memory` (comprehensive system audit)
- `large-file-handling` (FileReadTool/FileWriteTool/FileEditTool analysis)
- `shell-session-management` (ShellSession/SessionManager lifecycle)
- `glob-grep-optimization` (GlobTool/GrepTool memory patterns)
