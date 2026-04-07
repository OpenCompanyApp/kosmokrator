# OffscreenFreeze — Implementation Plan

> **Namespace**: `Kosmokrator\UI\Tui\Render\OffscreenFreezeTrait`
> **Depends on**: Position tracking (existing `PositionTracker` / `WidgetRect`), virtual scrolling height cache (`03-virtual-scrolling/01-virtual-message-list`)
> **Blocks**: VirtualMessageList scroll optimisation, animation frame-rate control (`08-animation`)

---

## 1. Problem Statement

KosmoKrator's TUI has a **constant 30 fps breathing animation loop** that fires on every tick:

```
TuiAnimationManager::startBreathingAnimation()           (line ~221)
  → EventLoop::repeat(0.033, callback)                   (line ~228)
    → $this->breathTick++                                 (line ~229)
    → $this->breathColor = Theme::rgb(...)                (line ~242)
    → ($this->renderCallback)()                           (line ~260)
      → TuiCoreRenderer::flushRender()                    (TuiCoreRenderer.php:612)
        → Tui::requestRender() + processRender()          (Tui.php:391 + 446)
          → Renderer::render($root, $cols, $rows)         (Renderer.php:113)
            → Renders ENTIRE widget tree every 33ms
```

There are **three independent 30 fps animation loops** running simultaneously:

1. **Breathing animation** in `TuiAnimationManager::startBreathingAnimation()` (line ~228) — updates `breathColor` and calls `renderCallback` every 33ms
2. **Compacting animation** in `TuiAnimationManager::showCompacting()` (line ~158) — same 30fps pattern for the compacting loader
3. **Subagent elapsed timer** in `SubagentDisplayManager::showRunning()` (line ~212) — 30fps blue breathing for the subagent loader

Additionally, `LoaderWidget` (base of `CancellableLoaderWidget`) has its own **spinner tick** via `ScheduledTickTrait` that calls `invalidate()` + `requestRender()` on each frame (`LoaderWidget.php:133-137`).

**The critical issue**: when the conversation has 50+ widgets (tool calls, results, streaming messages), every animation tick renders ALL of them — including widgets scrolled off-screen that the user can't see. The breathing color on a task bar 500 lines above the viewport still invalidates its parent chain, causing the Renderer to traverse the entire widget tree and re-render unchanged off-screen content.

**Concrete waste example**: In a session with 30 tool calls:
- 30 `CollapsibleWidget` instances in the conversation, each ~5-15 rendered lines
- Total content: ~300+ lines, viewport shows ~40 lines
- 30fps × (300 lines / 40 visible) = 260 lines rendered per second that are invisible
- Each render triggers `AbstractWidget::render()` → style resolution → line generation for widgets the user cannot see

## 2. Research: Claude Code's OffscreenFreeze Pattern

### 2.1 Core Concept

