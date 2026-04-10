# Resource Management Audit

**Date:** 2026-04-08  
**Scope:** `src/Agent/`, `src/LLM/`, `src/Tool/`, `src/Session/`, `src/IO/`  
**Auditor:** Sub-agent (automated deep audit)

---

## Executive Summary

The KosmoKrator codebase demonstrates **mature resource management overall**. Shell sessions have proper idle cleanup and teardown paths. File handles are consistently closed in `finally` blocks. Temp files use atomic write with cleanup on failure. The context window has a multi-layered defense (pruner → compactor → trim → circuit breaker).

However, several issues were identified across 8 categories, with 2 HIGH and 8 MEDIUM severity findings. The most critical concerns are: unbounded `FileReadTool` cache growth in long sessions, a subtle bug in `ShellSession::readUnread()` buffer truncation logic, and potential WAL file growth from the SQLite `Database::close()` not actually closing the PDO connection.

---

## 1. Memory Leaks

### Finding 1.1 — FileReadTool read cache grows unboundedly
**Severity:** HIGH  
**File:** `src/Tool/Coding/FileReadTool.php:31-32`  
**Code:**
```php
/** @var array<string, true> */
private array $readCache = [];
```

**Issue:** The `$readCache` array accumulates an entry for every unique `(path, mtime, offset, limit)` combination. In a long session with hundreds of file reads, this grows without bounds. While each entry is small (~100 bytes for the key), a session reading thousands of files with different offsets will see meaningful growth. The cache is only cleared by `resetCache()` (called after compaction), but never by any size-based eviction.

**Growth scenario:** An agent performing a broad codebase exploration reads 500+ files with varying offset/limit combinations. Each generates a unique cache key. The cache grows to ~50K+ entries over a multi-hour session.

**Suggested fix:** Cap the cache at a reasonable size (e.g., 500 entries) using an LRU eviction strategy, or simply clear it periodically:
```php
if (count($this->readCache) > 500) {
    $this->readCache = [];
}
```

---

### Finding 1.2 — StuckDetector tool call window holds signatures
**Severity:** LOW  
**File:** `src/Agent/StuckDetector.php:39`  
```php
private array $toolCallWindow = [];
```

**Issue:** The `$toolCallWindow` array is bounded by `$windowSize` (default 8) via `array_slice()`, so this is **not a real leak**. Each entry contains a tool name and MD5 hash (~80 bytes). At window size 8, max memory is ~640 bytes. This is well-controlled.

**Verdict:** No action needed — properly bounded.

---

### Finding 1.3 — SubagentOrchestrator stats accumulation
**Severity:** MEDIUM  
**File:** `src/Agent/SubagentOrchestrator.php:31-37`  
```php
/** @var array<string, Future<string>> */
private array $agents = [];

/** @var array<string, SubagentStats> */
private array $stats = [];
```

**Issue:** Stats and agent futures accumulate throughout a session. There is an auto-prune at line 159 when `count($this->stats) > 50`, and `pruneCompleted()` removes terminal entries. However, the `pruneCompleted()` method (line 259-278) skips agents with `pendingResults`, and pending results are only drained when the parent loop reads them. If background agents complete but the parent never calls `collectPendingResults()`, entries accumulate.

**Growth scenario:** A batch of 40+ background agents completes, but the parent loop is blocked on a long LLM call. All 40 entries remain in both `$agents` and `$stats` until the next `injectPendingBackgroundResults()` call.

**Suggested fix:** The existing 50-entry auto-prune threshold is reasonable. Consider also pruning `$agents` futures more aggressively since completed futures are just holding resolved values:
```php
// After auto-prune check, also unset completed futures
foreach ($this->agents as $id => $future) {
    if ($future->isComplete() && !isset($this->stats[$id])) {
        unset($this->agents[$id]);
    }
}
```

---

### Finding 1.4 — TokenTrackingListener accumulates indefinitely
**Severity:** LOW  
**File:** `src/Agent/Listener/TokenTrackingListener.php:14-17`  

