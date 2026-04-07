# UX Audit: Subagent Swarm Visibility

> **Research Question**: How well does KosmoKrator visualize the subagent swarm?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `SubagentDisplayManager.php`, `SwarmDashboardWidget.php`, `AgentDisplayFormatter.php`, `AgentTreeBuilder.php`, `TuiModalManager.php`, `TuiInputHandler.php`, `SubagentOrchestrator.php`

---

## Executive Summary

KosmoKrator's subagent visualization is **architecturally strong but surface-level weak**. The backend captures rich telemetry — per-agent status, elapsed time, tool-call counts, token usage, cost, ETA, dependency graphs, retry state — but the default in-conversation view exposes barely a third of it. The user sees a live tree with status dots and a loader spinner, with a breadcrumb hint (`ctrl+a for dashboard`) leading to a full-screen dashboard that *does* show the full picture. The problem: most users will never open the dashboard, and the inline view doesn't tell enough of the story.

Compared to htop, GitHub Actions, cargo, and VS Code's task panel, KosmoKrator's default swarm view is **less informative at a glance**. All four reference systems make parallel progress immediately visible without requiring a secondary view. KosmoKrator hides its best visualization behind a non-discoverable keyboard shortcut.

**Severity**: Medium-High. Swarm mode is KosmoKrator's differentiating feature. If users can't see what the swarm is doing, they lose trust and abort early.

---

## 1. Current Visualization Components

### 1.1 Architecture Overview

```
┌──────────────────────────────────────────────────────────────────┐
│                      Conversation (scroll)                       │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │ subagent-container (ContainerWidget)                       │  │
│  │                                                            │  │
│  │  ┌─ subagent-tree (TextWidget) ──────────────────────────┐ │  │
│  │  │ ⏺ 3 agents (2 running, 1 done)                       │ │  │
│  │  │ ├─ ● Explore  research-1  · Research auth patterns (42s)│ │  │
│  │  │ ├─ ● General  implement  · Write auth middleware (38s) │ │  │
│  │  │ └─ ✓ Explore  audit-1    · 1m 12s · 8 tools           │ │  │
│  │  └──────────────────────────────────────────────────────┘ │  │
│  │                                                            │  │
│  │  ┌─ CancellableLoaderWidget ────────────────────────────┐ │  │
│  │  │ ⟡ 3 agents active · 1 done · 0:42 · ctrl+a dash     │ │  │
│  │  └──────────────────────────────────────────────────────┘ │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                  │
│  ... conversation continues scrolling ...                        │
└──────────────────────────────────────────────────────────────────┘
```

The system has **four visualization layers**:

| Layer | Component | Trigger | Richness |
|-------|-----------|---------|----------|
| **Spawn tree** | `SubagentDisplayManager::showSpawn()` | Agent tool call parsed | Queued status only, no live data |
| **Running tree + loader** | `showRunning()` + `tickTreeRefresh()` | Orchestrator starts | Live status, elapsed, tool counts |
| **Batch results** | `showBatch()` | All agents complete | Success/fail, child trees, previews |
| **Full dashboard** | `SwarmDashboardWidget` via `/agents` or Ctrl+A | User action | Tokens, cost, ETA, per-type breakdown, failures |

### 1.2 Data Flow

```
SubagentOrchestrator (stats array)
    ↓ buildLiveAgentTree()
AgentTreeBuilder::buildSubtree()
    ↓ refreshTree()
SubagentDisplayManager::renderLiveTree()  ← inline tree (in conversation)
    ↓
TuiModalManager::showAgentsDashboard()   ← Ctrl+A / /agents
    ↓
SwarmDashboardWidget::render()           ← full-screen overlay
```

### 1.3 Code Locations

| Concern | File | Key Methods |
|---------|------|-------------|
| Inline tree lifecycle | `SubagentDisplayManager.php:47-355` | `showSpawn()`, `showRunning()`, `showBatch()`, `refreshTree()`, `tickTreeRefresh()` |
| Tree node rendering | `SubagentDisplayManager.php:308-355` | `renderTreeNodes()` — box-drawing tree with status icons |
| Dashboard rendering | `SwarmDashboardWidget.php:80-220` | `render()` — progress bar, resources, active, failures, by-type |
| Formatting utilities | `AgentDisplayFormatter.php` | `formatElapsed()`, `formatAgentStats()`, `renderChildTree()`, `extractResultPreview()` |
| Tree data builder | `AgentTreeBuilder.php` | `buildSpawnTree()`, `buildSubtree()` — flat→nested transform |
| Modal overlay | `TuiModalManager.php:375-430` | `showAgentsDashboard()` — suspension + auto-refresh |
| Input handling | `TuiInputHandler.php:178-184` | `\x01` byte → `/agents` command handler |