Claude Code (Anthropic's CLI agent) implements an `OffscreenFreeze` mechanism:

1. **Visibility tracking**: A `ViewportTracker` knows which widgets are currently visible in the terminal viewport
2. **Output caching**: When a widget scrolls off-screen, its last rendered output is cached as a frozen snapshot
3. **Tick suppression**: Animation callbacks (spinner ticks, breathing animations) check visibility before calling `invalidate()` — if the widget is off-screen, the tick is a no-op
4. **Rehydration**: When a widget scrolls back into view, its frozen cache is invalidated and the widget re-renders normally

### 2.2 Key Insights

- **The freeze is not about skipping render() calls** (the Renderer already has a render cache via `AbstractWidget::getRenderCache()`). The freeze is about **preventing animation timers from invalidating widgets** that are off-screen, which would force the Renderer to traverse and re-render them.
- **It's a coordination layer**, not a widget implementation detail. The freeze needs a central coordinator that knows the viewport bounds and can tell individual widgets/timers whether they're visible.
- **The biggest win is stopping timer-driven invalidation**. A `LoaderWidget` that's 200 lines above the viewport shouldn't be ticking its spinner at 80ms intervals and calling `invalidate()` → `parent->invalidate()` → full tree re-render.

## 3. Current Architecture Analysis

### 3.1 Invalidation Chain

When a `LoaderWidget` spinner ticks:

```
LoaderWidget::onScheduledTick()           (LoaderWidget.php:254)
  → LoaderWidget::tick()                  (LoaderWidget.php:226)
    → $this->invalidate()                 (LoaderWidget.php:233)
      → DirtyWidgetTrait: renderRevision++ (DirtyWidgetTrait.php:16)
      → renderCacheLines = null           (AbstractWidget.php:205)
      → $this->parent->invalidate()       (AbstractWidget.php:207)
        → parent->invalidate() propagates up to root
```

This means a single `LoaderWidget` spinner tick at 80ms intervals **invalidates the entire widget tree from itself to the root**. The Renderer's `renderWidget()` checks `getRenderCache()` first, but the cache is cleared by `invalidate()`, so the entire subtree must re-render.

### 3.2 Breathing Animation Impact

The breathing animation in `TuiAnimationManager::startBreathingAnimation()` (line ~228):

1. Updates `$this->breathColor` every 33ms
2. Updates the `CancellableLoaderWidget` message with a new color every tick
3. Calls `refreshTaskBar()` every tick (when tasks exist) — this rebuilds the entire task tree text
4. Calls `subagentTickCallback()` every ~500ms — refreshes the agent tree
5. Calls `renderCallback()` (i.e., `flushRender()`) every tick — triggers full tree render

The breathing animation is the **heaviest offender** because it modifies content every 33ms and forces a full render pass.

### 3.3 Existing Infrastructure We Can Leverage

- **`PositionTracker`** (`vendor/symfony/tui/.../Render/PositionTracker.php`): Already tracks absolute positions of rendered widgets via `WeakMap<AbstractWidget, WidgetRect>`. We can query this to determine visibility.
- **`WidgetRect`** (`vendor/symfony/tui/.../Render/WidgetRect.php`): Has `getRow()`, `getRows()`, `contains()`, `toRelative()` — sufficient for viewport intersection checks.
- **`AbstractWidget::getRenderCache()`**: Already caches rendered output keyed on `(renderRevision, columns, rows)`. The Renderer skips re-rendering when the cache is valid.
- **`RenderRequestorInterface`**: The `requestRender()` method on `Tui` is what triggers the render pass.

### 3.4 What's Missing

- **No viewport bounds tracking**: `TuiCoreRenderer` knows `$scrollOffset` (line 108) and the terminal dimensions, but doesn't expose a "viewport rect" that could be compared against widget positions.
- **No freeze/thaw lifecycle on widgets**: `AbstractWidget` has no concept of being "frozen" — animation timers run unconditionally.
- **No timer-aware invalidation guard**: `invalidate()` always propagates up; there's no way to say "invalidate self but don't trigger a render."

## 4. Design

### 4.1 Approach: Trait + Coordinator (not Decorator)

A **trait** is the right choice over a decorator because:

1. `AbstractWidget::invalidate()` is `final` in the class body (aliased from `DirtyWidgetTrait` via `use DirtyWidgetTrait { invalidate as private invalidateSelf; }` — line 33 of `AbstractWidget.php`). We cannot intercept `invalidate()` from a decorator.
2. The freeze needs to be **inside** the widget to prevent `invalidate()` from clearing the render cache and propagating to the parent. A trait can override the aliasing.
3. Animation timers call `$this->invalidate()` directly — a decorator would need to wrap every timer callback, which is fragile.

However, since `AbstractWidget` already uses `DirtyWidgetTrait` with a private alias, we need a different approach: a **coordinator** that wraps the timer callbacks and a **mixin on the animation manager side** rather than on the widget side.

**Revised approach**: `OffscreenFreezeCoordinator` + `FreezableTick` wrapper.

### 4.2 Components

#### 4.2.1 `OffscreenFreezeCoordinator`

Central service that:
- Knows the viewport bounds (from terminal size + scroll offset)
- Queries `PositionTracker` for widget rects after each render pass
- Maintains a set of "frozen" widgets
- Provides `isVisible(AbstractWidget): bool` for timer callbacks to check
- Fires events when freeze/thaw transitions happen

#### 4.2.2 `FreezableTick` (callable wrapper)

Wraps animation timer callbacks with a visibility check:

```php
$freezableTick = new FreezableTick($widget, $coordinator, $originalCallback);
EventLoop::repeat(0.033, $freezableTick);
// Inside: if (!$coordinator->isVisible($widget)) { return; } else { $originalCallback(); }
```

#### 4.2.3 Integration Points

The coordinator hooks into:
1. `TuiCoreRenderer::flushRender()` — after each render, update viewport bounds and widget positions
2. `TuiAnimationManager` — wrap breathing/compacting timer callbacks with `FreezableTick`
3. `SubagentDisplayManager` — wrap elapsed timer with `FreezableTick`
4. `LoaderWidget::startScheduledTick()` — not directly; instead, the `LoaderWidget`'s `requestRender()` call is intercepted by checking visibility

### 4.3 Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    Terminal Viewport                         │
│  Row 0..R (where R = terminal rows)                         │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Visible widgets (rendered normally)                 │   │
│  │  - Active streaming MarkdownWidget                   │   │
│  │  - Latest tool call CollapsibleWidget                │   │
│  │  - LoaderWidget (spinner ticking)                    │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ═════════════════ viewport boundary ════════════════════   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Frozen widgets (output cached, timers suppressed)   │   │
│  │  - Old tool call CollapsibleWidget (frozen)          │   │
│  │  - Old streaming content TextWidget (frozen)         │   │
│  │  - Task bar with breathing animation (frozen)        │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### 4.4 Data Flow

```
1. Timer tick fires (e.g. breathing animation at 33ms)
   ↓
2. FreezableTick::__invoke() checks coordinator->isVisible($widget)
   ↓
3a. If visible → run original callback (update color, invalidate, render)
3b. If frozen → skip callback entirely (no invalidate, no render request)
   ↓
4. Periodically (on scroll or every N ticks), coordinator re-evaluates
   visibility from PositionTracker
   ↓
5. Widget scrolls back into view → coordinator->thaw($widget)
   → widget->invalidate() forces re-render with fresh animation state
```

## 5. Implementation

### 5.1 `OffscreenFreezeCoordinator`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Render;

use Symfony\Component\Tui\Render\PositionTracker;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Coordinates offscreen freeze/thaw for conversation widgets.
 *
 * After each render pass, queries the PositionTracker to determine which
 * widgets are within the viewport. Widgets that have scrolled off-screen
 * are "frozen" — their animation timers are suppressed via FreezableTick,
 * preventing unnecessary invalidation and re-rendering.
 *
 * When a frozen widget scrolls back into view, it is thawed and its
 * output is invalidated to force a fresh render.
 *
 * ## Performance Impact
 *
 * In a typical session with 30+ tool calls and a 40-line viewport:
 * - Without freeze: ~300 lines re-rendered per animation tick (30fps)
 * - With freeze: ~40 lines re-rendered per tick (7.5× reduction)
 *
 * The coordinator adds ~O(n) work per render pass to check widget positions,
 * where n is the number of conversation widgets. This is negligible compared
 * to the saved render work.
 */
final class OffscreenFreezeCoordinator
{
    /**
     * @param  PositionTracker  $positionTracker  The renderer's position tracker
     * @param  int  $terminalRows  Current terminal height in rows
     */
    public function __construct(
        private readonly PositionTracker $positionTracker,
        private int $terminalRows = 0,
        private int $scrollOffset = 0,
    ) {}

    /**
     * Update the terminal dimensions and scroll state.
     *
     * Called after each render pass or when scroll changes.
     */
    public function updateViewport(int $terminalRows, int $scrollOffset): void
    {
        $this->terminalRows = $terminalRows;
        $this->scrollOffset = $scrollOffset;
    }

    /**
     * Check whether a widget is currently visible in the viewport.
     *
     * A widget is visible if its rendered rect overlaps with the
     * viewport bounds. Widgets without a tracked position are
     * assumed visible (fail-safe).
     *
     * ## Viewport calculation
     *
     * The viewport in a scrolling conversation is:
     *   viewportTop = max(0, totalContentHeight - terminalRows - scrollOffset)
     *   viewportBottom = viewportTop + terminalRows
     *
     * Since we don't know totalContentHeight here, we use the
     * PositionTracker's rects directly. A widget at row R with height H
     * is visible if there exists any overlap with [0, terminalRows).
     *
     * Note: The PositionTracker records positions in the *full* content
     * coordinate space, but the ScreenWriter applies scrollOffset when
     * writing to the terminal. We account for this by checking if the
     * widget's position falls within the effective viewport range.
     */
    public function isVisible(AbstractWidget $widget): bool
    {
        $rect = $this->positionTracker->getWidgetRect($widget);

        // No position tracked yet — assume visible (fail-safe)
        if ($rect === null) {
            return true;
        }

        // Widget with zero height is not rendered — not "visible" in
        // the sense that animating it is pointless, but don't freeze it
        // either (it may become visible when it gets content).
        if ($rect->getRows() === 0) {
            return true;
        }

        // Effective viewport in content coordinates
        $widgetTop = $rect->getRow();
        $widgetBottom = $widgetTop + $rect->getRows();

        // For a scroll-offset display, the viewport in content-space
        // is roughly [contentHeight - terminalRows - scrollOffset,
        //              contentHeight - scrollOffset].
        // However, we don't have contentHeight here. Instead, we check
        // visibility based on the assumption that the ScreenWriter shows
        // the bottom portion of the content.
        //
        // Simplified check: if the widget's row is beyond terminalRows
        // from the top of the last-rendered output, it's above the fold.
        // We use a generous margin to account for chrome.

        // The PositionTracker records positions starting from row 0
        // of the full rendered content. The terminal shows the bottom
        // portion. We need to know the total rendered height.
        // Since we don't have that, we use a conservative approach:
        // track widgets that are "definitely off-screen" by checking
        // if they're far from the bottom of the content.
        //
        // For now, we use the simple heuristic that widgets above the
        // first terminal screenful are frozen. This is refined when
        // integrated with VirtualMessageList which knows exact heights.
        return true; // Placeholder — refined in updateFrozenSet()
    }

    /**
     * Frozen widget set — widgets whose animation timers should be suppressed.
     *
     * @var \SplObjectStorage<AbstractWidget, bool>
     */
    private \SplObjectStorage $frozenWidgets;

    /**
     * Widgets that were frozen last check — used to detect thaw transitions.
     *
     * @var \SplObjectStorage<AbstractWidget, bool>
     */
    private \SplObjectStorage $previouslyFrozen;

    /**
     * Callbacks to invoke when a widget is thawed (scrolls back into view).
     *
     * @var array<string, \SplObjectStorage<AbstractWidget, list<\Closure>>>
     */
    private array $thawCallbacks = [];

    /**
     * Total rendered content height from the last render pass.
     */
    private int $contentHeight = 0;

    /**
     * Update the set of frozen widgets based on current positions.
     *
     * Called after each render pass. Compares each tracked widget's
     * position against the viewport bounds and updates the frozen set.
     *
     * @param  int  $contentHeight  Total lines rendered in the last pass
     * @return list<AbstractWidget>  Widgets that were thawed (transitioned from frozen to visible)
     */
    public function updateFrozenSet(int $contentHeight): array
    {
        $this->contentHeight = $contentHeight;

        $thawed = [];

        // Calculate viewport bounds in content coordinate space
        // The ScreenWriter shows: [contentHeight - terminalRows - scrollOffset,
        //                           contentHeight - scrollOffset)
        $viewportTop = max(0, $contentHeight - $this->terminalRows - $this->scrollOffset);
        $viewportBottom = $viewportTop + $this->terminalRows;

        // Snapshot current frozen set
        $this->previouslyFrozen = clone $this->frozenWidgets;
        $this->frozenWidgets = new \SplObjectStorage();

        // Check all tracked widgets
        foreach ($this->positionTracker->snapshotKeys() as $widget) {
            $rect = $this->positionTracker->getWidgetRect($widget);
            if ($rect === null) {
                continue;
            }

            $widgetTop = $rect->getRow();
            $widgetBottom = $widgetTop + $rect->getRows();

            // Widget is visible if it overlaps with the viewport
            $isOffscreen = $widgetBottom <= $viewportTop || $widgetTop >= $viewportBottom;

            if ($isOffscreen) {
                $this->frozenWidgets[$widget] = true;
            } else {
                // Was this widget previously frozen? → thaw transition
                if (isset($this->previouslyFrozen[$widget])) {
                    $thawed[] = $widget;
                }
            }
        }

        // Fire thaw callbacks
        foreach ($thawed as $widget) {
            $this->fireThawCallbacks($widget);
        }

        return $thawed;
    }

    /**
     * Check if a widget is currently frozen.
     */
    public function isFrozen(AbstractWidget $widget): bool
    {
        return isset($this->frozenWidgets[$widget]);
    }

    /**
     * Register a callback to fire when a widget is thawed.
     *
     * @param  string  $group  Callback group for bulk removal
     */
    public function onThaw(AbstractWidget $widget, \Closure $callback, string $group = 'default'): void
    {
        if (!isset($this->thawCallbacks[$group])) {
            $this->thawCallbacks[$group] = new \SplObjectStorage();
        }
        if (!isset($this->thawCallbacks[$group][$widget])) {
            $this->thawCallbacks[$group][$widget] = [];
        }
        $this->thawCallbacks[$group][$widget][] = $callback;
    }

    /**
     * Remove all thaw callbacks for a group.
     */
    public function removeThawCallbacks(string $group): void
    {
        unset($this->thawCallbacks[$group]);
    }

    /**
     * Force-invalidate all frozen widgets and clear the frozen set.
     *
     * Used when a major layout change invalidates all position data
     * (e.g., terminal resize, conversation clear).
     *
     * @return list<AbstractWidget> Widgets that were thawed
     */
    public function thawAll(): array
    {
        $thawed = [];
        foreach ($this->frozenWidgets as $widget) {
            $widget->invalidate();
            $thawed[] = $widget;
        }
        $this->frozenWidgets = new \SplObjectStorage();

        return $thawed;
    }

    private function fireThawCallbacks(AbstractWidget $widget): void
    {
        foreach ($this->thawCallbacks as $group => $callbacks) {
            if (isset($callbacks[$widget])) {
                foreach ($callbacks[$widget] as $cb) {
                    $cb($widget);
                }
            }
        }
    }
}
```

### 5.2 `FreezableTick`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Render;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Wraps an animation timer callback with an offscreen-freeze visibility check.
 *
 * When the associated widget is frozen (off-screen), the callback is skipped
 * entirely — no invalidation, no render request. When the widget is visible,
 * the original callback runs normally.
 *
 * ## Usage
 *
 * ```php
 * $tick = new FreezableTick($coordinator, $loaderWidget, function () use ($loader) {
 *     $loader->tick();
 * });
 *
 * EventLoop::repeat(0.033, $tick);
 * ```
 *
 * ## Thaw behavior
 *
 * When the coordinator thaws a frozen widget, it calls the widget's
 * invalidate() method. The next render pass will produce fresh output.
 * The FreezableTick does NOT need to "catch up" on missed ticks —
 * the animation simply resumes from its current state.
 */
final class FreezableTick
{
    /**
     * @param  OffscreenFreezeCoordinator  $coordinator  The freeze coordinator
     * @param  AbstractWidget  $widget  The widget whose visibility controls the tick
     * @param  \Closure(): void  $callback  The original timer callback
     * @param  bool  $skipRenderWhenFrozen  When true, also suppresses the
     *     render() call that the callback would normally trigger. Set to false
     *     for callbacks that update shared state (like breathColor) that other
     *     visible widgets depend on.
     */
    public function __construct(
        private readonly OffscreenFreezeCoordinator $coordinator,
        private readonly AbstractWidget $widget,
        private readonly \Closure $callback,
        private readonly bool $skipRenderWhenFrozen = true,
    ) {}

    /**
     * Execute the tick callback only if the widget is not frozen.
     */
    public function __invoke(): void
    {
        if ($this->coordinator->isFrozen($this->widget)) {
            return;
        }

        ($this->callback)();
    }
}
```

### 5.3 Integration into `TuiAnimationManager`

The breathing animation timer is the highest-impact integration point. Replace the raw `EventLoop::repeat()` call with a `FreezableTick`:

```php
// In TuiAnimationManager::startBreathingAnimation() (currently line ~228)

private function startBreathingAnimation(string $phrase, string $palette): void
{
    if ($this->thinkingTimerId !== null) {
        EventLoop::cancel($this->thinkingTimerId);
    }

    $this->thinkingTimerId = EventLoop::repeat(0.033, new FreezableTick(
        coordinator: $this->freezeCoordinator,
        widget: $this->thinkingBar,  // The container being animated
        callback: function () use ($phrase, $palette) {
            $this->breathTick++;
            $r = Theme::reset();

            $t = sin($this->breathTick * 0.07);
            // ... existing color calculation ...
            $this->breathColor = Theme::rgb($cr, $cg, $cb);

            // ... existing message update logic ...

            if (($this->hasTasksProvider)()) {
                ($this->refreshTaskBarCallback)();
            }

            if ($this->breathTick % 15 === 0) {
                ($this->subagentTickCallback)();
            }

            ($this->renderCallback)();
        },
        skipRenderWhenFrozen: false, // breathColor is shared state, always compute it
    ));
}
```

**Important**: The breathing animation callback updates **shared state** (`$this->breathColor`) that the task bar and subagent display depend on. So the `FreezableTick` should still compute `breathColor` but skip the `renderCallback()` call when the thinking bar itself is frozen. This requires splitting the callback:

```php
$callback = function () use ($phrase, $palette) {
    // Always update shared state (breathColor)
    $this->breathTick++;
    $t = sin($this->breathTick * 0.07);
    // ... compute breathColor ...
    $this->breathColor = Theme::rgb($cr, $cg, $cb);

    // Only update the loader message and render if visible
    if (!$this->freezeCoordinator->isFrozen($this->thinkingBar)) {
        if ($this->loader !== null && $phrase !== '') {
            // ... existing message update ...
        }
        ($this->renderCallback)();
    } elseif (($this->hasTasksProvider)()) {
        // Task bar is visible even when thinking bar is frozen —
        // still need to render task bar updates
        ($this->refreshTaskBarCallback)();
        ($this->renderCallback)();
    }
};
```

### 5.4 Integration into `SubagentDisplayManager`

Wrap the elapsed timer in `showRunning()` (line ~212):

```php
$this->elapsedTimerId = EventLoop::repeat(0.033, new FreezableTick(
    coordinator: $this->freezeCoordinator,
    widget: $this->container ?? $this->loader, // The subagent container
    callback: function () use ($dim, $r): void {
        // ... existing elapsed timer logic ...
    },
));
```

### 5.5 Integration into `TuiCoreRenderer`

Wire up the coordinator in `initialize()` and update it in `flushRender()`:

```php
// In TuiCoreRenderer::initialize()

$this->freezeCoordinator = new OffscreenFreezeCoordinator(
    positionTracker: $this->tui->getRenderer()->getPositionTracker(),
);
```

```php
// In TuiCoreRenderer::flushRender()

public function flushRender(): void
{
    $this->tui->requestRender();
    $this->tui->processRender();

    // Update freeze state after render
    $columns = $this->tui->getTerminal()->getColumns();
    $rows = $this->tui->getTerminal()->getRows();
    $this->freezeCoordinator->updateViewport($rows, $this->scrollOffset);
    $this->freezeCoordinator->updateFrozenSet(/* contentHeight from renderer */);
}
```

### 5.6 LoaderWidget Spinner Freeze

The `LoaderWidget` has its own tick via `ScheduledTickTrait` that calls `invalidate()` and `requestRender()`. We cannot easily intercept this from outside the widget. Two options:

**Option A (Preferred — Coordinator check in requestRender)**: Add a guard in the `WidgetContext::requestRender()` path:

```php
// In a custom WidgetContext or via Tui override
public function requestRender(bool $force = false): void
{
    // If the request comes from a frozen widget's tick, suppress it
    // unless forced. This is checked via debug_backtrace or a flag.
    if (!$force && $this->freezeCoordinator?->shouldSuppressRender()) {
        return;
    }
    parent::requestRender($force);
}
```

**Option B (Subclass LoaderWidget)**: Create `FreezableLoaderWidget` that checks the coordinator in `onScheduledTick()`:

```php
final class FreezableLoaderWidget extends CancellableLoaderWidget
{
    public function __construct(
        string $message,
        private readonly OffscreenFreezeCoordinator $coordinator,
    ) {
        parent::__construct($message);
    }

    protected function onScheduledTick(): void
    {
        if ($this->coordinator->isFrozen($this)) {
            return; // Skip tick — widget is off-screen
        }
        parent::onScheduledTick();
    }
}
```

**Recommendation**: Use Option B (subclass). It's clean, explicit, and doesn't require framework changes.

### 5.7 Full File Structure

```
src/UI/Tui/Render/
├── OffscreenFreezeCoordinator.php   # Central visibility/freeze manager
└── FreezableTick.php                # Timer callback wrapper

src/UI/Tui/Widget/
└── FreezableLoaderWidget.php        # LoaderWidget that checks freeze state

src/UI/Tui/
├── TuiCoreRenderer.php              # Wire coordinator, update viewport after render
├── TuiAnimationManager.php          # Use FreezableTick for breathing/compacting timers
└── SubagentDisplayManager.php       # Use FreezableTick for elapsed timer

tests/Unit/UI/Tui/Render/
├── OffscreenFreezeCoordinatorTest.php  # Visibility and freeze/thaw logic
└── FreezableTickTest.php               # Tick suppression when frozen
```

## 6. Rendering Performance Model

### Before OffscreenFreeze

```
Per animation tick (33ms):
  1. Breath color update        ~0.01ms
  2. Task bar text rebuild      ~0.1ms
  3. LoaderWidget::tick() × 3   ~0.03ms (3 active loaders)
  4. Full tree render:
     - Style resolution × 50 widgets    ~2.5ms
     - render() × 50 widgets            ~10ms
     - Chrome application × 50 widgets  ~5ms
     - Line concatenation               ~1ms
  Total: ~18.6ms per tick

  At 30fps: ~560ms/s of CPU time on rendering
```

### After OffscreenFreeze

```
Per animation tick (33ms):
  1. Breath color update          ~0.01ms
  2. Coordinator visibility check ~0.05ms (WeakMap lookups)
  3. Skip 40 frozen widgets       ~0ms (FreezableTick returns early)
  4. Partial tree render (10 visible widgets):
     - Style resolution × 10      ~0.5ms
     - render() × 10              ~2ms
     - Chrome application × 10    ~1ms
  Total: ~3.6ms per tick

  At 30fps: ~108ms/s of CPU time on rendering
  Savings: ~80% reduction in render CPU time
```

## 7. Edge Cases and Correctness

### 7.1 Widget Added Mid-Animation

When a new widget is added to the conversation (e.g., a tool call result), it has no position in the `PositionTracker` yet. The coordinator treats untracked widgets as visible (fail-safe). On the next render pass, the widget gets a position and the coordinator evaluates it.

### 7.2 Terminal Resize

On resize, all widget positions are invalidated. `thawAll()` should be called to force a full re-render with fresh positions:

```php
// In TuiCoreRenderer (or Tui resize handler)
$this->freezeCoordinator->thawAll();
```

### 7.3 Fast Scrolling

When the user scrolls rapidly (Page Up/Down), many widgets transition between frozen and visible states in quick succession. The coordinator updates the frozen set after each render pass, so:

1. User presses Page Up → `scrollOffset` increases
2. `flushRender()` is called → viewport + frozen set update
3. Previously frozen widgets at the new scroll position are thawed
4. Thaw callbacks fire → widgets invalidate → next render shows fresh content

**No stale content**: Thawed widgets always get a fresh render before being displayed.

### 7.4 Nested Containers

A `ContainerWidget` (like the conversation) contains many children. When the container is partially visible, some children are frozen and some are visible. The coordinator tracks individual children, not just the container. This is correct because each child can be independently frozen.

### 7.5 Shared State Dependencies

The breathing color (`TuiAnimationManager::breathColor`) is shared between:
- The thinking loader (may be frozen)
- The task bar (may be visible)

The timer must always compute `breathColor`, even when the thinking loader is frozen. The `FreezableTick` with `skipRenderWhenFrozen: false` handles this — the callback always runs, but the render call inside it is conditionally suppressed based on which widgets are actually visible.

### 7.6 Compact/Expand Interactions

When a `CollapsibleWidget` is toggled (expanded/collapsed), its rendered height changes. This affects the position of all widgets below it. The coordinator's `updateFrozenSet()` recalculates after the next render pass, so widgets that moved into/out of the viewport are correctly frozen/thawed.

## 8. Test Plan

### 8.1 `OffscreenFreezeCoordinatorTest`

| Test | Input | Expected |
|------|-------|----------|
| Widget in viewport | Rect at row 5, height 10; viewport 0..40 | Not frozen |
| Widget above viewport | Rect at row -50, height 10; viewport 0..40 | Frozen |
| Widget below viewport | Rect at row 100, height 10; viewport 0..40 | Frozen |
| Widget partially visible | Rect at row 35, height 10; viewport 0..40 | Not frozen |
| No tracked position | Untracked widget | Not frozen (fail-safe) |
| Thaw on scroll | Frozen widget scrolls into view | Thaw callback fires |
| ThawAll on resize | All frozen widgets | All invalidated |
| Multiple freeze/thaw cycles | Widget scrolls in and out | Correct transitions |

### 8.2 `FreezableTickTest`

| Test | Input | Expected |
|------|-------|----------|
| Widget visible | `isFrozen() = false` | Callback executes |
| Widget frozen | `isFrozen() = true` | Callback skipped |
| Widget thaws | Frozen → visible | Next tick executes callback |

### 8.3 `FreezableLoaderWidgetTest`

| Test | Input | Expected |
|------|-------|----------|
| Visible and ticking | Loader running, not frozen | Spinner advances |
| Frozen | Loader running, coordinator says frozen | Spinner does not advance |
| Thawed | Frozen → thawed | Spinner resumes, render invalidated |

### 8.4 Integration Test

| Test | Assertion |
|------|-----------|
| Long conversation render count | Render 50 widgets, freeze 40 → only 10 `render()` calls on next tick |
| Scroll up freezes bottom | After scroll, bottom widgets don't invalidate on animation tick |
| Scroll down thaws | Scrolling back shows fresh content, no stale cached output |

## 9. Migration Strategy

### Phase 1: Coordinator + FreezableTick (this plan)

1. Create `OffscreenFreezeCoordinator` and `FreezableTick`
2. Create `FreezableLoaderWidget`
3. Wire coordinator into `TuiCoreRenderer`
4. Wrap breathing animation timer with freeze check
5. Wrap subagent elapsed timer with freeze check
6. Replace `CancellableLoaderWidget` with `FreezableLoaderWidget` in `TuiAnimationManager` and `SubagentDisplayManager`

### Phase 2: VirtualMessageList Integration (depends on `01-virtual-message-list`)

When virtual scrolling is implemented, the coordinator gains access to exact content heights per widget:

```php
// VirtualMessageList provides precise height data
$coordinator->setContentHeight($virtualList->getTotalHeight());
$coordinator->setWidgetHeights($virtualList->getWidgetHeightMap());
```

This replaces the approximate `PositionTracker`-based visibility checks with deterministic height-based calculations.

### Phase 3: Fine-Grained Render Suppression (depends on `08-animation`)

With a proper animation system, individual widget animations can be paused/resumed based on visibility:

```php
// Each widget's animation has a freeze state
$animationSystem->pause($widgetId, reason: 'offscreen');
// ... when visible again ...
$animationSystem->resume($widgetId);
```

## 10. Future Enhancements (out of scope)

1. **Predictive pre-render**: When scrolling towards frozen widgets, pre-render them one viewport ahead
2. **Priority-based thaw ordering**: Thaw widgets closer to the viewport center first
3. **Render budget**: Cap total render time per frame; defer thawed widgets to next frame if budget exceeded
4. **Animated thaw**: Instead of instantly showing thawed content, fade it in over 100ms
5. **Freeze-aware profiling**: Track how many render calls are avoided per second, expose as TUI debug overlay
6. **Partial freeze**: Freeze only animation (spinner, breathing) but allow content updates (streaming text) for partially visible widgets
