# Deep Audit: Error Handling

**Date:** 2026-04-08  
**Scope:** `src/Agent/`, `src/LLM/`, `src/Tool/`, `src/UI/`  
**Auditor:** Automated (KosmoKrator sub-agent)

---

## Executive Summary

The error handling architecture is **well-designed overall**, with several sophisticated patterns:

- **SafeDisplay** wraps all UI calls to prevent rendering errors from crashing the agent loop
- **ErrorSanitizer** strips internal details before sending error messages to the LLM
- **Context overflow** is detected heuristically and handled with compaction → trimming fallback
- **RetryableLlmClient** implements exponential backoff with jitter and Retry-After header support
- **SubagentOrchestrator** has proper `finally` blocks for semaphore/timer cleanup
- **Circuit breaker** in ContextManager disables auto-compaction after 3 consecutive failures

However, there are **16 findings** ranging from CRITICAL to LOW, primarily around:

1. Timer leaks in BashTool on success path
2. Over-broad `catch (\RuntimeException)` shadowing context overflow detection
3. Stack trace loss in headless error propagation
4. FileWriteTool silently discarding exception details
5. No global unhandled rejection handler for Amp futures

---

## Findings

### Finding 1: BashTool Timer Leak on Timeout Path

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Tool/Coding/BashTool.php:83-134` |
| **Category** | Resource Cleanup |

**Issue:** When the process times out (line 115), the timer is cancelled inside the `if ($timedOut)` block at line 116. However, on the normal success path, the timer is only cancelled at line 134 — outside any `finally` block. If an exception occurs *between* lines 112-113 (`await` calls) and line 134, the timer fires into a dead process, which is harmless but wasteful. More critically, the timeout path cancels the timer *before* reading remaining output, meaning the `$timedOut` flag could theoretically race.

The GrepTool (`src/Tool/Coding/GrepTool.php:88-98`) handles this correctly with a `try/finally` pattern.

**Impact:** Minor timer leak; inconsistent cleanup pattern vs GrepTool.

**Suggested Fix:** Wrap lines 85-134 in a `try/finally` block and move `EventLoop::cancel($timerId)` into the `finally`, matching the GrepTool pattern:

```php
$timerId = EventLoop::delay($timeout, function () use ($process, &$timedOut): void {
    $timedOut = true;
    if ($process->isRunning()) {
        $process->kill();
    }
});

try {
    // ... process execution ...
} finally {
    EventLoop::cancel($timerId);
}
```

---

### Finding 2: Over-Broad `catch (\RuntimeException)` in AgentLoop::run()

| Attribute | Value |
|-----------|-------|
| **Severity** | HIGH |
| **File** | `src/Agent/AgentLoop.php:250-264` |
| **Category** | Exception Handling Patterns |

**Issue:** The `run()` method catches `CancelledException` first (line 245), then `RuntimeException` (line 250) to check for context overflow. But `RuntimeException` is very broad — it catches intentional domain exceptions like `RetryableHttpException` (which extends `\RuntimeException`), `KosmokratorException`, `SessionException`, etc. If a non-overflow `RuntimeException` is caught, it's logged and shown to the user, but the context overflow check (`handleContextOverflow`) runs first. The check is string-based heuristic matching on `$e->getMessage()`, which could accidentally match unrelated error messages containing substrings like "too long" or "token".

**Impact:** Non-overflow `RuntimeException` errors (e.g., API key errors, provider config errors) could be misidentified as context overflow, triggering unnecessary compaction/trimming. The `trimAttempts` counter would increment, potentially leading to data loss if 3 failed overflow "recoveries" occur.

**Suggested Fix:** Introduce a dedicated `ContextOverflowException` (already exists in `src/Exception/ContextOverflowException.php` but isn't used in the catch path). Have `AsyncLlmClient::guardResponseStatus()` and `RetryableLlmClient` throw it for context-length errors instead of relying on message-string heuristics:

```php
} catch (ContextOverflowException $e) {
    // Context overflow — compact or trim
    if ($this->handleContextOverflow($e, $trimAttempts)) {
        $round--;
        continue;
    }
    // ... error handling ...
} catch (CancelledException $e) {
    // ...
} catch (\RuntimeException $e) {
    // Non-overflow runtime errors — no heuristic check
    // ...
}
```

---

### Finding 3: Stack Trace Lost in Headless Error Propagation

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Agent/AgentLoop.php:447-456` |
| **Category** | Exception Context |

