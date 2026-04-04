# KosmoKrator Deep Audit

> **Date:** 2026-04-02
> **Scope:** Full codebase — 162 PHP source files (25,130 lines), 81 test files (12,278 lines)
> **Method:** 8 parallel audit domains via ~30 subagents, each finding verified against code with exact file:line references

## Audit Domains

| Domain | Focus |
|--------|-------|
| Security | Command injection, path traversal, input validation, secret exposure |
| Error Handling | Exception swallowing, missing finally blocks, recovery paths, infinite loops |
| Concurrency | Race conditions, semaphore leaks, fiber safety, cancellation propagation |
| API Boundaries | LLM response parsing, tool parameter validation, response size limits |
| Resource Management | File handle/process/DB leaks, temp file cleanup, unbounded buffering |
| Session Persistence | SQL injection, schema constraints, concurrent writes, file permissions |
| Logic Bugs | State machine violations, edge cases in patch/edit tools, off-by-one errors |
| Test Coverage | Untested classes, assertion depth, mock quality, isolation |

---

## Critical Findings (5)

### C1. BashTool EventLoop timer leak

**Location:** `src/Tool/Coding/BashTool.php:68-113`

The timeout timer created via `EventLoop::delay()` is only cancelled on the success path (line 99). If `$process->join()` or `$stdoutFuture->await()` throws, the catch block returns without calling `EventLoop::cancel($timerId)`. The timer callback holds a reference to the `Process` object, preventing GC.

```php
// Current: timer leaked on exception
} catch (\Throwable $e) {
    return ToolResult::error("Process error: {$e->getMessage()}");
}

// Fix: cancel timer in catch
} catch (\Throwable $e) {
    EventLoop::cancel($timerId);
    return ToolResult::error("Process error: {$e->getMessage()}");
}
```

### C2. Semaphore self-deadlock with nested agents

**Location:** `src/Agent/SubagentOrchestrator.php:165-201`

When parent agents hold semaphore slots and their child agents (spawned inside the semaphore-held zone) also need slots, all slots can be consumed by waiting parents. Children never acquire a slot, parents never finish — deadlock.

Trigger: `concurrency` set low (e.g., 2) with agents at depth > 1. The dependency wait happens *before* semaphore acquisition, but the factory execution runs *inside* the held semaphore zone, and nested `SubagentTool` calls re-enter `spawnAgent()` which tries to acquire the global semaphore again.

### C3. ShellSession unbounded buffer

**Location:** `src/Tool/Coding/ShellSession.php:41,54-55`

The `$buffer` string grows unboundedly as chunks are appended via `.= ` in `appendOutput()`. The `readUnread()` method updates `$readOffset` but **never truncates `$buffer`**. Long-running sessions (e.g., `tail -f`, build logs) accumulate memory indefinitely.

```php
// Fix: discard consumed portion in readUnread()
public function readUnread(): string
{
    $chunk = substr($this->buffer, $this->readOffset);
    $this->buffer = substr($this->buffer, $this->readOffset);
    $this->readOffset = 0;
    $this->touch();
    return $chunk;
}
```

### C4. Task::transitionTo() ignores state machine

**Location:** `src/Task/Task.php:57`

`TaskStatus::canTransitionTo()` defines valid transitions (pending→in_progress, in_progress→completed/cancelled/failed), but `transitionTo()` never calls it. Any-to-any state transitions are silently allowed. `TaskUpdateTool` also omits `failed` from its valid status list.

### C5. file_read is ALWAYS_SAFE in Guardian mode

**Location:** `src/Tool/Permission/GuardianEvaluator.php:23-30`

`file_read` is listed in `ALWAYS_SAFE`, meaning reads of any file are auto-approved without path checks. An LLM can read `/etc/passwd`, `~/.ssh/id_rsa`, or any file on the system with zero restriction and no user prompt.

---

## High Findings (8)

### H1. Raw exception messages leak to LLM

**Locations:** `src/Agent/ToolExecutor.php:307`, `src/Agent/AgentLoop.php:248,425`

`$e->getMessage()` from any caught `Throwable` (including PDO exceptions, filesystem errors) is returned directly as tool result text, which is then sent back to the LLM. This can leak internal filesystem paths, database credentials (if present in DSN), PHP version details, and stack trace information.

### H2. GuardianEvaluator mutative command check bypassed by full paths

**Location:** `src/Tool/Permission/GuardianEvaluator.php:140`

`MUTATIVE_PATTERNS` uses `str_starts_with($lower, $pattern)` to detect mutative commands. Full-path invocations like `/bin/rm -rf /` or `/usr/bin/git commit` bypass all pattern checks. Ask mode relies on this check to block mutative commands.

### H3. Concurrent file edits silently lose data

**Location:** `src/Tool/Coding/FileEditTool.php:135`

No file locking is used. If parallel subagents edit the same file, both read the original, find their matches, create temp files, and `rename()`. The second rename overwrites the first, silently discarding the earlier edit.

