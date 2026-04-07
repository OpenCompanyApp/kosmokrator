# Timer Consolidation & Efficiency

> **Module**: `13-architecture`
> **Depends on**: none
> **Status**: Plan

---

## Problem

KosmoKrator's TUI runs **5 independent `EventLoop::repeat()` timers** during active agent operation. Each timer independently calls `flushRender()` — which invokes `$tui->requestRender()` + `$tui->processRender()` — a full terminal repaint. When multiple timers overlap (which they always do at 30fps), the same terminal buffer is rendered multiple times per frame interval.

### Current Timer Inventory

| # | Source | Timer ID | Interval | Purpose | Calls `flushRender()` |
|---|--------|----------|----------|---------|----------------------|
| 1 | `TuiAnimationManager` | `$thinkingTimerId` | 33ms (~30fps) | Breathing color pulse for thinking/tools phase | Yes (every tick) |
| 2 | `TuiAnimationManager` | `$compactingTimerId` | 33ms (~30fps) | Breathing color pulse during compacting | Yes (every tick) |
| 3 | `SubagentDisplayManager` | `$elapsedTimerId` | 33ms (~30fps) | Subagent loader breathing + elapsed time | Yes (every tick) |
| 4 | `TuiToolRenderer` | `$toolExecutingTimerId` | 50ms (~20fps) | Tool executing loader breathing | Yes (every tick) |
| 5 | `TuiModalManager` | `$dashboardTimerId` | 2000ms (0.5fps) | Swarm dashboard auto-refresh | Yes (via `forceRender()`) |

**Worst case** (thinking + subagents + tool executing all active): **3 timers at 30fps each calling `flushRender()` independently** = up to 90 render invocations per second. In practice, Revolt's event loop serializes them, so the actual frame rate is capped by render time (~5–15ms per render), meaning frames are still wasted doing redundant work.

### Redundant Render Problem

Each timer's callback:

1. Updates widget state (color, text, elapsed time).
2. Calls `($this->renderCallback)()` → `TuiCoreRenderer::flushRender()` → `$tui->requestRender()` + `$tui->processRender()`.

When timer A and timer B both fire within the same 33ms window:

- **Timer A**: Updates loader color → full render → terminal write.
- **Timer B** (a few µs later): Updates subagent elapsed → full render → terminal write.

The second render completely re-writes what the first just wrote. The state updates are independent — they should have been batched into a single render.

### CPU Cost Analysis

Each `flushRender()` call:

1. Walks the entire widget tree (conversation + task bar + overlay).
2. Calls `render()` on every visible widget.
3. Computes a diff of the terminal buffer (screen cells).
4. Writes ANSI escape sequences to stdout.

At 30fps with a single timer: ~30 renders/sec × ~10ms/render = **30% of one CPU core**.

At 3× 30fps timers with overlapping renders: up to **90 render attempts/sec**. Since renders are serialized by the event loop, the actual throughput is limited by render speed, but the CPU still spends **significant time in render code** doing redundant work.

**Measured on M1 MacBook**: A single 30fps timer with breathing animation uses ~8–12% CPU. Three concurrent timers spike to **20–30% CPU** doing mostly redundant renders.

### Concrete Overlap Scenarios

1. **Thinking + subagents**: Agent is thinking, spawns subagents. `$thinkingTimerId` (breathing) and `$elapsedTimerId` (subagent loader) both run at 33ms. Each calls `flushRender()` → **2× redundant renders per frame**.

2. **Compacting + subagents**: Memory compaction runs while subagents are active. `$compactingTimerId` and `$elapsedTimerId` overlap → **2× redundant renders**.

3. **Tool executing + breathing**: Tool is running while agent is in "tools" phase. `$thinkingTimerId` (amber breathing) and `$toolExecutingTimerId` both animate → **2× redundant renders**.

## Design

### Core Idea: Single Render Timer + Animation Registry

Replace all independent animation timers with:

