# UX Audit: Error Handling & Edge Cases

**Date:** 2026-04-07  
**Auditor:** UX Research  
**Research question:** *How well does KosmoKrator handle errors and edge cases in the TUI?*  
**Files reviewed:**
- `src/UI/Tui/TuiCoreRenderer.php` — `showError()`, `showNotice()`, cancellation flow
- `src/UI/Tui/TuiToolRenderer.php` — tool result rendering, success/failure indicators
- `src/UI/Tui/TuiModalManager.php` — permission denied flow, modal state guards
- `src/UI/Tui/TuiInputHandler.php` — cancel/deny handling, escape chains
- `src/UI/Tui/Widget/BashCommandWidget.php` — auto-expand on failure, output normalization
- `src/UI/Tui/Widget/PermissionPromptWidget.php` — approval options, deny callback
- `src/UI/Tui/Widget/DiscoveryBatchWidget.php` — error status in discovery items
- `src/UI/Tui/Widget/CollapsibleWidget.php` — collapsed/expanded result toggle
- `src/UI/Tui/KosmokratorStyleSheet.php` — `.tool-error` style definition
- `src/UI/SafeDisplay.php` — fire-and-forget display wrapper
- `src/UI/Theme.php` — error color, status indicators
- `src/Agent/AgentLoop.php` — error catch blocks, `showError()` call sites
- `src/Agent/ErrorSanitizer.php` — sanitization before LLM context
- `src/LLM/RetryableLlmClient.php` — retry logic, exponential backoff
- `src/LLM/RetryableHttpException.php` — retryable status codes

---

## Executive Summary

KosmoKrator's error handling is **architecturally sound** (circuit breakers, sanitization, retry with backoff, SafeDisplay wrappers) but **UX-poor** (flat inline text, no classification, no recovery affordances, errors lost in scrollback). The system correctly catches, logs, and recovers from errors at the code level, but the *user-facing presentation* has significant gaps:

1. **No error classification** — API rate limits, auth failures, tool errors, and network disconnects all render identically as `✗ Error: <message>` in red text.
2. **No persistence** — errors scroll away immediately; there is no error log, toast, or persistent indicator.
3. **No recovery paths** — the user sees an error but gets no affordances to retry, dismiss, or take corrective action.
4. **No error-specific UI** — unlike bash failures (which auto-expand), most errors are plain text with no visual weight.
5. **Silent swallowed errors** — `SafeDisplay::call()` silently catches all display exceptions. Internal errors like highlight failures are logged to `error_log()` but never shown to the user.

**Overall grade: C-** — robust plumbing, poor presentation. The error *pipeline* is well-engineered; the error *display* needs a complete overhaul.

---

## 1. LLM API Errors (Rate Limit, Auth, Timeout)

### Current behavior

LLM errors flow through two layers:

1. **`RetryableLlmClient`** — catches `PrismRateLimitedException`, `PrismServerException`, `HttpException`, `ProviderError`. Retries with exponential backoff (2s → 4s → 8s → … → 60s cap) with ±15% jitter. Honors `Retry-After` headers. Has an `onRetry` callback.

2. **`AgentLoop`** — catches `RuntimeException` and `Throwable` from the LLM call. Calls `showError($e->getMessage())` on runtime exceptions, and `showError('An unexpected error occurred.')` on unexpected throwables.

The retry callback is wired in `LlmClientFactory:57`:
```php
$ui->showNotice("⟳ Retrying in {$delaySec}s (attempt {$attempt})");
```

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No error classification** | 🔴 High | Rate limit (429), auth failure (401/403), server error (500/502/503), timeout, and network disconnect all show identically. The user cannot distinguish "try again in 30s" from "your API key is invalid". |
| **Retry notices are ephemeral** | 🔴 High | `showNotice()` renders as a subtitle-style TextWidget that immediately scrolls up. If the agent makes progress after retrying, the user never saw the retry. |
| **No retry countdown** | 🟡 Medium | The retry notice says "Retrying in 5s" but shows no live countdown. The terminal appears frozen during the wait. |
| **Auth errors masked** | 🔴 High | 401/403 errors go through `ErrorSanitizer` before being sent back to the LLM, then show as generic `ErrorSanitizer::sanitize()` output. The user sees something like "401 error" but gets no guidance to check their API key or run `/settings`. |
| **Timeout = silent hang** | 🔴 High | If the LLM provider accepts the connection but never returns tokens, there's no client-side timeout visible to the user. The thinking spinner runs indefinitely. |
| **Retry state invisible** | 🟡 Medium | During retry, the phase stays `Thinking` with the same animation. The user can't tell if the request failed and is being retried, or if it's just slow. |