### H4. BashTool ignores Cancellation — zombie processes

**Location:** `src/Tool/Coding/BashTool.php:52-113`

`BashTool::execute()` takes no `Cancellation` parameter. If the user presses Ctrl+C while a bash tool is running in a subagent, the process won't be killed until it times out (up to 7200 seconds). Cancellation is caught at the LLM call level, but the spawned process continues as a zombie.

### H5. No PRAGMA busy_timeout on SQLite ✅ Fixed

**Location:** `src/Session/Database.php:30-32`

WAL mode is enabled but no `busy_timeout` is set. If two KosmoKrator processes access the same DB simultaneously (e.g., two terminal sessions), one will get an immediate `SQLITE_BUSY` exception instead of retrying.

**Fix:** Add `$this->pdo->exec('PRAGMA busy_timeout=5000');` after line 32.

### H6. DB directory 0755 instead of 0700 ✅ Fixed

**Location:** `src/Session/Database.php:19`

The database directory `~/.kosmokrator/data` is created with `0755` (world-readable). The log directory in `Kernel.php:124` uses `0700`. The DB file itself inherits the process umask (typically `0644` — world-readable).

### H7. PatchApplier blocked-path bypass via non-existent parents

**Location:** `src/Tool/Coding/Patch/PathResolver.php:33-35`

When a file doesn't exist yet (e.g., `add` operation), `PathResolver::resolve()` falls back to `realpath(dirname($path))`. If the parent directory itself doesn't exist, `realpath()` returns `false` → `resolve()` returns `null` → the resolved path is never checked against blocked paths.

### H8. PermissionEvaluator blocked-path check doesn't work for apply_patch

**Location:** `src/Tool/Permission/PermissionEvaluator.php:23`

The blocked-path check inspects `$args['path']`, but `apply_patch` passes arguments as `patch` (containing embedded paths), not `path`. The `PatchApplier` has its own internal check, but the `PermissionEvaluator` layer is completely bypassed for patch operations — single point of failure.

---

## Medium Findings (12)

### M1. No response body size limit on LLM HTTP

**Location:** `src/LLM/AsyncLlmClient.php:193`

The Amp HTTP client's `buffer()` reads the entire response into memory. No `Content-Length` check or body size cap. A compromised LLM API could return an arbitrarily large response causing OOM. Transfer timeout (600s) provides partial mitigation.

### M2. No secret redaction in ContextManager

**Location:** `src/Agent/ContextManager.php:130-157`

Memories, session recall, tool results, and parent briefs are injected into the system prompt verbatim. If any contain API keys, passwords, or other secrets (e.g., from `env` command output stored in session history), they are sent to the LLM API.

### M3. ShellStartTool no timeout upper bound ✅ Fixed

**Location:** `src/Tool/Coding/ShellStartTool.php:54`

Unlike `BashTool` which clamps timeouts to `max(1, min($timeout, 7200))`, `ShellStartTool` passes the timeout directly. A user/LLM could specify `timeout: 999999` (~11.5 days). The idle TTL (300s) partially mitigates this for idle sessions.

### M4. ToolExecutor missing finally for BashTool::$progressCallback ✅ Fixed

**Location:** `src/Agent/ToolExecutor.php:155-165`

`BashTool::$progressCallback` is set before execution and cleared after, but not in a `finally` block. If `executeSingleTool()` throws past its own catch (e.g., `ToolResult` constructor failure), the static callback leaks.

### M5. StuckDetector only in runHeadless()

**Location:** `src/Agent/AgentLoop.php`

The `StuckDetector` is only wired in `runHeadless()`. Interactive `run()` has no stuck detection — by design, since the user controls execution via Ctrl+C.

### M6. runHeadless() missing finally block

**Location:** `src/Agent/AgentLoop.php:337-487`

Unlike `run()` which has a `finally` block (line 325-328) that resets UI phase to Idle, `runHeadless()` has no guaranteed cleanup path.

### M7. maybeCompleteParent marks Completed even when children failed

**Location:** `src/Task/TaskStore.php:304`

When all children reach terminal status, the parent is auto-completed as `Completed` regardless of whether children are `Failed` or `Cancelled`. A parent with all-failed children should probably be marked `Failed`.

### M8. PatchParser inconsistent empty-line handling

**Location:** `src/Tool/Coding/Patch/PatchParser.php:34` vs `:157`

Empty lines between operations are silently skipped (line 34), but empty lines inside an update body throw an `InvalidArgumentException` (line 157). This inconsistency can confuse LLMs generating patches.

### M9. Lost exception context in all error logging

**Locations:** `AgentLoop.php:222,244,409`, `ToolExecutor.php:305`, `SubagentOrchestrator.php:203`

All catch blocks use only `$e->getMessage()`, discarding exception class name, file, and line. Makes debugging production issues very difficult. Should log `$e::class`, `$e->getFile()`, `$e->getLine()` alongside.

### M10. No transactions around multi-step DB operations