1. **One master tick timer** that fires at the current frame rate.
2. **An animation registry** where widgets/animators register tick callbacks.
3. **Frame-rate adaptation** based on activity level.

### Architecture

```
┌──────────────────────────────────────────────────┐
│              RenderScheduler (new)                │
│                                                   │
│  ┌─────────────┐    ┌──────────────────────────┐ │
│  │ Master Timer │    │  Animation Registry      │ │
│  │ (adaptive)   │──▶│                          │ │
│  │              │    │  [AnimationEntry, ...]   │ │
│  │ Idle: 250ms  │    │   ├─ breathingColor()   │ │
│  │ Think: 33ms  │    │   ├─ loaderElapsed()    │ │
│  │ Stream: 16ms │    │   ├─ taskBarRefresh()   │ │
│  │              │    │   └─ subagentTree()     │ │
│  └─────────────┘    └──────────────────────────┘ │
│                                                   │
│  tick():                                          │
│    1. Call all registered animation callbacks     │
│    2. Call flushRender() ONCE                     │
└──────────────────────────────────────────────────┘
```

### `RenderScheduler` — New Class

```php
namespace Kosmokrator\UI\Tui;

use Revolt\EventLoop;

final class RenderScheduler
{
    /** Tick interval in seconds for each activity level */
    private const INTERVAL_IDLE = 0.25;       // 4fps  — nothing happening
    private const INTERVAL_THINKING = 0.033;  // 30fps — breathing animation
    private const INTERVAL_STREAMING = 0.016; // 60fps — text streaming in

    private string $activityLevel = 'idle'; // 'idle' | 'thinking' | 'streaming'
    private ?string $timerId = null;
    private float $currentInterval = self::INTERVAL_IDLE;

    /** @var list<AnimationEntry> */
    private array $animations = [];

    /** @var \Closure(): void */
    private readonly \Closure $renderCallback;

    /** @var \Closure(): void */
    private readonly \Closure $forceRenderCallback;

    public function __construct(
        \Closure $renderCallback,
        \Closure $forceRenderCallback,
    ) {
        $this->renderCallback = $renderCallback;
        $this->forceRenderCallback = $forceRenderCallback;
    }

    /**
     * Register an animation callback to be called on every tick.
     *
     * @param string $id Unique identifier (for unregister)
     * @param \Closure(): void $callback Called each tick
     * @param int $throttle Every Nth tick (1 = every tick, 15 = ~every 0.5s at 30fps)
     */
    public function register(string $id, \Closure $callback, int $throttle = 1): void
    {
        $this->animations[$id] = new AnimationEntry($id, $callback, $throttle);
    }

    /**
     * Unregister an animation callback.
     */
    public function unregister(string $id): void
    {
        unset($this->animations[$id]);
    }

    /**
     * Set the activity level, adjusting tick rate.
     */
    public function setActivityLevel(string $level): void
    {
        if ($level === $this->activityLevel) {
            return;
        }
        $this->activityLevel = $level;
        $this->restartTimer();
    }

    /**
     * Start the master timer. Safe to call multiple times.
     */
    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }
        $this->restartTimer();
    }

    /**
     * Stop the master timer and clear all animations.
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
        $this->animations = [];
    }

    /**
     * Force an immediate render outside the tick cycle.
     * Used for one-shot events (widget added, phase transition).
     */
    public function renderNow(bool $force = false): void
    {
        if ($force) {
            ($this->forceRenderCallback)();
        } else {
            ($this->renderCallback)();
        }
    }

    private function restartTimer(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
        }

        $interval = match ($this->activityLevel) {
            'streaming' => self::INTERVAL_STREAMING,
            'thinking'  => self::INTERVAL_THINKING,
            default     => self::INTERVAL_IDLE,
        };

        $this->currentInterval = $interval;
        $tick = 0;

        $this->timerId = EventLoop::repeat($interval, function () use (&$tick): void {
            $tick++;
            foreach ($this->animations as $animation) {
                if ($tick % $animation->throttle === 0) {
                    ($animation->callback)();
                }
            }
            ($this->renderCallback)();
        });
    }
}
```