**Issue:** In `runHeadless()`, all errors are caught at line 447 and converted to a string via `'Error: '.$e->getMessage()`. The exception class, stack trace, and previous chain are discarded. When this string flows back to `SubagentOrchestrator::isRetryableResult()`, it classifies retryability based on string matching against the message — fragile and incomplete.

Similarly, in `runHeadless()`'s LLM response catch at line 447, `$e->getMessage()` is returned directly, losing the exception type needed for classification.

**Impact:** Debugging headless failures is harder because stack traces are discarded. The retry classifier may misclassify errors because it only sees message strings.

**Suggested Fix:** At minimum, log the full exception before converting to string:

```php
} catch (\Throwable $e) {
    $this->log->error('Headless agent error', [
        'exception' => get_class($e),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'round' => $round,
    ]);

    return 'Error: ' . ErrorSanitizer::sanitize($e->getMessage());
}
```

---

### Finding 4: FileWriteTool Silently Discards Exception Details

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Tool/Coding/FileWriteTool.php:66-70` |
| **Category** | Exception Context |

**Issue:** The `AtomicFileWriter::write()` call throws a `\RuntimeException` on failure with a descriptive message (e.g., "Failed to write temporary file for: /path" or "Failed to rename temporary file to: /path"). The catch block at line 68 catches `\RuntimeException` without binding the exception to a variable, discarding the specific failure reason:

```php
} catch (\RuntimeException) {
    return ToolResult::error("Failed to write file: {$path}");
}
```

The LLM only sees "Failed to write file: /path" — not whether the issue was a permissions error, disk full, or rename failure.

**Impact:** The LLM cannot reason about the root cause of write failures, leading to repetitive retry attempts.

**Suggested Fix:** Include the original exception message:

```php
} catch (\RuntimeException $e) {
    return ToolResult::error("Failed to write file: {$path} — {$e->getMessage()}");
}
```

---

### Finding 5: ContextManager Pre-Flight Swallows All Throwables Silently

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Agent/ContextManager.php:117-128` |
| **Category** | Exception Handling Patterns |

**Issue:** Both `preFlightCheck()` catch paths return `[0, 0]` on failure, effectively swallowing the error. The `KosmokratorException` catch logs a warning; the `\Throwable` catch logs an error. But in both cases, the caller (AgentLoop) has no indication that the pre-flight check failed — it proceeds as if the context is fine.

This is partially by design (fail gracefully, let the LLM call proceed and potentially fail with a clearer error), but the risk is that `preFlightCheck()` calls `performCompaction()` which calls the LLM. If that LLM call throws a `PrismRateLimitedException` (caught by the `\Throwable` handler), the error is logged but the agent loop continues and will hit the same rate limit on its own LLM call.

**Impact:** Transient LLM errors during compaction are silently swallowed. The agent loop may experience the same error on its next call without knowing compaction already failed.

**Suggested Fix:** This is partially mitigated by the circuit breaker (`consecutiveCompactionFailures`). Consider also dispatching a log event or metric that can be surfaced to the user when compaction repeatedly fails.

---