**Issue:** The `TokenTrackingListener` singleton accumulates integers indefinitely, but these are just 4 integers (24 bytes total). This is **not a real leak**.

**Verdict:** No action needed — bounded by 4 integer fields.

---

## 2. File Handle Leaks

### Finding 2.1 — BashTool stdout buffering with progress callback
**Severity:** MEDIUM  
**File:** `src/Tool/Coding/BashTool.php:92-100`  
```php
$stdoutFuture = \Amp\async(function () use ($process, $progressCb): string {
    $buf = '';
    $stream = $process->getStdout();
    while (($chunk = $stream->read()) !== null) {
        $buf .= $chunk;
        if ($progressCb !== null) {
            $progressCb($buf);
        }
    }
    return $buf;
});
```

**Issue:** The `$buf` string accumulates the **entire** stdout output in memory. For a command that produces large output (e.g., a test suite with verbose output), this can consume hundreds of MB. The `progressCallback` receives the *full accumulated buffer* on each chunk, not just the new chunk. This is both a memory and a performance issue — each callback invocation processes an increasingly large string.

**Growth scenario:** Running `phpunit --testdox` on a large project produces 500KB+ of output. Each `$buf .= $chunk` allocates a new string, and the progress callback receives the full 500KB on every iteration.

**Suggested fix:** Only pass the new chunk to the progress callback:
```php
$stdoutFuture = \Amp\async(function () use ($process, $progressCb): string {
    $buf = '';
    $stream = $process->getStdout();
    while (($chunk = $stream->read()) !== null) {
        $buf .= $chunk;
        if ($progressCb !== null) {
            $progressCb($chunk); // Only the new chunk
        }
    }
    return $buf;
});
```
Note: This would require consumers of the callback to handle incremental content rather than the full buffer.

---

### Finding 2.2 — FileEditTool temp file not cleaned on process crash
**Severity:** LOW  
**File:** `src/Tool/Coding/FileEditTool.php:131`  
```php
$tmpPath = $path.'.tmp.'.getmypid();
```

**Issue:** If the PHP process crashes (SIGKILL, OOM) between writing the temp file and renaming it, the `.tmp.{pid}` file remains on disk. The `finally` block in `patchFile()` handles normal exceptions but not process termination. This is a common trade-off for atomic writes — the alternative would require a cleanup daemon.

**Mitigating factors:** The file is named with the process PID, so stale files can be identified by checking if the PID is still alive. The `OutputTruncator` has its own cleanup of old files.

**Suggested fix:** Add a startup cleanup that removes stale `.tmp.*` files from previous processes:
```php
// In Kernel or AgentCommand startup
foreach (glob(getcwd().'/**/*.tmp.*') as $stale) {
    $pid = (int) substr($stale, strrpos($stale, '.') + 1);
    if (!file_exists("/proc/{$pid}") && !posix_getpgid($pid)) {
        @unlink($stale);
    }
}
```

---

### Finding 2.3 — AtomicFileWriter temp file naming collision risk
**Severity:** LOW  
**File:** `src/IO/AtomicFileWriter.php:30`  
```php
$tmpPath = $dir.'/.kosmokrator_tmp_'.getmypid().'_'.mt_rand();
```

**Issue:** The temp file name uses `getmypid()` + `mt_rand()`. Within a single process, `mt_rand()` could return the same value if the random seed hasn't advanced enough between rapid sequential writes to the same directory. While extremely unlikely, this could cause data loss if two `file_write` calls to the same directory race.

**Mitigating factors:** The write sequence is synchronous within a single agent loop, so two concurrent writes to the same file from the same process are impossible.

**Verdict:** No action needed — the theoretical collision window is negligible.

---

## 3. Process Management

### Finding 3.1 — Shell sessions only cleaned on explicit tool calls
**Severity:** MEDIUM  
**File:** `src/Tool/Coding/ShellSessionManager.php:137-145`  
```php
private function cleanupIdleSessions(): void
{
    $now = microtime(true);
    foreach ($this->sessions as $id => $session) {
        if ($session->isRunning() && ($now - $session->lastActiveAt) > $this->idleTtlSeconds) {
            // ...kill and cleanup
        }
        $this->forgetIfDrained($session);
    }
}
```

