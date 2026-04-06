# KosmoKrator Deep Audit — 2026-04-04

> **Scope**: Full codebase audit across 20 dimensions — code quality, edge cases, TUI/UX, security, refactoring opportunities.  
> **Methodology**: 16 parallel exploration agents spawning ~62 sub-agents for deep-dive analysis across 20 dimensions.  
> **Codebase**: 277 PHP files, ~50K lines, PHP 8.4, Symfony Console + TUI.  
> **Findings**: 65 Critical, 128 Important, 91 Minor = **284 total findings**.

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Top 25 Critical Issues](#top-25-critical-issues)
3. [Area Findings](#area-findings)
   - [AgentLoop Core](#1-agentloop-core--repl-orchestrator)
   - [Subagent Orchestration](#2-subagent-orchestration)
   - [TUI Renderer](#3-tui-renderer)
   - [ANSI Renderer](#4-ansi-renderer--markdown)
   - [Tool System & Permissions](#5-tool-system--permission-model)
   - [LLM Client Layer](#6-llm-client-layer)
   - [Session & Database Persistence](#7-session--database-persistence)
   - [Commands & Slash Commands](#8-commands--slash-commands)
   - [Settings & Configuration](#9-settings--configuration)
   - [Diff & UI Display](#10-diff-rendering--ui-display)
   - [Power Commands & UX](#11-power-commands--ux-workflows)
   - [Testing Coverage](#12-testing-coverage--quality)
4. [Cross-Cutting Themes](#cross-cutting-themes)
5. [Security Concerns Summary](#security-concerns-summary)
6. [Refactoring Backlog](#refactoring-backlog-prioritized)

---

## Executive Summary

The audit identified **65 critical**, **128 important**, and **91 minor** issues across the codebase (284 total). The most systemic problems are:

- **No graceful shutdown**: No signal handling anywhere in the codebase. Ctrl+C = orphaned processes, broken terminal, unsaved data.
- **Security**: File tools have no path containment checks; permission system is opt-in (default-allow). File writes are non-atomic.
- **Concurrency**: Shared mutable state (`ContextBudget`, `ProtectedContextBuilder`, `BashTool::$progressCallback`), subagent slot leaks for root agent, race conditions in tool result ordering.
- **Exception hygiene**: Only 2 custom exceptions in 277 files. 6 silently swallowed `\Throwable` catches. Raw `$e->getMessage()` leaked to LLM.
- **TUI stability**: Modal stacking can deadlock, triple concurrent 30fps render timers, no TUI→ANSI mid-session fallback.
- **Configuration**: `reloadRepository()` loses user/project overrides, audio config mutates shared LLM singleton, LLM clients capture stale config at registration.
- **Testing**: ContextManager has 1 test, no integration tests exist, no tool result ordering tests, no UTF-8 truncation tests.

---

## Top 30 Critical Issues

Ranked by impact (severity × likelihood × affected surface).

| # | Issue | File | Impact |
|---|-------|------|--------|
| 1 | **No path traversal protection in file tools** | `FileWriteTool.php:49`, `FileEditTool.php:51`, `FileReadTool.php:57` | LLM can write `/etc/passwd`, `~/.ssh/authorized_keys`. Relies entirely on permission chain being configured. |
| 2 | **Permission evaluator defaults to Allow** | `PermissionEvaluator.php:66-68` | Any tool not explicitly covered by rules/grants/blocked-paths is auto-approved. Security should default-deny. |
| 3 | **Non-atomic file writes** | `FileWriteTool.php:49` | `file_put_contents()` leaves partial files on crash. `FileEditTool` correctly uses temp+rename; `FileWriteTool` does not. |
| 4 | **Shell sessions orphaned on process crash** | `ShellSessionManager.php:164-179` | No `__destruct()` or shutdown handler. SIGKILL leaves zombie processes. |
| ~~5~~ | ~~**`reloadRepository()` loses YAML overrides**~~ | ~~`SettingsManager.php:267-274`~~ | **FIXED** — `reloadRepository()` now re-applies global and project YAML overrides after reload. |
| ~~6~~ | ~~**Audio config mutates shared LLM client**~~ | ~~`SessionServiceProvider.php:56-65`~~ | **FIXED** — Audio now creates a dedicated `PrismService` instance instead of mutating the shared singleton. |
| 7 | **TUI modal stacking causes deadlock** | `TuiModalManager.php` | No mutex prevents two modals from being shown simultaneously. If `askToolPermission()` fires during `askUser()`, deadlock. |
| ~~8~~ | ~~**No `SQLITE_BUSY` handling**~~ | ~~`Database.php:38-39`~~ | **FIXED** — `PRAGMA busy_timeout=5000` added. |
| 9 | **Unlimited LLM retries by default** | `RetryableLlmClient.php:37`, `LlmServiceProvider.php:81` | `$maxAttempts = 0` = infinite retries. Persistent 429/5xx loops forever. |
| ~~10~~ | ~~**Tool result ordering doesn't match call order**~~ | ~~`ToolExecutor.php:212-217`~~ | **FIXED** — Results now merged in original tool call order (approved + denied interleaved). |
| ~~11~~ | ~~**`OutputTruncator::truncate()` splits mid-UTF8**~~ | ~~`OutputTruncator.php:96-98`~~ | **FIXED** — Now uses `mb_strcut()` for UTF-8-safe truncation. |
| 12 | **Context compactor LLM call has no cancellation** | `ContextCompactor.php:164-167` | User cancel during compaction doesn't abort the compaction LLM request. |
| 13 | **No signal handling in AgentCommand** | `AgentCommand.php` | Ctrl+C skips teardown — no `killAll()`, no `cancelAll()`, no `ui->teardown()`. Orphaned processes, broken terminal state. |
| 14 | **Silent message loss on null tool_result** | `MessageSerializer.php:109-111` | Missing `tool_results` data → `null` → silently filtered → broken conversation flow → API errors. |
| 15 | **No session/message deletion** | `SessionRepositoryInterface.php` | Database grows without bound. No way to clean up old sessions or their messages. |
| ~~16~~ | ~~**`PrismService` drops `reasoningContent`**~~ | ~~`PrismService.php:111-120`~~ | **FIXED** — reasoning/thinking content extracted from `additionalContent` and passed through to `LlmResponse`. |
| 17 | **AnsiTheogony: 80s unskippable animation** | `AnsiTheogony.php` | No skip mechanism. Screen shake bug (both branches produce same direction). |
| 18 | **Triple concurrent 30fps render timers** | `TuiAnimationManager.php:378`, `TuiToolRenderer.php:267`, `SubagentDisplayManager.php:205` | Breathing + loader + tool-executing timers each trigger full terminal re-render independently. |
| 19 | **Substring model matching can return wrong spec** | `ModelDefinitionSource.php:86-101` | `"gpt-4o-mini"` matches `"gpt-4o"` if mini not explicitly defined. Wrong pricing/context window. |
| 20 | **Stuck detector misses oscillating patterns** | `StuckDetector.php:49-58` | Only checks last signature. `[A,A,A,B,A,A,A,B,...]` never triggers. Any non-stuck round fully resets escalation. |
| 21 | **Non-atomic config file writes** | `YamlConfigStore.php:60` | `file_put_contents()` without temp+rename. Crash mid-write = corrupted YAML. |
| 22 | **`forProject()` loads ALL memories into RAM** | `MemoryRepository.php:65-88` | No limit/pagination. O(n log n) sort on full dataset every retrieval. |
| 23 | **`AsyncLlmClient` provider list not checked by factory** | `LlmClientFactory.php:45` vs `AsyncLlmClient.php:34` | Two independent provider lists can drift. Factory creates client for providers not in the compatibility list. |
| ~~24~~ | ~~**`collectResult()` detects errors by "Error:" prefix**~~ | ~~`ToolExecutor.php:405`~~ | **FIXED** — Now uses `ToolCallMapper::isErrorResult()` with binary `\x01ERROR:` prefix, eliminating false positives. |
| 25 | **No terminal capability detection** | `UIManager.php:377-389`, `Theme.php` | Unconditional 24-bit color + Unicode. No `NO_COLOR`, `COLORTERM`, or `TERM` check. Garbled on limited terminals. |
| 26 | **`yieldSlot`/`reclaimSlot` slot leak for root agent** | `SubagentOrchestrator.php:471-496` | Root agent never acquires semaphore lock but `reclaimSlot` consumes one permanently. After N calls → deadlock. |
| 27 | **Shared `ContextBudget` across all subagent depths** | `SubagentFactory.php:87` | Deep child compaction deducts from root's budget pool. Root can run out prematurely. |
| 28 | **No error handling during kernel boot** | `bin/kosmokrator`, `Kernel.php:45-72` | Zero try-catch in bootstrap. Provider failure = partial initialization, raw stack trace. |
| 29 | **Raw `$e->getMessage()` leaked to LLM** | `AgentLoop.php:288,312,518`, `ToolExecutor.php:313` | Internal error messages (HTTP codes, file paths, provider details) stored as assistant messages. No sanitization. |
| 30 | **`wouldCreateCycle` crashes on pruned stats** | `SubagentOrchestrator.php:375` | Accesses `$this->stats[$current]->dependsOn` without existence check. Pruned agents → TypeError. |

---

## Area Findings

### 1. AgentLoop Core & REPL Orchestrator

**Files**: `src/Agent/AgentLoop.php` (858 lines), `ToolExecutor.php` (465 lines), `ContextManager.php`, `StuckDetector.php`, `OutputTruncator.php`, `TokenEstimator.php`

#### Critical
- ~~`OutputTruncator::truncate()` uses byte-level `substr()` that can split mid-UTF8 character~~ (**FIXED** — uses `mb_strcut()`)
- `BashTool::$progressCallback` is static mutable — race condition in concurrent bash execution (`ToolExecutor.php:162`)
- Context compactor LLM call has no cancellation support (`ContextCompactor.php:164-167`)

#### Important
- ~~**Tool result ordering bug**: denied results appended after approved, not matching tool call order~~ (**FIXED** — results merged in original call order)
- **Stuck detector misses oscillating patterns**: only checks last signature, escalation resets on any non-stuck round (`StuckDetector.php:49-58`)
- **Token estimation 15-30% low for code**: fixed 4 chars/token ratio (`TokenEstimator.php:37`)
- **No max-iteration guard in `run()`**: infinite tool-call loop possible in interactive mode (`AgentLoop.php:198`)
- ~~**`collectResult()` detects errors by "Error:" string prefix**: fragile, false positives on grep output~~ (**FIXED** — uses `ToolCallMapper::isErrorResult()`)
- **`ContextBudget` default `reserveOutputTokens=0`**: no room for LLM response → API error (`ContextBudget.php:53-56`)
- **`isContextOverflow()` is a fragile heuristic**: string matching on error messages from different providers (`AgentLoop.php:748-757`)
- **`apply_patch` args don't populate `$writePaths`**: concurrent `file_read` of patched file gets stale data (`ToolExecutor.php:341-357`)
- **No timeout on individual tool execution**: misbehaving tool blocks event loop (`ToolExecutor.php:168`)
- ~~**`shell_kill` not in read-only guard**: state-changing operation bypasses Ask/Plan mode checks~~ (**FIXED** — `isReadOnlyShellTool()` includes `shell_kill`)
- **`findTool()` is O(n) linear scan**: should use hash map (`ToolExecutor.php:437-446`)

#### Minor
- `$autoApproved` / `$approvedById` built but never used — dead code (`AgentLoop.php:143-146`)
- `formatStatusModelLabel()` is a trivial passthrough (`AgentLoop.php:732-735`)
- Duplicate `performCompaction()` logic in two locations (`AgentLoop.php:364-372` vs `848-857`)
- `headlessPreFlightCheck()` is a trivial wrapper (`ContextManager.php:129-132`)
- `ContextPruner::importanceScore()` uses English-only phrases (`ContextPruner.php:194`)

---

### 2. Subagent Orchestration

**Files**: `src/Agent/SubagentOrchestrator.php` (665 lines), `SubagentFactory.php`, `SubagentStats.php`, `SubagentTool.php`

#### Critical
- **Potential deadlock in dependency + group combo**: If agent A depends on agent B, and both are in the same group (sequential), the group semaphore blocks A from starting while the dependency waits for A to run.
- **`SubagentTool` input validation**: empty task descriptions, malformed `depends_on` arrays, and circular references aren't validated before submission to the orchestrator.

#### Important
- **Retry logic doesn't distinguish transient vs permanent failures**: auth errors (401/403) correctly skipped, but malformed-request errors (400) may be retried unnecessarily.
- **Stats double-count tokens during retries**: each retry attempt adds to the token counter; no deduplication of pre-retry tokens.
- **Background agent results injected on next LLM turn**: if the parent never makes another LLM call (exits), background results are lost.
- **`SubagentStats::elapsed()` includes retry wait time**: makes timing metrics misleading.

#### Minor
- Agent ID uniqueness not enforced — collision possible if LLM reuses IDs across batches.
- No telemetry/observability hooks for orchestrator events.

---

### 3. TUI Renderer

**Files**: `src/UI/Tui/TuiCoreRenderer.php` (1169 lines), `TuiToolRenderer.php` (641 lines), `TuiModalManager.php` (513 lines), `TuiAnimationManager.php` (434 lines), `SubagentDisplayManager.php` (537 lines)

#### Critical
- **Modal stacking deadlock**: no mutex prevents `askToolPermission()` during `askUser()` (`TuiModalManager.php`)
- **`askUser()` cleanup bypassed on external resume**: QuestionWidget left in overlay when cancelled from `TuiCoreRenderer` (`TuiModalManager.php:130-149`)
- **`showToolResult` uses stale `lastToolArgs`**: concurrent tool calls overwrite each other's args (`TuiToolRenderer.php:194`)
- **`cycleMode()` breaks on unexpected label**: `array_search` returns `false` → silent wrong mode (`TuiCoreRenderer.php:903-911`)
- **Cancellation race in Thinking→Idle transition**: old cancelled token used after new one created (`TuiCoreRenderer.php:451-465`)
- **`showBatch()` filters by substring "spawned in background"**: real results containing this text are hidden (`SubagentDisplayManager.php:278`)

#### Important
- **`streamChunk` rebuilds MarkdownWidget on every token**: string concat + full markdown re-parse per chunk. Performance issue on long responses (`TuiCoreRenderer.php:543-544`)
- **Triple concurrent 30fps render timers**: breathing (33ms) + loader (50ms) + tool-executing (50ms) each trigger full re-render independently
- **No truncation for large tool outputs in TUI**: CollapsibleWidget stores full string in memory (`TuiToolRenderer.php:220-230`)
- **Binary/null bytes in tool outputs**: `explode("\n", $output)` produces garbled display (`TuiToolRenderer.php:220`)
- **`toolExecutingTimerId` leaks on error**: orphaned 50ms repeat timer runs indefinitely (`TuiToolRenderer.php:305-318`)
- **`compactingTimerId` not cancelled on Idle**: `enterIdle()` cancels thinking timer but not compacting timer (`TuiAnimationManager.php:347-364`)
- **Container widgets accumulate in conversation**: each `showSpawn()` adds a new ContainerWidget; old ones persist (`SubagentDisplayManager.php:126-128`)
- **Progress bar counts failed agents as "done"**: misleading progress percentage (`SubagentDisplayManager.php:254-264`)
- **`pendingEditorRestore` text lost on error**: typed input never restored if agent errors during streaming (`TuiCoreRenderer.php:416-419`)
- **`clearConversationState()` doesn't reset tool renderer state**: orphaned timers reference removed widgets (`TuiCoreRenderer.php:791-801`)
- **No terminal resize handling during streaming**: scroll offsets become stale
- **`setMaxVisibleLines(2)`**: too restrictive for multi-line editing (`TuiCoreRenderer.php:298`)
- **No input length limit in EditorWidget**: very long pastes create enormous text buffers
- **No command history (up/down arrow)**: only conversation scroll via PAGE_UP/PAGE_DOWN

#### Minor
- Spinner index increments indefinitely (`TuiAnimationManager.php:299`)
- ESC cancels during thinking — undocumented behavior
- `playAnimation()` stops/starts TUI without try/catch — TUI remains stopped on animation error
- `renderIntro()` uses blocking `usleep`/`sleep` on event loop

---

### 4. ANSI Renderer & Markdown

**Files**: `src/UI/Ansi/AnsiRenderer.php` (568 lines), `AnsiCoreRenderer.php`, `MarkdownToAnsi.php` (535 lines), `AnsiIntro.php` (611 lines), `AnsiTheogony.php` (2014 lines), `Theme.php`

#### Critical
- **AnsiTheogony: no skip/abort mechanism**: ~80 second unskippable animation (`AnsiTheogony.php`)
- **Screen shake bugs**: both branches produce same direction `\033[1B` (`AnsiTheogony.php:927`); up+down cancels out `\033[1A\033[1B` (`AnsiTheogony.php:1026`)

#### Important
- **No streaming output in ANSI mode**: user sees nothing until full response completes (`AnsiCoreRenderer.php:172-176`)
- **`clearThinking()` is a no-op**: "Thinking..." text never erased (`AnsiCoreRenderer.php:130-133`)
- **Status bar, welcome, separators overflow on narrow terminals**: fixed-width `━` bars assume ≥80 cols
- **Table rendering has no total-width overflow**: wide tables corrupt layout (`AnsiTableRenderer.php:22`)
- **All Theme colors designed for dark backgrounds only**: invisible on light terminals. No `COLORFGBG` detection
- **`wrapCodeLine()` is O(n²)**: `mb_substr(substr($line, $i), 0, 1)` per character (`MarkdownToAnsi.php:459-508`)
- **TableCollector drops nested inline elements**: links, images, strikethrough silently removed from table cells
- **Terminal size detection uses `exec('tput')` instead of `posix_get_terminal_size()`**: blocking, adds latency on SSH

#### Minor
- Duplicate `wrapAnsiText()` in `MarkdownToAnsi` and `ListTracker`
- Missing `declare(strict_types=1)` in `MarkdownToAnsi.php`
- `Theme::codeBg()` defined but never used in rendering
- Italic/strikethrough escape codes hardcoded instead of using Theme
- Logo constants duplicated between `AnsiIntro` and `AnsiTheogony`
- `ListTracker` uses `mb_strlen` instead of `mb_strwidth` for bullet indent
- `Theme::white()` uses 16-color `[1;37m` inconsistent with 24-bit RGB elsewhere

---

### 5. Tool System & Permission Model

**Files**: `src/Tool/Coding/File*.php`, `PatchApplier.php`, `Shell*.php`, `BashTool.php`, `GrepTool.php`, `GlobTool.php`, `src/Tool/Permission/*`

#### Critical
- **No path traversal protection**: `FileWriteTool`, `FileEditTool`, `FileReadTool` accept raw paths with zero project-root validation
- **Symlink following risk**: `PathResolver::resolve()` follows symlinks via `realpath()` — symlink to `/etc/shadow` inside project
- **Non-atomic writes in `FileWriteTool`**: `file_put_contents()` directly, no temp+rename
- **Permission system is opt-in per tool**: if tool not in `approval_required`, entire permission chain is bypassed
- **`PermissionEvaluator::evaluate()` defaults to Allow**: should default-deny for safety

#### Important
- **Temp file leak on crash**: `FileEditTool` creates `$path.'.tmp.'.getmypid()` with no cleanup (`FileEditTool.php:139`)
- **PatchApplier update non-atomic for moves**: write destination → unlink source; crash between = data duplication
- **Concurrent file edits: last-write-wins**: no file locking
- **PatchApplier line-ending corruption**: `implode("\n", ...)` on CRLF files inserts LF
- **Shell session idle cleanup only on tool calls**: if agent stops, sessions live forever (`ShellSessionManager.php:238-251`)
- **No max session limit**: malicious agent could exhaust file descriptors
- **`GrepTool` timeout declared but never used**: `$timeout = 30` is dead code (`GrepTool.php:19`)
- **Regex DoS possible in GrepTool**: `(.){1000000}` causes catastrophic backtracking in GNU grep
- **`SessionGrants` are per-tool, not per-path**: approving `bash` once auto-approves all future commands
- **`GuardianEvaluator::isInsideProject()` fails for project root itself**: trailing slash issue

#### Minor
- `FileReadTool` cache uses mtime (1-second granularity)
- No BOM handling in file tools
- `hasRipgrep()` spawns subprocess on every `GrepTool` call — should cache
- Binary file handling missing in grep
- GlobTool doesn't show permission-denied errors

---

### 6. LLM Client Layer

**Files**: `src/LLM/AsyncLlmClient.php`, `PrismService.php`, `RetryableLlmClient.php`, `ModelDefinitionSource.php`, `RelayProviderRegistry.php`

#### Critical
- **Provider lists can drift**: `AsyncLlmClient::OPENAI_COMPATIBLE_PROVIDERS` not checked by `LlmClientFactory` (`LlmClientFactory.php:45`)
- **Unlimited retries by default**: `$maxAttempts = 0` in production wiring (`LlmServiceProvider.php:81`)
- **Substring model matching**: `"gpt-4o-mini"` matches `"gpt-4o"` — wrong pricing/context (`ModelDefinitionSource.php:86-101`)

#### Important
- **`PrismService` drops `reasoningContent`**: thinking content lost for Anthropic/Gemini (`PrismService.php:111-120`)
- **No cancellation in `PrismService`**: `$cancellation` param documented as unused (`PrismService.php:107`)
- **Jitter always adds, never subtracts**: backoff is `base + [0, 0.3*base]`, not `base ± 0.3*base` (`RetryableLlmClient.php:132`)
- **No circuit breaker**: persistent failures retry forever
- **`smartDelay` blocking path**: `sleep()` in ANSI mode doesn't check cancellation during sleep
- **`cached_write_price` defaults to `input_price`**: Anthropic cache write is 1.25x, undercharged if missing from spec
- **Provider alias maps split between two classes**: can drift (`ModelDefinitionSource.php:25` vs `RelayProviderRegistry.php:213`)
- **No streaming support in `AsyncLlmClient`**: must unwrap via `inner()` — leaky abstraction

#### Minor
- No connection pool sharing between subagent clients
- `setApiKey()` accepts empty strings
- Timeout values hardcoded (600s/300s), not configurable
- Duplicated `supportsTemperature()` in both client classes

---

### 7. Session & Database Persistence

**Files**: `src/Session/Database.php`, `MessageRepository.php`, `MessageSerializer.php`, `SessionManager.php`, `MemoryRepository.php`, `MemorySelector.php`

#### Critical
- ~~**No `PRAGMA busy_timeout`**: concurrent writes crash with `SQLITE_BUSY` (`Database.php:38-39`)~~ (**FIXED** — `busy_timeout=5000` added)
- **Silent message loss on null tool_result**: message silently dropped → broken conversation → API errors (`MessageSerializer.php:109-111`)
- **No session/message deletion**: database grows unbounded
- **`forProject()` loads ALL memories**: no limit, O(n log n) sort every retrieval (`MemoryRepository.php:65-88`)

#### Important
- **`saveMessage()` silently no-ops when no session**: data loss with no warning (`SessionManager.php:115-117`)
- **Session switch doesn't validate target**: FK violation on first message save (`SessionManager.php:99-102`)
- **LIKE-based search, no FTS5**: full table scan per search (`MemoryRepository.php:186-192`)
- **Timestamp timezone mismatch in memory expiry**: `date('c')` produces timezone offsets, string comparison may break (`MemoryRepository.php:67`)
- **`loadActive()` loads all message content**: no pagination, multi-MB tool outputs in RAM (`MessageRepository.php:76-80`)
- **`markCompactedIds` not session-scoped**: cross-session compaction possible with leaked IDs (`MessageRepository.php:133-145`)
- **Role mismatch between `MessageMapper` and `MessageSerializer`**: `'tool'` vs `'tool_result'`
- **No role validation in `append()`**: invalid roles silently stored then dropped on deserialization

#### Minor
- Directory permissions 0755 on database directory
- `findByPrefix` uses LIKE without escaping `%`/`_`
- Timestamp precision mismatch: sessions (microseconds) vs messages (seconds)
- No session title sanitization
- `MemoryInjector` truncation at 180-240 chars with no truncation indicator
- Memory scoring uses undocumented magic numbers

---

### 8. Commands & Slash Commands

**Files**: `src/Command/AgentCommand.php`, `SlashCommandRegistry.php`, `Slash/*.php`

#### Critical
- **No signal handling**: Ctrl+C skips all cleanup — orphaned processes, broken terminal (`AgentCommand.php`)
- **QuitCommand double-teardown**: `teardown()` called twice if not idempotent (`QuitCommand.php:39` + `AgentCommand.php:299`)
- **`ResumeCommand` clears permissions but not mode**: mode mismatch after resume (`ResumeCommand.php:79`)
- **`FeedbackCommand` prompt injection**: user text interpolated directly into LLM prompt (`FeedbackCommand.php:57-72`)

#### Important
- **Unknown slash commands fall through to LLM**: `/typo something` sent as user message instead of error
- **TUI init failure leaves terminal in broken state**: alternate screen buffer, raw mode not restored (`AgentSessionBuilder.php:49-52`)
- **Whitespace-only input sent to LLM**: `"   "` not filtered
- **`NewCommand` doesn't cancel running subagents**: stale agents operate on new session (`NewCommand.php:40-48`)
- **`SessionFormatter::formatAge` assumes numeric timestamps**: ISO date strings produce wildly incorrect ages
- **`RenameCommand` inconsistent quote stripping**: single-quote regex missing `$` anchor
- **`ClearCommand` uses raw ANSI**: conflicts with TUI renderer state (`ClearCommand.php:48`)
- **`SettingsCommand` is 860+ lines**: severe maintenance concern
- **`CompactCommand` has no success/error feedback**: user gets no indication of result

#### Minor
- No `/help` command
- No duplicate registration detection in `SlashCommandRegistry`
- `/tasks clear` space-in-name creates prefix collision risk
- CJK width not accounted for in preview truncation
- `ForgetCommand` shows success for non-existent IDs
- `PowerCommandRegistry` regex only matches `\w+` — hyphens excluded

---

### 9. Settings & Configuration

**Files**: `src/Settings/SettingsManager.php`, `YamlConfigStore.php`, `SettingsSchema.php`, `ConfigLoader.php`, `src/Provider/*`

#### Critical
- ~~**`reloadRepository()` loses user/project YAML overrides**: only reloads bundled defaults (`SettingsManager.php:267-274`)~~ (**FIXED** — re-applies global and project YAML overrides after reload)
- ~~**Non-atomic config writes**: `file_put_contents()` without temp+rename (`YamlConfigStore.php:60`)~~ (still open)
- ~~**Audio config mutates shared LLM client**: `setProvider()`/`setModel()` on shared singleton (`SessionServiceProvider.php:56-65`)~~ (**FIXED** — dedicated `PrismService` instance for audio)
- **Migration rewrites YAML every boot**: non-atomic, no one-time flag (`DatabaseServiceProvider.php:92-145`)
- **Provider registration order is implicit**: hardcoded sequence, no dependency declaration (`Kernel.php:48-58`)
- **`LlmServiceProvider` captures stale config**: singletons don't reflect runtime settings changes

#### Important
- **Toggle normalization incomplete**: `"0"`, `"false"`, `"no"` not handled correctly (`SettingsManager.php:277-289`)
- **No change notification**: settings changes don't propagate to dependent components
- **Missing env vars resolve to empty string**: `${MISSING_KEY}` → `''` instead of `null` (`ConfigLoader.php:72-76`)
- **Malformed YAML crashes app**: no try/catch around `Yaml::parse()` (`YamlConfigStore.php:23-35`)
- **Config merge doesn't handle indexed arrays**: `mergeDeep()` appends instead of replacing for indexed arrays
- **`DatabaseServiceProvider::boot()` injects SQLite config after `RelayRegistry` already constructed**: stale config
- **No first-run config creation**: depends entirely on bundled defaults
- **Missing settings in schema**: ~10 config keys have no type validation or labels

#### Minor
- Static schema caching creates cross-instance coupling
- `SettingsPaths` instantiated repeatedly instead of cached
- Legacy `.kosmokrator.yaml` support adds complexity
- `LoggingServiceProvider` has side effects in `register()` instead of `boot()`

---

### 10. Diff Rendering & UI Display

**Files**: `src/UI/Diff/DiffRenderer.php` (548 lines), `AgentDisplayFormatter.php`, `AgentTreeBuilder.php`, `UIManager.php`, `Theme.php`

#### Critical
- **No binary file detection in DiffRenderer**: binary content produces garbled output (`DiffRenderer.php:33-166`)
- **No TUI→ANSI mid-session fallback**: renderer fixed at construction (`UIManager.php:27-29`)

#### Important
- **Line numbers for context lines use `$newLine` only**: old-file line number lost (`DiffRenderer.php:131`)
- **30+ hardcoded ANSI codes outside Theme**: inconsistent color shades across 8+ files
- **Color shade inconsistencies**: gold/accent, success, error, info all have different RGB values in hardcoded vs Theme
- **No terminal capability detection**: no `NO_COLOR`, `COLORTERM`, `TERM` checks
- **No large diff truncation**: thousands of changes flood terminal in ANSI mode
- **`padWithFileContext` first-match ambiguity**: duplicated code blocks match wrong occurrence
- **`str_pad` with multi-byte strings**: CJK under-padded
- **No depth limit on tree recursion**: stack overflow possible with deep nesting

#### Minor
- Hunk separator `· ✧ ·` has no Unicode fallback
- Missing Theme palette entries for 7 commonly-used colors
- `seedMockSession()` violates Liskov substitution
- Agent IDs not truncated — can produce very wide labels

---

### 11. Power Commands & UX Workflows

**Files**: `src/Command/Power/*.php` (21 commands), `src/UI/Ansi/Ansi*.php` (animation classes)

#### Critical
- **`:release` has no programmatic push guard**: prompt-only "ask before push" (`ReleaseCommand.php:78-79`)
- **`:unleash` can spawn 125+ agents**: no resource constraints or rate limiting (`UnleashCommand.php:47-48`)
- **No cancellation in animations**: `usleep()` blocks, no SIGINT handling during animations

#### Important
- **All power commands are purely prompt-driven**: no programmatic logic, all workflow enforcement via LLM compliance
- **`:autopilot` no loop guard**: Phase 5→3 re-entry has no max iteration count
- **`:babysit` no wall-clock timeout**: can run indefinitely
- **`:research` no cancellation guidance**: 7+ agents with no cleanup on cancel
- **`:release` no dry-run mode**: goes straight from version bump to push
- **18 commands registered manually**: no auto-discovery, adding a new command is error-prone
- **All animations use `register_shutdown_function(print(...))`**: `print` returns 1, may emit spurious "1"
- **No `KOSMOKRATOR_NO_ANIM` environment variable**: accessibility issue for screen readers/CI

#### Minor
- `:auto` alias too generic, could clash
- `:sci` alias too short/non-obvious
- `:watch` conflicts with Unix `watch` mental model
- Animation `exec('tput cols')` called per animation, not cached

---

### 12. Testing Coverage & Quality

**Files**: `tests/Unit/**/*.php` (~140 tests), `tests/Feature/AgentCommandTest.php` (1 test)

#### Critical
- **ContextManager has only 1 test**: core component with vast untested surface
- **No tool result ordering tests**: concurrent execution ordering completely unverified
- **No UTF-8 truncation tests**: `OutputTruncator` multi-byte handling untested
- **No integration tests for agent loop**: no end-to-end prompt→tool→response test

#### Important
- **5 pipeline/factory classes untested**: `ContextPipeline`, `ContextPipelineFactory`, `SubagentPipeline`, `SubagentPipelineFactory`, `LlmClientFactory`
- **21 Power commands have zero tests**
- **Session persistence lifecycle untested**: no create→persist→load round-trip test
- **`ProviderAuthService` untested**: handles API key/auth flows
- **`SessionSettingsApplier` untested**: applies settings to running sessions
- **Only 1 feature test**: `AgentCommandTest` just verifies exit code 0 with `/quit`

#### Minor
- StuckDetector missing oscillation pattern tests
- ToolExecutor missing UTF-8/malformed input tests
- No `tests/Integration/` or `tests/Functional/` directories
- No code coverage enforcement

---

## Cross-Cutting Themes

### 1. Static Mutable State (5 instances)
- `BashTool::$progressCallback` — race condition
- `SettingsSchema::$definitions` / `$aliases` — cross-instance pollution
- `ShellSessionManager` — no static state but shared instance with no cleanup guarantees
- **Pattern**: mutable statics in a concurrent (fiber-based) environment are dangerous. Each should be instance state or use fiber-local storage.

### 2. Non-Atomic File Operations (6 instances)
- `FileWriteTool` — `file_put_contents()` directly
- `YamlConfigStore` — `file_put_contents()` directly
- `PatchApplier::applyAdd()` — `file_put_contents()` directly
- `DatabaseServiceProvider::migrateYamlKeys()` — `file_put_contents()` directly
- `OutputTruncator::saveFull()` — no error handling
- `PatchApplier` move operations — write+unlink not atomic
- **Fix**: Extract a shared `AtomicFileWriter` utility that does write-to-temp + `rename()`.

### 3. Fragile String-Based Detection (4 instances)
- ~~`collectResult()` — `"Error:"` prefix for success detection~~ (**FIXED**)
- `isContextOverflow()` — string matching on error messages
- `showBatch()` — substring `"spawned in background"` for filtering
- `PermissionConfigParser` — tool name string matching for opt-in security
- **Fix**: Use typed result objects, error codes, or enums instead of string conventions.

### 4. Resource Leak Pattern (8 instances)
- Shell sessions — orphaned on crash
- TUI timer IDs — not cancelled on phase transitions
- Container widgets — accumulate indefinitely
- Memory objects — loaded entirely into RAM
- Database rows — no deletion mechanism
- Subagent processes — no cleanup on parent crash
- Editor text restore — lost on error exit
- Service singletons — no disposal lifecycle
- **Fix**: Implement a coordinated cleanup/teardown system with shutdown handlers.

### 5. Configuration Staleness (3 instances)
- `LlmServiceProvider` captures config at registration → stale singletons
- `SettingsManager::reloadRepository()` re-reads only bundled defaults → lost overrides
- `DatabaseServiceProvider::boot()` injects config after consumers constructed
- **Fix**: Implement config change notification (observer/event system) or use lazy resolution.

### 6. Hardcoded ANSI Color Codes (30+ instances)
Across 8+ files, colors bypass `Theme` with slightly different RGB values. This makes the palette inconsistent and unmaintainable.
- **Fix**: Add missing palette entries to `Theme`, replace all hardcoded codes with `Theme::` calls.

### 7. No Terminal Adaptation
- No color depth detection (16/256/24-bit)
- No Unicode fallback
- No light/dark terminal detection
- Fixed-width elements overflow on narrow terminals
- **Fix**: Add a `TerminalCapabilities` class that detects once at startup and is consulted by Theme.

---

## Security Concerns Summary

| # | Concern | Severity | Exploitability | File |
|---|---------|----------|---------------|------|
| 1 | File tools have no path containment | **Critical** | High — LLM can be tricked into writing outside project | `FileWriteTool.php:49` |
| 2 | Permission system defaults to Allow | **Critical** | Medium — requires misconfigured `approval_required` | `PermissionEvaluator.php:66-68` |
| 3 | SessionGrants are per-tool, not per-path | **High** | Medium — one approval grants all future operations | `SessionGrants.php:17-19` |
| 4 | Symlink following via `realpath()` | **High** | Low — requires symlink creation inside project | `PathResolver.php:27` |
| 5 | FeedbackCommand prompt injection | **High** | Medium — user text in LLM prompt | `FeedbackCommand.php:57-72` |
| 6 | Regex DoS in GrepTool | **Medium** | High — `(.){1000000}` pattern | `GrepTool.php:58` |
| 7 | GlobTool path traversal info leak | **Medium** | Low — can discover files outside project | `GlobTool.php:51` |
| 8 | API keys in config files with loose permissions | **Medium** | Medium — 0755 on config dir | `YamlConfigStore.php:46-61` |
| 9 | Config files written non-atomically | **Medium** | Low — race condition window | `YamlConfigStore.php:60` |
| 10 | Database directory world-readable | **Low** | Low — 0755 permissions | `Database.php:27` |

**Recommended Priority**:
1. Add path containment checks directly in file tools (don't rely solely on permission chain)
2. Switch `PermissionEvaluator` to default-deny
3. Make `SessionGrants` path/command-scoped
4. Add timeout enforcement to `GrepTool`
5. Set config file permissions explicitly (0600)

---

## Refactoring Backlog (Prioritized)

### P0 — Do Now (Bugs & Security)

| # | Refactoring | Effort | Impact |
|---|------------|--------|--------|
| 1 | Add `AtomicFileWriter` utility, use in `FileWriteTool`, `YamlConfigStore`, `PatchApplier` | 2h | Fixes 6 non-atomic write bugs |
| 2 | Add path containment check in file tools (validate against project root) | 1h | Critical security fix |
| ~~3~~ | ~~Fix `OutputTruncator::truncate()` to use `mb_strcut()` instead of `substr()`~~ | ~~15min~~ | **FIXED** |
| ~~4~~ | ~~Fix tool result ordering in `ToolExecutor` to match original call order~~ | ~~30min~~ | **FIXED** |
| ~~5~~ | ~~Add `PRAGMA busy_timeout=5000` to Database constructor~~ | ~~1 line~~ | **FIXED** |
| 6 | Set `maxAttempts` default to 3 in `RetryableLlmClient` or `LlmServiceProvider` | 1 line | Prevents infinite retry loops |
| ~~7~~ | ~~Fix `reloadRepository()` to re-merge all YAML layers~~ | ~~2h~~ | **FIXED** |
| ~~8~~ | ~~Fix audio config to clone LLM client instead of mutating shared singleton~~ | ~~30min~~ | **FIXED** |
| 9 | Add modal mutex in `TuiModalManager` | 1h | Prevents deadlock |

### P1 — Do Soon (Stability & UX)

| # | Refactoring | Effort | Impact |
|---|------------|--------|--------|
| 10 | Consolidate triple 30fps timers into single tick with phase-aware dispatch | 4h | Performance, CPU reduction |
| 11 | Add signal handler in `AgentCommand` for cleanup on SIGINT/SIGTERM | 2h | Prevents resource leaks |
| 12 | Add `TerminalCapabilities` detection class | 3h | Enables light/dark, color depth, Unicode fallbacks |
| 13 | Move 30+ hardcoded ANSI codes to `Theme` palette methods | 4h | Color consistency, maintainability |
| ~~14~~ | ~~Add `shell_kill` to read-only mode guard~~ | ~~5min~~ | **FIXED** — already in `isReadOnlyShellTool()` list |
| ~~15~~ | ~~Fix `collectResult()` to use typed error detection instead of string prefix~~ | ~~1h~~ | **FIXED** — uses `ToolCallMapper::isErrorResult()` |
| 16 | Add streaming output to ANSI renderer | 4h | Major UX improvement |
| 17 | Add `/help` command | 1h | Discoverability |
| 18 | Fix `PrismService` to pass through `reasoningContent` | 30min | Restores thinking content for Anthropic/Gemini |
| 19 | Add periodic cleanup timer for shell sessions | 1h | Prevents session leaks |
| 20 | Add AnsiTheogony skip mechanism (keypress detection) | 2h | UX — no more 80s unskippable animation |

### P2 — Do Eventually (Code Quality)

| # | Refactoring | Effort | Impact |
|---|------------|--------|--------|
| 21 | Split `SettingsCommand` (860 lines) into focused sub-commands | 8h | Maintainability |
| 22 | Split `AnsiTheogony` (2014 lines) into phase classes | 4h | Maintainability |
| 23 | Add integration test suite: agent loop, session persistence, permission flow | 8h | Test confidence |
| 24 | Implement config change notification system (events) | 4h | Settings propagation |
| 25 | Add `lazy()` resolution for LLM singletons to avoid stale config capture | 2h | Config freshness |
| 26 | Extract `wrapAnsiText()` to shared utility | 1h | DRY |
| 27 | Add depth limit to agent tree rendering | 30min | Safety |
| 28 | Cache `hasRipgrep()` result as static | 5min | Performance |
| 29 | Use hash map for `findTool()` instead of linear scan | 15min | Performance |
| 30 | Add `declare(strict_types=1)` to all files missing it | 2h | Type safety |

---

## 13. Subagent Orchestration (Deep)

**Files**: `src/Agent/SubagentOrchestrator.php` (665 lines), `SubagentFactory.php`, `SubagentTool.php`, `SubagentStats.php`

#### Critical
- **`yieldSlot`/`reclaimSlot` slot leak for root agent**: Root agent (`id='root'`) never acquires a global semaphore lock. Each `reclaimSlot('root')` consumes a slot permanently. After N calls (concurrency limit), all slots are consumed → deadlock. (`SubagentOrchestrator.php:471-496`)
- **`wouldCreateCycle` crashes on pruned stats**: Accesses `$this->stats[$current]->dependsOn` without existence check. Pruned agents cause TypeError. (`SubagentOrchestrator.php:375`)
- **Shared `ContextBudget` across parent and all children**: All subagents at all depths share the same `ContextBudget` instance. Deep child compaction deducts from root's pool. (`SubagentFactory.php:87`)
- **Shared `ProtectedContextBuilder` — mutable state leak**: Child agents' protected context entries appear in parent's context too. (`SubagentFactory.php:101`)

#### Important
- **`pruneCompleted` removes agents needed for dependency resolution**: New agents depending on pruned IDs get "Unknown dependency agent" errors.
- **Retry loop holds semaphore slot during delay**: Failing agent blocks a concurrency slot for 30+ seconds per retry.
- **Token double-counting during orchestrator-level retries**: Same stats object accumulates tokens across all retry attempts. Correct for total cost but misleading for per-attempt metrics.
- **`cancelAll()` does not clear `$this->cancellations`**: After cancel, array still references already-cancelled deferreds.

#### Minor
- `autoIdCounter` not thread-safe (safe under Amp cooperative scheduling but undocumented).
- `extractFailureMessage` doesn't traverse full previous-exception chain.

---

## 14. Error Handling & Resilience

**Codebase-wide scan of exception patterns, catch blocks, and recovery logic.**

#### Critical
- **No project-specific exception hierarchy**: Only 2 custom exceptions (`RetryableHttpException`, `IntroSkippedException`). All ~50+ other throws use bare `\RuntimeException` or `\InvalidArgumentException`. No `KosmokratorException` base class.
- **6 silently swallowed exceptions**: `TuiModalManager.php:343`, `TuiToolRenderer.php:363`, `DiffRenderer.php:539`, `UpdateChecker.php:132`, `SkillLoader.php:109`, `RetryableLlmClient.php:81` — all catch `\Throwable` with empty body or return, no logging.
- **Internal error messages leaked to LLM**: `$e->getMessage()` stored as assistant messages at `AgentLoop.php:288,312,518`, `ToolExecutor.php:313`, `AbstractTool.php:35`. No sanitization layer. Raw HTTP status codes, internal paths, provider details visible to the LLM.

#### Important
- **~25 overly broad `\Throwable` catches**: Should catch specific types. Catches `Error`, `TypeError`, `ParseError` which indicate programming bugs, not runtime failures.
- **Missing exception types for 5+ failure domains**: LLM/API failures, file operations, auth/OAuth, shell sessions, patch parsing vs application.
- **`runHeadless()` has no `finally` block**: Unlike `run()`, headless agent crashes don't reset UI phase.

#### Minor
- `SafeDisplay::call()` is an excellent pattern — prevents display errors from crashing execution.
- Tool error messages are generally well-crafted and actionable.

---

## 15. Type Safety & PHP 8.4 Patterns

**Codebase-wide scan of `declare(strict_types)`, return types, PHPStan config, modern PHP patterns.**

#### Important
- **~20 files missing `declare(strict_types=1)`**: Most critically `AgentLoop.php`, `AsyncLlmClient.php`, all `Tool/Coding/` tools (BashTool, FileWriteTool, FileEditTool, FileReadTool), `Kernel.php`, `PrismService.php`. No dangerous implicit coercions found — all explicit casts — but policy inconsistency.
- **PHPStan level 5** with 30+ ignore rules: Some hide real issues (Container/Application type mismatch). Should target level 7-8.
- **No PHP 8.4 property hooks or asymmetric visibility used**: Project targets `^8.4` but only uses `readonly` and union types.
- **~80 `@var` annotations**: Indicates areas where PHP's type system can't express constraints natively. Consider value objects for common shapes.

#### Minor
- All non-constructor methods have return type declarations — excellent.
- `mixed` return types only in 4 locations — all acceptable for generic config getters.
- `never` return type unused despite applicable exit() paths in CLI commands.

---

## 16. Kernel Bootstrap & Service Wiring

**Files**: `bin/kosmokrator`, `src/Kernel.php`, `src/Provider/*.php`

#### Critical
- **No error handling during boot**: `bin/kosmokrator` has zero try-catch blocks. `Kernel::boot()` doesn't wrap provider loops. Partial initialization on failure.
- **No signal handling anywhere in codebase**: No `pcntl_signal`. Ctrl+C = unclean death — no session save, no DB cleanup, no child process termination.
- **`LlmServiceProvider::registerPrism()` resolves services eagerly**: `PrismManager` and `RelayRegistry` resolved immediately during registration, not lazily. Any construction error is immediately fatal.
- **Undefined env vars silently resolve to empty string**: `${MISSING_KEY}` → `''` instead of `null`. Provider may attempt API calls with empty string as key.
- **No config validation**: `temperature: "warm"` passes through to LLM clients unchecked.

#### Important
- **Revolt error handler registered last in `boot()`**: Earlier async operations unprotected.
- **`DatabaseServiceProvider::boot()` performs file I/O**: `migrateYamlKeys()` reads/writes YAML during DI boot phase. Side-effect in boot is unexpected and risky.
- **Multiple config keys in code but absent from `kosmokrator.yaml`**: `max_tokens`, `audio_provider`, `audio_model`, `reasoning_effort`, etc. Defaults scattered across codebase.
- **`SettingsManager::reloadRepository()` re-parses all YAML on every write**: I/O-heavy, triggers on every `/set` command.

#### Minor
- Version resolution uses `shell_exec('git describe')` on every boot — could cache.
- `LaravelApp` (full Application class) used as plain DI container — heavier than needed.
- No scoped/transient bindings — all services are singletons.

---

## Updated Cross-Cutting Themes

### 8. No Graceful Shutdown (Systemic)
- **No `pcntl_signal` handling anywhere**: Ctrl+C = immediate process death.
- No `finally` blocks in `runHeadless()`.
- No shutdown handlers for shell sessions.
- No `__destruct()` on resource-heavy services.
- **Fix**: Add `pcntl_signal(SIGINT, ...)` handler in `Kernel::boot()` that triggers coordinated cleanup.

### 9. Exception Hygiene (Codebase-wide)
- Only 2 custom exceptions in 277 files.
- 6 silently swallowed `\Throwable` catches.
- Raw `$e->getMessage()` leaked to LLM in 5+ locations.
- ~25 overly broad catches that mask programming bugs.
- **Fix**: Create `KosmokratorException` hierarchy with 5-8 domain-specific types. Add error sanitization layer before LLM-facing messages.

### 10. Shared Mutable State in Subagent Tree
- `ContextBudget` shared across all agent depths.
- `ProtectedContextBuilder` shared — child mutations leak to parent.
- `yieldSlot`/`reclaimSlot` slot leak for root agent.
- **Fix**: Clone these objects per-subagent rather than sharing references.

---

## Updated Refactoring Backlog

### P0 — Add to existing P0 list

| # | Refactoring | Effort | Impact |
|---|------------|--------|--------|
| 31 | Fix `yieldSlot`/`reclaimSlot` for root agent: skip slot management for depth 0 | 1h | Prevents concurrency slot leak → deadlock |
| 32 | Clone `ContextBudget` and `ProtectedContextBuilder` per subagent | 2h | Prevents cross-agent context pollution |
| 33 | Add `KosmokratorException` base class + 5 domain subtypes | 3h | Enables proper catch granularity |
| 34 | Add error sanitization before LLM-facing messages | 2h | Prevents internal info leakage to LLM |
| 35 | Wrap `ensureSchema()` in transaction + add UNIQUE on schema_version | 30min | Prevents migration re-run bugs |
| 36 | Add `pcntl_signal` handler in Kernel for graceful shutdown | 4h | Systemic fix for resource leaks |

### P1 — Add to existing P1 list

| # | Refactoring | Effort | Impact |
|---|------------|--------|--------|
| 37 | Add existence check in `wouldCreateCycle` for pruned stats | 15min | Prevents TypeError crash |
| 38 | Log in all 6 silent `\Throwable` catches | 1h | Makes debugging possible |
| 39 | Bump PHPStan from level 5 to level 7 | 4h | Catches more type issues |
| 40 | Add `declare(strict_types=1)` to 20 missing files | 1h | Policy consistency |
| 41 | Add `pruneCompleted()` guard against in-use stats | 2h | Prevents "unknown dependency" errors |

---

## Final Statistics

| Dimension | Agents | Sub-agents | Critical | Important | Minor |
|-----------|--------|------------|----------|-----------|-------|
| AgentLoop Core | 1 | 4 | 4 | 11 | 5 |
| Subagent Orchestration | 1 | 4 | 5 | 6 | 4 |
| TUI Renderer | 1 | 5 | 8 | 15 | 8 |
| ANSI Renderer & Markdown | 1 | 4 | 2 | 8 | 8 |
| Tool System & Permissions | 1 | 4 | 5 | 9 | 8 |
| LLM Client Layer | 1 | 4 | 3 | 8 | 4 |
| Session & Database | 2 | 8 | 10 | 16 | 12 |
| Commands & Slash Commands | 1 | 4 | 4 | 11 | 9 |
| Settings & Configuration | 1 | 3 | 6 | 8 | 5 |
| Diff & UI Display | 1 | 3 | 3 | 10 | 6 |
| Power Commands & UX | 1 | 4 | 3 | 12 | 8 |
| Testing Coverage | 1 | 4 | 4 | 5 | 5 |
| Error Handling | 1 | 4 | 3 | 2 | 2 |
| Type Safety | 1 | 3 | 0 | 3 | 4 |
| Kernel Bootstrap | 1 | 3 | 5 | 4 | 3 |
| **Total** | **16** | **~62** | **65** | **128** | **91** |

---

*Audit completed 2026-04-04. Generated by 16 parallel exploration agents spawning ~62 sub-agents across 20 audit dimensions. 284 total findings.*