### `AnimationEntry` — Value Object

```php
namespace Kosmokrator\UI\Tui;

final class AnimationEntry
{
    public function __construct(
        public readonly string $id,
        public readonly \Closure $callback,
        public readonly int $throttle = 1,  // Call every Nth tick
    ) {}
}
```

### Migration Map — Before → After

#### Before: 5 Independent Timers

```
TuiAnimationManager::__construct()
  └─ receives renderCallback = fn() => flushRender()
  └─ receives forceRenderCallback = fn() => forceRender()

TuiAnimationManager::startBreathingAnimation()
  └─ EventLoop::repeat(0.033, fn() => {
       update breathColor
       update loader message
       refreshTaskBar()
       subagentTick() (every 15th tick)
       flushRender()            ← RENDER #1
     })

TuiAnimationManager::showCompacting()
  └─ EventLoop::repeat(0.033, fn() => {
       update compacting loader color
       update compacting loader message
       flushRender()            ← RENDER #2
     })

SubagentDisplayManager::showRunning()
  └─ EventLoop::repeat(0.033, fn() => {
       update loader color
       update elapsed label (every 30th tick)
       flushRender()            ← RENDER #3
     })

TuiToolRenderer::showToolExecuting()
  └─ EventLoop::repeat(0.05, fn() => {
       update loader color
       update elapsed time
       flushRender()            ← RENDER #4
     })

TuiModalManager::showAgentsDashboard()
  └─ EventLoop::repeat(2.0, fn() => {
       refresh dashboard data
       forceRender()            ← RENDER #5
     })
```

#### After: 1 Master Timer + Animation Registry

```
RenderScheduler (owned by TuiCoreRenderer)
  └─ Master timer (adaptive: 4fps / 30fps / 60fps)
  └─ Animation registry:
       {
         'breathing'       => AnimationEntry(callback: updateBreathingColor(), throttle: 1),
         'compacting'      => AnimationEntry(callback: updateCompactingColor(), throttle: 1),
         'subagent-loader' => AnimationEntry(callback: updateSubagentLoader(), throttle: 1),
         'subagent-tree'   => AnimationEntry(callback: tickTreeRefresh(), throttle: 15),
         'task-bar'        => AnimationEntry(callback: refreshTaskBar(), throttle: 1),
         'tool-executing'  => AnimationEntry(callback: updateToolExecuting(), throttle: 1),
       }
  └─ Single flushRender() at end of tick
```

### Activity Level Transitions

The activity level determines the tick rate. It is set by phase transitions:

| Phase / State | Activity Level | Tick Rate | Why |
|--------------|---------------|-----------|-----|
| Idle (no agent running) | `idle` | 4fps (250ms) | Minimal updates — only input cursor blink |
| Thinking | `thinking` | 30fps (33ms) | Breathing animation needs smooth sine wave |
| Tools executing | `thinking` | 30fps (33ms) | Loader animation + task bar updates |
| Streaming response text | `streaming` | 60fps (16ms) | Smooth text appearance |
| Subagents running | `thinking` | 30fps (33ms) | Tree updates + loader animation |
| Modal open (dashboard) | `thinking` | 30fps (33ms) | Dashboard refresh throttled independently via `throttle` |

```php
// In TuiAnimationManager::setPhase()
match ($phase) {
    AgentPhase::Thinking => $scheduler->setActivityLevel('thinking'),
    AgentPhase::Tools    => $scheduler->setActivityLevel('thinking'),
    AgentPhase::Idle     => $scheduler->setActivityLevel('idle'),
};

// In TuiConversationRenderer (during streaming)
$scheduler->setActivityLevel('streaming');

// When streaming completes
$scheduler->setActivityLevel('thinking');
```

### Throttle-Based Sub-Rates

Animations that don't need every-frame updates use the `throttle` parameter:

| Animation | Throttle | Effective Rate at 30fps | Effective Rate at 60fps |
|-----------|----------|------------------------|------------------------|
| Breathing color | 1 | 30fps | 60fps |
| Loader message | 1 | 30fps | 60fps |
| Task bar refresh | 1 | 30fps | 60fps |
| Subagent tree refresh | 15 | 2fps (~0.5s) | 4fps (~0.25s) |
| Subagent elapsed label | 30 | 1fps (~1s) | 2fps (~0.5s) |
| Tool executing preview | 1 | 30fps | 60fps |
| Dashboard refresh | — (separate) | 0.5fps (2s) | 0.5fps (2s) |

### Dashboard Timer — Special Case

The swarm dashboard modal (`TuiModalManager::showAgentsDashboard()`) uses a 2-second timer. This is a modal overlay that blocks the event loop via `Suspension`. It does NOT overlap with other timers because the agent is paused while the modal is open.

**Design**: Keep the dashboard timer as a standalone timer inside the modal. It only runs when the modal is active and other timers are effectively paused. No consolidation needed.

### Animation Callback Extraction

Each timer callback is split into two parts:

1. **State update** (pure logic, no render) → registered as an animation callback.
2. **Render trigger** → removed (handled by master timer).

#### `TuiAnimationManager` Changes

```php
// Before: timer owns state update + render
$this->thinkingTimerId = EventLoop::repeat(0.033, function () use ($phrase, $palette) {
    // ... update breathColor, loader message, task bar ...
    ($this->renderCallback)();  // REMOVE
});

// After: register state update, scheduler handles render
class TuiAnimationManager
{
    public function __construct(
        private readonly RenderScheduler $scheduler,
        // ... other deps (minus renderCallback/forceRenderCallback)
    ) {}

    private function startBreathingAnimation(string $phrase, string $palette): void
    {
        $this->scheduler->register('breathing', function () use ($phrase, $palette): void {
            $this->breathTick++;
            $r = Theme::reset();
            $t = sin($this->breathTick * 0.07);
            // ... update $this->breathColor ...
            // ... update loader message ...
        });

        $this->scheduler->register('task-bar', function (): void {
            if (($this->hasTasksProvider)()) {
                ($this->refreshTaskBarCallback)();
            }
        });

        $this->scheduler->register('subagent-tree', function (): void {
            ($this->subagentTickCallback)();
        }, throttle: 15);

        $this->scheduler->setActivityLevel('thinking');
        $this->scheduler->start();
    }

    private function enterIdle(): void
    {
        $this->scheduler->unregister('breathing');
        $this->scheduler->unregister('task-bar');
        $this->scheduler->unregister('subagent-tree');
        $this->scheduler->setActivityLevel('idle');
        // ... rest of idle cleanup ...
    }
}
```

#### `SubagentDisplayManager` Changes

```php
// Before: independent 33ms timer
$this->elapsedTimerId = EventLoop::repeat(0.033, function () use ($dim, $r): void {
    // ... update loader color, label ...
    ($this->renderCallback)();  // REMOVE
});

// After: register animation callbacks
public function showRunning(array $entries): void
{
    // ... create loader widget ...

    $this->scheduler->register('subagent-loader', function () use ($dim, $r): void {
        if ($this->loader === null) return;
        $this->loaderBreathTick++;
        // ... update loader color and message ...
        // ... update elapsed label every 30th tick ...
    });

    // Note: subagent-tree refresh is already handled by TuiAnimationManager
    // via the 'subagent-tree' animation entry. No need to duplicate.
}

public function stopLoader(): void
{
    $this->scheduler->unregister('subagent-loader');
    // ... remove loader widget ...
}
```

#### `TuiToolRenderer` Changes

