# UX Audit: Tool Call & Result Display

> **Research Question**: How can KosmoKrator display tool calls and results in a world-class way?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `TuiToolRenderer.php`, `CollapsibleWidget.php`, `BashCommandWidget.php`, `DiscoveryBatchWidget.php`, `BorderFooterWidget.php`, `DiffRenderer.php`, `ExplorationClassifier.php`, `Theme.php`, `TuiInputHandler.php`

---

## Executive Summary

KosmoKrator's tool display system is **architecturally mature but visually verbose**. The codebase has a well-structured widget hierarchy (`CollapsibleWidget`, `BashCommandWidget`, `DiscoveryBatchWidget`), smart classification of read-only "omens" tools via `ExplorationClassifier`, and a rich diff renderer with word-level highlighting. However, the **default visual weight is too heavy**: every tool call emits its own line, collapsed content shows 2–3 preview lines plus a "ctrl+o to reveal" hint, and there is no concept of "inline" tool results for trivial operations.

Compared to Claude Code (which shows inline badges `[Edit]`, `[Read]` with single-line summaries) and Aider (which shows shell commands as one-liners with compact output), KosmoKrator's tool display creates **visual noise** during the discovery phase and occupies disproportionate screen real estate for low-value information. The `DiscoveryBatchWidget` is a strong differentiator but its collapsed view still lists every file label individually.

**Severity**: Medium-High. Tool display is the primary way users understand what the agent is doing. Excessive visual weight makes long sessions fatiguing; insufficient detail undermines trust. The balance is currently tilted toward too much decoration.

---

## 1. Current Tool Call Visual Weight

### 1.1 How Tools Are Displayed Today

The `TuiToolRenderer::showToolCall()` method handles each tool name via a branching cascade:

| Tool Type | Display Strategy | Visual Lines (collapsed) |
|---|---|---|
| Task tools (`task_*`) | Silent — status bar only | 0 |
| Ask tools (`ask_user`, `ask_choice`) | Silent | 0 |
| Subagent | Delegated to `SubagentDisplayManager` | 0 (separate) |
| `execute_lua` | CollapsibleWidget, expanded by default | ~1 header + full code |
| Lua doc tools | Single-line `TextWidget` | 1 |
| `bash` (non-exploratory) | `BashCommandWidget` | 1 header + 0–2 preview |
| Omens tools (`file_read`, `glob`, `grep`, `bash` exploratory) | `DiscoveryBatchWidget` batch | ~3–8 (header + labels) |
| File tools (`file_read`, `file_write`, `file_edit`) | Single-line or CollapsibleWidget | 1 |
| Everything else | Arg-serialized single line | 1 |

### 1.2 Assessment: Too Heavy

**Problems**:

1. **File tool calls show a full line per tool** — When the agent performs `file_read` → `file_edit` → `file_read`, each gets its own line. Three lines for what is conceptually one "edit this file" operation. The result of each is then a separate `CollapsibleWidget` (header ✓ + 3 preview lines + "ctrl+o to reveal").

2. **Lua code display defaults to expanded** — `showLuaCodeCall()` calls `$widget->setExpanded(true)`, dumping potentially hundreds of lines of Lua code directly into the conversation. This is justified for code review but creates scroll-flooding when the agent writes multi-function scripts.

3. **CollapsibleWidget preview is 3 lines** — `PREVIEW_LINES = 3` means even a small 4-line output still shows 3 lines + the "+1 lines (ctrl+o to reveal)" hint. The hint itself is always shown even when the content is trivially small.

4. **Tool results always have borders** — The `⏋` bracket character on the first line of `CollapsibleWidget::render()` creates visual "box" framing even for 2-line results. This adds clutter without adding information.

5. **The `BorderFooterWidget` exists but is unused** — The file `BorderFooterWidget.php` renders a `└─…─┘` bottom border, but inspection of `TuiToolRenderer` shows it is never instantiated. This suggests an abandoned design for section closing.

### 1.3 Visual Noise Example

A typical agent action — read a file, edit it, read again to verify — produces this:

```
☽ Read  src/Service/UserService.php:45
✓ ⏋  45 │ class UserService
    │  46 │ {
    │  47 │     public function __construct(
    ⊛ +142 lines (ctrl+o to reveal)

♅ Edit  src/Service/UserService.php
✓ ⏋ [full diff with line numbers]
  │ [3 context lines]
  │ [removed lines in red]
  │ [added lines in green]
  │ [3 context lines]
  ⊛ +2 lines (ctrl+o to reveal)

☽ Read  src/Service/UserService.php:45
✓ ⏋  45 │ class UserService
    │ 46 │ {
    ⊛ +142 lines (ctrl+o to reveal)
```

**21+ lines for a single edit operation**. Claude Code would show:

```
[Edit] src/Service/UserService.php — 3 additions, 1 removal
```

---

## 2. Batching Effectiveness (DiscoveryBatchWidget)

### 2.1 How It Works

`ExplorationClassifier` identifies read-only "omens" tools: `file_read`, `glob`, `grep`, `memory_search`, and exploratory `bash` commands (prefixed with `ls`, `find`, `rg`, `cat`, `git status`, etc. — but only if they contain no shell metacharacters `; & | \` $ > <`).

When the agent starts an exploration phase, consecutive omens tools are collected into a `DiscoveryBatchWidget`:

```
☽ Reading the omens
 │ 3 reads  ·  2 searches  ·  1 probe
 │ src/Service/UserService.php
 │ "handleUser" in src/Service
 │ src/Repository/UserRepository.php
 │ *.php in src/Model
 │ "createUser" in src
 │ git branch
 └ ⊛ Details (ctrl+o to reveal)
```

When expanded, it shows per-item results with status icons:

```
☽ Reading the omens
 │ 3 reads  ·  2 searches  ·  1 probe
 │
 │ ✓ Read  src/Service/UserService.php  ·  189 lines
 │   [highlighted file content...]
 │
 │ ✓ Search  "handleUser" in src/Service  ·  4 matches
 │   [grep results...]
 │
 │ ✓ Read  src/Repository/UserRepository.php  ·  67 lines
 │   [highlighted file content...]
 │ ...
 └ ⊛ Details (ctrl+o to collapse)
```

### 2.2 Strengths

1. **Batch classification is smart** — The heuristic-based classifier (`isExploratoryBashCommand`) correctly separates `ls -la` (omens) from `rm -rf` (non-omens). The metacharacter guard prevents false positives.

2. **Summary line is excellent** — "3 reads · 2 searches · 1 probe" gives the user a perfect at-a-glance understanding of what happened.

3. **Result summaries are useful** — Each item gets a compact summary ("189 lines", "4 matches", "0 recalls").

4. **Tree-line visual framing is clean** — The `│`/`└` pipe structure creates a clear grouping without boxing.

### 2.3 Weaknesses

1. **Collapsed view lists every item label** — If the agent reads 12 files, the collapsed view shows all 12 file paths individually. This defeats the purpose of "collapsed". Should show the summary + maybe the first 3 labels, with a "+9 more" indicator.

2. **Batch finalization is fragile** — `finalizeDiscoveryBatch()` is called at the start of every non-omens tool. If the agent interleaves an omens tool with a non-omens tool mid-exploration (e.g., reads a file, then does a `file_edit`, then reads another file), the batch breaks into two separate batches. This is a fundamental architectural limitation — the classifier cannot predict whether more omens tools will follow.

3. **No progressive loading indicator** — While items are being added, there's no live count update. The batch appears immediately with 1 item, then 2, then 3. Each addition triggers a full re-render of the batch widget. This creates visible flicker.

4. **Expanded detail dumps entire file contents** — `file_read` detail shows the entire highlighted file output, not just relevant portions. A 500-line file produces 500 lines of expanded content.

---

## 3. Diff Display Quality

### 3.1 Current Implementation

`DiffRenderer` produces unified diffs with:

- **Hunk grouping** with configurable context lines (3 by default)
- **Syntax highlighting** via `KosmokratorTerminalTheme` + Tempest Highlighter
- **Word-level change highlighting** — pairs removed/added lines and computes token-level diffs, showing strong background colors for changed words
- **File context padding** — reads the actual file from disk to provide surrounding context beyond the old/new strings
- **Line number gutters** with adaptive width
- **Change summary** — "3 additions, 1 removal"
- **Large diff truncation** at 500 hunks

