# UX Audit: Status Feedback & Activity Communication

**Date:** 2026-04-07  
**Auditor:** UX Research  
**Research question:** *How well does KosmoKrator communicate what the agent is doing?*  
**Files reviewed:**
- `src/UI/Tui/TuiAnimationManager.php` — thinking/loading animation lifecycle
- `src/UI/Tui/TuiCoreRenderer.php` — status bar, phase transitions, task bar
- `src/UI/Tui/TuiToolRenderer.php` — tool execution spinners, discovery batches
- `src/UI/Tui/SubagentDisplayManager.php` — subagent tree, spawn/batch lifecycle
- `src/UI/Theme.php` — color palette, icons, labels
- `src/UI/TerminalNotification.php` — OS-level completion notifications
- `src/Agent/AgentPhase.php` — phase enum (Thinking, Tools, Idle)

---

## Executive Summary

KosmoKrator's status feedback is **thematically rich** (celestial spinners, mythological phrases, breathing animations) but **informationally sparse**. The system excels at conveying *that something is happening* but struggles to communicate *what specifically is happening*, *for how long*, and *whether things are stuck*. Compared to Claude Code's verb-aware spinner and lazygit's toast system, KosmoKrator lacks:

1. **Context verbs** — the spinner says "Consulting the Oracle at Delphi…" instead of "Reading 3 files…"
2. **Stall detection** — no color escalation, no timeout warning, no "still working" nudge
3. **Toast notifications** — errors and completions flash by with no persistent indicator
4. **Phase-specific detail** — the status bar shows mode + permission + token count, but not *current action*

**Overall grade: C+** — beautiful but underinformative. The bones are solid; the information layer needs filling in.

---

## 1. Thinking Phase: Is the User Informed Enough?

### Current behavior

When the agent enters the Thinking phase, `TuiAnimationManager::enterThinking()`:

1. Picks a **random mythological phrase** from `THINKING_PHRASES` (15 phrases):
   - "◈ Consulting the Oracle at Delphi…"
   - "♃ Aligning the celestial spheres…"
   - "☽ Reading the astral charts…"
2. Starts a **CancellableLoaderWidget** with a random spinner from 14 celestial themes (cosmos, planets, runes, eclipse, etc.)
3. Begins a **30fps breathing animation** with blue color oscillation (`sin(tick * 0.07)`)
4. Shows an **elapsed timer** (M:SS format) — but only when no subagents are running

If tasks exist in the TaskStore, the standalone loader is suppressed; instead, the breathing animation pulses on in-progress task items in the task bar.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No semantic context** | 🔴 High | "Consulting the Oracle at Delphi" is whimsical but tells the user nothing. Claude Code shows the actual reasoning verb: "Analyzing", "Comparing", "Writing". |
| **Random phrase per think** | 🟡 Medium | Each thinking phase gets a new random phrase. On fast turnarounds, the phrase flickers. On long thinks, the same phrase hangs for 30+ seconds, making it feel frozen. |
| **No streaming thought preview** | 🟡 Medium | The LLM's `reasoning`/`thinking` tokens could be streamed as faint text, giving the user a real-time window into what the model is reasoning about. Currently these are hidden until `showReasoningContent()` fires (and then shown collapsed). |
| **No stall detection** | 🔴 High | If the LLM takes 60+ seconds with no token, the animation looks identical to second 1. No color shift, no "still thinking…" nudge. |
| **Elapsed timer hidden during subagents** | 🟡 Medium | When subagents are active, `hasSubagentActivityProvider()` returns true, and the elapsed timer is suppressed. The user loses all sense of time. |

### Comparison: Claude Code

Claude Code's spinner is **verb-aware**: it extracts context from the model's streaming output and rotates verbs like "Reading", "Searching", "Analyzing". It also implements a **shimmer effect** and **stall-aware color shifts** — after 10s of silence, the spinner turns amber; after 30s, it adds a "still working" suffix.

### Comparison: Aider