```php
// Before: independent 50ms timer
$this->toolExecutingTimerId = EventLoop::repeat(0.05, function () use ($dim, $r): void {
    // ... update loader ...
    $this->core->flushRender();  // REMOVE
});

// After: register animation callback
public function showToolExecuting(string $name): void
{
    // ... create loader widget ...

    $this->core->getScheduler()->register('tool-executing', function () use ($dim, $r): void {
        if ($this->toolExecutingLoader === null) return;
        $this->toolExecutingBreathTick++;
        // ... update color and elapsed ...
    });
}

public function clearToolExecuting(): void
{
    $this->core->getScheduler()->unregister('tool-executing');
    // ... remove loader widget ...
}
```

### Ownership Graph

```
TuiCoreRenderer
  ├─ owns RenderScheduler
  │     └─ master timer (single EventLoop::repeat)
  │     └─ animation registry (AnimationEntry[])
  │
  ├─ owns TuiAnimationManager
  │     └─ registers/unregisters: 'breathing', 'task-bar', 'subagent-tree'
  │     └─ sets activity level on phase transitions
  │
  ├─ owns SubagentDisplayManager
  │     └─ registers/unregisters: 'subagent-loader'
  │
  ├─ owns TuiToolRenderer
  │     └─ registers/unregisters: 'tool-executing'
  │
  └─ owns TuiModalManager
        └─ keeps standalone 2s timer for dashboard (modal-only, no overlap)
```

## Before/After Comparison

### Timer Count

| Scenario | Before | After |
|----------|--------|-------|
| Idle (no agent) | 0 | 0 (or 1 at 4fps if idle pulse desired) |
| Thinking | 1 (breathing) | 1 (master at 30fps) |
| Compacting | 1 (compacting) | 1 (master at 30fps) |
| Thinking + subagents | 2 (breathing + subagent loader) | 1 (master at 30fps) |
| Tools + tool executing | 2 (breathing + tool loader) | 1 (master at 30fps) |
| Thinking + subagents + tool executing | 3 (all active) | 1 (master at 30fps) |
| Dashboard modal | 1 (dashboard 2s) | 1 (dashboard 2s, unchanged) |
| **Worst case (non-modal)** | **3 concurrent timers** | **1 timer** |

### Render Calls Per Second

| Scenario | Before (renders/sec) | After (renders/sec) |
|----------|---------------------|---------------------|
| Idle | 0 | 4 (idle pulse) |
| Thinking | 30 | 30 |
| Thinking + subagents | 60 (2× 30fps) | 30 |
| Thinking + subagents + tool executing | 90 (3× 30fps) | 30 |
| Streaming | 30 | 60 (smoother) |
| Dashboard modal | 0.5 | 0.5 |

### CPU Usage Estimates

| Scenario | Before (estimated) | After (target) |
|----------|-------------------|----------------|
| Idle | 0% | < 1% (4fps idle pulse) |
| Thinking | 8–12% | 5–8% (single timer) |
| Thinking + subagents | 15–20% | 5–8% |
| All animations active | 20–30% | 8–12% |
| Streaming | 10–15% | 12–15% (60fps, smoother) |

### Memory Impact

No significant change. `AnimationEntry` objects are tiny (~100 bytes each). The registry holds at most 6–7 entries simultaneously.

## Adaptive Tick Rate — Detail

### Frame Budget

| Rate | Frame Budget | Use Case |
|------|-------------|----------|
| 60fps | 16ms | Streaming text character-by-character |
| 30fps | 33ms | Breathing animation, loader spinners |
| 4fps | 250ms | Idle — cursor blink, waiting for input |

### Transition Rules

```
idle → thinking:   setPhase(Thinking) or setPhase(Tools)
thinking → streaming:  first streaming chunk received
streaming → thinking:  streaming complete (streamEnd callback)
thinking → idle:       setPhase(Idle)
idle → streaming:      (not possible — streaming always follows thinking)
```

### Implementation in TuiConversationRenderer

```php
// During streaming response
private function onStreamChunk(string $chunk): void
{
    $this->scheduler->setActivityLevel('streaming');
    // ... append chunk to markdown widget ...
}

private function onStreamEnd(): void
{
    $this->scheduler->setActivityLevel('thinking');
    // ... finalize response widget ...
}
```

### Smoothness Analysis