### Comparison: Claude Code

Claude Code displays rate-limit errors inline with a **category badge** (e.g. `[Rate Limited]`) and shows a **live countdown timer** before retry. Auth errors trigger a **persistent error banner** with instructions. After 3 consecutive failures, it shows an **error summary** with suggested actions.

### Comparison: Lazygit

Lazygit uses a **toast notification** that slides in from the bottom-right corner. Rate-limit errors show as: `[ERROR] GitHub API rate limit exceeded — resets in 47m`. The toast auto-dismisses after 10s but is also logged to a persistent error panel accessible via `e`.

---

## 2. Tool Execution Errors (Bash Failure, File Not Found)

### Current behavior

Tool errors are rendered via `TuiToolRenderer::showToolResult()`:

```php
$statusColor = $success ? Theme::success() : Theme::error();
$indicator = $success ? '✓' : '✗';
$header = "{$statusColor}{$indicator}{$r}";
```

For **bash commands**, `BashCommandWidget::setResult()` auto-expands on failure:
```php
if (! $success) {
    $this->expanded = true;
}
```

For **discovery items**, error status is tracked as `'status' => 'error'` in the batch items array.

For **generic tool errors** (file not found, permission denied by OS, etc.), output is wrapped in a `CollapsibleWidget` with a `✗` header, defaulting to collapsed.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **Generic tools: errors hidden in collapsed widget** | 🔴 High | `file_edit` failures, `grep` errors, `apply_patch` rejections are all in `CollapsibleWidget` which defaults to collapsed. The user sees `✗` but must toggle (ctrl+o) to see what went wrong. This is the opposite of bash, which auto-expands on failure. |
| **No error type distinction** | 🟡 Medium | "File not found", "permission denied by OS", "syntax error in patch" all show as `✗` + raw output. No icon, color, or label distinction. |
| **Discovery batch: errors shown inline** | 🟢 Low | Discovery items that error show `'status' => 'error'` and are visible in the batch. This is actually well-handled — the batch structure keeps errors in context. |
| **Tool execution exceptions: raw message** | 🟡 Medium | `AgentLoop::handleToolExecutionError()` shows `showError('Tool execution error: ' . $e->getMessage())`. The raw exception message may contain stack traces or internal class names. `ErrorSanitizer` runs before it goes to the LLM, but the *user* sees the unsanitized version. |
| **No exit code for bash** | 🟡 Medium | Bash failures show `✗ command failed` but the actual exit code is swallowed. A user debugging a script would want to see `exit code 1` or `exit code 127`. |

### Comparison: Claude Code

Claude Code shows tool errors with a **colored error badge**, the **command name**, and an **expandable traceback**. Bash errors include the exit code prominently. File-not-found errors show the path in a distinct color with a "file does not exist" label.

### Comparison: Vim/Neovim

Vim displays errors as `E{code}: {message}` in a **highlighted message line** at the bottom of the screen. The error stays visible until the user presses a key (dismissable). Errors are also appended to `:messages` for later review.

---

## 3. Permission Denied

### Current behavior

Permission denied flows through `PermissionPromptWidget`, which is a full modal overlay:

1. `TuiModalManager::askToolPermission()` creates a `PermissionPromptWidget` with preview context
2. The widget shows: tool icon + label, summary, scope, preview, and 5 options (Allow once, Always allow, Guardian, Prometheus, Deny)
3. User navigates with ↑/↓, confirms with Enter, or dismisses with Esc (which triggers `'deny'`)
4. The overlay blocks via Revolt `Suspension`

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **Esc = Deny with no confirmation** | 🟡 Medium | Pressing Escape immediately denies the tool call. There's no "Are you sure?" or visual feedback that the denial was registered. The modal just disappears. |
| **No "deny reason" feedback** | 🟡 Medium | When the user denies, the agent receives `'deny'` but has no context about *why*. The LLM may retry the same tool call blindly. |
| **Deny is recoverable but the user doesn't know** | 🟢 Low | The agent loop processes `'deny'` as a tool error result and can adjust. But the UI doesn't communicate this — the user may assume denial is permanent/abortive. |
| **No batch permission** | 🟡 Medium | If the agent makes 10 sequential bash calls, the user must approve each one individually. There's no "approve all bash for next 5 minutes" option beyond "Guardian" or "Prometheus". |

