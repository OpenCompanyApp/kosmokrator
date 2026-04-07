# UX Audit: Conversation Flow

> **Research Question**: How smooth is the conversation flow in KosmoKrator's TUI compared to Claude Code, Aider, and other AI chat TUIs?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `TuiCoreRenderer.php`, `TuiToolRenderer.php`, `TuiConversationRenderer.php`, `TuiAnimationManager.php`, `SubagentDisplayManager.php`, `CollapsibleWidget.php`, `DiscoveryBatchWidget.php`, `BashCommandWidget.php`, `HistoryStatusWidget.php`, `AnsweredQuestionsWidget.php`, `AgentPhase.php`

---

## Executive Summary

KosmoKrator's conversation flow is **architecturally sophisticated but unevenly polished**. The system has a rich phase model (`Thinking → Tools → Idle`), a deep animation pipeline (breathing colors, cosmic spinners, thematic phrases), and clever progressive disclosure (collapsible widgets, discovery batches). However, the flow suffers from several seams: tool call/result are separate widgets (causing visual jitter), the thinking-to-streaming transition is abrupt, discovery batches can dominate the viewport, and there is no inline "tool use" narrative like Claude Code's unified blocks.

Compared to competitors:
- **Claude Code** has the cleanest flow: tool calls are inline cards, diffs are always visible, transitions are invisible
- **Aider** has the most raw/efficient flow: stable/unstable streaming lines, minimal chrome, maximum signal
- **ChatGPT Web** has the most familiar flow: typing indicator → streaming → tool badges → done

KosmoKrator's cosmic theming is distinctive but occasionally fights readability. The conversation flow needs structural changes, not just styling tweaks.

**Severity**: Medium-High. Conversation flow is the primary user experience — every interaction passes through it. Small friction compounds across hundreds of turns.

---

## 2. Current Conversation Flow

### 2.1 Phase Model

The system uses a three-phase model defined in `AgentPhase`:

```
AgentPhase::Thinking  →  AgentPhase::Tools  →  AgentPhase::Idle
```

These map to visual states:

| Phase | Visual Indicator | Animation | Color Palette |
|-------|-----------------|-----------|---------------|
| Thinking | Loader spinner + cosmic phrase | 30fps breathing, sine wave | Blue (112,160,208) ±40 |
| Tools | Same loader continues, switches palette | 30fps breathing continues | Amber (200,150,60) ±40 |
| Idle | Loader removed with `✓` finish indicator | All timers cancelled | None (breathColor = null) |

### 2.2 Full Turn Sequence

A complete user turn flows through these render calls:

```
1. showUserMessage($text)        → TextWidget with "⟡ {text}" + background highlight
2. setPhase(Thinking)            → Thinking loader appears in thinkingBar zone
3. showReasoningContent($text)   → CollapsibleWidget "⟐ Reasoning" (if extended thinking)
4. showToolCall($name, $args)    → Various: TextWidget, CollapsibleWidget, BashCommandWidget, DiscoveryBatchWidget
5. showToolExecuting($name)      → CancellableLoaderWidget "running..." (some tools only)
6. updateToolExecuting($output)  → Updates loader with last output line preview
7. clearToolExecuting()          → Removes loader from conversation
8. showToolResult($name, $output) → CollapsibleWidget with ✓/✗ + diff/code/output
9. [Steps 4-8 repeat for each tool]
10. streamChunk($text)           → MarkdownWidget appended to, character by character
11. streamComplete()             → activeResponse nulled
12. setPhase(Idle)               → All loaders/timers cancelled, terminal notification sent
13. prompt()                     → Input focused, suspension resumed
```

### 2.3 Widget Hierarchy

The TUI layout (top to bottom):