Aider uses a simple `█` block spinner with a model name prefix. Minimalist but honest: `claude-3.5-sonnet █ thinking…`. The simplicity is a feature — there's no performative animation that masks inactivity.

### Recommendations

```
┌─ Thinking Phase — Current ─────────────────────────────────────────┐
│                                                                     │
│  ◈ Consulting the Oracle at Delphi…  1:23                          │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─ Thinking Phase — Proposed ─────────────────────────────────────────┐
│                                                                     │
│  ◈ Thinking…  1:23                                                 │
│  │ Analyzing user request structure…                                │
│  │ Considering 3 relevant files…                                    │
│  └ Mapping dependencies in src/UI/…                                 │
│                                                                     │
│  ── After 15s stall ──                                              │
│  ◈ Thinking…  1:23  ⚠ waiting for model response                  │
│     (color shifts to amber)                                         │
│                                                                     │
│  ── After 60s stall ──                                              │
│  ◈ Thinking…  1:23  ⚠ still processing — no response for 60s      │
│     (color shifts to red, intermittent pulse)                       │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Action items:**
1. Replace mythological phrases with **contextual verbs** drawn from the model's streaming tokens or the current task description. Keep celestial flavor as optional (e.g., "☽ Reading 3 files…").
2. Add **stall detection**: track time since last token received. At 15s → amber + "waiting". At 60s → red + "still processing".
3. Stream **reasoning token previews** as a faint single-line trailing the spinner.
4. Always show the **elapsed timer**, even during subagent activity.

---

## 2. Tool Execution Phase: Can the User Tell Which Tool Is Running?

### Current behavior

The `TuiToolRenderer::showToolExecuting()` method:

1. Creates a `CancellableLoaderWidget` with the label `"running…"`
2. Starts a 50fps breathing animation timer
3. Updates with a **preview of the last output line** via `updateToolExecuting()`
4. Shows elapsed seconds in parentheses: `running… (12s)`

The tool call itself is shown as a **compact one-liner** above the loader:
```
☽ Read  src/UI/Theme.php
```

### What's good

- The tool call widget clearly shows **tool icon + name + target** (path, command, etc.)
- Discovery batch tools are grouped in a `DiscoveryBatchWidget` — excellent for parallel file reads
- Bash commands get their own `BashCommandWidget` with live output streaming

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **"running…" is generic** | 🟡 Medium | The executing loader says "running…" not "Reading file…" or "Executing bash…". The tool name is visible only in the tool call widget above, which may have scrolled off screen. |
| **Loader and call are separate** | 🟡 Medium | The tool call header and the executing spinner are two separate widgets. On small terminals, the header may scroll away, leaving only "running… (5s)" visible. |
| **No progress for file operations** | 🟢 Low | File reads/writes are binary (running → done). No progress indication for large files. |
| **No spinner differentiation by tool type** | 🟢 Low | All tool-executing spinners use the 'cosmos' spinner at 120ms. Could use different spinners for read vs. write vs. bash. |

### Comparison: Cursor

Cursor shows a **persistent tool indicator** in its bottom status bar that names the active tool: "Reading file…" → "Editing file…" → "Running command…". This is always visible regardless of scroll position.

### Recommendations

```
┌─ Tool Execution — Current ──────────────────────────────────────────┐
│                                                                     │
│  ☽ Read  src/UI/Theme.php       ← tool call (may scroll away)      │
│  ◉ running… (12s)               ← generic loader                   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─ Tool Execution — Proposed ─────────────────────────────────────────┐
│                                                                     │
│  ☽ Read  src/UI/Theme.php       ← tool call                        │
│  ◉ Reading file… (12s)          ← verb + tool context              │
│    Last line of output preview…                                     │
│                                                                     │
│  ── Status bar integration ──                                       │
│  Edit · Guardian ◈ · 12k/200k · Reading Theme.php                  │
│                                    ^^^^^^^^^^^^^^^^^ current action │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Action items:**
1. Replace `"running…"` with **`"Reading {filename}…"`, `"Editing {filename}…"`, `"Running command…"`, etc.** — derived from the tool name and args.
2. Add the **current tool action to the status bar** so it's always visible regardless of scroll.
3. Consider different spinner speeds per tool category (fast for reads, slower for bash).