---

## 2. Audit by Research Question

### 2.1 Is it clear what agents are running?

**Partially.** The inline tree shows agent IDs, types, and task descriptions:

```
⏺ 3 agents (2 running, 1 done)
├─ ● Explore  research-1  · Research auth patterns (42s)
├─ ● General  implement   · Write auth middleware (38s)
└─ ✓ Explore  audit-1     · 1m 12s · 8 tools
```

**What works:**
- Status icon differentiation (● running, ✓ done, ✗ failed, ◌ queued, ⟳ retrying) is clear and follows familiar conventions
- Agent type (`Explore`, `General`, `Plan`) is always shown with a consistent ` ucfirst()` label
- Task description is truncated at 50 chars with `…` — prevents line overflow
- Summary header (`⏺ 3 agents (2 running, 1 done)`) gives at-a-glance counts

**What's missing:**
- **No dependency graph visualization.** The `depends_on` and `group` coordination tags are formatted by `AgentDisplayFormatter::formatCoordinationTags()` but this method is **never called during the running phase** — it only exists for spawn display. The user cannot see which agents are blocked on others.
- **No per-agent progress indication.** An agent that's been running for 60 seconds shows the same `●` dot as one that just started. There's no progress bar, no tool-call-in-progress indicator, no phase indicator.
- **Agent IDs are often auto-generated** (`agent-1`, `agent-2`). The `AgentTreeBuilder::buildSpawnTree()` falls back to numeric IDs when none is provided, making the tree read like `agent-1`, `agent-2` instead of meaningful names.

**Rating: 6/10** — You can see *something* is running but not *what it's waiting on* or *how far along it is*.

---

### 2.2 Is progress visible?

**Marginally.** Progress is communicated through three channels:

1. **Loader spinner label** — updates every ~1s with "N agents active · M done · M:SS"
2. **Tree node status transitions** — ● → ✓ or ✗ as agents complete
3. **Elapsed time per agent** — shown in parentheses for running agents

**What works:**
- The elapsed timer is accurate and updated frequently (every 33ms for breathing animation, label refresh every 30th tick ≈ 1s)
- Color escalation for long-running agents: blue → amber at 60s → red at 120s (line 222-226 in `SubagentDisplayManager.php`)
- The tree is updated in-place — no flickering or rebuilding

**What's missing:**
- **No aggregate progress bar in the inline view.** The dashboard has one (38-char block bar with percentage), but the inline view only shows "2 running, 1 done" as text.
- **No per-agent progress bar.** The dashboard has per-agent bars (`━━━━━━░░░░░` scaled to 120s max), but the inline tree has nothing.
- **No ETA in the inline view.** The dashboard computes `~2m 30s remaining`, but the user must open the dashboard to see it.
- **No "current tool" indicator.** When an agent is executing a `file_write` vs a `bash` command, the user sees identical `●` dots.

**Rating: 4/10** — You can tell time is passing, but not how much work is left.

---

### 2.3 Can you tell which agents succeeded/failed?

**Yes, in batch results. Not during execution.**

**During execution (live tree):**
```
├─ ● Explore  research-1  · Research auth patterns (42s)   ← amber ●, ambiguous
├─ ● General  implement   · Write auth middleware (38s)     ← amber ●, ambiguous
└─ ✓ Explore  audit-1     · 1m 12s · 8 tools              ← green ✓, clear
```
- Done agents turn green ✓ with elapsed + tool count — **clear**
- Failed/cancelled agents turn red ✗ — **clear**
- Running agents are all amber ● — **no indication of health**

**After completion (`showBatch()`):**

Single agent:
```
✓ Done · 42s · 3 tools
  [collapsible full output]
```

Multiple agents:
```
✓ 3/3 Explore + General agents finished
  ✓ Explore  research-1 · 42s · 3 tools · Found 12 auth patterns
  ✓ General  implement  · 1m 8s · 12 tools · Auth middleware written
  ✓ Explore  audit-1    · 1m 12s · 8 tools
  [collapsible: Full output]
```