```
┌──────────────────────────────────────────────────────────────────────┐
│ ContainerWidget (conversation)                                       │
│   ├── TextWidget "⟡ User message"              (user-message)       │
│   ├── CancellableLoaderWidget                  (in thinkingBar)      │
│   ├── CollapsibleWidget "Reasoning"            (tool-result)         │
│   ├── DiscoveryBatchWidget                     (tool-batch)          │
│   ├── TextWidget "▷ file_read  src/Foo.php"    (tool-call)           │
│   ├── CollapsibleWidget "✓"                    (tool-result)         │
│   ├── BashCommandWidget "$ phpunit"             (tool-shell)         │
│   ├── MarkdownWidget                           (response)            │
│   └── ...                                                           │
├──────────────────────────────────────────────────────────────────────┤
│ HistoryStatusWidget                                                  │
├──────────────────────────────────────────────────────────────────────┤
│ ContainerWidget (overlay)                                            │
├──────────────────────────────────────────────────────────────────────┤
│ TextWidget (taskBar)                                                 │
├──────────────────────────────────────────────────────────────────────┤
│ ContainerWidget (thinkingBar)                                        │
│   └── CancellableLoaderWidget                                        │
├──────────────────────────────────────────────────────────────────────┤
│ EditorWidget (prompt input)                                          │
├──────────────────────────────────────────────────────────────────────┤
│ ProgressBarWidget (statusBar) "Edit · Guardian ◈ · 12k/200k · ..."  │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 3. Audit Findings

### 3.1 Message Flow: User → Thinking → Streaming → Tool Call → Tool Result → Streaming → Done

**Current state**: The flow is **architecturally correct** but has visible seams at transitions.

#### Problem: Thinking → Streaming transition is abrupt

When `streamChunk()` is first called, it:
1. Calls `clearThinking()` → `setPhase(Idle)` → all timers cancelled, loader removed with `✓`
2. Creates a new `MarkdownWidget`
3. Adds it to the conversation
4. Renders

This creates a visible flash: loader disappears → empty space → markdown widget appears. There's no morphing or handoff animation.

```php
// TuiCoreRenderer.php:219-233
public function streamChunk(string $text): void
{
    // ...
    if ($this->activeResponse === null) {
        $this->clearThinking();  // ← Instant phase transition, loader removed
        // Creates new widget immediately after
        if ($this->containsAnsiEscapes($text)) {
            $this->activeResponse = new AnsiArtWidget('');
        } else {
            $this->activeResponse = new MarkdownWidget('');
        }
        $this->addConversationWidget($this->activeResponse);
    }
}
```

#### Problem: Tool call and tool result are separate widgets

Each tool call adds a `TextWidget` (call line), and then the tool result adds a `CollapsibleWidget` (result block). Between these two widgets, the conversation can have interleaved content (especially for bash, where the `BashCommandWidget` persists across the entire execution). This creates visual fragmentation:

```
▷ file_edit  src/UI/Theme.php          ← TextWidget (tool-call)
✓ ⏋                                    ← CollapsibleWidget (tool-result)
  - line 45: old content
  + line 45: new content
  ⊛ +0 lines (ctrl+o to reveal)
```

Compare with Claude Code's unified tool-use block:

```
── Edit: src/UI/Theme.php ──────────────────────
  - line 45: old content
  + line 45: new content