---

## 3. Long-Running Tasks: Stall Detection & Color Escalation

### Current behavior

KosmoKrator has **no stall detection**. The breathing animation runs at the same color and speed regardless of elapsed time. The only escalation mechanism exists in `SubagentDisplayManager`:

```php
// SubagentDisplayManager::showRunning() — line ~155
if ($elapsed >= 120) {
    $color = Theme::error();    // red at 2 minutes
} elseif ($elapsed >= 60) {
    $color = Theme::warning();  // amber at 1 minute
}
```

This is applied to the subagent loader label only, not to the main thinking loader or tool executing loader.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No main spinner escalation** | 🔴 High | The thinking loader breathes the same blue from second 0 to second 300. A stalled API call looks identical to an active think. |
| **No "still working" messages** | 🔴 High | After 30s of silence, the user has zero reassurance that the system is still alive. |
| **No cancel hint for stuck operations** | 🟡 Medium | The CancellableLoaderWidget supports Escape to cancel, but this is never surfaced to the user. After 60s, a "press Esc to cancel" hint should appear. |
| **Subagent escalation is 60/120s** | 🟢 Low | The thresholds are reasonable but undocumented. No way for users to configure them. |

### Comparison: Claude Code

Claude Code implements **tiered color escalation**:
- **0–10s**: Cyan spinner, active verb
- **10–30s**: Amber spinner, "waiting for response" suffix
- **30–60s**: Red spinner, shimmer animation slows
- **60s+**: Pulsing red, "press Ctrl+C to cancel" hint appears

### Comparison: Lazygit

Lazygit uses a **simple loading indicator** with a spinning `|/-\` character in the status bar. For long operations, it shows elapsed time and a "this is taking longer than usual" message.

### Recommendations

```
┌─ Stall Escalation — Proposed Timeline ──────────────────────────────┐
│                                                                     │
│  0–10s    Blue breathing    "Reading 3 files…"                      │
│  10–30s   Blue → Amber      "Reading 3 files… (15s)"               │
│  30–60s   Amber → Orange    "Still processing… (42s)"              │
│  60–120s  Orange → Red      "Taking longer than expected (1:15)"   │
│  120s+    Red pulse          "No response for 2+ minutes · Esc ⏎"  │
│                                                                     │
│  Implementation: TuiAnimationManager tracks time since last         │
│  token/streaming event, not just phase entry time.                  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Action items:**
1. Add **last-activity timestamp** to TuiAnimationManager — updated on every streaming token and tool result.
2. Implement **4-tier color escalation** (blue → amber → orange → red) based on time since last activity.
3. Show **"press Esc to cancel" hint** after 30s of stall.
4. Extend the subagent escalation logic to the main thinking and tool executing loaders.

---

## 4. Status Bar: Is It Informative?

### Current behavior

The status bar is a `ProgressBarWidget` showing:

```
Edit · Guardian ◈ · 12.5k/200k · gpt-4o
```

Components:
1. **Mode label** (`Edit`, `Plan`, `Ask`) — color-coded
2. **Permission mode** (`Guardian ◈`, `Argus ◈`, `Prometheus ◈`) — color-coded
3. **Token usage** (`12.5k/200k`) — color escalates: green < 50%, amber < 75%, red ≥ 75%
4. **Model name** — dimmed white

The progress bar itself is 20 characters wide, mapping context usage.

### What's good

- Token context bar provides **glanceable resource usage** — the user knows when compaction is needed
- Mode and permission are **always visible** — no guessing what state the agent is in
- Color escalation on context usage is intuitive

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No current action** | 🔴 High | The status bar doesn't show what the agent is currently doing. "Edit · Guardian ◈ · 12k/200k" is missing "Thinking…" or "Reading Theme.php". |
| **No cost display** | 🟡 Medium | Session cost is tracked (`lastStatusCost`) but not shown in the status bar. Users on paid APIs have no visibility into spend. |
| **No request counter** | 🟢 Low | No way to see "request 5 of session" without counting manually. |
| **No duration indicator** | 🟡 Medium | The total session time or per-request time is not visible. |