**What works:**
- Batch results are well-structured: summary line + per-agent detail + collapsible full output
- Result preview extraction (`extractResultPreview()`) shows the first meaningful output line — very useful
- Child agent trees are nested beneath their parent with proper box-drawing connectors
- Success/fail counts are front and center (`✓ 3/3` or `✓ 2/3`)

**What's missing:**
- **Failed agents during execution aren't highlighted differently from healthy running agents.** If agent-3 fails with an error at second 20, and agents 1-2 keep running for another minute, the tree still shows all three as `●` until the batch completes. (The status *does* update in the tree data — `renderTreeNodes` handles `failed` with ✗ — but only if the tree refresh picks it up before batch display.)
- **Error messages are not shown in the batch inline view.** The dashboard shows truncated errors (28 chars), but the inline batch results only show ✓/✗ icons without the error detail.

**Rating: 7/10** — Post-completion is excellent. During execution is limited.

---

### 2.4 Is the tree structure clear?

**Yes, for shallow trees. Degrades at depth.**

The tree uses standard Unicode box-drawing characters:

```
⏺ 3 agents (2 running, 1 done)
├─ ● Explore  research-1  · Research auth patterns (42s)
├─ ● General  implement   · Write auth middleware (38s)
│  ├─ ● Explore  sub-1  · Write tests (12s)
│  └─ ◌ Explore  sub-2  · · Check types
└─ ✓ Explore  audit-1    · 1m 12s · 8 tools
```

**What works:**
- Box-drawing connectors (├─, └─, │) are universally understood
- Continuation indentation (`│  ` vs `   `) correctly differentiates siblings from last-child branches
- Tree is built recursively from the orchestrator's parent-ID hierarchy — the nesting is accurate
- `AgentTreeBuilder::buildSubtree()` correctly walks `parentId → children` relationships

**What's missing:**
- **No visual differentiation of depth levels.** All levels use the same connector style. At depth 3+, it becomes a wall of `│  │  │  ├─` with no way to quickly identify the root vs leaf nodes.
- **No fold/collapse for completed subtrees.** Once 8 of 10 agents in a subtree finish, those 8 done lines are still visible, pushing the 2 remaining running agents below the fold.
- **Width doesn't scale.** Task descriptions are truncated at 50 chars regardless of terminal width. On a 140-char terminal, the right 40% of the tree area is dead space.

**Rating: 7/10** — Clean for typical 1-2 level depth. Needs attention for deep swarms.

---

### 2.5 Dashboard accessibility (Ctrl+A — is it discoverable?)

**Poor.** The dashboard is KosmoKrator's best swarm visualization, but it's hidden behind two obscurity layers:

**Layer 1: The hint text.**
During agent execution, the loader shows:
```
⟡ 3 agents active · 1 done · 0:42 · ctrl+a for dashboard
```

This hint is:
- **Present only during execution.** Before agents start and after they finish, there is no indication the dashboard exists.
- **Dim-styled** (`Theme::dim()` = gray ANSI color) — low contrast against dark backgrounds.
- **Embedded in a moving spinner.** The loader breathes with a sine-wave color animation, making the dim hint even harder to read.
- **Not in the slash-command cheat sheet.** The welcome screen's "Quick Reference" section shows `/agents` as a slash command, but the Ctrl+A shortcut is not mentioned.

**Layer 2: The `/agents` command.**
- Listed in `TuiInputHandler::SLASH_COMMANDS` with description "Show swarm progress dashboard"
- Appears in slash-command autocomplete when typing `/a...`
- **Only visible when the user types `/` at the prompt** — not shown in any help panel or status bar

**Layer 3: The Ctrl+A binding itself.**
- Implemented as raw byte `\x01` in `TuiInputHandler::handleInput()` (line 178)
- This is `Ctrl+A` which is a standard terminal shortcut, but **KosmoKrator never teaches shortcuts**. There's no keybinding panel, no `?` help overlay, no first-run tutorial.
- The Ctrl+A binding is **not documented anywhere in the TUI itself** — it only appears as the dim hint in the loader.

**Comparison with reference systems:**