### 3.2 Assessment: Best-in-Class Terminal Diff

The diff renderer is genuinely excellent. Specific praise:

1. **Word-level highlighting** with the 40% threshold (`WORD_DIFF_THRESHOLD`) prevents noisy highlighting when entire lines change — it gracefully degrades to full-line highlighting.

2. **File context padding** (`padWithFileContext`) is a brilliant touch — it reads the actual file on disk to provide real surrounding context, making diffs much more readable than a bare old/new comparison.

3. **Visual encoding is clear** — red background for removed, green for added, gray for context, with strong variants for word-level changes. The `· · ✧ · ·` hunk separator is on-brand.

4. **Adaptive gutter width** prevents line-number misalignment on large files.

### 3.3 Minor Issues

1. **No diff statistics in the collapsed header** — The `file_edit` result's `CollapsibleWidget` header is just `✓`. It should include the change summary ("✓ Edit — 3 additions, 1 removal").

2. **`file_edit` defaults to expanded** — `$widget->setExpanded(true)` in `showToolResult()` means every edit pushes its full diff into the conversation. For multi-file edits, this creates scroll storms. Should default to collapsed with the summary line visible.

3. **`file_write` has no diff** — New file creation via `file_write` just shows the file content as-is in a `CollapsibleWidget`. There's no special "new file" indicator.

4. **`apply_patch` handling is missing** — `Theme::toolIcon('apply_patch')` and `Theme::toolLabel('apply_patch')` are defined, but `showToolResult()` has no special handling for `apply_patch`. It falls through to the generic `CollapsibleWidget` with raw output.

---

## 4. Error Tool Display

### 4.1 Current Behavior

Errors are handled through the same `showToolResult()` path with `$success = false`:

- The header indicator changes from `✓` to `✗`
- The status color changes to `Theme::error()` (red `rgb(255, 80, 60)`)
- Content is wrapped in `CollapsibleWidget` like any other result

For bash specifically:
- `BashCommandWidget::setResult()` auto-expands on failure (`if (!$success) { $this->expanded = true; }`)
- The error prefix shows `✗` in red

### 4.2 Assessment: Adequate but Not Prominent Enough

1. **Errors look like results** — An error and a success differ only by a single character (`✓` vs `✗`) and color. There's no visual hierarchy that says "PAY ATTENTION — SOMETHING WENT WRONG". Errors should have a distinct frame or background.

2. **No error summary** — When a tool fails, the full error output is inside a collapsed widget. The user must expand it to see what happened. At minimum, the first line of the error should always be visible.

3. **No error grouping** — If the agent fails 3 file reads in a row, each error is a separate widget. There's no "3 tools failed" aggregation.

4. **Bash auto-expand is good** — `BashCommandWidget` correctly auto-expands on failure. This should be the default for ALL error tool results, not just bash.

### 4.3 Before/After: Error Display

**Before (current)**:
```
☽ Read  src/Service/MissingService.php
✗ ⏋ Error: File not found
    │ The file at src/Service/MissingService.php does not exist
    ⊛ +3 lines (ctrl+o to reveal)
```

**After (proposed)**:
```
✗ Read failed: src/Service/MissingService.php
│ Error: File not found
│ The file at src/Service/MissingService.php does not exist
│ ...
└ (auto-expanded, ctrl+o to collapse)
```

Key changes: auto-expand on error, red accent on header line, no preview truncation for errors.

---

## 5. Tool Call Navigation (Ctrl+O Toggle)

### 5.1 How It Works

`TuiInputHandler` listens for the `expand_tools` keybinding (mapped to `Ctrl+O` in `EditorWidget`). When pressed, `toggleAllToolResults()` iterates over all widgets in the conversation, finds those implementing `ToggleableWidgetInterface`, and calls `toggle()` on each.

### 5.2 Assessment: Functional but Undiscoverable