### Assessment

The permission prompt is actually one of the **stronger** error/edge-case handlers. The preview is well-structured (scope, expected result, diff preview for edits). The main gap is the **silent deny** and lack of batch permission granularity.

---

## 4. Network Disconnection

### Current behavior

Network disconnections are handled at the `RetryableLlmClient` level:
- `HttpException` (Amp HTTP client) is classified as retryable → automatic retry with backoff
- After `maxAttempts` (default 0 = unlimited), the exception propagates to `AgentLoop`
- `AgentLoop` catches `RuntimeException` and calls `showError($e->getMessage())`
- `AgentLoop` catches `Throwable` and calls `showError('An unexpected error occurred.')`

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No network status indicator** | 🔴 High | The user has no way to distinguish "network down" from "LLM thinking for a long time". The thinking spinner continues during retries. |
| **Unlimited retries with no feedback** | 🔴 High | Default `maxAttempts = 0` means the client retries forever. If the network is truly down, the agent will silently retry forever with only occasional `showNotice("⟳ Retrying…")` messages that scroll away. |
| **No user-visible retry counter** | 🔴 High | The `onRetry` callback shows attempt number, but this notice scrolls up and is quickly buried by subsequent retry notices or other output. |
| **No "cancel retry" affordance** | 🟡 Medium | The user can press Ctrl+C or Escape to cancel, but the cancel action targets the `DeferredCancellation` token — it cancels the *current request*, not just the retries. The user might not realize this also aborts the agent loop. |
| **Reconnection success not shown** | 🟡 Medium | When a retry succeeds, there's no "connection restored" confirmation. The user may not realize the agent has recovered. |

### Comparison: Lazygit

Lazygit shows a persistent **network status indicator** in the status bar: `[OFFLINE]` or `[CONNECTED]`. Failed operations show as toasts with a "retry" keybinding. After 3 consecutive failures, lazygit pauses and shows a modal: "Network appears disconnected. [Retry] [Cancel]".

---

## 5. Terminal Too Small

### Current behavior

**No handling exists.** There is no minimum terminal size check anywhere in the TUI code. The `Tui` framework uses `getTerminal()->getRows()` and `getTerminal()->getColumns()` for layout, but KosmoKrator never validates these against a minimum.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No minimum size guard** | 🔴 High | If the terminal is very small (e.g., 40×10), widgets will overlap, truncate, or render garbage. The permission prompt's bordered layout breaks below ~50 columns. |
| **No resize handler** | 🟡 Medium | If the user resizes the terminal during operation, the TUI framework handles basic layout recalculation, but there's no "terminal too small" warning or minimum-size enforcement. |
| **Content becomes unusable** | 🔴 High | The status bar, task bar, thinking bar, input editor, and conversation all compete for vertical space. Below ~15 rows, only the status bar and input are visible. |

### Comparison: Lazygit

Lazygit enforces a minimum terminal size and shows a centered message: "Terminal too small. Please resize to at least 80×24." The message is shown instead of the UI until the terminal is large enough.

### Comparison: Vim/Neovim

Vim shows `-- Insufficient width --` or `-- Insufficient height --` messages and continues with reduced functionality. Neovim uses a "message grid" that overlays the main content.

---

## 6. Invalid User Input

### Current behavior

User input flows through the `EditorWidget` → `TuiInputHandler::handleSubmit()`. There is **no input validation** at the TUI level:

- Empty messages are silently ignored: `if (trim($value) !== '') { ... }`
- Invalid slash commands (e.g., `/foo`) are passed to the immediate command handler, which may show a notice or silently drop them
- Invalid power commands (e.g., `:bar`) behave similarly
- The LLM receives raw user text; validation happens at the agent level

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No feedback for unknown commands** | 🟡 Medium | Typing `/foo` and pressing Enter silently does nothing (or sends it as a message to the LLM). No "Unknown command" feedback. |
| **No input validation errors** | 🟢 Low | This is actually by design — the LLM handles freeform input. But edge cases like `/settings` when the settings panel is already open, or `/resume` when no sessions exist, could benefit from inline feedback. |
| **Modal conflict: `LogicException`** | 🟡 Medium | If a modal is already active and another modal is requested, `TuiModalManager` throws a `LogicException('A modal is already active')`. This crashes the display (caught by `SafeDisplay`, so the agent continues, but the user sees nothing). |
| **Ctrl+C during prompt = quit** | 🔴 High | `TuiInputHandler::handleCancel()` chains through: ask suspension → request cancellation → prompt suspension → immediate command handler. The last fallback is `$handler('/quit')`. Pressing Ctrl+C at the idle prompt *quits the application* with no confirmation. |