| System | Dashboard Access | Discoverability |
|--------|-----------------|-----------------|
| htop | Opens by default — it IS the dashboard | ★★★★★ |
| GitHub Actions | Dashboard is the primary view | ★★★★★ |
| VS Code Tasks | Panel in sidebar, always visible | ★★★★☆ |
| cargo/make | Output is inline, no separate dashboard | N/A |
| **KosmoKrator** | Ctrl+A (undocumented) or `/agents` (buried) | ★★☆☆☆ |

**Rating: 3/10** — The best visualization is the least accessible.

---

### 2.6 Background vs await agents — visual distinction?

**Subtle but present.**

**In the inline tree:**
- **No visual distinction.** Background and await agents render identically with `●` dots during execution. The tree does not show the agent's `mode` field.

**In batch results (`showBatch()`):**
```php
// SubagentDisplayManager.php:281-285
$entries = array_values(array_filter($entries, fn ($e) => 
    ($e['args']['mode'] ?? 'await') !== 'background' && 
    !str_contains($e['result'] ?? '', 'spawned in background')
));
if (empty($entries)) {
    // All background — keep loader and tree running
    return;
}
```

- **Background agents are silently filtered out** from batch results. If all agents are background mode, `showBatch()` returns early with no visible output.
- The loader and tree keep running for background agents — the user sees the spinning animation but gets no result when they finish.

**In the dashboard (`SwarmDashboardWidget`):**
- The dashboard shows all agents regardless of mode — it iterates `$s['active']` and `$s['failures']` without mode filtering.
- However, the agent type column is padded to 8 chars and doesn't show mode — you'd need to infer it from behavior (background agents complete without showing results inline).

**The problem:** Background agents are KosmoKrator's "fire and forget" mode. The user says `subagent(..., mode: 'background')` and the agent works silently. The current visualization:
1. Shows the agent in the tree while running ✓
2. Removes it from batch results when done ✓ (intentional)
3. Does NOT indicate it was a background agent ✗
4. Does NOT show "N background agents still running" ✗

This means a user who sees 5 agents spawn but only 3 results might be confused about where the other 2 went.

**Rating: 4/10** — Background agents are handled functionally but not communicated.

---

### 2.7 Resource usage (tokens, cost) — visible?

**Only in the dashboard. Not in the inline view.**

**Inline tree — what's shown:**
```
├─ ● Explore  research-1  · Research auth patterns (42s)
└─ ✓ Explore  audit-1     · 1m 12s · 8 tools
```
- Elapsed time ✓
- Tool call count ✓
- Token count ✗
- Cost ✗
- Rate ✗
- ETA ✗

**Dashboard — what's shown:**
```
┌──────────────────────────────────────────────────────────────────────┐
│                                                                      │
│  ⏺  S W A R M   C O N T R O L                                      │
│                                                                      │
│  █████████████████████░░░░░░░░░░░░░░░░░░  66.7%                     │
│  2 of 3 agents completed                                             │
│                                                                      │
│  ✓ 2 done   ● 1 running   ◌ 0 queued   ✗ 0 failed                 │
│                                                                      │
│  ├─── ☉ Resources ─────────────────────────────────────────────┤    │
│                                                                      │
│  Tokens    12.4k in  ·  3.2k out  ·  15.6k total                   │
│  Cost      $0.08   ·  avg $0.03/agent                               │
│  Elapsed   1m 12s  ·  rate 2.5 agents/min                          │
│  ETA       ~24s remaining                                           │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

The dashboard is excellent — token formatting uses `Theme::formatTokenCount()` (12.4k, 1.2M), cost uses `Theme::formatCost()`, and the per-type breakdown shows cost distribution across agent types. The auto-refresh timer (2s interval in `TuiModalManager.php:409`) keeps it live.

**The problem:** This data is invisible unless the user presses Ctrl+A. For cost-sensitive users (which is everyone using LLM APIs), not seeing token consumption in real-time is a trust issue.

**Comparison:**

| System | Resource Visibility | Location |
|--------|---------------------|----------|
| GitHub Actions | Minutes, parallel jobs | Always visible in sidebar |
| htop | CPU%, MEM%, TIME | Column-based, always visible |
| Claude Code CLI | Token count | Shown in status bar after each response |
| **KosmoKrator** | Tokens, cost, ETA | Hidden behind Ctrl+A |

**Rating: 3/10 inline / 9/10 dashboard.** The data exists but isn't surfaced where users need it.

---

## 3. Comparative Analysis

### 3.1 htop — Parallel Process View

htop is the gold standard for "many things happening at once" visualization:

```
  PID USER     PRI NI VIRT  RES   SHR  S CPU% MEM%  TIME+  Command
  1423 root      20  0  452M  28M  8.4M S 12.3 1.4  1:42.3 node server.js
  1424 root      20  0  120M  12M  3.2M R  8.1 0.6  0:52.1 cargo build
  1425 root      20  0   98M  8.2M 2.1M S  2.4 0.4  0:12.3 make -j4