### Comparison: All competitors

| Feature | Claude Code | Lazygit | Cursor | Aider | KosmoKrator |
|---------|-------------|---------|--------|-------|-------------|
| Current action | ✅ | ✅ | ✅ | ❌ | ❌ |
| Token usage | ✅ | N/A | ✅ | ✅ | ✅ |
| Cost display | ✅ | N/A | ✅ | ❌ | ❌ (tracked but hidden) |
| Model name | ✅ | N/A | ✅ | ✅ | ✅ |
| Session duration | ❌ | ❌ | ❌ | ❌ | ❌ |

### Recommendations

```
┌─ Status Bar — Current ──────────────────────────────────────────────┐
│                                                                     │
│  Edit · Guardian ◈ · 12.5k/200k · gpt-4o                          │
│  ══════════════════════                                             │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─ Status Bar — Proposed ─────────────────────────────────────────────┐
│                                                                     │
│  Edit · Guardian ◈ · 12.5k/200k · $0.042 · gpt-4o                 │
│  ═════════════════════════ · ◉ Reading Theme.php… (8s)             │
│                                                                     │
│  Breakdown:                                                         │
│  [mode] · [permission] · [tokens] · [cost] · [model] · [action]   │
│                                                                     │
│  When idle:                                                         │
│  Edit · Guardian ◈ · 12.5k/200k · $0.042 · gpt-4o · Ready         │
│                                                                     │
│  When thinking:                                                     │
│  Edit · Guardian ◈ · 12.5k/200k · $0.042 · gpt-4o · ◉ Thinking…  │
│                                                                     │
│  When executing tool:                                               │
│  Edit · Guardian ◈ · 12.5k/200k · $0.042 · gpt-4o · ☽ Reading…   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Action items:**
1. Add a **current action segment** to the right side of the status bar — always shows what's happening now.
2. Add **cost display** (`$0.042`) between tokens and model name.
3. Consider a **session timer** in the HistoryStatusWidget area.

---

## 5. Error/Success Feedback: Toast Notifications Needed?

### Current behavior

**Error display:** `TuiCoreRenderer::showError()` creates a red `TextWidget` with `"✗ Error: {message}"` in the conversation. It scrolls away with new content.

**Success display:** Tool results show a green `✓` prefix on the collapsed result widget. No dedicated success notification.

**OS-level notifications:** `TerminalNotification::notify()` fires on phase → Idle, sending BEL + OSC sequences for iTerm2, Ghostty, and Kitty. This is only for **task completion**, not for errors.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **Errors scroll away** | 🔴 High | An error in a long agent session can scroll off screen within seconds as the agent retries or continues. No persistent indicator. |
| **No error summary** | 🔴 High | If 3 tools fail in a row, there's no aggregate indicator. The user must scroll back through conversation to see all errors. |
| **No toast system** | 🟡 Medium | Transient feedback (tool success, file written, permission denied) disappears immediately. A toast that auto-dismisses after 3s would provide confirmation without clutter. |
| **No error sound/haptic** | 🟢 Low | Only completion triggers BEL. Errors are silent. |

### Comparison: Lazygit

Lazygit implements a **toast notification system**:
- Success toasts: green, auto-dismiss after 2s
- Error toasts: red, auto-dismiss after 5s
- Toasts appear at the bottom of the screen, overlaying content
- Multiple toasts stack vertically

### Comparison: Cursor

Cursor shows **inline error banners** that persist until dismissed, with a subtle red left-border. No toast system — errors are part of the conversation flow but styled distinctly.

### Recommendations

```
┌─ Toast Notification — Proposed ─────────────────────────────────────┐
│                                                                     │
│  ┌──────────────────────────────────────────────────────────┐       │
│  │ ✓ File written: src/UI/Theme.php                        │       │
│  └──────────────────────────────────────────────────────────┘       │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────┐       │
│  │ ✗ Error: Permission denied for bash command              │       │
│  └──────────────────────────────────────────────────────────┘       │
│                                                                     │
│  Placement: floating overlay at bottom-right of conversation area.  │
│  Success toasts: 2s auto-dismiss, green border                      │
│  Error toasts: 5s auto-dismiss, red border, Bell on first appear    │
│  Warning toasts: 3s auto-dismiss, amber border                      │
│  Max 3 stacked toasts at once; oldest dismissed first.              │
│                                                                     │
│  ┌─ Error Summary Widget (persistent, in conversation) ──────┐     │
│  │ ✗ 3 errors this turn:                                     │     │
│  │   · Permission denied for bash (src/Test.php:42)          │     │
│  │   · File not found: config/missing.yaml                   │     │
│  │   · Grep returned no matches in excluded directory        │     │
│  └────────────────────────────────────────────────────────────┘     │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Action items:**
1. Implement a **ToastWidget** — overlay at bottom-right, auto-dismissing, color-coded by type.
2. Add an **Error Summary Widget** — shown at end of turn when 2+ errors occurred. Collapsed by default.
3. Play **BEL on error** (in addition to completion) to catch user attention.
4. Keep current inline error display in conversation as-is (for scrollback context).