### Finding 6: SubagentOrchestrator Retry Classifies ALL RuntimeExceptions as Retryable

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Agent/SubagentOrchestrator.php:668-679` |
| **Category** | Retry Logic |

**Issue:** `isRetryableException()` returns `true` for all `\RuntimeException` instances unless the message contains specific denylisted strings (`watchdog:`, `unknown dependency`, `401`, `403`, `authentication`, `unauthorized`). This denylist approach is fragile — any `RuntimeException` with a message not containing these strings will be retried, including:

- Configuration errors ("model not found")
- Context overflow errors that should trigger compaction instead
- Session persistence failures
- Invalid argument errors from dependency resolution (partially mitigated by "unknown dependency" check)

**Impact:** Agent-level retries may waste time retrying non-transient errors (2 retries × exponential backoff = up to 90 seconds wasted).

**Suggested Fix:** Switch to an allowlist approach for `RuntimeException`, similar to the existing `isRetryable()` in `RetryableLlmClient`. Only retry if the message contains known retryable patterns (429, 5xx, network error, timeout, etc.):

```php
if ($e instanceof \RuntimeException) {
    $msg = strtolower($e->getMessage());

    // Only retry known transient patterns
    return str_contains($msg, '429')
        || str_contains($msg, 'rate limit')
        || str_contains($msg, 'timeout')
        || str_contains($msg, 'connection')
        || preg_match('/\b5\d{2}\b/', $msg);
}
```

---

### Finding 7: No Global Unhandled Rejection Handler for Amp Futures

| Attribute | Value |
|-----------|-------|
| **Severity** | HIGH |
| **File** | Cross-cutting (no specific file) |
| **Category** | Fiber/Async Error Handling |

**Issue:** The codebase spawns Amp futures in multiple locations:

- `SubagentOrchestrator::spawnAgent()` — wraps in `try/catch/finally` ✓
- `ToolExecutor::executeToolCalls()` concurrent groups — awaits directly ✓
- `ShellSessionManager::startBackgroundReaders()` — spawns bare `async()` calls without error handling
- `BashTool::handle()` — spawns async futures for stdout/stderr ✓ (awaits them)

For `ShellSessionManager::startBackgroundReaders()` (line 195-219), three bare `async()` calls are made without `await()` or error handling. If any of these throw (e.g., process crash during stream read), the exception becomes an `UnhandledFutureError` when the fiber is garbage collected. This is partially mitigated by `ShellSessionManager::killAll()` on teardown, but there's a window between a process crash and teardown where this could occur.

The `SubagentOrchestrator` explicitly handles this with `ignorePendingFutures()` in `__destruct()`, but `ShellSessionManager` does not.

**Impact:** Unhandled `UnhandledFutureError` from shell background readers could crash the process during GC.

**Suggested Fix:** Add error handling to background reader fibers:

```php
\Amp\async(function () use ($session): void {
    try {
        $stream = $session->process->getStdout();
        while (($chunk = $stream->read()) !== null) {
            $session->appendOutput($chunk);
        }
    } catch (\Throwable $e) {
        // Process was killed or exited — expected, not an error
    }
});
```

---

### Finding 8: AgentLoop::run() Throwable Catch Shows Generic Message

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Agent/AgentLoop.php:265-276` |
| **Category** | User-Facing Errors |

**Issue:** The catch-all `\Throwable` handler at line 265 shows the user "An unexpected error occurred." with no details. While this prevents internal leaks, it also prevents the user from understanding what went wrong (e.g., an out-of-memory error, a type error from a bug, etc.).

The error IS logged with the exception class and message, so debugging from logs is possible. But the user has no actionable information.

**Impact:** User cannot distinguish between a transient error and a fundamental configuration issue.

**Suggested Fix:** This is largely by design (prevent internal detail leakage). Consider adding the exception class name for known safe types:

```php
SafeDisplay::call(fn () => $this->ui->showError(
    $e instanceof \Error
        ? 'Internal error: ' . get_class($e)
        : 'An unexpected error occurred.'
), $this->log);
```

---