```

**What KosmoKrator can learn:**
- **Columnar layout.** htop uses fixed-width columns for CPU%, MEM%, TIME — instantly scannable. KosmoKrator's tree uses free-form text with `·` separators, requiring linear scanning.
- **Always-visible status.** Every process's state is visible at all times. KosmoKrator hides most metrics in the dashboard.
- **Color coding by resource usage.** htop turns bars red when CPU > 50%. KosmoKrator only escalates the loader color after 60s elapsed — no per-agent color coding.

### 3.2 GitHub Actions — Pipeline Visualization

GitHub Actions shows parallel jobs as a DAG:

```
┌─────────┐  ┌─────────┐  ┌─────────┐
│  Build   │  │  Lint   │  │  Test   │
│    ✓     │  │    ✓     │  │    ●    │
│   42s    │  │   12s    │  │  1m 8s  │
└────┬─────┘  └────┬─────┘  └────┬────┘
     │              │              │
     └──────────────┼──────────────┘
                    │
              ┌─────┴─────┐
              │  Deploy    │
              │    ◌       │
              │  waiting   │
              └───────────┘
```

**What KosmoKrator can learn:**
- **Dependency graph.** GitHub Actions shows which jobs block others. KosmoKrator has `depends_on` data but doesn't visualize it.
- **Phase/state coloring.** Distinct colors for waiting, running, success, failure — instantly scannable. KosmoKrator has this in icons but not in row background or border color.
- **Time-to-completion per node.** Always visible. KosmoKrator shows elapsed but not ETA per agent.

### 3.3 cargo/make -j — Build Parallel Output

```
   Compiling serde v1.0.188
   Compiling tokio v1.32.0
   Compiling kosmokrator v0.1.0
    Finished dev [unoptimized + debuginfo] target(s) in 42.3s