---

## 6. Subagent Progress: Is It Clear What Child Agents Are Doing?

### Current behavior

`SubagentDisplayManager` implements the most sophisticated feedback in the system:

1. **Show spawn**: tree of agent types + IDs + task descriptions
2. **Show running**: loader with elapsed timer, agent count, done count
3. **Live tree refresh**: every ~0.5s, the tree updates with status icons (✓ done, ● running, ◌ waiting, ✗ failed, ⟳ retrying)
4. **Color escalation**: blue → amber (60s) → red (120s) on the loader
5. **Batch results**: summary with per-agent status, child tree, collapsible full output
6. **Dashboard hint**: "ctrl+a for dashboard" shown in the loader label

Example live tree:
```
⏺ 3 agents (2 running, 1 done)
  ├─ ◌ Explore  research-agent · Find authentication patterns
  ├─ ● General  fix-agent · Patch the login bug (1:23)
  └─ ✓ Plan    plan-agent · 0:42 · 3 tools · Design solution
```

### What's good

- **Excellent tree visualization** — status icons, elapsed times, task descriptions, tool call counts
- **Color escalation** — the only place in the codebase that implements stall detection
- **Agent count + done count** in the loader label gives a sense of progress
- **Batch results** show type-level summary (e.g., "2/3 explore agents finished")

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No tool-level detail per agent** | 🟡 Medium | The tree shows "3 tools" but not *which* tools. The user can't tell if an agent is stuck on a permission prompt vs. reading a file. |
| **No progress bar per agent** | 🟡 Medium | No way to gauge how far along an agent is. Is it 10% done or 90% done? |
| **Dashboard hint buried** | 🟢 Low | "ctrl+a for dashboard" is only visible in the loader label, which may scroll away. Should be in the status bar when subagents are active. |
| **No inter-agent dependency visualization** | 🟢 Low | When agents depend on each other, the tree shows them flat. No indication of `depends_on` relationships. |
| **Background agents are invisible** | 🟡 Medium | `showBatch()` filters out background agents. If the user sees "3 agents active" but only 1 result appears, they may be confused about where the other 2 went. |

### Comparison: No direct competitor

No other terminal AI agent implements subagent visualization at this level. Claude Code has no subagent UI. Aider has no subagents. Cursor's agent system is GUI-only. This is a **competitive advantage** for KosmoKrator.

### Recommendations