### Finding 9: ErrorSanitizer Strips Too Aggressively — Loses Context Overflow Signal

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Agent/ErrorSanitizer.php:42-45` |
| **Category** | Logging / Exception Context |

**Issue:** `ErrorSanitizer::sanitize()` strips class references matching `Kosmokrator\*` and `Prism\*`. If a context overflow error message contains a class reference (e.g., `"Prism\Prism\Exceptions\PrismRequestException: context_length_exceeded"`), the class name is replaced with `[internal]`, potentially breaking downstream string-based error classification.

Additionally, the stack trace stripping (lines 30-32) uses regex patterns that may not catch all PHP stack trace formats (e.g., exceptions from Amp fibers have different formatting).

**Impact:** Over-sanitization may remove useful context from error messages sent to the LLM, hampering its ability to self-correct.

**Suggested Fix:** This is a trade-off between security and usability. Consider preserving the exception class short name (without namespace) for better LLM reasoning:

```php
// Preserve short class names, strip full namespaces
$message = preg_replace('/\\\\?Kosmokrator\\\\([\w\\\\]+)/m', '$1', $message);
```

---

### Finding 10: SubagentTool Batch Mode Loses Partial Results on Failure

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Tool/Coding/SubagentTool.php:262-268` |
| **Category** | Error Propagation |

**Issue:** In `handleBatch()`, when `await($futures)` throws (line 263), the entire batch fails with a generic message: "Batch execution failed: {message}". Any successfully completed agent results are discarded. The `SubagentOrchestrator` already handles individual agent failures gracefully (background agents inject failure as a pending result, await agents throw), but `Amp\Future\await()` throws on the *first* failure, aborting the rest.

**Impact:** In batch mode with N agents, if 1 fails, the user/LLM loses results from all other agents.

**Suggested Fix:** Use `Amp\Future\awaitAll()` or individual error handling:

```php
$results = [];
foreach ($futures as $id => $future) {
    try {
        $results[$id] = $future->await();
    } catch (\Throwable $e) {
        $results[$id] = "[FAILED] {$e->getMessage()}";
    }
}
```

---

### Finding 11: SafeDisplay Swallows All UI Errors Without Recovery

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/UI/SafeDisplay.php:24-35` |
| **Category** | Exception Handling Patterns |

**Issue:** `SafeDisplay::call()` is used throughout the agent loop for all UI interactions. It catches `\Throwable` and logs a warning, preventing UI errors from crashing the agent. This is the correct design for display-only calls.

However, if the UI consistently fails (e.g., TUI terminal corruption), every single display call will fail silently, and the agent will continue operating in "headless" mode — the user sees nothing but the agent keeps making LLM calls and tool calls.

**Impact:** In a broken terminal state, the agent continues burning API credits invisibly.

**Suggested Fix:** Add a counter for consecutive SafeDisplay failures. After N consecutive failures, log a critical error and potentially halt:

```php
private static int $consecutiveFailures = 0;

public static function call(callable $fn, ?LoggerInterface $log = null): void
{
    try {
        $fn();
        self::$consecutiveFailures = 0;
    } catch (\Throwable $e) {
        self::$consecutiveFailures++;
        if (self::$consecutiveFailures > 10) {
            $log?->critical('Too many consecutive display failures — terminal may be broken');
        }
        $log?->warning('Display call failed', [...]);
    }
}
```

---

### Finding 12: RetryableLlmClient Stream Retry After First Yield Can't Un-Yield

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/LLM/RetryableLlmClient.php:80-84` |
| **Category** | Error Propagation |

**Issue:** This is explicitly documented and handled correctly (throw if already yielded). However, it means that mid-stream failures (e.g., connection reset after receiving 50% of the response) always propagate to the caller. The `AgentLoop::streamResponse()` method doesn't have its own retry logic, so a mid-stream failure becomes a hard error in the agent loop.

**Impact:** Long streaming responses are vulnerable to transient network failures that can't be recovered without restarting the entire agent loop iteration.