```

**What KosmoKrator can learn:**
- **Minimal but sufficient.** Build systems show just the verb + target + timing. KosmoKrator's tree is already more informative than this — the issue isn't structure but density of useful data.
- **Running counter.** Cargo shows "Compiling N/M" progress. KosmoKrator does this ("N agents active · M done") — this is a point of parity.

### 3.4 VS Code Task Panel

VS Code shows running tasks in a sidebar with:
- Task name + spinning icon
- Click to expand output
- Progress bar for tasks that report progress
- Stop button per task

**What KosmoKrator can learn:**
- **Inline expand/collapse per agent.** KosmoKrator's `CollapsibleWidget` is used for batch results but not for individual running agents. If you could expand `● Explore research-1` to see its live output mid-execution, that would be transformative.
- **Per-task action buttons.** VS Code lets you stop/restart individual tasks. KosmoKrator has cancellation support (`SubagentOrchestrator::cancelAll()`) but no UI to cancel a single agent.

---

## 4. Key Findings Summary

| # | Finding | Severity | Component |
|---|---------|----------|-----------|
| F1 | Dashboard hidden behind non-discoverable shortcut | High | `TuiInputHandler.php:178` |
| F2 | No dependency graph visualization | Medium | `SubagentDisplayManager.php` |
| F3 | No per-agent progress bar or phase indicator | Medium | `renderTreeNodes()` |
| F4 | Token/cost data invisible without dashboard | High | `SubagentDisplayManager.php` |
| F5 | Background agents not visually distinguished | Medium | `showBatch()` filter |
| F6 | No inline ETA — only in dashboard | Medium | Loader label |
| F7 | Failed agents during execution look like running agents | Low-Med | Tree refresh timing |
| F8 | Deep trees (>2 levels) visually degrade | Low | Tree indentation |
| F9 | No way to inspect/cancel individual agents | Medium | No UI for per-agent actions |
| F10 | Color escalation only on elapsed time, not token cost | Low | Loader timer |

---

## 5. Recommendations

### 5.1 Inline Progress Bar (Addresses F3, F6)

Add a compact progress bar to the loader label and tree header:

**Current:**
```
⟡ 3 agents active · 1 done · 0:42 · ctrl+a for dashboard
```

**Proposed:**
```
⟡ ██████░░░░░░ 2/3 · 0:42 · ~24s left · ctrl+a for dashboard
```

Implementation: The dashboard already computes `$pct = $s['done'] / $s['total']` and ETA. Expose this data to the loader label formatter in `SubagentDisplayManager::showRunning()`.

### 5.2 Token/Cost in Status Bar (Addresses F4, F10)

Add a resource ticker to the tree header or a dedicated status bar section:

**Current tree header:**
```
⏺ 3 agents (2 running, 1 done)
```

**Proposed:**
```
⏺ 3 agents (2 running, 1 done) · 15.6k tokens · $0.08
```

Implementation: The `renderLiveTree()` method already has access to the tree data via `$this->treeProvider`. Add token/cost aggregation to the tree provider callback.

### 5.3 Dependency Arrows (Addresses F2)

For agents with `depends_on`, show blocking relationships:

**Current:**
```
├─ ● General  implement  · Write auth (38s)
├─ ◌ Explore  tests      · · Run tests
```

**Proposed:**
```
├─ ● General  implement  · Write auth (38s)
├─ ◌ Explore  tests      · · Run tests ⤏ waiting on implement
```

Implementation: Add `dependsOn` to the tree node data structure in `AgentTreeBuilder::buildSubtree()`. Render in `renderTreeNodes()` for non-running statuses.

### 5.4 Background Agent Badge (Addresses F5)

Add a `◇` badge for background agents in the tree:

**Proposed:**
```
├─ ● Explore  bg-audit  ◇ · Background audit (42s)
├─ ● General  implement    · Write auth (38s)
```

Also add a summary line: `⏺ 3 agents (2 running, 1 done) · 1 background`

### 5.5 Dashboard Discoverability (Addresses F1)

Three changes:

1. **Always show `/agents` in status bar footer** when agents are running:
```
Edit · Guardian ◈ · 3 agents running · /agents for details
```

2. **Add `?` help overlay** that lists keybindings including Ctrl+A.

3. **First-run hint**: When agents are spawned for the first time, show a one-time hint:
```
💡 Tip: Press Ctrl+A or type /agents to see the swarm dashboard
```

### 5.6 Per-Agent Live Output Preview (Addresses F9)

Allow expanding a running agent in the tree to see its last N lines of output:

**Proposed:**
```
├─ ▸ ● Explore  research-1  · Research auth (42s)
│     (press → to expand live output)
```
After pressing →:
```
├─ ▼ ● Explore  research-1  · Research auth (42s)
│     ├─ Reading src/Auth/AuthController.php
│     ├─ Searching for "password_hash" pattern...
│     └─ Found 12 matches in 3 files
```

This is architecturally challenging (requires streaming agent output to the display layer) but would make KosmoKrator's swarm visualization genuinely world-class.

---

## 6. Mockups

### 6.1 Current Inline View (Annotated Issues)

```
                                    ╔══ INSUFFICIENT ══╗
⏺ 3 agents (2 running, 1 done)     ║ No tokens, cost,  ║
├─ ● Explore  research-1           ║ ETA, or deps      ║
│  · Research auth patterns (42s)   ╚══════════════════╝
├─ ● General  implement
│  · Write auth middleware (38s)    ╔══ AMBIGUOUS ═════╗
└─ ✓ Explore  audit-1              ║ All running agents║
   · 1m 12s · 8 tools              ║ look identical    ║
                                    ╚══════════════════╝
  ⟡ 3 agents active · 1 done
    · 0:42 · ctrl+a for dashboard
         ╔═════════════════════╗
         ║ Only hint to the    ║
         ║ full dashboard      ║
         ╚═════════════════════╝
```

### 6.2 Proposed Enhanced Inline View

```
⏺ 3 agents (2 running, 1 done) · 15.6k tokens · $0.08 · ~24s left
├─ ● Explore  research-1  · Research auth (42s) · 5 tools
│     Reading AuthController.php...
├─ ● General  implement   · Write auth (38s) · 8 tools
└─ ✓ Explore  audit-1     · 1m 12s · 8 tools

  ⟡ ██████░░░░ 2/3 · 0:42 · ~24s · 15.6k tok · $0.08
    Ctrl+A dashboard · /agents