**Location:** `src/Session/SessionManager.php:69-93`

`saveMessage()` performs INSERT + UPDATE + potential SELECT + UPDATE without wrapping in a transaction. If the process crashes between the message insert and the session touch, data will be inconsistent.

### M11. Temp file leak on exception in FileEditTool

**Location:** `src/Tool/Coding/FileEditTool.php:149-170`

If `stream_copy_to_stream()` or `fwrite()` throws inside `patchFile()`, the `finally` block closes file handles but does NOT delete the `.tmp.<pid>` file. The `@unlink($tmpPath)` at line 175 only runs when `rename()` returns false, not on exceptions.

### M12. BashTool static $progressCallback race across subagents

**Location:** `src/Tool/Coding/BashTool.php:17`, `src/Agent/ToolExecutor.php:155-165`

`BashTool::$progressCallback` is a static property shared across all fibers. If a background subagent and its parent both execute bash tools, they overwrite each other's callback.

---

## By Design

- **Interactive run() has no round limit or StuckDetector** — the user controls execution and can Ctrl+C at any time. Headless mode has both guards since there's no human in the loop.

---

## What's Healthy

| Area | Assessment |
|------|------------|
| SQL injection | All queries use prepared statements with parameterized values |
| PHP object injection | Zero `unserialize()` calls in the codebase |
| JSON deserialization | Uses `json_decode($str, true)` with array type checks |
| Semaphore finally blocks | Orchestrator `finally` correctly releases both group and global semaphores |
| StuckDetector escalation | Well-designed 3-stage path: nudge → final notice → force return |
| Background agent cancellation | `cancelAll()` correctly cancels all background agents on shutdown |
| LLM HTTP cancellation | Cancellation token propagated to both request and body buffering |
| File handle management | `FileEditTool` streaming path uses proper try/finally with fclose |
| Process cleanup on exit | `AgentCommand` teardown calls `cancelAll()` then `killAll()` |
| WAL mode | Enabled for concurrent SQLite reads |
| Foreign keys | Enforced via `PRAGMA foreign_keys=ON` |
| Dependency cycle detection | DFS-based cycle detection before agent spawning |
| LIKE injection | Wildcards properly escaped in both `MessageRepository` and `MemoryRepository` |

---

## Test Coverage Summary

| Metric | Value |
|--------|-------|
| Test files | 79 |
| Test methods | ~662 |
| Classes with tests | ~65 of ~100 concrete classes |
| Core logic method coverage | ~85% |
| Skipped/incomplete tests | 0 |

### Critical Untested Code

| Priority | File | LOC | Risk |
|----------|------|-----|------|
| P0 | `src/Agent/ToolExecutor.php` | 456 | Core execution pipeline — permission checks, concurrent execution, error handling |
| P0 | `src/Agent/AgentSessionBuilder.php` | ~240 | Complex DI wiring — broken wiring goes undetected |
| P1 | `src/Agent/MemorySelector.php` | — | Scoring/ranking algorithm — bugs silently degrade agent intelligence |
| P1 | `src/Agent/ContextBudget.php` | — | Threshold math for auto-compact/blocking — trivially testable |
| P1 | `src/Settings/SettingsManager.php` | ~220 | Entire Settings/ namespace has zero tests |
| P2 | `src/Tool/Coding/Patch/PatchApplier.php` | — | Disk-modifying code with no tests |
| P2 | Shell tool classes (Start/Write/Read/Kill) | — | Process I/O tools, only ShellSessionManager tested |
| P2 | `src/LLM/PromptFrameBuilder.php` | — | Builds system prompt frames |

### Services with Zero Tests

1. `CodexOAuthService` — OAuth for Codex auth
2. `CodexAuthFlow` — Full auth flow orchestration
3. `Relay` — External PrismRelay registration
4. `PatchApplier` — Only tested indirectly via `ApplyPatchToolTest`
5. `SessionGrants` — Auto-wired singleton

### DI Wiring

No test verifies that the container correctly resolves all registered services. The only integration test (`Feature/AgentCommandTest.php`) boots the kernel and runs `/quit` — a smoke test, not a DI verification.

---

## Recommended Fix Priority

1. **C1** (timer leak) — One-line fix, zero risk
2. **C3** (unbounded buffer) — Three-line fix, zero risk
3. **C5** (file_read ALWAYS_SAFE) — Design decision needed: restrict to project dir or keep open?
4. **H5** (busy_timeout) — One-line fix, zero risk
5. **H6** (0755→0700) — One-line fix, zero risk
6. **H7** (PathResolver null) — Small fix in PathResolver
7. **C2** (semaphore deadlock) — Design decision: reserve slots for children? Pre-check depth?
8. **C4** (state machine) — Wire `canTransitionTo()` into `transitionTo()`
9. **H1** (exception message leak) — Sanitize paths and env details from error messages
10. **H2** (full-path bypass) — Expand mutative patterns or use `basename()` extraction