**Suggested Fix:** Consider adding a buffer-and-retry mechanism for streaming: accumulate the full response in a buffer, and if the stream fails before `stream_end`, retry the request and concatenate. This is complex but would improve resilience for long tool-heavy responses.

---

### Finding 13: SubagentOrchestrator Destructor May Run During Event Loop Shutdown

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Agent/SubagentOrchestrator.php:68-72` |
| **Category** | Fiber/Async Error Handling |

**Issue:** `__destruct()` calls `cancelAll()` and `ignorePendingFutures()`. During PHP shutdown, the Revolt event loop may already be stopped, making `cancel()` calls on `DeferredCancellation` objects potentially unsafe (they schedule callbacks on the event loop). The `ignorePendingFutures()` call is safe (it just marks futures as ignored).

**Impact:** Potential PHP warnings during shutdown if the event loop is already stopped. In practice, this is mitigated because `cancelAll()` is typically called explicitly before shutdown.

**Suggested Fix:** Guard against double-cleanup:

```php
private bool $destroyed = false;

public function cancelAll(): void
{
    if ($this->destroyed) {
        return;
    }
    // ... existing logic ...
}

public function __destruct()
{
    $this->destroyed = true;
    $this->cancelAll();
    $this->ignorePendingFutures();
}
```

---

### Finding 14: NullRenderer Auto-Approves All Permission Prompts

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/UI/NullRenderer.php:66-69` |
| **Category** | User-Facing Errors / Security |

**Issue:** `NullRenderer::askToolPermission()` always returns `'allow'`, and `askChoice()` returns `'dismissed'`. This means headless subagents in **Explore** or **Plan** mode (which should be read-only) will auto-approve any tool permission requests, including write operations that somehow reach the permission evaluator.

This is partially mitigated by `ToolExecutor::executeSingleTool()` which catches `RuntimeException` and `Throwable` from tool execution, and by mode-based tool filtering. But if a tool is in the mode's allowed list but blocked by the permission policy, the `NullRenderer` overrides the denial.

**Impact:** Subagents bypass permission policies. A misconfigured tool in Explore mode that should require approval will execute without question.

**Suggested Fix:** Pass the parent's permission mode to `NullRenderer` or add mode-aware permission handling in `ToolExecutor` for headless contexts:

```php
// In NullRenderer:
public function askToolPermission(string $toolName, array $args): string
{
    // Respect the mode's default for non-interactive contexts
    return $this->readOnly ? 'deny' : 'allow';
}
```

---

### Finding 15: ShellSessionManager Background Readers Don't Handle Process Kill Race

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Tool/Coding/ShellSessionManager.php:193-220` |
| **Category** | Fiber/Async Error Handling |

**Issue:** The three background reader fibers in `startBackgroundReaders()` have no error handling. If the process is killed (via `kill()`, timeout, or idle cleanup) while a reader fiber is blocked on `$stream->read()`, the fiber receives a `ProcessException` or `CancelledException` that propagates as an unhandled future error.

Additionally, the exit-code reader at line 209 calls `$session->process->join()`, which will throw if the process was already killed.

**Impact:** Unhandled fiber exceptions during process teardown.

**Suggested Fix:** Wrap each reader in try/catch:

```php
\Amp\async(function () use ($session): void {
    try {
        $exitCode = $session->process->join();
        // ... existing logic ...
    } catch (\Throwable $e) {
        // Process was killed — expected during cleanup
        $session->appendSystemLine("Process terminated unexpectedly.");
    }
});
```

---

### Finding 16: AtomicFileWriter Temp File Collision Risk

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/IO/AtomicFileWriter.php:36` |
| **Category** | Resource Cleanup |

**Issue:** The temp file name uses `getmypid() . '_' . mt_rand()`. In a concurrent environment (multiple subagents writing to the same directory), `mt_rand()` provides insufficient collision resistance. If two writers generate the same random value for the same PID, one will overwrite the other's temp file.

**Impact:** Potential data corruption in highly concurrent write scenarios.