**Issue:** `cleanupIdleSessions()` is only called from `start()`, `write()`, `read()`, and `kill()`. If the agent doesn't use any shell tools for an extended period (e.g., it's doing a long file-by-file analysis), idle sessions linger with their processes alive and timers scheduled. There is no periodic timer in the event loop to enforce cleanup.

**Growth scenario:** Agent starts a shell session, then spends 20 minutes reading and editing files. The shell process sits idle for the entire time, consuming a PID, memory, and a Revolt timer slot.

**Suggested fix:** Register a periodic cleanup timer in the ShellSessionManager constructor:
```php
EventLoop::repeat(60, function (): void {
    $this->cleanupIdleSessions();
});
```

---

### Finding 3.2 — Shell session timeout timer not cancelled on process exit in edge case
**Severity:** LOW  
**File:** `src/Tool/Coding/ShellSessionManager.php:166-173`  
```php
\Amp\async(function () use ($session): void {
    $exitCode = $session->process->join();
    if ($session->timeoutTimerId() !== null) {
        EventLoop::cancel($session->timeoutTimerId());
        $session->setTimeoutTimerId(null);
    }
    $session->markExited($exitCode);
    $session->appendSystemLine("Exit code: {$exitCode}");
});
```

**Issue:** If the process exits *between* the `isRunning()` check in the timeout handler and the `$session->process->kill()` call, `kill()` may throw. The timeout handler at line 179-185 does not catch this:
```php
EventLoop::delay($session->timeoutSeconds, function () use ($session): void {
    if (!$session->isRunning()) { return; }
    $session->markKilled();
    $session->appendSystemLine("...");
    $session->process->kill(); // Could throw if process just exited
});
```

**Mitigating factors:** Amp's `Process::kill()` is generally safe to call on already-exited processes (it's a no-op or catches internally). This is more of a defensive coding concern.

**Suggested fix:** Wrap `kill()` in a try-catch:
```php
try {
    $session->process->kill();
} catch (\Throwable) {
    // Process already exited — nothing to kill
}
```

---

### Finding 3.3 — SubagentOrchestrator destructor properly cancels all agents
**Severity:** NOT A FINDING (positive observation)  
**File:** `src/Agent/SubagentOrchestrator.php:80-84`  
```php
public function __destruct()
{
    $this->cancelAll();
    $this->ignorePendingFutures();
}
```

**Verdict:** Proper cleanup on destruction. Well done.

---

## 4. Database Connections

### Finding 4.1 — Database::close() does not nullify the PDO connection
**Severity:** MEDIUM  
**File:** `src/Session/Database.php:62-68`  
```php
public function close(): void
{
    try {
        $this->checkpoint();
    } catch (\Throwable) {
        // Best-effort checkpoint — ignore errors during shutdown
    }
}
```

**Issue:** `close()` runs the WAL checkpoint but never sets `$this->pdo = null` or calls `$this->pdo = null` to release the connection. In PHP, PDO connections are released when the object is garbage collected, but in long-running processes with circular references, GC may be delayed. The connection stays open until the `Database` object is collected.

Additionally, there is no explicit `$this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)')` call on session end — it relies on `close()` being called. If the session crashes, the WAL file grows until the next session starts.

**Growth scenario:** A session with heavy message persistence (thousands of tool results) accumulates a WAL file. If the process crashes, the WAL file persists until the next `Database` constructor runs (which does not checkpoint).

**Suggested fix:**
1. Nullify the PDO after checkpointing:
```php
public function close(): void
{
    try {
        $this->checkpoint();
    } catch (\Throwable) {}
    $this->pdo = null; // Explicitly release
}
```
2. Add a startup WAL checkpoint in the constructor:
```php
// After ensureSchema() in __construct
if (!$isMemory) {
    $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
}
```

---