```
┌─ Subagent Tree — Proposed Enhancement ──────────────────────────────┐
│                                                                     │
│  ⏺ 3 agents (1 running, 1 waiting, 1 done)  1:42                   │
│  ├─ ✓ Plan    plan-agent  0:42 · 3 tools                           │
│  │   └─ depends on: [fix-agent]                                     │
│  ├─ ● General fix-agent  1:23 · Reading auth.php…                  │
│  │   ├─ ☽ Read  src/auth/Login.php                                 │
│  │   └─ ◉ Running grep for "password_hash"…                        │
│  └─ ◌ Explore research-agent                                       │
│      └─ waiting for fix-agent…                                     │
│                                                                     │
│  Status bar: Edit · Guardian · 12k/200k · 2/3 agents · 1:42        │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Action items:**
1. Show **current tool** under each running agent node (single line, updates in real-time).
2. Render **`depends_on` relationships** with subtle connector lines.
3. Add **agent count to status bar** when subagents are active (`2/3 agents`).
4. Surface **background agent count** — "2 agents in background" as a subtle indicator.

---

## 7. Comprehensive Recommendations

### Priority Matrix

| # | Recommendation | Impact | Effort | Priority |
|---|---------------|--------|--------|----------|
| R1 | Replace mythological phrases with contextual verbs | 🔴 High | 🟡 Medium | **P0** |
| R2 | Add stall detection + color escalation to main spinner | 🔴 High | 🟡 Medium | **P0** |
| R3 | Add current action to status bar | 🔴 High | 🟢 Low | **P0** |
| R4 | Add cost display to status bar | 🟡 Medium | 🟢 Low | **P1** |
| R5 | Implement toast notification system | 🟡 Medium | 🔴 High | **P1** |
| R6 | Stream reasoning token previews | 🟡 Medium | 🟡 Medium | **P1** |
| R7 | Add tool-specific executing labels | 🟡 Medium | 🟢 Low | **P1** |
| R8 | Add error summary widget | 🟡 Medium | 🟡 Medium | **P2** |
| R9 | Show current tool under subagent nodes | 🟡 Medium | 🟡 Medium | **P2** |
| R10 | Surface cancel hint after 30s stall | 🟡 Medium | 🟢 Low | **P2** |
| R11 | Render agent dependencies in tree | 🟢 Low | 🟡 Medium | **P3** |
| R12 | Add background agent indicator | 🟢 Low | 🟢 Low | **P3** |
| R13 | Differentiate spinners by tool type | 🟢 Low | 🟢 Low | **P3** |

### Architecture Recommendations

#### 7.1 Activity Context Provider

Create a new class that tracks the semantic context of what's happening:

```php
// Proposed: src/UI/Tui/ActivityContext.php
final class ActivityContext
{
    private string $verb = 'Ready';           // "Reading", "Thinking", "Editing"
    private ?string $target = null;           // "Theme.php", "3 files"
    private ?string $detail = null;           // "line 42–80"
    private float $lastActivityAt = 0.0;      // timestamp of last token/event
    private int $stallTier = 0;               // 0=active, 1=slow, 2=stalled, 3=frozen
    
    public function update(string $verb, ?string $target = null, ?string $detail = null): void;
    public function touch(): void;             // reset lastActivityAt
    public function getStallTier(): int;       // computed from elapsed since lastActivityAt
    public function getStatusLabel(): string;  // "Reading Theme.php…"
    public function getStatusColor(): string;  // blue/amber/orange/red per stallTier
}
```

This would be injected into TuiAnimationManager, TuiToolRenderer, and TuiCoreRenderer, replacing the scattered hardcoded logic.

#### 7.2 Toast Widget

```php
// Proposed: src/UI/Tui/Widget/ToastWidget.php
final class ToastWidget extends AbstractWidget
{
    public const SUCCESS = 'success';  // 2s auto-dismiss, green
    public const WARNING = 'warning';  // 3s auto-dismiss, amber
    public const ERROR   = 'error';    // 5s auto-dismiss, red
    