✓ Applied
```

#### Problem: Reasoning content appears as a separate collapsible block

`showReasoningContent()` creates a `CollapsibleWidget` with header "⟐ Reasoning" that is always collapsed. This interrupts the flow between thinking and the actual response, adding a visible but inert element.

**Rating**: 5/10 — Functionally correct, visually jarring transitions.

---

### 3.2 Visual Continuity During Transitions

**Current state**: Transitions are **visible events** rather than **invisible handoffs**.

| Transition | Current Behavior | Ideal Behavior |
|-----------|-----------------|----------------|
| User → Thinking | User message added, then loader appears in thinkingBar | Seamless — loader morphs from user message |
| Thinking → Streaming | Loader removed with `✓`, new widget created | Loader fades into response text |
| Streaming → Tool Call | Response widget finalized, tool widget appears | Response pauses, tool block opens inline |
| Tool Call → Tool Executing | New `CancellableLoaderWidget` added to conversation | Spinner appears within tool block |
| Tool Executing → Tool Result | Loader removed, CollapsibleWidget added | Loader morphs into result content |
| Tool Result → Streaming | Nothing (streaming picks up the same response widget) | Response continues seamlessly |
| Streaming → Done | `streamComplete()` nulls activeResponse, `setPhase(Idle)` | Subtle completion indicator |

The breathing animation (30fps sine wave) is smooth and distinctive — this is a genuine strength. The cosmic phrases ("Reading the astral charts...", "Summoning Athena's wisdom...") add personality without being distracting.

However, the animation only applies to the loader in the `thinkingBar` zone — tool-executing spinners are a separate system with their own timer and color logic. This means the visual language is **inconsistent between the thinking phase and tool execution phase**.

**Rating**: 4/10 — Good animations, poor continuity at transitions.

---

### 3.3 Tool Call Clutter

**Current state**: The system uses a **smart categorization system** to reduce clutter, but it's inconsistent.

#### The Good: Discovery Batching

`ExplorationClassifier` identifies "omens tools" (file_read, glob, grep, bash probes, memory_search) and batches them into a single `DiscoveryBatchWidget`:

```
📜 Reading the omens
 │ 3 reads  ·  2 globs  ·  1 search
 │ src/UI/Theme.php
 │ src/UI/Tui/TuiCoreRenderer.php
 │ src/UI/Tui/TuiToolRenderer.php
 │ **/*.php
 │ src/Agent/*.php
 │ "AgentPhase" in src/
 └ ⊛ Details (ctrl+o to reveal)
```

This is excellent — it collapses 6 tool calls that would otherwise take 12+ lines into a compact 9-line block.

#### The Good: Silent Tool Categories

Task tools (`task_create`, `task_update`, etc.) are rendered only in the task bar, not in the conversation. Ask tools (`ask_user`, `ask_choice`) are handled via question recaps. These are smart decisions that reduce noise.

#### The Problem: Non-batched tools still create 2 widgets each

For file_edit, file_write, file_read (non-discovery), execute_lua, and other "action" tools, the pattern is:

```
Line 1: ▷ file_edit  src/UI/Theme.php              (TextWidget, tool-call)
Line 2: ✓ ⏋                                         (CollapsibleWidget, tool-result)
Line 3:   - old content
Line 4:   + new content
Line 5:   ⊛ +0 lines (ctrl+o to reveal)
```

For a typical agent turn that involves 3-5 action tools after discovery, this creates 6-10 widgets — each visually separated. The `CollapsibleWidget` only shows 3 preview lines and requires `ctrl+o` to expand. The "⏋" bracket character is visually noisy.

#### The Problem: BashCommandWidget and ToolExecutingLoader are parallel systems

Bash commands get their own `BashCommandWidget` (collapsed with 2 output preview lines). Non-bash tools get a `CancellableLoaderWidget` during execution. These use different visual languages — different collapse indicators, different expand hints, different border styles.

#### Comparison: Claude Code's Approach

Claude Code renders tool calls as unified blocks:

```
Read file
src/UI/Tui/TuiCoreRenderer.php
✓ 847 lines

Edit file
src/UI/Tui/TuiToolRenderer.php
- line 45: old
+ line 45: new
✓ Applied
```

No separate call/result widgets. No collapsible brackets. The result is always inline.

**Rating**: 6/10 — Good batching system, but the non-batched path creates too many fragments.

---

### 3.4 Streaming Feel

**Current state**: Streaming is **functionally smooth but visually basic**.

The streaming architecture in `streamChunk()`:

```php
public function streamChunk(string $text): void
{
    // First chunk: create MarkdownWidget or AnsiArtWidget
    if ($this->activeResponse === null) {
        $this->clearThinking();
        // ... create widget
        $this->addConversationWidget($this->activeResponse);
    }
    // Subsequent chunks: append text, re-render
    $current = $this->activeResponse->getText();
    $this->activeResponse->setText($current.$text);
    $this->markHiddenConversationActivity();
    $this->flushRender();
}
```

Key observations:

1. **No word-level buffering** — each `streamChunk` call triggers a full re-render. If chunks arrive at high frequency (common with streaming APIs), this creates unnecessary render pressure.

2. **No cursor/typing indicator** — there's no visual "typing cursor" during streaming. The text just appears. Compare with ChatGPT's blinking cursor at the end of streaming text, or Aider's ">" character at the leading edge.

3. **No "stable/unstable" line distinction** — Aider famously separates "stable" lines (won't change) from "unstable" lines (still being streamed). This gives the reader a sense of progress. KosmoKrator's `MarkdownWidget` re-renders all text on each chunk.

4. **MarkdownWidget → AnsiArtWidget mid-stream swap** — if streaming starts as markdown but encounters ANSI escapes, the widget is swapped:
   ```php
   } elseif (! $this->activeResponseIsAnsi && $this->containsAnsiEscapes($text)) {
       $accumulated = $this->activeResponse->getText();
       $this->conversation->remove($this->activeResponse);
       $this->activeResponse = new AnsiArtWidget($accumulated);
       // ...
   }
   ```
   This creates a visible flash — the old widget is removed, a new one is created.

5. **Hidden activity tracking** — if the user is scrolling history during streaming, `markHiddenConversationActivity()` sets a flag and shows a "new activity below ↓" indicator in `HistoryStatusWidget`. This is a good design pattern.

**Rating**: 5/10 — Functional streaming, missing polish (cursor, buffering, stable/unstable).

---

### 3.5 Context Switching (Scrolling During Streaming)

**Current state**: Scrolling during active streaming is **well-handled** with some edge cases.

The scroll system (`TuiCoreRenderer`):

```php
private function scrollHistoryUp(): void
{
    $this->scrollOffset += $this->historyScrollStep();
    $this->applyScrollOffset();
}

private function jumpToLiveOutput(): void
{
    $this->scrollOffset = 0;
    $this->hasHiddenActivityBelow = false;
    $this->applyScrollOffset();
}
```

Key behaviors:

1. **Scroll step is adaptive**: `max(6, rows - 10)` — good, avoids tiny-scroll on large terminals.

2. **Hidden activity tracking**: When the user is browsing history and new content arrives (streaming, tool calls), `markHiddenConversationActivity()` sets a flag and shows:
   ```
    │ Browsing history                 new activity below ↓ │
   ```

3. **Jump-back to live**: `End` key or scrolling back to offset 0 returns to live output. The indicator disappears.

4. **Missing: scroll position preservation** — When the user scrolls up during streaming, new widgets are still appended to the conversation. When they jump back to live, they see everything. But there's no way to see a "live tail" view that auto-scrolls while still allowing scroll-up to freeze (like `less --exit-follow-on-close` or terminal `tmux` copy-mode).

5. **Missing: per-tool collapse state during scroll** — When scrolling, collapsed widgets remain collapsed. There's no "collapse all" or "expand all" command to manage visual density when reviewing history.

**Rating**: 7/10 — Good scroll tracking with live-update indicator. Missing power-user features.

---

### 3.6 Error Display

**Current state**: Errors are **visible but not prominent enough**.

Error rendering path:

```php
// TuiCoreRenderer.php
public function showError(string $message): void
{
    $this->showMessage("✗ Error: {$message}", 'tool-error');
}
```

```php
// TuiToolRenderer.php — tool result errors
$statusColor = $success ? Theme::success() : Theme::error();
$indicator = $success ? '✓' : '✗';
```

Key observations:

1. **Tool errors are collapsed by default** — `CollapsibleWidget` starts collapsed, showing only 3 preview lines. Error output (stack traces, error messages) may be truncated behind "⊛ +N lines (ctrl+o to reveal)".

2. **Bash failures auto-expand** — `BashCommandWidget::setResult()` sets `$this->expanded = true` on failure. This is correct behavior.

3. **Error color is consistent** — `Theme::error()` provides a red color used everywhere. The `✗` indicator is used consistently for both errors and failures.

4. **Missing: error severity levels** — All errors use the same visual treatment. A rate limit error (transient, user should wait) looks identical to a syntax error (permanent, user must fix).

5. **Missing: error grouping** — If a batch of tools fails (e.g., 3 file_reads fail), each error is a separate widget. There's no error summary.

6. **Missing: error recovery hints** — Errors show the error message but don't suggest next steps ("Try again?", "Check your API key?", etc.).

**Rating**: 5/10 — Errors are visible but not actionable.

---

### 3.7 Subagent Flow

**Current state**: Subagent display is **the most sophisticated in the industry** but has UX complexity.

The `SubagentDisplayManager` manages a full lifecycle:

```
showSpawn()  →  showRunning()  →  showBatch()
```

With a live tree refresh:

```
⏺ 3 agents (2 running, 1 done)
  ├─ ● General  implement-auth · (0:45)
  ├─ ● General  write-tests · (0:30)
  └─ ✓ Explore  research-patterns · 0:12 · 4 tools · Found 3 patterns
```

This is genuinely impressive — no competitor shows a live agent tree with elapsed times and tool counts. The color escalation (blue → amber at 60s → red at 120s) is a smart UX signal for long-running operations.

**Problems**:

1. **Tree widget updates replace the entire text** — `refreshTree()` calls `setText()` on the `TextWidget`, which triggers a full re-render. For rapid updates, this can cause visual flickering.

2. **Batch result display is complex** — For multi-agent results, the system shows a summary `TextWidget` plus a `CollapsibleWidget` for full output. The child tree rendering adds additional visual complexity. Users need to parse:
   - Summary line ("2/3 agents finished")
   - Per-agent status lines with previews
   - Child trees
   - "Full output" collapsible

3. **ctrl+a dashboard hint is invisible** — The loader says `ctrl+a for dashboard` but this is a dim hint in a moving spinner. Users may not notice it.

**Rating**: 8/10 — Best-in-class agent tree visualization, minor UX polish needed.

---

## 4. Competitive Analysis

### 4.1 Claude Code

**Strengths to learn from**:
- **Unified tool blocks**: Tool call + result are one visual unit. No separate widgets.
- **Diff previews always visible**: `file_edit` results show the diff inline, not collapsed.
- **Clean phase transitions**: No visible loader → response transition. The text simply starts appearing.
- **Minimal chrome**: No spinners, no cosmic phrases, no breathing animations. Maximum signal-to-noise.
- **Tool use counts**: Shows "3 tool calls" as a summary badge rather than 3 separate blocks.

**Where KosmoKrator is better**:
- Discovery batching (Claude Code shows each tool call individually)
- Subagent tree visualization
- Themed personality (cosmic aesthetic)
- Task bar with live task tree

### 4.2 Aider

**Strengths to learn from**:
- **Stable/unstable streaming**: Lines marked as "stable" (committed) vs "unstable" (still being generated). Users can read stable lines while unstable ones are still changing.
- **Minimal output**: Only shows essential information. Tool calls are one line.
- **Direct file editing model**: Edits are applied and shown as diffs immediately, without intermediate blocks.
- **Speed over polish**: Prioritizes throughput over visual niceties.

**Where KosmoKrator is better**:
- Structured tool display (Aider is very raw)
- Syntax highlighting in tool results
- Subagent support
- Progressive disclosure via collapsibles

### 4.3 ChatGPT Web UI

**Strengths to learn from**:
- **Typing indicator**: "..." animation before streaming starts. Universally understood.
- **Tool use badges**: Small colored badges ("Searched 3 sources", "Generated image") that expand on click.
- **Streaming cursor**: Blinking cursor at the end of streaming text.
- **Smooth scroll**: Auto-scrolls to follow streaming, pauses when user scrolls up, resumes when scrolled to bottom.

**Where KosmoKrator is better**:
- Terminal-native design (not a web app)
- More detailed tool output
- Diff views, syntax highlighting
- Subagent visualization

---

## 5. Ideal Conversation Flow

### 5.1 Target Flow — ASCII Mockup

A single turn in the ideal flow:

```
┌──────────────────────────────────────────────────────────────────────┐
│                                                                      │
│  ⟡ Refactor the TUI renderer to support streaming transitions       │
│                                                                      │
│  ── Reading the omens ────────────────────────────────────────────── │
│  │ 3 reads  ·  2 globs  ·  1 search                    ✓ all done  │
│  │  src/UI/Tui/TuiCoreRenderer.php                       847 lines  │
│  │  src/UI/Tui/TuiToolRenderer.php                       612 lines  │
│  │  src/UI/Tui/TuiAnimationManager.php                   340 lines  │
│  │  **/*Widget.php                                        4 files   │
│  │  src/UI/Tui/*.php                                      8 files   │
│  │  "streamChunk" in src/                                 3 matches │
│  └──────────────────────────────────────────────────────────────────│
│                                                                      │
│  ── Edit src/UI/Tui/TuiCoreRenderer.php ─────────────────────────── │
│  │  217 |    public function streamChunk(string $text): void       │
│  │  218 |    {                                                      │
│  │  219 | -      $this->clearThinking();                           │
│  │  219 | +      $this->transitionFromThinking();                  │
│  │  220 |        if ($this->activeResponse === null) {             │
│  │  221 | -          $md = new MarkdownWidget('');                 │
│  │  221 | +          $md = new StreamingMarkdownWidget('');        │
│  │  223 |        }                                                  │
│  │  ✓ Applied                                            2 changes │
│  └──────────────────────────────────────────────────────────────────│
│                                                                      │
│  ── Edit src/UI/Tui/TuiToolRenderer.php ─────────────────────────── │
│  │  (ctrl+o to reveal)                                    5 changes │
│  └──────────────────────────────────────────────────────────────────│
│                                                                      │
│  ── Bash ─────────────────────────────────────────────────────────── │
│  │  $ phpunit --filter=TuiCoreRenderer                             │
│  │  ... 3 tests, 0 failures                                        │
│  │  ✓ Passed                                               0.8s     │
│  └──────────────────────────────────────────────────────────────────│
│                                                                      │
│  I've refactored the streaming transitions in TuiCoreRenderer to│
│  use a smooth handoff from the thinking animation instead of an│
│  abrupt clear. Key changes:█                                   │
│                                                                      │
│  1. **`transitionFromThinking()`** — fades the loader into the     │
│  first line of the response widget                                  │
│  2. **`StreamingMarkdownWidget`** — tracks stable/unstable lines   │
│  3. **Unified tool blocks** — tool call and result are now a single│
│  widget                                                             │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│  Edit · Guardian ◈ · 45k/200k · gpt-4.1                            │
└──────────────────────────────────────────────────────────────────────┘
```

### 5.2 Target Flow — Multi-Agent Turn

```
┌──────────────────────────────────────────────────────────────────────┐
│                                                                      │
│  ⟡ Refactor all TUI renderers and add tests                         │
│                                                                      │
│  ── Reading the omens ────────────────────────────────────────────── │
│  │ 8 reads  ·  3 globs  ·  2 searches                  ✓ all done  │
│  └──────────────────────────────────────────────────────────────────│
│                                                                      │
│  ⏺ 3 agents ━━━━━━━━━━━━━━━━━━━━━━━━━━ 2:15 · ctrl+a dashboard   │
│  ├─ ● General  refactor-renderers  · running (1:45)                │
│  │   └─ Explore  read-patterns  · ✓ 0:12 · 4 tools                │
│  ├─ ● General  write-tests        · running (1:30)                │
│  └─ ○ General  update-docs        · waiting                       │
│                                                                      │
│  (as agents complete, results appear here)                           │
│                                                                      │
│  ⏺ 3 agents ✓ ━━━━━━━━━━━━━━━━━━━━━━━━━ all done 2:45             │
│  ├─ ✓ General  refactor-renderers  · 1:52 · 8 tools               │
│  ├─ ✓ General  write-tests         · 1:38 · 12 tools              │
│  └─ ✓ General  update-docs         · 0:45 · 3 tools               │
│  (ctrl+o to see full results)                                       │
│                                                                      │
│  All three agents completed successfully. I refactored...│
│  █                                                                  │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│  Edit · Guardian ◈ · 78k/200k · gpt-4.1                            │
└──────────────────────────────────────────────────────────────────────┘
```

### 5.3 Target Flow — Error State

```
┌──────────────────────────────────────────────────────────────────────┐
│                                                                      │
│  ⟡ Deploy to production                                             │
│                                                                      │
│  ── Bash ─────────────────────────────────────────────────────────── │
│  │  $ deploy --env=production                                      │
│  │  ✗ Error: SSH connection refused (host: prod-web-01)             │
│  │  ✗ Connection timed out after 30s                                │
│  │                                                                  │
│  │  Possible causes:                                                │
│  │  • SSH key not configured for production                         │
│  │  • Firewall blocking port 22                                     │
│  │  • Host is down                                                  │
│  └──────────────────────────────────────────────────────────────────│
│                                                                      │
│  ✗ The deployment failed. The production server at `prod-web-01`   │
│  refused the SSH connection. Would you like me to...                │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
```

---

## 6. Recommendations

### 6.1 Unified Tool Block Widget (Priority: High)

**Current**: Tool call and tool result are separate widgets.
**Target**: Single `ToolBlockWidget` that contains both the call header and result content.

```php
// Instead of:
//   TextWidget "▷ file_edit  path" → CollapsibleWidget "✓ diff"

// One widget:
class ToolBlockWidget extends AbstractWidget implements ToggleableWidgetInterface
{
    public function __construct(
        private string $toolName,
        private array $args,
        private ?string $result = null,     // null = still executing
        private bool $success = true,
    ) {}

    // Renders as:
    // ── Edit src/UI/Theme.php ─────────────── ✓ Applied
    // (diff preview when collapsed, full diff when expanded)
}
```

This eliminates the two-widget pattern, reduces visual fragmentation, and matches Claude Code's clean inline blocks.

### 6.2 Smooth Thinking → Streaming Transition (Priority: High)

**Current**: `clearThinking()` removes loader, `streamChunk()` creates new widget — visible flash.
**Target**: Morph loader into streaming response.

Options:
1. **Cross-fade**: Keep the loader visible for 200ms while the markdown widget fades in underneath.
2. **In-place morph**: Replace the `CancellableLoaderWidget` with a `MarkdownWidget` at the same position in the container.
3. **Cursor start**: After clearing the loader, show a brief "▌" cursor for 100ms before text starts streaming.

The simplest fix: don't call `clearThinking()` in `streamChunk()`. Instead, let `streamComplete()` or the first render after streaming starts handle the phase transition.

### 6.3 Streaming Cursor and Word Buffering (Priority: Medium)

**Current**: Each `streamChunk()` triggers a full re-render.
**Target**: 
- Add a blinking cursor character at the end of streaming text
- Buffer chunks for 16ms (one frame at 60fps) before re-rendering
- Track "stable" vs "unstable" lines for readability

```php
public function streamChunk(string $text): void
{
    if ($this->activeResponse === null) {
        $this->activeResponse = new MarkdownWidget('');
        $this->activeResponse->addStyleClass('response');
        $this->addConversationWidget($this->activeResponse);
    }

    $current = $this->activeResponse->getText();
    $this->activeResponse->setText($current . $text . '▌'); // streaming cursor

    if (!$this->renderBufferTimerActive) {
        $this->renderBufferTimerActive = true;
        EventLoop::delay(0.016, function () {
            $this->flushRender();
            $this->renderBufferTimerActive = false;
        });
    }
}
```

### 6.4 Tool Block Border System (Priority: Medium)

**Current**: `CollapsibleWidget` uses `⏋` bracket, `BashCommandWidget` uses `└` prefix, `DiscoveryBatchWidget` uses `│` tree lines.
**Target**: Unified visual language for all collapsible blocks.

Proposed border grammar:
```
── Title ─────────────────── status ────     ← header line
│  content                                   ← content lines
│  content
│  ⊛ +N lines (ctrl+o to reveal)            ← collapse hint
└──────────────────────────────────────────── ← only when expanded
```

All block widgets should use this same structure. The `── Title ──` header format is borrowed from Claude Code and provides excellent scanability.

### 6.5 Error Auto-Expand and Recovery Hints (Priority: Medium)

**Current**: Tool errors are collapsed by default. No recovery hints.
**Target**:
- All failed tool results auto-expand (like `BashCommandWidget` already does)
- Error blocks show a "try again?" action or contextual hint
- Rate limit errors show a countdown timer

```php
// In CollapsibleWidget or ToolBlockWidget:
if (!$success) {
    $this->setExpanded(true);
    $this->addRecoveryHint($this->suggestRecovery($toolName, $output));
}
```

### 6.6 Discovery Batch Inline Status (Priority: Low)

**Current**: Discovery batch shows a summary line, then each item on a separate line.
**Target**: More compact display for simple batches.

For batches with ≤ 5 items:
```
── Reading the omens ──── 3 reads  ·  1 search ──── ✓ all done ──
│ src/Foo.php (847 lines)  src/Bar.php (120 lines)  src/Baz.php (45 lines)
│ "pattern" in src/ (3 matches)
└ ⊛ ctrl+o to see full content
```

For batches with > 5 items, show a compact summary with expand:
```
── Reading the omens ──── 12 reads  ·  3 globs  ·  2 searches ── ✓ ──
└ ⊛ ctrl+o to see all 17 items
```

### 6.7 Phase-Transition Animations (Priority: Low)

**Current**: Phase transitions are instant (loader appears/disappears immediately).
**Target**: Brief morphing animations (100-200ms) at key transitions.

| Transition | Animation |
|-----------|-----------|
| Thinking start | Loader fades in (opacity 0→1 over 150ms) |
| Thinking → Streaming | Loader collapses upward into first response line |
| Streaming → Idle | Streaming cursor fades out |
| Idle → Prompt | Subtle input focus glow |

Implementation: Use `EventLoop::delay()` with staged text updates rather than true animation frames.

---

## 7. Implementation Priority Matrix

| Recommendation | Impact | Effort | Priority |
|---------------|--------|--------|----------|
| Unified Tool Block Widget | High | High | P0 |
| Smooth Thinking → Streaming | High | Medium | P0 |
| Streaming Cursor | Medium | Low | P1 |
| Word Buffering (16ms) | Medium | Low | P1 |
| Tool Block Border System | Medium | Medium | P1 |
| Error Auto-Expand + Hints | Medium | Low | P2 |
| Discovery Batch Compact | Low | Low | P2 |
| Phase-Transition Animations | Low | High | P3 |

---

## 8. Scoring Summary

| Dimension | Current | Target | Notes |
|-----------|---------|--------|-------|
| Message flow completeness | 5/10 | 9/10 | All phases rendered, but with seams |
| Visual continuity | 4/10 | 9/10 | Abrupt transitions, separate widgets |
| Tool call clarity | 6/10 | 9/10 | Good batching, fragmented non-batch |
| Streaming feel | 5/10 | 8/10 | Functional, missing cursor/buffering |
| Context switching | 7/10 | 9/10 | Good scroll tracking, missing live-tail |
| Error display | 5/10 | 8/10 | Visible, not actionable |
| Subagent visualization | 8/10 | 9/10 | Best-in-class, minor polish |
| **Overall** | **5.7/10** | **8.7/10** | |

The biggest wins are:
1. **Unified tool blocks** (eliminates the 2-widget-per-tool pattern)
2. **Smooth thinking→streaming transition** (eliminates the biggest visual flash)
3. **Streaming cursor + buffering** (makes streaming feel responsive rather than janky)

These three changes would move the overall score from 5.7 to approximately 7.5 without any other changes.