---

## 7. Recovery Paths (Retry, Dismiss, Abort)

### Current behavior

| Error Type | User Recovery Path | Available? |
|-----------|-------------------|------------|
| LLM API rate limit | Wait for retry (automatic) | ✅ Automatic, invisible |
| LLM API auth failure | Fix API key in `/settings`, restart | ❌ No guidance shown |
| LLM API timeout | Ctrl+C to cancel, re-prompt | ⚠️ Cancels entire request |
| Tool execution error | Agent sees error result, may retry | ✅ Automatic in agent loop |
| Permission denied (user) | Agent adjusts and continues | ✅ Handled |
| Bash command failure | Output visible, agent reads it | ✅ Auto-expanded |
| Network disconnection | Ctrl+C, wait, re-prompt | ⚠️ No clear affordance |
| Terminal too small | Resize terminal | ❌ No warning shown |
| Modal conflict | Nothing (crash swallowed) | ❌ Silent failure |
| Ctrl+C at prompt | Immediate quit | ❌ No confirmation |

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No "retry" button or command** | 🔴 High | After a non-retryable error, the user must re-type their request. There's no `/retry` or "try again" affordance. |
| **Cancel is nuclear** | 🔴 High | Ctrl+C cancels the *entire agent loop round*. There's no "cancel current tool, keep conversation" option. The user loses all progress for the current turn. |
| **No error summary** | 🟡 Medium | After multiple errors (e.g., network flapping causing 5 retries then failure), the user sees 5 scattered `showNotice`/`showError` lines in scrollback with no consolidated summary. |
| **No guided recovery** | 🔴 High | Auth errors, missing API keys, expired tokens — these all require specific user action. The TUI never says "Run /settings to update your API key" or "Check your network connection". |

---

## 8. Error Visibility (Are Errors Lost in Scrollback?)

### Current behavior

Errors are rendered as regular conversation widgets via `showError()`:
```php
public function showError(string $message): void
{
    $this->showMessage("✗ Error: {$message}", 'tool-error');
}
```

This adds a `TextWidget` with style class `.tool-error` (color: `#ff5040`, padding: `0 3 0 3`) to the conversation container. The widget scrolls with all other conversation content.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **Errors scroll away immediately** | 🔴 High | As soon as the agent recovers and produces new output, errors scroll up. The user may not notice them at all if they're not watching the terminal. |
| **No error indicator in status bar** | 🔴 High | The status bar shows mode, permission, tokens, and model — but never "last error" or "error count". There's no way to see at a glance that something went wrong. |
| **No error log panel** | 🔴 High | Unlike lazygit (press `e` for error log) or Vim (`:messages`), there's no way to review past errors. Once scrolled away, they're gone. |
| **Error style lacks visual weight** | 🟡 Medium | `.tool-error` is just red text with horizontal padding. No background highlight, no border, no icon scaling. It blends with the `✓` success indicators (which use the same widget shape, different color). |
| **Multiple errors look like noise** | 🟡 Medium | During a failing agent turn, multiple errors may appear in sequence. Each is a separate TextWidget with no visual grouping. They look like regular tool output, not a coherent error narrative. |
| **Notice vs. error ambiguity** | 🟡 Medium | `showNotice()` uses style class `'subtitle'` (gold accent). `showError()` uses `'tool-error'` (red). But both are TextWidgets in the conversation. The visual hierarchy doesn't distinguish "informational notice" from "action-needed error". |

### Comparison: Vim/Neovim

Vim errors stay on the command line until the user presses a key. They're also accumulated in `:messages`. Critical errors use `E{code}:` prefix and red highlighting. The user can always review.

### Comparison: Lazygit

Lazygit shows errors as **toast popups** that persist for ~8 seconds in the lower-right corner, overlaid on the current view. Errors are also logged to a persistent error panel (toggle with `e`). The toast has a colored left border (red for errors, yellow for warnings).

---

## 9. Recommendations

### 9.1 Error Classification & Typed Display

Replace the flat `showError(string $message)` with a typed error system:

```
┌─ Current ──────────────────────────────────────────────────────────┐
│                                                                     │
│  ✗ Error: API error (429): Rate limit exceeded                     │
│                                                                     │
│  [immediately scrolls away as agent continues]                     │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─ Proposed: Typed Error Block ──────────────────────────────────────┐
│                                                                     │
│  ┌─ ⚠ Rate Limited ────────────────────────────────────────────┐  │
│  │ Provider returned HTTP 429. Retrying automatically.         │  │
│  │                                                              │  │
│  │  Attempt 3/5  ·  Next retry in 8s                           │  │
│  │  ████████░░░░░░░  retrying...                               │  │
│  │                                                              │  │
│  │  [Esc cancel] [/settings change API key]                     │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Implementation sketch:**
```php
enum ErrorCategory {
    case RateLimit;      // 429 — auto-retry, show countdown
    case AuthFailure;    // 401/403 — guide to /settings
    case ServerError;    // 5xx — auto-retry, show attempt count
    case Timeout;        // no response — show duration, offer retry
    case NetworkError;   // connection refused / DNS — show status
    case ToolError;      // tool execution failure — show context
    case UserDenied;     // permission denied — show alternative
    case InternalError;  // unexpected exception — show sanitized message
}

interface CoreRendererInterface {
    public function showError(string $message): void;        // legacy
    public function showTypedError(ErrorCategory $cat, ErrorContext $ctx): void;  // new
}
```

### 9.2 Error Toast Overlay

Add a persistent toast overlay for errors that doesn't scroll away:

```
┌─ Toast Overlay Mockup ─────────────────────────────────────────────┐
│                                                                     │
│                                                        ┌──────────┐ │
│  Agent conversation content...                         │ ✗ Auth   │ │
│  flowing here with streaming...                        │ Failure  │ │
│                                                        │          │ │
│  ✓ file_edit src/Foo.php                              │ Check    │ │
│  ✓ bash phpunit                                       │ API key  │ │
│  ✗ Error: API error (401)                             │          │ │
│  [agent continues...]                                 │ [×]      │ │
│                                                        └──────────┘ │
│  ─── status bar ─────────────────────────────────────────────────  │
│  Edit · Guardian ◈ · 12k/200k · claude-sonnet-4-20250514          │
│  ▌                                                                  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Properties:**
- Toast appears in the upper-right of the conversation area
- Auto-dismisses after 10s for transient errors (rate limit, retry)
- Stays until dismissed for action-required errors (auth, config)
- Red left border for errors, amber for warnings, blue for info
- Dismiss with click/Enter, or automatically when agent recovers
- Errors are also appended to an error log (accessible via `/errors`)

### 9.3 Status Bar Error Indicator

Add an error indicator to the status bar:

```
┌─ Current Status Bar ──────────────────────────────────────────────┐
│  Edit · Guardian ◈ · 12k/200k · claude-sonnet-4-20250514        │
└───────────────────────────────────────────────────────────────────┘

┌─ Proposed: With Error Indicator ──────────────────────────────────┐
│  Edit · Guardian ◈ · 12k/200k · claude-sonnet-4-20250514 · ⚠ 3  │
└───────────────────────────────────────────────────────────────────┘
```

The `⚠ 3` shows the count of unacknowledged errors this session. Clicking or pressing a dedicated key opens the error log.

### 9.4 Auto-Expand All Failures (Not Just Bash)

Currently only `BashCommandWidget` auto-expands on failure. This should be universal:

```php
// In TuiToolRenderer::showToolResult()
$widget = new CollapsibleWidget($header, $content, $lineCount);
$widget->addStyleClass('tool-result');
if (!$success) {
    $widget->setExpanded(true);  // Always expand failures
}
```

### 9.5 Guided Recovery Messages

Add actionable context to error messages:

```
┌─ Current ──────────────────────────────────────────────────────────┐
│  ✗ Error: API error (401)                                         │
└─────────────────────────────────────────────────────────────────────┘

┌─ Proposed ─────────────────────────────────────────────────────────┐
│  ✗ Authentication Failed                                           │
│                                                                     │
│  The API provider rejected your credentials. This usually means    │
│  your API key is missing, expired, or invalid.                     │
│                                                                     │
│  → Run /settings to check your API key configuration               │
│  → Run /quit and set KOSMOKRATOR_API_KEY environment variable      │
└─────────────────────────────────────────────────────────────────────┘
```

### 9.6 Terminal Minimum Size Guard

Add a minimum size check during `TuiCoreRenderer::initialize()`:

```
┌─ Terminal Too Small ──────────────────────────────────────────────┐
│                                                                     │
│        ⚠ Terminal too small                                        │
│                                                                     │
│   KosmoKrator requires at least 80×24.                             │
│   Current size: 52×12                                              │
│                                                                     │
│   Please resize your terminal or switch to a larger window.        │
│   The UI will appear automatically when ready.                     │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Implementation sketch:**
```php
// In TuiCoreRenderer::initialize()
private const MIN_COLS = 80;
private const MIN_ROWS = 24;

// After $this->tui->start()
$rows = $this->tui->getTerminal()->getRows();
$cols = $this->tui->getTerminal()->getColumns();
if ($rows < self::MIN_ROWS || $cols < self::MIN_COLS) {
    $this->showMinimumSizeWarning($cols, $rows);
    // Poll until resized
}
```

### 9.7 Ctrl+C Confirmation at Idle Prompt

When the user presses Ctrl+C at the idle prompt (no active request), show a confirmation instead of quitting immediately:

```
┌─ Current: Immediate quit ─────────────────────────────────────────┐
│  [Ctrl+C] → application exits                                     │
└───────────────────────────────────────────────────────────────────┘

┌─ Proposed: Confirmation ──────────────────────────────────────────┐
│                                                                     │
│  Press Ctrl+C again to quit. (or Esc to cancel)                   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

This follows the lazygit / htop pattern: first Ctrl+C shows a warning, second Ctrl+C within 2 seconds confirms quit.

### 9.8 Error Log Command

Add `/errors` command to show a scrollable error log:

```
┌─ /errors — Error Log ─────────────────────────────────────────────┐
│                                                                     │
│  1. [14:23:01] ⚠ Rate Limited — HTTP 429, retried (success)      │
│  2. [14:23:45] ✗ Auth Failure — Invalid API key                   │
│  3. [14:24:02] ✗ Tool Error — file_edit failed: not found         │
│                                                                     │
│  ↑/↓ scroll  Enter details  Esc close                              │
└─────────────────────────────────────────────────────────────────────┘
```

### 9.9 Retry Visibility Improvements

For `RetryableLlmClient` retries, show a **persistent retry indicator** instead of a scrolling notice:

```
┌─ Retry Indicator in Conversation ─────────────────────────────────┐
│                                                                     │
│  ┌─ ⟳ Retrying (attempt 2/5) ──────────────────────────────────┐ │
│  │ Rate limited by provider. Waiting 4s before retry...        │ │
│  │ ████████░░░░░░░░░░░░  4.2s remaining                       │ │
│  └──────────────────────────────────────────────────────────────┘ │
│                                                                     │
│  [updates in place, doesn't create new widgets]                    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

The indicator should update in place (single widget with timer) rather than creating new TextWidget instances for each retry.

---

## Error Handling Scorecard

| Category | Score | Notes |
|----------|-------|-------|
| **LLM API errors** | **D+** | Auto-retry exists but invisible; no classification, no guidance |
| **Tool execution errors** | **C** | Bash auto-expand is good; other tools collapse errors |
| **Permission denied** | **B-** | Well-structured modal; Esc=deny is a trap |
| **Network disconnection** | **D** | Infinite silent retries; no status indicator |
| **Terminal too small** | **F** | No handling at all |
| **Invalid user input** | **C** | Generally acceptable; unknown commands silent; Ctrl+C quits |
| **Recovery paths** | **D** | No retry affordance; cancel is nuclear; no guided recovery |
| **Error visibility** | **D** | Errors scroll away; no log; no status indicator |
| **Error sanitization** | **B+** | Good separation (user sees raw, LLM gets sanitized) |
| **Internal error resilience** | **B** | SafeDisplay prevents cascading crashes |

---

## Priority Matrix

| Priority | Recommendation | Effort | Impact |
|----------|---------------|--------|--------|
| **P0** | Auto-expand all error CollapsibleWidgets | Low | High |
| **P0** | Terminal minimum size guard | Low | High |
| **P0** | Ctrl+C confirmation at idle prompt | Low | High |
| **P1** | Error toast overlay | Medium | High |
| **P1** | Typed error categories with recovery guidance | Medium | High |
| **P1** | Status bar error indicator | Low | Medium |
| **P1** | Retry indicator (in-place, not scrolling) | Medium | High |
| **P2** | `/errors` command with scrollable error log | Medium | Medium |
| **P2** | Network status indicator | Medium | Medium |
| **P2** | Batch permission approval | Medium | Medium |
| **P3** | Error dismissal keybinding | Low | Low |
| **P3** | Guided onboarding for auth errors | Medium | Medium |