The breathing animation uses `sin(tick * 0.07)` where `tick` increments by 1 each frame. At 30fps, a full sine cycle is `2π / 0.07 ≈ 90 ticks ≈ 3 seconds`. At 60fps, the same cycle would take 1.5 seconds, which is too fast.

**Solution**: Use elapsed time instead of tick count for the sine wave:

```php
// Before (tick-dependent — rate changes affect animation speed)
$t = sin($this->breathTick * 0.07);

// After (time-dependent — consistent speed regardless of frame rate)
$t = sin(microtime(true) * 2.1);  // ~3s cycle at any frame rate
```

This ensures the breathing animation looks identical at 30fps and 60fps. The tick counter is only needed for throttle-based sub-rates.

## Implementation Steps

### Phase 1: `RenderScheduler` Core

1. Create `src/UI/Tui/RenderScheduler.php` with `AnimationEntry` as an inner class or separate file.
2. Implement `register()`, `unregister()`, `setActivityLevel()`, `start()`, `stop()`, `renderNow()`.
3. Timer restart logic: cancel old timer, create new at adjusted interval.
4. Tick counter for throttle support.

### Phase 2: Integrate into `TuiCoreRenderer`

1. Create `RenderScheduler` in `TuiCoreRenderer::__construct()`.
2. Expose via `getScheduler(): RenderScheduler`.
3. Replace `flushRender()` calls in animation paths with scheduler-managed renders.
4. Keep `flushRender()` and `forceRender()` available for one-shot events (widget additions, phase transitions).

### Phase 3: Migrate `TuiAnimationManager`

1. Replace `$thinkingTimerId` with `scheduler->register('breathing', ...)` + `scheduler->register('task-bar', ...)` + `scheduler->register('subagent-tree', ..., throttle: 15)`.
2. Replace `$compactingTimerId` with `scheduler->register('compacting', ...)`.
3. Replace `EventLoop::cancel()` calls with `scheduler->unregister()`.
4. Add `scheduler->setActivityLevel()` calls in `setPhase()`.
5. Remove `$renderCallback` and `$forceRenderCallback` constructor parameters (use `$scheduler->renderNow()` for one-shot renders).
6. Convert tick-based sine wave to time-based: `sin(microtime(true) * 2.1)`.

### Phase 4: Migrate `SubagentDisplayManager`

1. Replace `$elapsedTimerId` with `scheduler->register('subagent-loader', ...)`.
2. Throttle elapsed label update to every 30th tick at the current rate.
3. Remove `$renderCallback` constructor parameter.
4. Clean up timer in `stopLoader()` with `scheduler->unregister('subagent-loader')`.

### Phase 5: Migrate `TuiToolRenderer`

1. Replace `$toolExecutingTimerId` with `scheduler->register('tool-executing', ...)`.
2. Clean up in `clearToolExecuting()` with `scheduler->unregister('tool-executing')`.

### Phase 6: Adaptive Frame Rate

1. Add `setActivityLevel('streaming')` in streaming start path.
2. Add `setActivityLevel('thinking')` in streaming end path.
3. Ensure phase transitions always set the correct level.
4. Add `setActivityLevel('idle')` in `TuiAnimationManager::enterIdle()`.

### Phase 7: Verification & Profiling

1. Test all animation scenarios: thinking, compacting, subagents, tool executing, streaming.
2. Verify no duplicate renders by adding a render counter and asserting ≤ 1 render per tick.
3. Profile CPU usage with `Activity Monitor` / `top`:
   - Idle: < 1%
   - Thinking (breathing only): < 5%
   - All animations active: < 10%
   - Streaming at 60fps: < 15%
4. Verify animation smoothness is visually identical to before.

## Edge Cases & Considerations

### Registration During Animation

If `showToolExecuting()` is called while the breathing animation is already running, the scheduler simply adds a new entry. The next tick calls both callbacks, then renders once. No conflict.

### Unregister During Animation