    public function addToast(string $message, string $type, int $durationMs = 3000): void;
    public function tick(): void;  // called from render loop, dismisses expired toasts
}
```

#### 7.3 Status Bar Enhancement

Add a 6th segment to the status bar message:

```
Current:  "{mode} · {permission} · {tokens} · {cost} · {model}"
Proposed: "{mode} · {permission} · {tokens} · {cost} · {model} · {action}"
```

The `{action}` segment is populated from `ActivityContext::getStatusLabel()` and updates in real-time during breathing animation ticks.

---

## Appendix A: Spinner Inventory

KosmoKrator ships 14 custom spinners, all celestial-themed:

| Name | Frames | Visual |
|------|--------|--------|
| cosmos | ✦✧⊛◈⊛✧ | Pulsing cosmic gem |
| planets | ☿♀♁♂♃♄♅♆ | Planetary orbit |
| elements | 🜁🜂🜃🜄 | Alchemical elements |
| stars | ⋆✧★✦★✧ | Twinkling stars |
| ouroboros | ◴◷◶◵ | Serpent cycle |
| oracle | ◉◎◉○◎○ | All-seeing eye |
| runes | ᚠᚢᚦᚨᚱᚲᚷᚹ | Elder Futhark runes |
| fate | ⚀⚁⚂⚃⚄⚅ | Dice of fate |
| sigil | ᛭⊹✳✴✳⊹ | Arcane sigil pulse |
| serpent | ∿≀∾≀ | Cosmic serpent wave |
| eclipse | ◐◓◑◒ | Solar eclipse |
| hourglass | ⧗⧖⧗⧖ | Sands of Chronos |
| trident | ψΨψ⊥ | Poseidon's trident |
| aether | ·∘○◌○∘ | Aetheric ripple |

**Assessment:** The spinners are visually distinctive and on-brand. The random selection per thinking phase adds variety. No changes needed to the spinner system itself — the issue is what text accompanies them.

## Appendix B: Phase Transition Flow

```
User submits prompt
       │
       ▼
  ┌─────────┐  enterThinking()     Blue breathing   Random phrase
  │ Thinking │ ──────────────────►  30fps sine wave  + elapsed timer
  └────┬────┘
       │ (model emits tool call)
       ▼
  ┌─────────┐  enterTools()       Amber breathing   Same phrase
  │  Tools   │ ──────────────────►  30fps sine wave  (no phrase change)
  └────┬────┘
       │ (all tools complete, streaming done)
       ▼
  ┌─────────┐  enterIdle()        All timers cancel  Widgets removed
  │  Idle    │ ──────────────────►  breathColor=null  Notification sent
  └─────────┘
```

**Gap:** There's no phase for "Streaming Response" — the Idle transition happens after `streamComplete()`, but the user sees no indicator that the agent is actively composing text. The markdown widget updates in real-time, but there's no status bar indicator for "Writing response…".

## Appendix C: Thinking Phrases (Current)

All 15 phrases from `TuiAnimationManager::THINKING_PHRASES`:

| # | Phrase |
|---|--------|
| 1 | ◈ Consulting the Oracle at Delphi… |
| 2 | ♃ Aligning the celestial spheres… |
| 3 | ⚡ Channeling Prometheus' fire… |
| 4 | ♄ Weaving the threads of Fate… |
| 5 | ☽ Reading the astral charts… |
| 6 | ♂ Invoking the nine Muses… |
| 7 | ♆ Traversing the Aether… |
| 8 | ♅ Deciphering cosmic glyphs… |
| 9 | ⚡ Summoning Athena's wisdom… |
| 10 | ☉ Attuning to the Music of the Spheres… |
| 11 | ♃ Gazing into the cosmic void… |
| 12 | ◈ Unraveling the Labyrinth… |
| 13 | ♆ Communing with the Titans… |
| 14 | ♄ Forging in Hephaestus' workshop… |
| 15 | ☽ Scrying the heavens… |

**Recommendation:** Keep these as optional flavor (e.g., `--theme celestial` flag) but default to semantic verbs drawn from context.