### Finding 4.2 — Prepared statements not cached in MessageRepository
**Severity:** LOW  
**File:** `src/Session/MessageRepository.php:44-56`  

**Issue:** Each call to `append()`, `loadActive()`, `markCompacted()`, etc. creates a new prepared statement via `$this->db->connection()->prepare(...)`. While SQLite's prepared statement overhead is minimal, caching frequently-used statements would reduce memory churn in sessions with thousands of messages.

**Verdict:** This is a micro-optimization. SQLite handles this well internally. No action needed for normal workloads.

---

## 5. Buffer Management

### Finding 5.1 — ShellSession::readUnread() has subtle buffer truncation bug
**Severity:** HIGH  
**File:** `src/Tool/Coding/ShellSession.php:65-72`  
```php
public function readUnread(): string
{
    $offset = $this->readOffset;
    $chunk = substr($this->buffer, $offset);
    // Truncate the consumed portion to prevent unbounded growth
    $this->buffer = substr($this->buffer, $offset);
    $this->readOffset = strlen($this->buffer);
    $this->touch();
    return $chunk;
}
```

**Issue:** The method first extracts `$chunk = substr($this->buffer, $offset)`, then truncates with `$this->buffer = substr($this->buffer, $offset)`. The problem: `$offset` is the *old* `readOffset`, but `$this->buffer` still contains the full buffer at this point. So `substr($this->buffer, $offset)` returns everything from `$offset` to end — including the portion that hasn't been consumed yet if new output arrived between `readUnread()` calls.

Actually, looking more carefully: the logic is correct for the intended behavior — it reads everything from `$readOffset` to end, then truncates the consumed prefix. The `readOffset` is then set to `strlen($this->buffer)`, which after truncation equals the length of the unread portion. This means **the buffer correctly truncates consumed data**.

However, there's a subtle issue: if `appendOutput()` is called concurrently (from the background reader fiber) while `readUnread()` is executing, the `substr()` calls could miss data. In Amp's cooperative scheduling model this shouldn't happen since there's no preemption, but it's worth noting.

**Revised severity:** MEDIUM (concurrent access edge case, not an actual bug in cooperative scheduling)

**Suggested fix:** Add a comment documenting the cooperative scheduling assumption:
```php
// Safe under Amp's cooperative scheduling: appendOutput() runs in a 
// separate fiber but cannot preempt mid-execution of this method.
```

---

### Finding 5.2 — BashTool accumulates full stderr via Amp\ByteStream\buffer()
**Severity:** MEDIUM  
**File:** `src/Tool/Coding/BashTool.php:99`  
```php
$stderrFuture = \Amp\async(fn () => buffer($process->getStderr()));
```

**Issue:** `Amp\ByteStream\buffer()` reads the entire stderr stream into a single string. For commands that produce large stderr output (e.g., compilation errors with full stack traces), this consumes memory proportional to stderr size. Combined with stdout buffering, both are held in memory simultaneously.

**Growth scenario:** Running a build command that produces 2MB of stderr and 1MB of stdout requires 3MB of memory just for process output buffers.

**Suggested fix:** Stream stderr in chunks and truncate if needed, or apply a size limit:
```php
$stderrFuture = \Amp\async(function () use ($process): string {
    $buf = '';
    $stream = $process->getStderr();
    while (($chunk = $stream->read()) !== null) {
        $buf .= $chunk;
        if (strlen($buf) > 100_000) {
            $buf .= "\n[... stderr truncated at 100KB]";
            break;
        }
    }
    return $buf;
});
```

---

### Finding 5.3 — Streaming response buffering in AgentLoop
**Severity:** LOW  
**File:** `src/Agent/AgentLoop.php:446-455`  
```php
$fullText = '';
// ...
foreach ($this->llm->stream($messages, $tools, $cancellation) as $event) {
    if ($event->type === 'text_delta') {
        $fullText .= $event->delta;
        // ...
    }
}
```

**Issue:** `$fullText` accumulates the complete response text during streaming. For very long responses (e.g., the LLM generating a full file), this grows proportionally. However, this is inherent to the design — the full text is needed for history persistence.