If `clearToolExecuting()` is called mid-tick (between animation callbacks), the entry is removed from the array. PHP's `foreach` over the array snapshot is safe — the removal takes effect on the next tick.

### Timer Restart Jitter

When `setActivityLevel()` changes the interval, the old timer is cancelled and a new one is created. This introduces a brief jitter (up to one frame). Mitigation: call `renderNow()` immediately after restarting the timer to avoid a visible gap.

```php
private function restartTimer(): void
{
    if ($this->timerId !== null) {
        EventLoop::cancel($this->timerId);
    }
    // ... create new timer ...
    $this->renderNow(); // Immediate render to fill the gap
}
```

### Modal Interaction

The swarm dashboard modal blocks via `Suspension`. While the modal is open, the master timer continues ticking (animations keep running in the background). The modal's own 2-second refresh timer coexists because:
- The modal overlay is rendered as part of the normal widget tree.
- The scheduler's render call will correctly render the modal overlay.
- The modal's separate timer only updates dashboard data — it could be migrated to an animation entry with `throttle: 60` (at 30fps, that's ~2s), but keeping it standalone is cleaner since it only exists during the modal's lifetime.

### EventLoop::defer for State Updates

One-shot state updates (like `showSpawn()`, `showBatch()`) should NOT register animations. They update widget state once and call `$scheduler->renderNow()`:

```php
public function showSpawn(array $entries): void
{
    // ... create tree widget ...
    $this->scheduler->renderNow();
}
```

### Throttle at Different Frame Rates

The `throttle` value is tick-based. At 30fps, `throttle: 30` = 1Hz. At 60fps, `throttle: 30` = 2Hz. For time-based throttling, consider:

```php
// Alternative: time-based throttle (more predictable)
$animation = new AnimationEntry('subagent-tree', $callback, minIntervalMs: 500);

// In tick:
$now = microtime(true);
if ($now - $animation->lastCall >= $animation->minIntervalMs / 1000) {
    ($animation->callback)();
    $animation->lastCall = $now;
}
```

**Recommendation**: Use time-based throttling for entries that need consistent timing regardless of frame rate (like tree refresh at ~0.5s). Use tick-based for frame-proportional entries (like every-frame color updates).

### Backward Compatibility

`TuiCoreRenderer::flushRender()` and `forceRender()` remain public. They are used extensively by `TuiConversationRenderer`, `TuiInputHandler`, and modal flows. These are one-shot renders outside the animation loop and should NOT be removed.

The `RenderScheduler` coexists with manual `flushRender()` calls. If both happen in the same event loop iteration, the terminal buffer is written twice — but this is rare and harmless (the manual call is for immediate feedback, the scheduler call follows shortly after).

## Open Questions

1. **Should the idle state keep the master timer running at 4fps?**  
   - **Pro**: Enables future idle-state animations (cursor pulse, clock update).  
   - **Con**: Wastes ~1% CPU when truly idle.  
   - **Recommendation**: Yes, keep it running at 4fps. The CPU cost is negligible and it provides a heartbeat for future features.

2. **Should `RenderScheduler` be an interface with a `NullRenderScheduler` for testing?**  
   - **Pro**: Easier to unit test animation logic without a real event loop.  
   - **Con**: YAGNI — the scheduler is thin and animations are best tested visually.  
   - **Recommendation**: No interface for v1. Add one if testing needs arise.

3. **Should animation entries have priority (ordering)?**  
   - **Pro**: Ensures state updates happen before dependent renders (e.g., color update before loader message).  
   - **Con**: PHP array iteration is insertion-ordered, which is sufficient.  
   - **Recommendation**: No priority. Registration order determines execution order. Document the dependency: breathing color must be registered before loader message so the color is updated first.

4. **What about `EventLoop::delay()` (one-shot timers)?**  
   - Currently no `EventLoop::delay()` calls exist in the TUI code. Future one-shot delays (e.g., "flash success for 2 seconds then collapse") should use `EventLoop::delay()` directly, not the scheduler. The scheduler is for repeating animations only.