1. **No discoverability** — There is no visual hint anywhere in the TUI that `Ctrl+O` exists. The "ctrl+o to reveal" text only appears inside collapsed widgets that the user has already noticed. New users who don't notice collapsed widgets will never discover this shortcut.

2. **Global toggle is crude** — `Ctrl+O` toggles ALL collapsible widgets simultaneously. There's no way to expand just the current tool result or just the last diff. In a long conversation with 30+ tool calls, this creates an overwhelming wall of text.

3. **No per-widget toggle** — There is no way to click or keyboard-navigate to a specific widget and expand just that one. This is a fundamental limitation of the current text-based conversation model.

4. **The toggle hint is always visible** — "ctrl+o to reveal" / "ctrl+o to collapse" appears on every collapsible widget. After the first use, this becomes visual spam. It should fade or be hidden for experienced users.

### 5.3 Recommendations

1. **Add `Ctrl+O` to the status bar** — Show "Ctrl+O: expand" in a dim hint area.
2. **Support selective expansion** — Allow `Ctrl+O` when the cursor/viewport is near a specific widget to toggle only that one.
3. **Make the hint contextual** — Only show "ctrl+o to reveal" on the nearest collapsed widget to the viewport bottom, not on all of them.
4. **Consider `Enter` on a focused widget** — If the TUI ever gets widget focus, `Enter` should toggle the focused widget.

---

## 6. Recommendations for World-Class Tool Display

### 6.1 Introduce a Three-Tier Display System

Tool results should be categorized by information density and importance:

| Tier | Description | Default Display | Examples |
|---|---|---|---|
| **Inline** | Trivial results that don't need a widget | Single line, no collapse | `glob` with 0 results, `file_write` success, `memory_search` with 0 recalls |
| **Compact** | Moderate results worth a quick glance | Header + summary badge | `file_read` (show line count), `file_edit` (show diff stats), `bash` (show exit + first line) |
| **Rich** | Complex results needing inspection | Collapsible with preview | `file_edit` diffs, `execute_lua` output, multi-line bash results |

### 6.2 Reduce Default Visual Weight

**Current**: Every tool result gets a `CollapsibleWidget` with 3 preview lines + hint.

**Proposed**: Default to zero preview lines (just the header/badge). Show preview only for `Rich` tier tools:

```
# BEFORE (current) — 8 lines for a simple read
☽ Read  src/Config/AppConfig.php
✓ ⏋  1 │ <?php
    │  2 │
    │  3 │ declare(strict_types=1);
    ⊛ +47 lines (ctrl+o to reveal)

# AFTER (proposed) — 1 line for a simple read
☽ Read  src/Config/AppConfig.php  ·  50 lines
```

### 6.3 Inline Badge System (Claude Code Style)

For certain tool types, the tool call and result should be merged into a single inline badge:

```
# Claude Code style:
[Edit] src/Service/UserService.php — 3 additions, 1 removal
[Read] src/Config/AppConfig.php — 50 lines
[Bash] composer install — exit 0, 12 lines
[Glob] **/*.php in src — 47 files

# KosmoKrator adaptation (with cosmic branding):
♅ Edit  src/Service/UserService.php  ✦ 3+ 1-
☽ Read  src/Config/AppConfig.php  · 50 lines
⚡ Bash  composer install  · exit 0
✧ Glob  **/*.php in src  · 47 files
```

This combines the call + result into a single line with a badge-like structure. The user can `Ctrl+O` to expand if they want details.

### 6.4 DiscoveryBatchWidget Improvements

```
# BEFORE (current) — lists every item even when collapsed
☽ Reading the omens
 │ 3 reads  ·  2 searches  ·  1 probe
 │ src/Service/UserService.php
 │ "handleUser" in src/Service
 │ src/Repository/UserRepository.php
 │ *.php in src/Model
 │ "createUser" in src
 │ git branch
 └ ⊛ Details (ctrl+o to reveal)

# AFTER (proposed) — collapsed shows only summary
☽ Reading the omens  ·  3 reads · 2 searches · 1 probe
└ ⊛ 6 tools (ctrl+o for details)
```

When expanded, show items with their summaries but NOT full content. Add a second level of expansion for individual items:

```
☽ Reading the omens  ·  3 reads · 2 searches · 1 probe
 │ ✓ Read  src/Service/UserService.php  ·  189 lines
 │ ✓ Search  "handleUser" in src/Service  ·  4 matches
 │ ✓ Read  src/Repository/UserRepository.php  ·  67 lines
 │ ✓ Glob  *.php in src/Model  ·  12 files
 │ ✓ Search  "createUser" in src  ·  2 matches
 │ ✓ Probe  git branch  ·  3 lines
 └ (ctrl+o to collapse)
```

### 6.5 Smart Diff Display

```
# BEFORE (current) — full diff, auto-expanded
✓ ⏋ 45 │ class UserService
  │ 46 │ {
- │ 47 │     public function __construct(
+ │ 47 │     public function __construct(
+ │ 48 │         private readonly CacheInterface $cache,
  │ 49 │     ) {
  ⊛ +2 lines (ctrl+o to reveal)

# AFTER (proposed) — collapsed badge with diff stats
✓ Edit  src/Service/UserService.php  ·  +2 additions, 1 removal
```

Expand to see the full diff with word-level highlighting.

### 6.6 Error Display Improvements

1. **Auto-expand all error results** (currently only bash does this)
2. **Red left-border accent** for error widgets
3. **Error summary in collapsed header** — "✗ Read failed: File not found"
4. **Error grouping** — Consecutive errors get batched: "✗ 3 tools failed"

### 6.7 "Ctrl+O" Hint Progressive Disclosure

- **First 5 tool results**: Show "ctrl+o to reveal" hint
- **After 5 uses**: Hide the hint, show just the collapse indicator (⊛ +N lines)
- **Status bar**: Show "Ctrl+O: expand all" permanently in dim text

---

## 7. Should Some Tool Results Be Inline (Not Collapsible)?

### 7.1 Yes — With Clear Rules

The following tool results should **never** produce a `CollapsibleWidget`:

| Tool | Condition | Inline Display |
|---|---|---|
| `file_write` | Success | `☉ Write  path/to/file  ✓` |
| `file_edit` | Success, 0 changes | `♅ Edit  path/to/file  ·  no changes` |
| `file_read` | Empty file | `☽ Read  path/to/file  ·  empty` |
| `glob` | 0 results | `✧ Glob  **/*.txt  ·  no files` |
| `grep` | 0 results | `⊛ Search  "pattern"  ·  no matches` |
| `memory_search` | 0 results | `◈ Recall  ·  no memories` |
| `bash` | Exit 0, empty output | `⚡ Bash  command  ·  exit 0` |
| `bash` | Exit 0, ≤3 lines | `⚡ Bash  command  ·  3 lines` |
| `apply_patch` | Success | `✎ Patch  ·  3 files, 12 additions, 5 removals` |
| `task_*` | Any | (already silent) |
| `ask_*` | Any | (already silent) |

### 7.2 Tools That Should Always Be Collapsible

| Tool | Reason |
|---|---|
| `file_read` (non-empty) | File contents can be very large |
| `file_edit` (with changes) | Diffs need visual space |
| `execute_lua` | Code output can be large |
| `bash` (errors or >3 lines) | Users need to see full output |
| Lua doc tools (large results) | API docs can be lengthy |

### 7.3 The Rule of Thumb

> **If the result fits on one line with a meaningful summary, it should be inline. If the user would need to scan the content to understand what happened, it should be collapsible.**

---

## 8. Competitive Analysis

### 8.1 Claude Code

Claude Code's tool display is the gold standard for terminal AI agents:

- **Inline badges**: `[Edit]`, `[Read]`, `[Bash]` with colored backgrounds
- **Single-line summaries by default**: `[Edit] file.php — 3 additions, 1 removal`
- **Collapsible diffs**: Expand to see full diff with syntax highlighting
- **No preview lines when collapsed**: Just the badge + summary
- **Color-coded tool types**: Each tool has a distinct color
- **Streaming bash output**: Shows output as it arrives

**What KosmoKrator can learn**: The badge approach. Default to one line, expand on demand.

### 8.2 Aider