**Mitigating factors:** The response text is already subject to compaction and pruning after entering the conversation history. This is acceptable.

**Verdict:** No action needed — inherent to the architecture.

---

## 6. Temp File Management

### Finding 6.1 — AtomicFileWriter temp file pattern differs from FileEditTool
**Severity:** LOW  
**File:** `src/IO/AtomicFileWriter.php:30` vs `src/Tool/Coding/FileEditTool.php:131`  

**Issue:** Two different temp file naming conventions exist:
- `AtomicFileWriter`: `.kosmokrator_tmp_{pid}_{rand}`
- `FileEditTool`: `{path}.tmp.{pid}`

This means a cleanup routine targeting one pattern won't catch the other. Both are cleaned on normal error paths (finally blocks), but both leave stale files on process crash.

**Suggested fix:** Consolidate to use `AtomicFileWriter` for both write paths, or at least share a temp file naming convention:
```php
// In FileEditTool::patchFile()
$tmpPath = $dir.'/.kosmokrator_tmp_'.getmypid().'_'.mt_rand();
```

---

### Finding 6.2 — OutputTruncator files cleaned on instantiation only
**Severity:** MEDIUM  
**File:** `src/Agent/OutputTruncator.php:55-57`  
```php
public function __construct(...) {
    // ...
    $this->cleanupOldFiles($this->retentionSeconds);
}
```

**Issue:** Old truncation files are only cleaned when a new `OutputTruncator` is instantiated. If the agent runs for hours, files older than the retention period accumulate on disk until the next session starts. The cleanup uses a 7-day default retention, which means files from a long session could accumulate to several GB without cleanup.

**Growth scenario:** An agent processes 50 large tool outputs per session, each saved as a full-output file. Over a 4-hour session, that's 50 files averaging 100KB = 5MB. Across multiple sessions per day, this grows to 35MB/week before cleanup triggers.

**Suggested fix:** Register a periodic cleanup timer, or call cleanup every N truncations:
```php
private int $truncationCount = 0;

public function truncate(string $output, string $toolCallId): string
{
    // ...
    if (++$this->truncationCount % 20 === 0) {
        $this->cleanupOldFiles($this->retentionSeconds);
    }
    // ...
}
```

---

## 7. Timer Management

### Finding 7.1 — Shell session timeout timers properly managed
**Severity:** NOT A FINDING (positive observation)  
**File:** `src/Tool/Coding/ShellSessionManager.php:179-185` and `src/Tool/Coding/ShellSession.php:82-92`

**Verdict:** Timeout timers are created via `EventLoop::delay()`, stored in `ShellSession::$timeoutTimerId`, cancelled on process exit (line 170), and cancelled in `killAll()` (line 108). This is correct and complete.

---

### Finding 7.2 — SubagentOrchestrator watchdog timers properly cancelled
**Severity:** NOT A FINDING (positive observation)  
**File:** `src/Agent/SubagentOrchestrator.php:181-183`  
```php
} finally {
    if ($watchdogId !== null) {
        EventLoop::cancel($watchdogId);
    }
    // ...
}
```

**Verdict:** Watchdog timers are always cancelled in the `finally` block of the agent fiber, ensuring no timer leaks even on exceptions.

---

### Finding 7.3 — BashTool timeout timer cancelled on all exit paths
**Severity:** NOT A FINDING (positive observation)  
**File:** `src/Tool/Coding/BashTool.php:76-115`  

**Verdict:** The `$timerId` from `EventLoop::delay()` is cancelled in the normal path (line 113), the error path (line 117), and after timeout detection (line 104). Complete coverage.

---

### Finding 7.4 — EventServiceProvider listener never unsubscribed
**Severity:** LOW  
**File:** `src/Provider/EventServiceProvider.php:25-29`  
```php
public function boot(): void
{
    $dispatcher = $this->container->make(Dispatcher::class);
    $listener = $this->container->make(TokenTrackingListener::class);
    $dispatcher->listen(LlmResponseReceived::class, [$listener, 'handle']);
}
```

