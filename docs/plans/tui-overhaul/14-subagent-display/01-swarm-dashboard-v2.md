# Swarm Dashboard V2 — World-Class Multi-Agent TUI Dashboard

> **Module**: `14-subagent-display`  
> **Depends on**: `02-widget-library`, `06-layout`, `09-input-system`  
> **Status**: Plan  
> **Replaces**: `SwarmDashboardWidget` (current single-panel overlay)

---

## Table of Contents

1. [Problem](#problem)
2. [Design Inspirations](#design-inspirations)
3. [Architecture Overview](#architecture-overview)
4. [Dashboard Layouts](#dashboard-layouts)
5. [Component Specifications](#component-specifications)
6. [Data Flow & Live Updates](#data-flow--live-updates)
7. [Keyboard Navigation](#keyboard-navigation)
8. [PHP Class Structure](#php-class-structure)
9. [Migration Path](#migration-path)
10. [Future Extensions](#future-extensions)

---

## Problem

The current `SwarmDashboardWidget` is a single fixed-width overlay (70 columns max) with no interactivity beyond dismiss. It renders a static snapshot of swarm state as a flat list. Key limitations:

| Limitation | Impact |
|---|---|
| Fixed 70-column width, no responsive layout | Wastes screen space on wide terminals |
| No keyboard navigation between panels | Cannot inspect individual agents |
| Flat agent list (no hierarchy) | Can't see parent→child relationships |
| Single dismiss action (Esc/q) | No drill-down, expand, or filter |
| No compact vs full-screen modes | Overlay obscures conversation context |
| Progress bar is time-based estimate (120s) | Inaccurate for variable-length tasks |
| No resource rate calculations | No tokens/min or cost projections |
| Static render — no panel focus state | Feels like a status dump, not a dashboard |

**Goal**: Transform from a static overlay into an interactive, multi-panel dashboard inspired by GitHub Actions, Docker Compose, k9s, and CI/CD pipeline views — while keeping it instantly dismissible and non-blocking.

---

## Design Inspirations

### GitHub Actions Workflow View
- **Pipeline stages** with parallel branches → our agent dependency tree
- **Job-level status badges** (✓ ✓ ✗ ● ◌) → our per-agent status icons
- **Expandable job logs** → our agent detail panel (Enter to expand)
- **Re-run button** on failures → our retry mechanism

### Docker Compose `docker compose up` Dashboard
- **Service list** with colored status → our agent list with type-colored status
- **Per-service resource usage** (CPU/mem) → our per-agent token/tool usage
- **Log streaming** per container → our agent progress bars

### k9s Kubernetes Dashboard
- **Tab-based navigation** (Pods, Logs, Events) → our panel focus (Tab to cycle)
- **Column sorting** → our sort-by-type/elapsed/status
- **Describe view** (d) → our agent detail expansion
- **Resource meters** (CPU/Memory bars) → our token/cost gauges

### CI/CD Pipeline Views (GitLab, Jenkins Blue Ocean)
- **Stage progress bars** with parallel lanes → our grouped agent progress
- **Duration tracking** per stage → our per-agent elapsed timers
- **Artifact counts** → our tool call counts
- **Overall pipeline ETA** → our completion estimate

---

## Architecture Overview

The V2 dashboard is a **composed widget** with multiple focusable panels arranged in a responsive grid. It operates in two modes:

```
┌─────────────────────────────────────────────────┐
│              SwarmDashboardV2Widget              │
│  (manages layout, focus, keyboard dispatch)      │
│                                                  │
│  ┌──────────────────────────────────────────────┐│
│  │         ProgressHeaderPanel                   ││
│  │  [overall bar + counts + elapsed]             ││
│  └──────────────────────────────────────────────┘│
│  ┌──────────────────┐  ┌────────────────────────┐│
│  │                  │  │                        ││
│  │  AgentTreePanel  │  │  ActiveAgentsPanel      ││
│  │  (hierarchy)     │  │  (per-agent progress)   ││
│  │  [FOCUSABLE]     │  │  [FOCUSABLE]            ││
│  │                  │  │                        ││
│  └──────────────────┘  └────────────────────────┘│
│  ┌──────────────────┐  ┌────────────────────────┐│
│  │                  │  │                        ││
│  │ ResourceMeterPanel│  │ FailurePanel           ││
│  │ (tokens/cost/rate)│  │ (errors + retry count) ││
│  │                  │  │  [FOCUSABLE]            ││
│  └──────────────────┘  └────────────────────────┘│
│  ┌──────────────────────────────────────────────┐│
│  │         TypeBreakdownPanel                    ││
│  │  [bar chart by agent type]                    ││
│  └──────────────────────────────────────────────┘│
│  ┌──────────────────────────────────────────────┐│
│  │         FooterBar                             ││
│  │  [keybindings hint]                           ││
│  └──────────────────────────────────────────────┘│
└─────────────────────────────────────────────────┘
```

**Key principle**: The dashboard is **non-modal** — it overlays the conversation but the agent loop continues running underneath. Data flows in via `setData()` calls on a refresh timer, not by pausing execution.

---

## Dashboard Layouts

### 3.1 Full-Screen Mode (default)

Activated by `ctrl+a` from the main TUI. Takes the full terminal. Responsive layout adapts to terminal width:

#### Wide terminal (≥100 columns)

```
┌──────────────────────────────────────────────────────────────────────────┐
│  ⏺ S W A R M   C O N T R O L                                            │
│  ████████████████████░░░░░░░░░░░░░░░░░░░░░  52.3%   12 of 23 agents     │
│  ✓ 12 done   ● 5 running   ◌ 4 queued   ✗ 2 failed    2m 45s elapsed   │
├──────────────────────────────────────┬───────────────────────────────────┤
│  ◈ Agent Tree                        │  ● Active Agents (5)             │
│  ├── ✓ Explore  src-check     12s    │  ┌─────────────────────────────┐ │
│  ├── ● General  plan-refactor  45s   │  │ ● General  plan-refactor    │ │
│  │   ├── ✓ Explore  find-refs  8s    │  │   ████████░░░░ 45s  12k tok │ │
│  │   ├── ● Explore  deep-scan  32s   │  │   5 tools · group: writers  │ │
│  │   └── ◌ Explore  type-check        │  ├─────────────────────────────┤ │
│  ├── ◌ General  test-runner           │  │ ● Explore  deep-scan        │ │
│  │   ◌ depends on: plan-refactor      │  │   ██████░░░░░░ 32s  8k tok  │ │
│  └── ✗ Explore  api-check      5s    │  │   3 tools · idle 15s        │ │
│                                      │  ├─────────────────────────────┤ │
│                                      │  │ ● Explore  log-parser       │ │
│                                      │  │   ████░░░░░░░░ 18s  4k tok  │ │
│                                      │  │   2 tools · 2 retries       │ │
│                                      │  ├─────────────────────────────┤ │
│                                      │  │ ● Plan     arch-review      │ │
│                                      │  │   ██░░░░░░░░░░ 12s  2k tok  │ │
│                                      │  │   1 tool                     │ │
│                                      │  │ ... 1 more running           │ │
│                                      │  └─────────────────────────────┘ │
├──────────────────────────────────────┴───────────────────────────────────┤
│  ☉ Resources                    │  ✗ Failures (2)                        │
│  Tokens  42.1k in · 8.3k out   │  ✗ Explore  api-check                 │
│  Cost    $0.042 · avg $1.8m/agent│    Retry 1/2 · 429 rate limit         │
│  Rate    3.2k tok/min           │  ✗ Explore  lint-old                  │
│  ETA     ~1m 20s remaining      │    Failed · context window exceeded    │
├─────────────────────────────────┴────────────────────────────────────────┤
│  ◈ By Type                                                               │
│  General  ████████████░░░░░░  5 · 2 done · 2 running · 1 queued         │
│  Explore  ████████████████░░  14 · 8 done · 3 running · 2 queued · 1 fail│
│  Plan     ████░░░░░░░░░░░░░░  4 · 2 done · 0 running · 1 queued · 1 fail│
├──────────────────────────────────────────────────────────────────────────┤
│  Esc close  ·  Tab next panel  ·  ↑↓ scroll  ·  Enter expand  ·  r retry│
└──────────────────────────────────────────────────────────────────────────┘
```

#### Narrow terminal (<100 columns) — single column

```
┌────────────────────────────────────┐
│  ⏺ S W A R M   C O N T R O L      │
│  ████████████░░░░░░░░░  52.3%      │
│  ✓12  ●5  ◌4  ✗2    2m 45s        │
├────────────────────────────────────┤
│  ◈ Agent Tree                      │
│  ├── ✓ Explore  src-check    12s   │
│  ├── ● General  plan-refactor 45s  │
│  │   ├── ✓ Explore  find-refs 8s   │
│  │   └── ● Explore  deep-scan 32s  │
│  ├── ◌ General  test-runner        │
│  └── ✗ Explore  api-check    5s   │
├────────────────────────────────────┤
│  ● Active (5)                      │
│  ● General  plan-refactor  45s     │
│    ████████░░  12k tok · 5 tools   │
│  ● Explore  deep-scan     32s     │
│    ██████░░░░  8k tok · 3 tools   │
│  ... 3 more                        │
├────────────────────────────────────┤
│  ☉ Resources                       │
│  Tokens  42.1k in · 8.3k out      │
│  Cost    $0.042  ·  Rate 3.2k/m   │
│  ETA     ~1m 20s                   │
├────────────────────────────────────┤
│  ✗ Failures (2)                    │
│  ✗ api-check · 429 (1/2 retries)  │
│  ✗ lint-old · context exceeded     │
├────────────────────────────────────┤
│  Esc · Tab · ↑↓ · Enter · r       │
└────────────────────────────────────┘
```

### 3.2 Compact Mode (overlay strip)

Activated by `ctrl+s` from the main TUI. Shows a minimal strip at the bottom of the conversation area — 6–8 lines. Does NOT take over the full screen. Ideal for monitoring swarm progress while continuing to read conversation output.

```
┌──────────────────────────────────────────────────────────────────────────┐
│  (conversation content scrolls above...)                                 │
│                                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│  ⏺ Swarm: ████████████████████░░░░░░░░  67.8%  ·  15/23  ·  3m 12s     │
│  ✓ 15 done  ·  ● 4 running  ·  ◌ 2 queued  ·  ✗ 2 failed              │
│  ● General plan-refactor 45s · ● Explore deep-scan 32s                  │
│  ● Explore log-parser 18s · ● Plan arch-review 12s                      │
│  ✗ api-check: 429 rate limit (retry 1/2)                                │
│  Tokens 50.4k · Cost $0.051 · ETA ~45s · ctrl+a for full dashboard      │
└──────────────────────────────────────────────────────────────────────────┘
```

### 3.3 Agent Detail Expansion

When a user presses `Enter` on a focused agent (in tree or active panel), the dashboard shows an inline detail panel:

```
┌──────────────────────────────────────────────────────────────────────────┐
│  ┌─ Agent: plan-refactor ──────────────────────────────────────────────┐ │
│  │  Type     General                                                   │ │
│  │  Status   ● Running (45s elapsed)                                   │ │
│  │  Parent   root                                                      │ │
│  │  Children ✓ find-refs (8s) · ● deep-scan (32s) · ◌ type-check      │ │
│  │  Tokens   12.4k in · 3.1k out · 15.5k total                        │ │
│  │  Tools    5 calls (latest: file_edit)                               │ │
│  │  Group    writers                                                   │ │
│  │  Retries  0                                                         │ │
│  │  Task    Refactor the authentication module to use PSR-15           │ │
│  │           middleware pattern instead of the current ad-hoc approach  │ │
│  │  Activity Last tool call 3s ago                                     │ │
│  │                                                                     │ │
│  │  Tool History:                                                      │ │
│  │   1. file_read  src/Auth/AuthService.php          0.8k tokens      │ │
│  │   2. grep       pattern:"class AuthService"        0.4k tokens      │ │
│  │   3. file_read  src/Auth/Middleware.php            0.6k tokens      │ │
│  │   4. file_edit  src/Auth/AuthService.php           2.1k tokens      │ │
│  │   5. bash       phpunit tests/Auth/                1.5k tokens      │ │
│  └─────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
│  Esc back  ·  ↑↓ scroll  ·  c cancel agent                              │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Component Specifications

### 4.1 ProgressHeaderPanel

**Responsibility**: Overall completion summary — the "pipeline status bar."

**Data inputs** (from `SwarmSummary`):
- `int total`, `done`, `running`, `queued`, `failed`, `cancelled`
- `float elapsed` (total wall-clock time since first agent started)
- `float eta` (estimated time to completion)

**Render logic**:
```
Progress bar:
  - Width: available columns minus padding (≈ 40 chars in full-screen)
  - Fill color gradient: green (<50%) → gold (50–80%) → cyan (>80%)
  - Fill character: █ (filled) + ░ (empty)
  
Status counts (single line):
  ✓ {done} done   ● {running} running   ◌ {queued} queued   ✗ {failed} failed
  
  Color coding:
    ✓ = Theme::success() (green)
    ● = Theme::accent() (gold)  
    ◌ = Theme::info() (cyan)
    ✗ = Theme::error() (red)
    — Only show non-zero counts

Elapsed time:
  Format: "{elapsed}" using AgentDisplayFormatter::formatElapsed()
  
ETA (conditional):
  Only shown when >0 and at least 1 agent running
  Format: "~{eta} remaining"
  Calculation: (remaining agents / completion rate) based on avg agent duration
```

**Key methods**:
```php
public function render(int $width): array;  // Returns ANSI line array
```

### 4.2 AgentTreePanel

**Responsibility**: Interactive hierarchical view of the agent tree — the "workflow graph."

**Data inputs** (from `AgentTreeBuilder::buildTree()`):
```php
array<int, array{
    id: string,
    type: string,
    task: string,
    status: string,
    elapsed: float,
    toolCalls: int,
    success: bool,
    error: ?string,
    children: array
}>
```

**Render logic**:
- Uses box-drawing characters for hierarchy (├─, └─, │)
- Status icons per agent:
  - `done` → ✓ green
  - `failed` → ✗ red
  - `cancelled` → ✗ red dim
  - `running` → ● amber (breathing animation)
  - `queued` / `waiting` → ◌ gray
  - `retrying` → ⟳ amber
- Agent type colored by `Theme::agentGeneral()`, `Theme::agentPlan()`, `Theme::agentDefault()`
- Elapsed time shown for running/done agents
- Dependency arrows: `→ depends on: id1, id2`
- Group labels: `group: writers`
- Scrollable when tree exceeds panel height
- **Focused agent** highlighted with inverted background

**Focus state**:
- Current selection indicated by `▸` prefix + inverted background on selected line
- `↑`/`↓` to move selection within tree
- `Enter` to expand agent detail
- `→` to expand collapsed subtree, `←` to collapse

**Key methods**:
```php
public function setTreeData(array $nodes): void;
public function render(int $width, int $height): array;
public function getSelectedAgentId(): ?string;
public function moveSelection(int $delta): void;  // +1 down, -1 up
public function toggleExpand(string $agentId): void;
```

### 4.3 ActiveAgentsPanel

**Responsibility**: Per-agent progress display for running/retrying agents — the "container status" view.

**Data inputs** (filtered from `SubagentOrchestrator::allStats()` where `status ∈ {running, retrying}`):
```php
array<string, SubagentStats>  // keyed by agent ID, filtered to active
```

**Per-agent row layout**:
```
┌─────────────────────────────────────┐
│ ● {type}  {id}                      │
│   {progress_bar}  {elapsed}  {tokens}│
│   {tools} tools · {group_or_idle}   │
│   {retry_info}                       │
└─────────────────────────────────────┘
```

**Progress bar calculation**:
The current V1 uses `elapsed / 120.0` as a fixed heuristic. V2 improves this:

```
progress_ratio = switch(true) {
    agent.status === 'retrying' => 0.0,  // Reset on retry
    agent has completed children => weighted child completion,
    agent.toolCalls > 0 => min(toolCalls / estimated_tool_calls, 1.0),
    default => min(elapsed / 60.0, 0.95),  // Time-based with 95% cap
}
```

Where `estimated_tool_calls` defaults to 10 (configurable). The bar uses `━` for filled and `░` for empty, colored by type (`Theme::agentGeneral()`, etc.).

**Token display**: `Theme::formatTokenCount(tokensIn + tokensOut)` with rate `tok/min` calculated from `tokens / elapsed * 60`.

**Idle warning**: If `idleSeconds() > 30`, show amber `idle {n}s` indicator.

**Retry badge**: `↻ retry {attempt}/{maxRetries}` in amber.

**Sorting**: By elapsed descending (longest-running first). Limit display to 8 agents, show "… N more running" for overflow.

**Focus state**:
- Selected agent highlighted
- `Enter` opens detail panel
- `c` cancels the selected running agent

### 4.4 ResourceMeterPanel

**Responsibility**: Aggregate resource consumption — the "cluster metrics" view.

**Data inputs**:
```php
array{
    tokensIn: int,
    tokensOut: int,
    cost: float,
    avgCost: float,
    elapsed: float,
    rate: float,      // agents/min completion rate
    tokenRate: float,  // tokens/min consumption rate
    eta: float
}
```

**Render logic**:
```
Tokens    {in} in  ·  {out} out  ·  {total} total
Cost      ${cost}  ·  avg ${avgCost}/agent
Rate      {tokenRate} tok/min  ·  {agentRate} agents/min
ETA       ~{eta} remaining
```

**Token rate calculation** (new in V2):
```php
$tokenRate = $elapsed > 0 
    ? ($tokensIn + $tokensOut) / $elapsed * 60 
    : 0;
```

**ETA improvement** (V2):
```php
$remaining = $total - $done - $failed;
$avgDuration = /* mean elapsed of completed agents */;
$eta = $remaining * $avgDuration / max($running, 1);
```

This replaces the V1 fixed-rate estimate with an adaptive one based on actual agent completion times.

### 4.5 FailurePanel

**Responsibility**: Failed agent list with error context and retry status — the "failed jobs" view.

**Data inputs** (filtered from `allStats()` where `status === 'failed'`):
```php
array<string, SubagentStats>  // filtered to failed
```

**Per-failure row**:
```
✗ {type}  {id}
  {error_message}  ·  retry {attempt}/{maxRetries}
  elapsed {time}  ·  {toolCalls} tools before failure
```

**Error message**: Truncated to fit panel width, with full message available in detail expansion.

**Recovery indicator**: If `retriedAndRecovered > 0`, show `{N} recovered via retry · {M} permanent` header.

**Retry action**: When a failed agent is focused and has remaining retries, show `[r] retry` hint in footer.

**Focus state**: `Enter` shows full error + tool history. `r` triggers retry.

### 4.6 TypeBreakdownPanel

**Responsibility**: Horizontal bar chart showing agent distribution by type — the "resource allocation" view.

**Data inputs** (from summary):
```php
array<string, array{done: int, running: int, queued: int, failed: int, tokensIn: int, tokensOut: int}>
```

**Render logic**:
```
General  {bar}  {total} · {done} done · {running} running · {queued} queued · {failed} fail
Explore  {bar}  {total} · {done} done · {running} running · {queued} queued · {failed} fail
Plan     {bar}  {total} · {done} done · {running} running · {queued} queued · {failed} fail
```

**Bar width**: Proportional to `count / maxCount` across all types, using type-specific colors:
- General: `Theme::agentGeneral()` (goldenrod)
- Explore: `Theme::agentDefault()` (cyan)
- Plan: `Theme::agentPlan()` (purple)

**Segmented bar** (advanced — optional for V2):
```
General  ██████░░░░██░░░  5 · 2 done · 2 running · 1 queued
         ^^^^   ^^^^  ^
         done   run   queued
```

Each segment colored by status (green/gold/cyan/red).

### 4.7 FooterBar

**Responsibility**: Keybinding hints, contextual to current focus.

**Render logic**:
```
Esc close  ·  Tab next panel  ·  ↑↓ scroll  ·  Enter expand  ·  r retry  ·  c cancel agent
```

Context-sensitive hints:
- Tree focus: `Enter expand · → expand subtree · ← collapse`
- Active agent focus: `Enter detail · c cancel agent`
- Failure focus: `Enter error detail · r retry`
- No focus: `Tab to select panel`

---

## Data Flow & Live Updates

### 5.1 Data Provider: `SwarmDataProvider`

A new class that bridges `SubagentOrchestrator` stats to dashboard-ready arrays. Extracted from the current inline calculation in `TuiRenderer`.

```php
namespace Kosmokrator\UI\Tui\Dashboard;

final class SwarmDataProvider
{
    public function __construct(
        private readonly SubagentOrchestrator $orchestrator,
        private readonly AgentTreeBuilder $treeBuilder,
    ) {}

    /**
     * Compute full summary from current orchestrator state.
     * Called on every refresh tick.
     */
    public function getSummary(): SwarmSummary { ... }

    /**
     * Build agent tree from orchestrator stats.
     */
    public function getTree(): array { ... }

    /**
     * Get active (running/retrying) agent stats.
     * @return array<SubagentStats>
     */
    public function getActiveAgents(): array { ... }

    /**
     * Get failed agent stats.
     * @return array<SubagentStats>
     */
    public function getFailedAgents(): array { ... }
}
```

### 5.2 SwarmSummary Value Object

Replaces the raw `$summary` array with a typed DTO:

```php
namespace Kosmokrator\UI\Tui\Dashboard;

final class SwarmSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $done,
        public readonly int $running,
        public readonly int $queued,
        public readonly int $failed,
        public readonly int $cancelled,
        public readonly int $retrying,
        public readonly int $retriedAndRecovered,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly float $cost,
        public readonly float $avgCost,
        public readonly float $elapsed,
        public readonly float $rate,         // agents/min
        public readonly float $tokenRate,    // tokens/min
        public readonly float $eta,
        /** @var array<string, array{done: int, running: int, queued: int, failed: int, tokensIn: int, tokensOut: int}> */
        public readonly array $byType,
    ) {}

    public function completionPct(): float
    {
        return $this->total > 0 ? $this->done / $this->total : 0.0;
    }
}
```

### 5.3 Refresh Mechanism

The dashboard refreshes on the existing `EventLoop::repeat()` timer from `SubagentDisplayManager`:

```
Current: SubagentDisplayManager::elapsedTimerId (33ms)
  → Updates loader label every 30th tick (~1s)
  → refreshTree() called from breathing animation

V2 Addition: SwarmDashboardV2Widget registers its own refresh
  → EventLoop::repeat(1.0, [$this, 'refresh'])
  → Calls SwarmDataProvider::getSummary() + getTree()
  → Pushes updates to all panels
  → Triggers render via existing renderCallback
```

**Refresh rate**: 1 second for data, 33ms for animation (breathing dots, progress bar shimmer). Animation is purely cosmetic — the data model updates at 1Hz.

### 5.4 Signal-Based State Transitions

```
Agent spawned   → Stats added to orchestrator → Tree gets new node (queued)
Agent starts    → Stats.status = 'running'    → Tree node turns amber, Active panel gains entry
Agent progress  → Stats.toolCalls++           → Active panel progress bar updates
Agent completes → Stats.status = 'done'       → Tree node turns green, Active panel removes entry
Agent fails     → Stats.status = 'failed'     → Failure panel gains entry, Active panel removes
Agent retries   → Stats.status = 'retrying'   → Active panel shows retry badge
```

All transitions are detected by polling `SwarmDataProvider` on the refresh timer — no event bus needed for V1.

---

## Keyboard Navigation

### 6.1 Key Map

| Key | Action | Context |
|-----|--------|---------|
| `Esc` / `q` | Dismiss dashboard (return to conversation) | Global |
| `Tab` | Cycle focus to next panel | Global |
| `Shift+Tab` | Cycle focus to previous panel | Global |
| `↑` / `k` | Move selection up | Tree, Active, Failures |
| `↓` / `j` | Move selection down | Tree, Active, Failures |
| `Enter` | Expand selected agent detail | Tree, Active, Failures |
| `→` / `l` | Expand subtree / enter detail | Tree |
| `←` / `h` | Collapse subtree / exit detail | Tree |
| `r` | Retry failed agent | Failures |
| `c` | Cancel running agent | Active, Detail |
| `1`–`5` | Jump to panel by number | Global |
| `f` | Toggle full-screen / compact mode | Global |
| `?` | Show keybinding help overlay | Global |

### 6.2 Focus Ring

Panels form a focus ring. Only one panel is focused at a time. Focus determines which panel receives directional input:

```
ProgressHeader (non-focusable, info only)
     ↓
AgentTree ←→ ActiveAgents
     ↓           ↓
ResourceMeter ←→ Failures
     ↓
TypeBreakdown (non-focusable, info only)
     ↓
FooterBar (non-focusable, shows context hints)
```

Focus cycle: `Tab` moves through `[AgentTree → ActiveAgents → Failures]` (only focusable panels). `Shift+Tab` reverses.

### 6.3 Panel Focus Indicators

Each focusable panel gets a distinct border when focused:
- **Focused**: Bright border color (white or gold) + `▸` cursor on selected item
- **Unfocused**: Dim border color (gray) + no cursor

```
Focused panel:    ┌─── ◈ Agent Tree ────────────────┐  (bright border)
                  │ ▸ ├── ✓ Explore  src-check  12s │  (selection indicator)
                  │   ├── ● General  plan-ref  45s  │

Unfocused panel:  ┌─── ● Active Agents (5) ─────────┐  (dim border)
                  │   ● General  plan-refactor  45s  │  (no cursor)
```

---

## PHP Class Structure

### 7.1 File Layout

```
src/UI/Tui/
├── Dashboard/
│   ├── SwarmDashboardV2Widget.php    # Main composed widget (replaces SwarmDashboardWidget)
│   ├── SwarmDataProvider.php         # Data bridge from Orchestrator to panels
│   ├── SwarmSummary.php              # Summary value object
│   ├── Panel/
│   │   ├── PanelInterface.php        # Contract for focusable panels
│   │   ├── AbstractPanel.php         # Base panel with border, title, focus state
│   │   ├── ProgressHeaderPanel.php   # Overall progress bar + counts
│   │   ├── AgentTreePanel.php        # Hierarchical agent tree
│   │   ├── ActiveAgentsPanel.php     # Per-agent progress bars
│   │   ├── ResourceMeterPanel.php    # Token/cost/rate gauges
│   │   ├── FailurePanel.php          # Failed agent list
│   │   ├── TypeBreakdownPanel.php    # Bar chart by agent type
│   │   └── FooterBar.php            # Keybinding hints
│   └── Layout/
│       ├── DashboardLayout.php       # Responsive panel arrangement
│       ├── WideLayout.php            # ≥100 columns: 2-column grid
│       └── NarrowLayout.php          # <100 columns: single column stack
└── SubagentDisplayManager.php        # Updated to use V2 dashboard
```

### 7.2 Core Interfaces

```php
namespace Kosmokrator\UI\Tui\Dashboard\Panel;

interface PanelInterface
{
    /** Render the panel to ANSI lines within given dimensions. */
    public function render(int $width, int $height): array;

    /** Whether this panel accepts keyboard focus. */
    public function isFocusable(): bool;

    /** Set focus state. */
    public function setFocused(bool $focused): void;

    /** Handle a key press. Returns true if consumed. */
    public function handleKey(string $key): bool;

    /** Get the panel's unique identifier for focus cycling. */
    public function getPanelId(): string;
}
```

### 7.3 Class Signatures

#### SwarmDashboardV2Widget

```php
namespace Kosmokrator\UI\Tui\Dashboard;

use Kosmokrator\UI\Tui\Dashboard\Panel\PanelInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

class SwarmDashboardV2Widget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private bool $compactMode = false;
    private int $focusedPanelIndex = 0;

    /** @var array<PanelInterface> */
    private array $panels;

    /** @var callable|null */
    private $onDismissCallback = null;

    /** @var callable|null */
    private $onCancelAgentCallback = null;

    /** @var callable|null */
    private $onRetryAgentCallback = null;

    public function __construct(
        private readonly SwarmDataProvider $dataProvider,
        private readonly AgentDisplayFormatter $formatter = new AgentDisplayFormatter,
    ) {
        $this->panels = [
            new Panel\ProgressHeaderPanel($formatter),
            new Panel\AgentTreePanel($formatter),
            new Panel\ActiveAgentsPanel($formatter),
            new Panel\ResourceMeterPanel($formatter),
            new Panel\FailurePanel($formatter),
            new Panel\TypeBreakdownPanel($formatter),
            new Panel\FooterBar(),
        ];
    }

    public function render(RenderContext $context): array;
    public function handleInput(string $data): void;
    public function onDismiss(callable $callback): static;
    public function onCancelAgent(callable $callback): static;
    public function onRetryAgent(callable $callback): static;
    public function refresh(): void;  // Called by timer
    public function setCompactMode(bool $compact): void;

    protected static function getDefaultKeybindings(): array
    {
        return [
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
            'tab_next' => ['tab'],
            'tab_prev' => ['shift+tab'],
        ];
    }
}
```

#### AbstractPanel

```php
namespace Kosmokrator\UI\Tui\Dashboard\Panel;

abstract class AbstractPanel implements PanelInterface
{
    protected bool $focused = false;
    protected int $selectedRow = 0;
    protected int $scrollOffset = 0;

    public function __construct(
        protected readonly string $panelId,
        protected readonly string $title,
        protected readonly string $titleIcon,
        protected readonly bool $focusable = true,
    ) {}

    public function getPanelId(): string { return $this->panelId; }
    public function isFocusable(): bool { return $this->focusable; }
    public function setFocused(bool $focused): void { $this->focused = $focused; }

    /** Render panel with border, title, and content. */
    public function render(int $width, int $height): array
    {
        $border = $this->focused ? Theme::accent() : Theme::dim();
        $lines = [];
        // Title bar
        $lines[] = $this->renderTitleBar($width, $border);
        // Content area (delegated to subclass)
        $contentLines = $this->renderContent($width - 2, $height - 2);
        foreach ($contentLines as $line) {
            $lines[] = $this->padLine($line, $width, $border);
        }
        // Bottom border
        $lines[] = $this->renderBottomBorder($width, $border);
        return $lines;
    }

    /** Subclasses implement this to provide panel content. */
    abstract protected function renderContent(int $width, int $height): array;

    public function handleKey(string $key): bool { return false; }
}
```

#### AgentTreePanel

```php
namespace Kosmokrator\UI\Tui\Dashboard\Panel;

final class AgentTreePanel extends AbstractPanel
{
    private array $treeNodes = [];
    private array $expandedNodes = [];  // agentId => bool
    private array $flatIndex = [];      // Flattened visible nodes for selection

    public function __construct(
        AgentDisplayFormatter $formatter,
    ) {
        parent::__construct('tree', 'Agent Tree', '◈', true);
    }

    public function setTreeData(array $nodes): void;
    public function getSelectedAgentId(): ?string;
    public function moveSelection(int $delta): void;
    public function toggleExpand(string $agentId): void;

    protected function renderContent(int $width, int $height): array;
    public function handleKey(string $key): bool;
}
```

#### SwarmDataProvider

```php
namespace Kosmokrator\UI\Tui\Dashboard;

use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\UI\AgentTreeBuilder;

final class SwarmDataProvider
{
    private ?SwarmSummary $cachedSummary = null;
    private float $lastSummaryTime = 0.0;

    public function __construct(
        private readonly SubagentOrchestrator $orchestrator,
        private readonly AgentTreeBuilder $treeBuilder,
    ) {}

    public function getSummary(): SwarmSummary;
    public function getTree(): array;
    
    /** @return array<SubagentStats> */
    public function getActiveAgents(): array;
    
    /** @return array<SubagentStats> */
    public function getFailedAgents(): array;

    /** Compute completion rate (agents/min) based on recent history. */
    private function computeRate(): float;

    /** Compute token consumption rate (tokens/min). */
    private function computeTokenRate(): float;

    /** Estimate time to completion. */
    private function computeEta(): float;
}
```

### 7.4 Integration Points

**SubagentDisplayManager** changes (minimal):
```php
// Current: constructs SwarmDashboardWidget inline
// V2: delegates to SwarmDashboardV2Widget

public function showDashboard(): void
{
    if ($this->dashboardWidget !== null) {
        return; // Already showing
    }
    
    $dataProvider = new SwarmDataProvider(
        $this->orchestrator,
        $this->treeBuilder,
    );
    
    $this->dashboardWidget = new SwarmDashboardV2Widget($dataProvider, $this->formatter);
    $this->dashboardWidget->onDismiss(function () {
        $this->dashboardWidget = null;
    });
    
    // Register refresh timer
    $this->dashboardTimerId = EventLoop::repeat(1.0, function () {
        $this->dashboardWidget?->refresh();
        ($this->renderCallback)();
    });
    
    $this->conversation->add($this->dashboardWidget);
}
```

**TuiInputHandler** changes:
```php
// Add ctrl+a binding for dashboard toggle
// Add ctrl+s for compact mode toggle
```

---

## Migration Path

### Phase 1: Foundation (no visual changes)
1. Create `SwarmSummary` value object
2. Create `SwarmDataProvider` — extract summary computation from `TuiRenderer`
3. Refactor current `SwarmDashboardWidget` to use `SwarmDataProvider`
4. **Tests**: Unit tests for `SwarmDataProvider` and `SwarmSummary`

### Phase 2: Panel Architecture
1. Create `PanelInterface` and `AbstractPanel`
2. Create `ProgressHeaderPanel` (extract from V1 render)
3. Create `ResourceMeterPanel` (extract from V1 render)
4. Create `FooterBar` (extract from V1 render)
5. Create `DashboardLayout` with wide/narrow strategies
6. **Tests**: Panel render tests with known data

### Phase 3: Interactive Panels
1. Create `AgentTreePanel` with selection + expand/collapse
2. Create `ActiveAgentsPanel` with per-agent progress bars
3. Create `FailurePanel` with error details
4. Create `TypeBreakdownPanel` with bar chart
5. Wire focus ring and keyboard navigation
6. **Tests**: Keyboard input handling, selection state

### Phase 4: Composed Dashboard
1. Create `SwarmDashboardV2Widget` composing all panels
2. Wire into `SubagentDisplayManager` behind feature flag
3. Add compact mode render path
4. Add agent detail expansion (Enter key)
5. **Tests**: Integration tests with mock orchestrator

### Phase 5: Polish & Cleanup
1. Remove V1 `SwarmDashboardWidget` (replaced)
2. Add `c` cancel-agent and `r` retry-agent actions
3. Add animation (breathing dots on running agents, progress bar shimmer)
4. Performance: cache flattened tree index, skip recompute if stats unchanged
5. **Tests**: Full keyboard walkthrough scenario test

---

## Future Extensions

- **Agent log streaming**: Show last N lines of agent output in detail panel (like `docker logs -f`)
- **Dependency graph view**: ASCII-art DAG instead of tree (like GitHub Actions graph view)
- **Timeline view**: Gantt-chart style showing agent execution over time
- **Filtering**: `/` to filter agents by type, status, or task text
- **Sorting**: `s` to cycle sort order (by elapsed, by type, by status)
- **Export**: `e` to export swarm summary as JSON
- **Agent cancellation**: `c` on running agent to cancel (wired to `DeferredCancellation`)
- **Cost alerting**: Amber/red flash when cost exceeds configurable thresholds
- **Multi-swarm support**: Tab between multiple concurrent swarm invocations