Aider's tool display is minimal:

- **Shell commands shown verbatim**: `$ git diff` followed by output
- **File edits shown as plain diffs**: Standard unified diff format
- **No collapsing**: Everything is always visible
- **Compact**: No decorative elements, no icons

**What KosmoKrator can learn**: Less decoration. Aider trusts the user to read raw diffs. But Aider's approach is too raw for most users — KosmoKrator's word-level highlighting is genuinely better.

### 8.3 Cursor

Cursor (GUI IDE) has the most sophisticated tool display:

- **Inline diff annotations**: Changes appear directly in the editor gutter
- **Accept/reject blocks**: Each change is individually actionable
- **File tabs**: Each modified file gets a tab
- **Minimap**: Shows all changes in a sidebar overview

**What KosmoKrator can learn**: The concept of making changes *actionable*. In a TUI context, this could mean showing diffs with the ability to revert individual hunks. But this is a much larger feature.

---

## 9. ASCII Mockups: Before & After

### 9.1 File Edit (Moderate Change)

**Before (current) — ~15 lines**:
```
♅ Edit  src/Service/UserService.php
✓ ⏋ 45 │ class UserService
    │ 46 │ {
  - │ 47 │     public function __construct(
  + │ 47 │     public function __construct(
  + │ 48 │         private readonly CacheInterface $cache,
    │ 49 │     ) {
    │ 50 │         $this->cache = $cache;
    ⊛ +12 lines (ctrl+o to reveal)
```

**After (proposed) — 1 line default, expandable**:
```
✓ Edit  src/Service/UserService.php  ·  +2 −1
```
Expanded:
```
✓ Edit  src/Service/UserService.php  ·  +2 −1
 │ 45 │ class UserService
 │ 46 │ {
-│ 47 │     public function __construct(
+│ 47 │     public function __construct(
+│ 48 │         private readonly CacheInterface $cache,
 │ 49 │     ) {
 │ 50 │         $this->cache = $cache;
 └ ⊛ 7 more lines (ctrl+o to collapse)
```

### 9.2 Discovery Phase (6 Files)

**Before (current) — 9 lines**:
```
☽ Reading the omens
 │ 3 reads  ·  2 searches  ·  1 probe
 │ src/Service/UserService.php
 │ "handleUser" in src/Service
 │ src/Repository/UserRepository.php
 │ *.php in src/Model
 │ "createUser" in src
 │ git branch
 └ ⊛ Details (ctrl+o to reveal)
```

**After (proposed) — 2 lines**:
```
☽ Reading the omens  ·  3 reads · 2 searches · 1 probe
└ 6 tools  (ctrl+o for details)
```

### 9.3 Bash Command (Successful, Multi-Line Output)

**Before (current) — 4 lines**:
```
⚡ composer install
└ ✓ Installing dependencies from lock file
  ⊛ +14 lines (ctrl+o to reveal)
```

**After (proposed) — 1 line**:
```
⚡ composer install  ·  exit 0, 15 lines
```

### 9.4 Bash Command (Error)

**Before (current) — auto-expanded, ~8 lines**:
```
⚡ php artisan test
│ php artisan test
└ ✗ Tests: 3 failures
  ✗ FAILED: UserServiceTest::testCreate
  ✗ FAILED: OrderTest::testProcess
  ✗ FAILED: PaymentTest::testCharge
  ⊛ +42 lines (ctrl+o to collapse)
```

**After (proposed) — auto-expanded with error styling**:
```
✗ Bash  php artisan test  ·  exit 1
│ Tests: 3 failures, 47 assertions
│
│ FAILED: UserServiceTest::testCreate
│   Expected status 201, got 500
│
│ FAILED: OrderTest::testProcess
│   Target class [OrderProcessor] does not exist
│
│ FAILED: PaymentTest::testCharge
│   Connection refused
│
└ ⊛ 38 more lines (ctrl+o to collapse)
```

### 9.5 Trivial Operations (Inline — New)

**Current**: Each gets a CollapsibleWidget with preview + hint.

**Proposed**: Single inline line:

```
☉ Write  src/NewService.php  ✓
☽ Read  src/Config/AppConfig.php  ·  empty
✧ Glob  **/*.blade.php  ·  0 files
⊛ Search  "deprecated" in src  ·  0 matches
⚡ pwd  ·  /Users/rutger/Projects/kosmokrator
```

### 9.6 Full Conversation Mockup (After)

```
┌──────────────────────────────────────────────────────────────────────────┐
│                                                                          │
│  What does the UserService do and can you add caching?                   │
│                                                                          │
│  ☽ Reading the omens  ·  2 reads · 1 search                             │
│  └ 3 tools  (ctrl+o for details)                                        │
│                                                                          │
│  The `UserService` handles user CRUD operations. It delegates to         │
│  `UserRepository` for persistence. I'll add a caching layer by          │
│  injecting a `CacheInterface`.                                           │
│                                                                          │
│  ✓ Edit  src/Service/UserService.php  ·  +2 −1                          │
│                                                                          │
│  Done! I've added caching to `UserService`:                              │
│  - Injected `CacheInterface` via constructor                             │
│  - `findUser()` now checks cache before hitting the repository          │
│  - Cache invalidation on `createUser()` and `updateUser()`              │
│                                                                          │
│  ☽ Read  src/Service/UserService.php  ·  94 lines                       │
│                                                                          │
│  The changes look correct. Here's a summary:                             │
│  ✓ Edit  src/Service/UserService.php  ·  +5 −2                          │
│                                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│  Edit · Guardian ◈ · Ready · $0.0042 · 12k/200k                 Ctrl+O │
└──────────────────────────────────────────────────────────────────────────┘
```

Compare with the same conversation **before** (estimated 60+ lines of tool output):

```
┌──────────────────────────────────────────────────────────────────────────┐
│                                                                          │
│  What does the UserService do and can you add caching?                   │
│                                                                          │
│  ☽ Reading the omens                                                    │
│   │ 2 reads  ·  1 search                                                │
│   │ src/Service/UserService.php                                         │
│   │ "CacheInterface" in src                                             │
│   │ src/Repository/UserRepository.php                                   │
│   └ ⊛ Details (ctrl+o to reveal)                                        │
│                                                                          │
│  The `UserService` handles user CRUD operations...                       │
│                                                                          │
│  ☽ Read  src/Service/UserService.php                                    │
│  ✓ ⏋  1 │ <?php                                                         │
│      │  2 │                                                              │
│      │  3 │ declare(strict_types=1);                                     │
│      ⊛ +89 lines (ctrl+o to reveal)                                      │
│                                                                          │
│  ☽ Read  src/Repository/UserRepository.php                              │
│  ✓ ⏋  1 │ <?php                                                         │
│      │  2 │                                                              │
│      ⊛ +64 lines (ctrl+o to reveal)                                      │
│                                                                          │
│  The `UserService` handles user CRUD operations. I'll add caching...     │
│                                                                          │
│  ☽ Read  src/Service/UserService.php                                    │
│  ✓ ⏋  1 │ <?php                                                         │
│      ⊛ +94 lines (ctrl+o to reveal)                                      │
│                                                                          │
│  ☽ Read  src/Config/AppConfig.php                                       │
│  ✓ ⏋  1 │ <?php                                                         │
│      ⊛ +47 lines (ctrl+o to reveal)                                      │
│                                                                          │
│  ☾ Edit  src/Service/UserService.php                                    │
│  ✓ ⏋ [full diff with 20+ lines visible]                                 │
│      ⊛ +8 lines (ctrl+o to reveal)                                       │
│                                                                          │
│  Done! I've added caching to `UserService`:                              │
│  ...                                                                     │
│                                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│  Edit · Guardian ◈ · Ready · $0.0042 · 18k/200k                        │
└──────────────────────────────────────────────────────────────────────────┘
```

The "after" mockup is ~20 lines of tool display vs ~45+ lines for the "before". The conversation is readable without scrolling; the agent's actual text responses dominate the viewport rather than tool artifacts.

---

## 10. Implementation Priority