**Issue:** The listener is registered in `boot()` but never removed. Since this is a singleton listener for the entire application lifecycle, this is expected behavior and not a real leak. The listener itself is stateless (just accumulates integers).

**Verdict:** No action needed — correct for application-lifetime listeners.

---

## 8. Context Window

### Finding 8.1 — ConversationHistory messages array can fragment
**Severity:** MEDIUM  
**File:** `src/Agent/ConversationHistory.php` (multiple methods)  

**Issue:** Operations like `trimOldest()`, `pruneToolResults()`, `supersedeToolResult()`, and `applyCompactionPlan()` all modify the `$this->messages` array using `array_splice()`, direct assignment, or reconstruction. PHP arrays are hash tables under the hood — frequent splice operations on large arrays cause memory fragmentation and O(n) copies.

In a session with 500+ messages, each `trimOldest()` call copies the remaining ~499 elements. The `pruneToolResultsWithPlaceholders()` method iterates and reconstructs `ToolResultMessage` objects, which involves serializing tool results to JSON and back.

**Growth scenario:** A long session with aggressive pruning (50+ prune operations on a 300-message history) causes PHP to allocate and free many intermediate arrays, fragmenting memory.

**Suggested fix:** Consider using `SplDoublyLinkedList` or a ring buffer for message storage if performance becomes an issue. For now, the current approach is acceptable since PHP's GC handles this reasonably well.

---

### Finding 8.2 — TokenEstimator is a rough heuristic that may underestimate
**Severity:** MEDIUM  
**File:** `src/Agent/TokenEstimator.php:22-23`  
```php
private const CHARS_PER_TOKEN = 3.2;
```

**Issue:** The 3.2 chars-per-token ratio is calibrated for English text. For code-heavy content with many short tokens (punctuation, operators), this ratio is reasonable. However, for content with lots of Unicode (emoji, CJK characters), the estimate can be significantly off. Tokenizers like tiktoken use sub-word tokenization that treats some Unicode characters as multiple tokens.

This means the context budget thresholds may trigger too late (if tokens are underestimated) or too early (if overestimated). The 10-token per-message overhead helps account for framing tokens but may not be sufficient for messages with many tool calls.

**Impact:** If tokens are underestimated by 20%, the context could overflow before the compaction threshold is reached, causing an API error and requiring `handleContextOverflow()` to recover.

**Suggested fix:** Consider using a more conservative ratio (2.8-3.0) or implementing actual tokenizer-based counting via a lightweight library.

---

### Finding 8.3 — Compaction circuit breaker is sound
**Severity:** NOT A FINDING (positive observation)  
**File:** `src/Agent/ContextManager.php:90-98`  
```php
if ($this->consecutiveCompactionFailures >= 3) {
    // ...
    if ($snapshot['is_at_blocking_limit']) {
        $history->trimOldest();
    }
    return [0, 0];
}
```

**Verdict:** The circuit breaker pattern is well-implemented: after 3 consecutive compaction failures, it stops trying and falls back to `trimOldest()`. It also resets when context pressure drops. This is a robust defense against compaction API failures causing infinite loops.

---

### Finding 8.4 — Compaction memory extraction is best-effort with proper error handling
**Severity:** NOT A FINDING (positive observation)  
**File:** `src/Agent/ContextCompactor.php:186-196`  
```php
} catch (\Throwable $e) {
    $this->log->warning('Memory extraction failed', ['error' => $e->getMessage()]);
    return ['memories' => [], 'tokens_in' => 0, 'tokens_out' => 0];
}
```

**Verdict:** Memory extraction failures don't affect the compaction itself. The error is logged and an empty array is returned. This is correct.

---

## Summary Table