```

### 6.3 Proposed Dashboard Redesign

The current dashboard is already strong. Key additions:

```
┌────────────────────────────────────────────────────────────────────────┐
│                                                                        │
│  ⏺  S W A R M   C O N T R O L                                        │
│                                                                        │
│  █████████████████████████████░░░░░░░░  66.7%                          │
│  2 of 3 agents completed  ·  ~24s remaining                           │
│                                                                        │
│  ✓ 2 done   ● 1 running   ◌ 0 queued   ✗ 0 failed   ◇ 1 background  │
│                                                                        │
│  ├─── ☉ Resources ───────────────────────────────────────────────┤    │
│                                                                        │
│  Tokens    15.6k total  (12.4k in · 3.2k out)                        │
│  Cost      $0.08  ·  avg $0.03/agent                                  │
│  Rate      2.5 agents/min                                             │
│                                                                        │
│  ├─── ● Active (1) ──────────────────────────────────────────────┤    │
│                                                                        │
│  ● Explore  research-1                                                │
│    Research auth patterns · 42s · 5 tools                            │
│    depends_on: implement                                              │
│    ▸ Reading AuthController.php...                    ← LIVE PREVIEW  │
│                                                                        │
│  ├─── ✓ Completed (2) ──────────────────────────────────────────┤    │
│                                                                        │
│  ✓ Explore  audit-1      · 1m 12s · 8 tools  · 12.1k tokens         │
│  ✓ General  implement    · 38s · 8 tools      · 3.5k tokens          │
│                                                                        │
│  Esc/q close  ·  auto-refreshes every 2s  ·  → expand agent          │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

### 6.4 Proposed Status Bar Integration

```
┌──────────────────────────────────────────────────────────────────────┐
│  ... conversation ...                                                │
│                                                                      │
│  ⏺ 3 agents · ██████░░░░ 67% · ~24s · 15.6k tok · $0.08           │
│                                                                      │
│  ─────────────────────────────────────────────────────────────────── │
│  ▏                                                                   │
│  ─────────────────────────────────────────────────────────────────── │
│  Edit · Guardian ◈ · ⏺ 3 agents · /agents                          │
└──────────────────────────────────────────────────────────────────────┘
         ▲
    Status bar shows agent count
    and /agents hint when swarm is active
```

---

## 7. Implementation Priority Matrix

| Priority | Recommendation | Effort | Impact | Files |
|----------|---------------|--------|--------|-------|
| **P0** | Token/cost in loader label | Low | High | `SubagentDisplayManager.php` |
| **P0** | Progress bar in loader label | Low | High | `SubagentDisplayManager.php` |
| **P0** | Status bar agent indicator | Medium | High | `TuiCoreRenderer.php` |
| **P1** | Dashboard discoverability hints | Low | High | `SubagentDisplayManager.php`, welcome screen |
| **P1** | Background agent badge | Low | Medium | `renderLiveTree()`, `renderTreeNodes()` |
| **P2** | Dependency arrows in tree | Medium | Medium | `AgentTreeBuilder.php`, `SubagentDisplayManager.php` |
| **P2** | Per-agent token count in tree | Low | Medium | `renderTreeNodes()` |
| **P3** | Live output preview per agent | High | High | Architecture change — streaming |
| **P3** | Per-agent cancel action | High | Medium | `SubagentOrchestrator.php`, new UI |
| **P3** | Collapsible subtrees for completed | Medium | Low | `SubagentDisplayManager.php` |

---

## 8. Conclusion

KosmoKrator's swarm visualization has a strong foundation — the `SwarmDashboardWidget` is information-rich and well-designed. The critical gap is that this dashboard is **the only place where most of the useful information appears**, and most users will never find it. The inline view (which is what users see 95% of the time) shows status icons and elapsed time but hides the metrics that matter most: tokens, cost, and progress.

The single highest-impact change is to **surface token count and a progress bar in the inline loader label**. This requires minimal code change (the data already flows through `$this->treeProvider`) and immediately addresses the two biggest trust gaps: "how much is this costing?" and "how long until it's done?"

For world-class swarm visualization, the north star should be: **the inline view should be 80% as informative as the dashboard**. Today it's roughly 30%. The dashboard should be for power-user details (per-type breakdown, failure investigation, individual agent inspection), not for basic metrics that every user needs.