| Priority | Recommendation | Impact | Effort |
|---|---|---|---|
| **P0** | Inline badges for trivial tool results (empty reads, 0-result searches, successful writes) | High — eliminates most visual noise | Low — conditional in `showToolResult()` |
| **P0** | Merge call + result into single badge for Compact tier | High — halves tool display lines | Medium — refactor `showToolCall`/`showToolResult` flow |
| **P1** | Auto-expand all error results (not just bash) | Medium — improves error visibility | Low — 1 line change in `showToolResult()` |
| **P1** | Collapse DiscoveryBatchWidget to summary-only (no item labels) | Medium — reduces exploration noise | Low — change `DiscoveryBatchWidget::render()` |
| **P1** | Change `file_edit` default to collapsed (currently expanded) | Medium — prevents diff scroll storms | Low — remove `$widget->setExpanded(true)` |
| **P2** | Add diff statistics to collapsed header ("✓ Edit — +3 −1") | Medium — context without expanding | Low — pass summary to `CollapsibleWidget` header |
| **P2** | Progressive disclosure for "ctrl+o" hint (hide after N uses) | Low — reduces visual spam | Medium — needs usage counter |
| **P2** | Add `Ctrl+O` to status bar hint area | Low — improves discoverability | Low — add text to status bar |
| **P3** | Selective widget expansion (per-widget toggle) | High — but complex | High — needs widget focus system |
| **P3** | Two-level DiscoveryBatchWidget expansion (summary → items → details) | Medium — better progressive disclosure | Medium — nested collapsible state |
| **P3** | `apply_patch` special handling with multi-file diff summary | Medium — better patch visibility | Medium — new display path |

---

## 11. Architectural Observations

### 11.1 Strengths to Preserve

1. **`ExplorationClassifier` is well-designed** — The heuristic approach (prefix matching + metacharacter guard) is simple, fast, and correct for the vast majority of cases. Don't replace with ML; keep it deterministic.

2. **`DiffRenderer` is genuinely world-class** — The word-level highlighting with adaptive threshold, file context padding, and syntax highlighting is better than any other terminal AI agent's diff display. Preserve this and build on it.

3. **`ToggleableWidgetInterface` is the right abstraction** — The contract is clean and allows polymorphic toggling. Don't break this when adding selective expansion.

4. **Tool icon system (`Theme::toolIcon`)** — The astrological/alchemical icon set (☽ ☉ ⚡ ☿) is distinctive and on-brand. Don't replace with generic emoji.

### 11.2 Technical Debt

1. **`lastToolArgs` / `lastToolArgsByName`** — These are mutable state that creates coupling between `showToolCall()` and `showToolResult()`. If the results arrive out of order (which shouldn't happen but could in async scenarios), the wrong args get associated. Consider passing context through the widget itself.

2. **`activeDiscoveryBatch` / `activeDiscoveryItems`** — These are managed as mutable arrays on the renderer. The batch lifecycle (create → add items → finalize) is entirely implicit. A formal state machine would be more robust.

3. **`BorderFooterWidget` is dead code** — It's never used. Either remove it or adopt it for section closing as originally intended.

4. **No widget identity system** — The conversation is a flat list of widgets. There's no way to address a specific widget by ID (e.g., "toggle widget #7"). This blocks selective expansion.

---

## 12. Summary of Findings

| Dimension | Current State | Target State |
|---|---|---|
| **Visual weight** | Too heavy — every tool gets 4–8 lines | Inline badges for trivial results; 1-line summaries for moderate |
| **Discovery batching** | Good architecture, noisy collapsed view | Summary-only collapsed; two-level expansion |
| **Diff quality** | World-class — preserve as-is | Add stats to collapsed header; default to collapsed |
| **Error display** | Adequate but too similar to success | Auto-expand errors; red accent; error summary in header |
| **Navigation** | Ctrl+O exists but undiscoverable | Status bar hint; progressive hint disclosure; per-widget toggle |
| **Inline results** | Not implemented | Empty/0-result/trivial-success tools → single inline line |

The single highest-impact change is **introducing inline badges for tool results**. This would reduce tool display lines by 60–70% in a typical session, making the conversation dominated by the agent's actual reasoning and responses rather than tool artifacts.