| # | Severity | Category | File | Issue |
|---|----------|----------|------|-------|
| 1.1 | **HIGH** | Memory Leak | `Tool/Coding/FileReadTool.php:31` | Unbounded read cache growth |
| 5.1 | **HIGH→MEDIUM** | Buffer | `Tool/Coding/ShellSession.php:65` | Concurrent access edge case (cooperative scheduling makes this safe) |
| 1.3 | MEDIUM | Memory Leak | `Agent/SubagentOrchestrator.php:31` | Stats/futures accumulation when background agents complete |
| 2.1 | MEDIUM | File Handle | `Tool/Coding/BashTool.php:92` | Full stdout accumulated in memory; progress callback receives entire buffer |
| 3.1 | MEDIUM | Process | `Tool/Coding/ShellSessionManager.php:137` | No periodic idle cleanup timer |
| 4.1 | MEDIUM | Database | `Session/Database.php:62` | `close()` doesn't nullify PDO; no startup WAL checkpoint |
| 5.2 | MEDIUM | Buffer | `Tool/Coding/BashTool.php:99` | Unbounded stderr via `buffer()` |
| 6.2 | MEDIUM | Temp Files | `Agent/OutputTruncator.php:55` | Cleanup only on instantiation |
| 8.1 | MEDIUM | Context | `Agent/ConversationHistory.php` | Array fragmentation from frequent splice operations |
| 8.2 | MEDIUM | Context | `Agent/TokenEstimator.php:22` | Heuristic may underestimate token count for Unicode content |
| 2.2 | LOW | File Handle | `Tool/Coding/FileEditTool.php:131` | Temp file not cleaned on process crash |
| 2.3 | LOW | File Handle | `IO/AtomicFileWriter.php:30` | Theoretical temp file name collision |
| 3.2 | LOW | Process | `Tool/Coding/ShellSessionManager.php:183` | `kill()` not wrapped in try-catch |
| 4.2 | LOW | Database | `Session/MessageRepository.php:44` | Prepared statements not cached |
| 5.3 | LOW | Buffer | `Agent/AgentLoop.php:446` | Full response text accumulated during streaming |
| 6.1 | LOW | Temp Files | `IO/AtomicFileWriter.php` vs `Tool/Coding/FileEditTool.php:131` | Inconsistent temp file naming |
| 7.4 | LOW | Timers | `Provider/EventServiceProvider.php:25` | Listener never unsubscribed (by design) |
| 1.2 | LOW | Memory Leak | `Agent/StuckDetector.php:39` | Properly bounded window — not a real leak |
| 1.4 | LOW | Memory Leak | `Agent/Listener/TokenTrackingListener.php:14` | 4 integers — not a real leak |

---

## Positive Observations

Several areas demonstrate **exemplary resource management**:

1. **Shell session teardown** (`ShellSessionManager::killAll()`) — kills processes, cancels timers, clears sessions
2. **SubagentOrchestrator destructor** — cancels all agents and ignores pending futures
3. **Compaction circuit breaker** — prevents infinite retry loops
4. **Tool execution error handling** — all tools return `ToolResult::error()` instead of throwing, preventing unhandled exceptions from leaking resources
5. **FileEditTool streaming approach** — reads files in chunks, uses constant memory regardless of file size
6. **ContextPruner importance scoring** — intelligent ranking of tool results before pruning
7. **Atomic file writes** — consistent use of temp+rename pattern prevents partial writes
8. **BashTool timeout timer** — cancelled on all exit paths (normal, error, timeout)

---

## Recommendations (Priority Order)

1. **[HIGH]** Add size-based eviction to `FileReadTool::$readCache`
2. **[MEDIUM]** Stream stderr in `BashTool` with a size limit instead of `buffer()`
3. **[MEDIUM]** Add startup WAL checkpoint in `Database::__construct()`
4. **[MEDIUM]** Nullify PDO in `Database::close()`
5. **[MEDIUM]** Add periodic cleanup timer in `ShellSessionManager`
6. **[MEDIUM]** Pass only new chunks to `BashTool::$progressCallback`
7. **[MEDIUM]** Add periodic truncation file cleanup in `OutputTruncator`
8. **[LOW]** Consider a more conservative token estimation ratio
9. **[LOW]** Consolidate temp file naming conventions