**Suggested Fix:** Use `tempnam()` or add more entropy:

```php
$tmpPath = $dir . '/.kosmokrator_tmp_' . getmypid() . '_' . bin2hex(random_bytes(8));
```

---

## Error Propagation Flow Summary

```
Tool Error (ToolResult::error)
  ↓
ToolExecutor::executeSingleTool()
  catch (RuntimeException) → ToolResult with ERROR_PREFIX
  catch (Throwable) → ToolResult with ERROR_PREFIX
  ↓
ToolExecutor::executeToolCalls()
  catch (Throwable) → handleToolExecutionError()
  ↓
AgentLoop::run() / runHeadless()
  Interactive: showError() + history + return
  Headless: return 'Error: ' + message
  ↓
LLM receives error as tool result → can retry or explain
```

```
LLM API Error
  ↓
AsyncLlmClient::guardResponseStatus()
  429/5xx → RetryableHttpException
  other → RuntimeException
  ↓
RetryableLlmClient
  isRetryable() → retry with backoff
  not retryable → throw
  ↓
AgentLoop::callLlm()
  Interactive: catch (CancelledException) → return
                catch (RuntimeException) → check context overflow → showError or retry
                catch (Throwable) → generic error
  Headless: catch (Throwable) → return error string
```

```
Subagent Error
  ↓
SubagentOrchestrator::spawnAgent() async closure
  catch (Throwable) → stats.status = 'failed'
    Background: inject as pendingResult, return (don't throw)
    Await: throw to caller
  ↓
SubagentTool::handleSingle()
  Await mode: future->await() → propagate
  Background: return immediately
```

---

## Positive Patterns Worth Noting

1. **`SafeDisplay::call()`** — Consistently wraps all display-only UI calls. Prevents cascading failures from rendering errors.

2. **`ErrorSanitizer`** — Strips internal paths, class names, API keys before sending to LLM. Good security boundary.

3. **Circuit breaker in `ContextManager`** — Disables auto-compaction after 3 consecutive failures, preventing infinite compaction loops.

4. **`SubagentOrchestrator` `finally` block** — Properly releases semaphores, cancels watchdogs, and cleans up cancellation tokens.

5. **`RetryableLlmClient` backoff strategy** — Honors Retry-After headers, uses exponential backoff with jitter, and has configurable max attempts.

6. **`ToolResult` as error carrier** — Tools return `ToolResult::error()` instead of throwing, allowing the LLM to see and reason about failures.

7. **Watchdog timer in SubagentOrchestrator** — Prevents runaway agents with configurable idle timeout.

8. **`GrepTool` timer cleanup** — Model implementation with `try/finally` for event loop timer cancellation.

---

## Recommendations Summary

| # | Severity | Finding | Effort |
|---|----------|---------|--------|
| 1 | MEDIUM | BashTool timer cleanup pattern | Low |
| 2 | HIGH | Over-broad RuntimeException catch in AgentLoop | Medium |
| 3 | MEDIUM | Stack trace loss in headless propagation | Low |
| 4 | MEDIUM | FileWriteTool discarding exception details | Low |
| 5 | MEDIUM | ContextManager swallowing all throwables | Low |
| 6 | MEDIUM | SubagentOrchestrator retry allowlist | Medium |
| 7 | HIGH | No global unhandled rejection handler | Medium |
| 8 | LOW | Generic unexpected error message | Low |
| 9 | MEDIUM | ErrorSanitizer over-stripping | Low |
| 10 | LOW | Batch partial result loss | Medium |
| 11 | LOW | SafeDisplay consecutive failure detection | Low |
| 12 | LOW | Mid-stream failure propagation | High |
| 13 | LOW | Destructor during event loop shutdown | Low |
| 14 | MEDIUM | NullRenderer auto-approving permissions | Low |
| 15 | MEDIUM | ShellSession background reader error handling | Low |
| 16 | LOW | AtomicFileWriter temp collision risk | Low |
