# 03 — TuiEffectRunner: Automatic Render Scheduling

> Replaces all 56 manual `flushRender()` / `forceRender()` calls with a reactive
> effect system that automatically triggers renders when state changes.

## Current State: The Problem

Every UI mutation currently ends with an explicit render call. This creates:

1. **Render thrashing** — multiple signal-like changes in quick succession each
   trigger a full `requestRender()` + `processRender()` cycle.
2. **Scattered scheduling decisions** — every call site must decide *now* whether
   to use `flushRender()` (non-forced) or `forceRender()` (forced), with no
   batching or priority logic.
3. **Coupling** — sub-renderers (TuiToolRenderer, TuiModalManager, etc.) receive
   `$renderCallback` / `$forceRenderCallback` closures they must invoke, threading
   render control through the entire object graph.

### Inventory of All Manual Render Calls

#### `TuiCoreRenderer` — 14 calls

| Line | Method | Type | Context |
|------|--------|------|---------|
| 349 | `renderIntro()` | flush | Welcome screen rendered |
| 382 | `showUserMessage()` | flush | User message bubble added |
| 451 | `showReasoningContent()` | flush | Collapsible reasoning widget added |
| 486 | `streamChunk()` | flush | Streaming text appended to active widget |
| 494 | `streamComplete()` | flush | Streaming finished, activeResponse cleared |
| 514 | `showMode()` | flush | Mode label changed in status bar |
| 522 | `setPermissionMode()` | flush | Permission mode changed in status bar |
| 547 | `showStatus()` | flush | Token/context status bar updated |
| 576 | `refreshRuntimeSelection()` | flush | Model/provider switched |
| 629 | `playAnimation()` | force | TUI restarted after full-screen animation |
| 715 | `flushPendingQuestionRecap()` | flush | Q&A recap widget added |
| 823 | `applyScrollOffset()` | flush | Scroll position changed |
| 853 | `showMessage()` | flush | Error/notice message added |

#### `TuiToolRenderer` — 15 calls (via `$this->core->flushRender()`)

| Line | Method | Context |
|------|--------|---------|
| 96 | `showToolCall()` | Task tool: task bar refreshed |
| 134 | `showToolCall()` | Bash command widget added |
| 141 | `showToolCall()` | Discovery batch item appended |
| 181 | `showToolCall()` | Generic tool call label added |
| 200 | `showToolResult()` | Task tool result: task bar refreshed |
| 219 | `showToolResult()` | Bash command completed |
| 226 | `showToolResult()` | Discovery batch item completed |
| 238 | `showToolResult()` | Lua execution result added |
| 250 | `showToolResult()` | Lua doc result added |
| 277 | `showToolResult()` | Generic tool result added |
| 330 | `showToolExecuting()` | Loader spinner started |
| 333 | `showToolExecuting()` | After spinner timer set up |
| 722 | `showLuaCodeCall()` | Lua code block added |
| 746 | `showLuaDocCall()` | Lua doc compact call added |

#### `TuiModalManager` — 7 flush + 10 force = 17 calls

| Line | Method | Type | Context |
|------|--------|------|---------|
| 71 | `askToolPermission()` | flush | Permission overlay shown |
| 91 | `askToolPermission()` | force | Permission overlay dismissed |
| 113 | `approvePlan()` | flush | Plan approval overlay shown |
| 136 | `approvePlan()` | force | Plan approval overlay dismissed |
| 164 | `askUser()` | flush | Question overlay shown |
| 172 | `askUser()` | force | Question overlay dismissed |
| 251 | `askChoice()` | flush | Choice list overlay shown |
| 275 | `askChoice()` | force | Choice list overlay dismissed |
| 298 | `showSettings()` | force | Settings panel shown (full swap) |
| 318 | `showSettings()` | force | Settings panel dismissed |
| 320 | `showSettings()` | flush | Settings panel dismissed (focus restore) |
| 349 | `showSessionPicker()` | flush | Session picker shown |
| 369 | `showSessionPicker()` | force | Session picker dismissed |
| 393 | `showAgentsDashboard()` | flush | Dashboard overlay shown |
| 406 | `showAgentsDashboard()` | force | Dashboard auto-refresh tick |
| 425 | `showAgentsDashboard()` | force | Dashboard dismissed |

#### `TuiConversationRenderer` — 2 calls

| Line | Method | Context |
|------|--------|---------|
| 38 | `clearConversation()` | All widgets cleared |
| 250 | `replayHistory()` | Full history replay finished |

#### `TuiInputHandler` — 4 calls (via closures)

| Line | Method | Type | Context |
|------|--------|------|---------|
| 157 | `handleInput()` | flush | Slash completion navigated |
| 231 | `handleInput()` | force | Ctrl+L explicit refresh |
| 387 | `handleInput()` | flush | Slash completion items updated |
| 395 | `handleInput()` | flush | Slash completion hidden |

#### `TuiAnimationManager` — 2 force calls

| Line | Method | Context |
|------|--------|---------|
| 258 | `clearCompacting()` | Compacting loader removed |
| 369 | `enterIdle()` | Thinking loader removed, cleanup done |

#### `SubagentDisplayManager` — 5 calls (via `$this->renderCallback`)

| Line | Method | Context |
|------|--------|---------|
| 152 | `spawn()` | Subagent loader added |
| 252 | `completeAgent()` | Subagent tree refreshed |
| 255 | `completeAgent()` | After subagent loader removed |
| 322 | `tickTreeRefresh()` | Periodic tree update |
| 358 | `elapsed timer tick` | Elapsed time label updated |

#### `TuiRenderer` — 1 force call

| Line | Method | Context |
|------|--------|---------|
| 236 | `prompt()` | After TUI start, initial force render |

**Total: 35 flushRender + 13 forceRender + 3 timer-based + 5 SubagentDisplayManager = 56 call sites**

---

## Architecture

### Core Concept

```
State Change → Signal Emission → TuiEffectRunner → Scheduled Render
```

The `TuiEffectRunner` subscribes to all signals in the `TuiStateStore`. When any
signal changes, the runner decides *when* and *how* to render — not the call site.

### Render Scheduling Strategies

```php
enum RenderPriority: string
{
    case Immediate = 'immediate';  // Next microtask — no debouncing
    case Deferred  = 'deferred';   // Batched via EventLoop::defer()
    case Tick      = 'tick';       // Aligned to animation frame (30fps)
}
```

| Priority | Mechanism | Use Case | Latency |
|----------|-----------|----------|---------|
| **Immediate** | Direct `requestRender(force: true)` + `processRender()` | Modal open/close, Ctrl+L, animation restart | 0ms |
| **Deferred** | `EventLoop::defer()` — coalesces multiple changes into one render | Status bar updates, tool call/result, mode changes | ~0ms (next event loop tick) |
| **Tick** | 30fps timer — `EventLoop::repeat(0.033, ...)` | Streaming chunks, breathing animation, elapsed timers | ≤33ms |

### Debouncing Strategy

The deferred strategy uses `EventLoop::defer()` for coalescing:

```php
private bool $deferredScheduled = false;

private function scheduleDeferred(): void
{
    if ($this->deferredScheduled) {
        return;  // Already have a deferred render queued
    }
    $this->deferredScheduled = true;
    EventLoop::defer(function (): void {
        $this->deferredScheduled = false;
        $this->doRender(force: false);
    });
}
```

This means 5 rapid state mutations (e.g., mode change + status update + tool call
within the same sync block) produce exactly **one** render.

The tick strategy uses a standing 30fps timer that checks a dirty flag:

```php
private bool $tickDirty = false;

// Set up once during init
EventLoop::repeat(0.033, function (): void {
    if (!$this->tickDirty) {
        return;
    }
    $this->tickDirty = false;
    $this->doRender(force: false);
});
```

### Signal → Priority Mapping

```php
private function priorityForSignal(string $signalName): RenderPriority
{
    return match ($signalName) {
        // Immediate — UI-blocking state changes
        'modal.active',
        'modal.dismissed',
        'tui.forceRefresh'           => RenderPriority::Immediate,

        // Tick-aligned — high-frequency streaming
        'response.streamText',
        'animation.breathColor',
        'animation.thinkingPhrase',
        'subagent.elapsed',
        'toolExecuting.preview'       => RenderPriority::Tick,

        // Deferred — everything else
        default                       => RenderPriority::Deferred,
    };
}
```

---

## Class Design

### `TuiEffectRunner`

```php
<?php

namespace Kosmokrator\UI\Tui\Effect;

use Revolt\EventLoop;

final class TuiEffectRunner
{
    private bool $deferredScheduled = false;
    private bool $tickDirty = false;
    private bool $forceNext = false;
    private ?string $tickTimerId = null;

    /**
     * @param \Closure(bool $force): void $renderFn  Executes requestRender + processRender
     * @param int<1, max> $tickFps  Frames per second for tick-aligned renders (default 30)
     */
    public function __construct(
        private readonly \Closure $renderFn,
        private readonly int $tickFps = 30,
    ) {}

    /**
     * Subscribe to all signals from the state store.
     *
     * Call once during TuiCoreRenderer initialization.
     */
    public function connect(TuiStateStore $store): void
    {
        $store->subscribeAll(function (string $signalName, mixed $value): void {
            $this->onSignalChange($signalName, $value);
        });

        // Start the tick timer
        $interval = 1.0 / $this->tickFps;
        $this->tickTimerId = EventLoop::repeat($interval, function (): void {
            $this->processTick();
        });
    }

    /**
     * Request an immediate forced render (for Ctrl+L, animation restart, etc.)
     */
    public function forceRenderNow(): void
    {
        $this->doRender(force: true);
    }

    /**
     * Tear down the tick timer. Call during TuiCoreRenderer::teardown().
     */
    public function disconnect(): void
    {
        if ($this->tickTimerId !== null) {
            EventLoop::cancel($this->tickTimerId);
            $this->tickTimerId = null;
        }
        $this->deferredScheduled = false;
        $this->tickDirty = false;
    }

    private function onSignalChange(string $signalName, mixed $value): void
    {
        $priority = $this->priorityForSignal($signalName);

        match ($priority) {
            RenderPriority::Immediate => $this->doRender(force: true),
            RenderPriority::Deferred  => $this->scheduleDeferred(),
            RenderPriority::Tick      => $this->scheduleTick(),
        };
    }

    private function scheduleDeferred(): void
    {
        if ($this->deferredScheduled) {
            return;
        }
        $this->deferredScheduled = true;
        EventLoop::defer(function (): void {
            $this->deferredScheduled = false;
            $this->doRender(force: $this->forceNext);
            $this->forceNext = false;
        });
    }

    private function scheduleTick(): void
    {
        $this->tickDirty = true;
    }

    private function processTick(): void
    {
        if (!$this->tickDirty) {
            return;
        }
        $this->tickDirty = false;
        $this->doRender(force: false);
    }

    private function doRender(bool $force): void
    {
        ($this->renderFn)($force);
    }

    private function priorityForSignal(string $signalName): RenderPriority
    {
        return match ($signalName) {
            'modal.active',
            'modal.dismissed',
            'tui.forceRefresh'      => RenderPriority::Immediate,

            'response.streamText',
            'animation.breathColor',
            'animation.thinkingPhrase',
            'subagent.elapsed',
            'toolExecuting.preview'  => RenderPriority::Tick,

            default                  => RenderPriority::Deferred,
        };
    }
}
```

### Integration with `TuiCoreRenderer`

The existing `flushRender()` / `forceRender()` methods become thin wrappers that
are eventually replaced:

```php
// Phase 1: TuiCoreRenderer owns the effect runner
private TuiEffectRunner $effectRunner;

public function __construct()
{
    // ...
    $this->effectRunner = new TuiEffectRunner(
        renderFn: fn (bool $force) => $this->executeRender($force),
    );
}

private function executeRender(bool $force): void
{
    $this->tui->requestRender(force: $force);
    $this->tui->processRender();
}

// During init, after TuiStateStore is created:
$this->effectRunner->connect($this->stateStore);
```

### Signal Mapping Per Call Site

Each current manual render call will be replaced by setting a signal value. The
effect runner reacts automatically:

| Current Call Site | Signal(s) Written | Priority |
|------------------|-------------------|----------|
| `renderIntro()` | `conversation.widgets` (mutated) | Deferred |
| `showUserMessage()` | `conversation.widgets` | Deferred |
| `showReasoningContent()` | `conversation.widgets` | Deferred |
| `streamChunk()` | `response.streamText` | Tick |
| `streamComplete()` | `response.active` → null | Deferred |
| `showMode()` | `status.modeLabel`, `status.modeColor` | Deferred |
| `setPermissionMode()` | `status.permissionLabel`, `status.permissionColor` | Deferred |
| `showStatus()` | `status.tokensIn`, `status.tokensOut`, etc. | Deferred |
| `refreshRuntimeSelection()` | `status.provider`, `status.model` | Deferred |
| `playAnimation()` | `tui.forceRefresh` | Immediate |
| `flushPendingQuestionRecap()` | `conversation.widgets` | Deferred |
| `applyScrollOffset()` | `scroll.offset` | Deferred |
| Modal open | `modal.active` = true | Immediate |
| Modal close | `modal.dismissed` = true | Immediate |
| Tool call added | `conversation.widgets` | Deferred |
| Tool result added | `conversation.widgets` | Deferred |
| Discovery batch | `conversation.widgets` | Deferred |
| Bash widget | `conversation.widgets` | Deferred |
| Tool executing loader | `toolExecuting.preview` | Tick |
| Animation breathing | `animation.breathColor` | Tick |
| Subagent elapsed | `subagent.elapsed` | Tick |
| Ctrl+L | `tui.forceRefresh` | Immediate |
| Slash completion | `conversation.widgets` | Deferred |

---

## Migration Plan

### Phase 0: Prepare Infrastructure (Prerequisite)

- [ ] Implement `TuiStateStore` (plan `01-state-store.md`)
- [ ] Implement signal system (plan `02-signals.md`)
- [ ] Implement `TuiEffectRunner` as described above

### Phase 1: Introduce Effect Runner Alongside Manual Calls

**Goal**: Effect runner runs in parallel but doesn't replace anything yet.
Validate that signal emissions + automatic renders produce identical results.

1. Create `TuiEffectRunner` and wire it into `TuiCoreRenderer`.
2. After every existing `flushRender()` call, add the corresponding signal write:
   ```php
   // Old (kept):
   $this->flushRender();
   // New (parallel, for validation):
   $this->stateStore->set('conversation.widgets', $this->conversation->getChildren());
   ```
3. The effect runner will produce *extra* renders during this phase — that's OK.
   It's fire-and-forget validation.
4. **Files to touch**: `TuiCoreRenderer.php` only (the central flushRender/forceRender).

**Remove nothing yet. Keep all 56 calls intact.**

### Phase 2: Remove Deferred Calls (Batch 1 — Easy Wins)

**Goal**: Remove `flushRender()` calls where the signal write is trivial.

These call sites just mutate a widget and call `flushRender()`. Once the mutation
happens via a signal, the render is automatic.

**Target: ~20 calls in TuiCoreRenderer**

| Method | Line | Strategy |
|--------|------|----------|
| `showMode()` | 514 | Signal: `status.modeLabel` — already set before flushRender |
| `setPermissionMode()` | 522 | Signal: `status.permissionLabel` — already set |
| `showStatus()` | 547 | Signal: `status.tokensIn/out/cost` — already set |
| `refreshRuntimeSelection()` | 576 | Signal: `status.provider/model` — already set |
| `showUserMessage()` | 382 | Signal: `conversation.widgets` after add |
| `showReasoningContent()` | 451 | Signal: `conversation.widgets` after add |
| `renderIntro()` | 349 | Signal: `conversation.widgets` after all adds |
| `showMessage()` | 853 | Signal: `conversation.widgets` after add |
| `flushPendingQuestionRecap()` | 715 | Signal: `conversation.widgets` after add |
| `applyScrollOffset()` | 823 | Signal: `scroll.offset` after update |
| `streamComplete()` | 494 | Signal: `response.active` → null |

**For each**: replace `flushRender()` with a signal write to the store. Remove the
`flushRender()` call. The effect runner handles the rest.

### Phase 3: Remove Deferred Calls (Batch 2 — TuiToolRenderer)

**Goal**: Remove all 15 `flushRender()` calls in `TuiToolRenderer`.

All these follow the same pattern: mutate conversation widgets → `flushRender()`.
Replace with signal writes to `conversation.widgets`.

**Key insight**: `TuiToolRenderer` currently calls `$this->core->flushRender()`.
Instead, it should call `$this->core->stateStore()->set('conversation.widgets', ...)`.

But we can simplify further: `TuiCoreRenderer::addConversationWidget()` already
adds to the conversation container. If we emit the signal *inside*
`addConversationWidget()`, all 15 TuiToolRenderer calls become automatic.

```php
// TuiCoreRenderer
public function addConversationWidget(AbstractWidget $widget): void
{
    $this->conversation->add($widget);
    $this->markHiddenConversationActivity();
    $this->stateStore->set('conversation.widgets', true); // dirty flag
}
```

This eliminates the need for TuiToolRenderer to know about rendering at all.
**After this change, remove all 15 `$this->core->flushRender()` calls in TuiToolRenderer.**

### Phase 4: Remove Deferred Calls (Batch 3 — Sub-renderers)

**Goal**: Remove render callbacks from TuiModalManager, TuiAnimationManager,
TuiInputHandler, SubagentDisplayManager.

**TuiModalManager** (7 flush + 10 force):
- Modal open → `modal.active` signal → Immediate render ✓
- Modal close → `modal.dismissed` signal → Immediate render ✓
- Dashboard refresh timer → `subagent.dashboard` signal → Tick render ✓
- Remove `renderCallback` and `forceRenderCallback` from constructor

**TuiAnimationManager** (2 force):
- `clearCompacting()` → `animation.compacting` signal → Immediate render
- `enterIdle()` → `animation.phase` signal → Immediate render
- Remove `renderCallback` and `forceRenderCallback` from constructor

**TuiInputHandler** (4 calls):
- Slash completion → `conversation.widgets` signal → Deferred render
- Ctrl+L → `tui.forceRefresh` signal → Immediate render
- Remove `flushRender` and `forceRender` from constructor

**SubagentDisplayManager** (5 calls):
- Spawn/complete/tick → `subagent.tree` or `conversation.widgets` → Deferred/Tick
- Remove `renderCallback` from constructor

**TuiConversationRenderer** (2 calls):
- `clearConversation()` → `conversation.cleared` signal → Deferred
- `replayHistory()` → `conversation.widgets` signal → Deferred

### Phase 5: Remove Dead Code

1. Delete `TuiCoreRenderer::flushRender()` and `TuiCoreRenderer::forceRender()`.
2. Delete `renderCallback` / `forceRenderCallback` parameters from:
   - `TuiModalManager::__construct()`
   - `TuiAnimationManager::__construct()`
   - `SubagentDisplayManager::__construct()`
3. Delete `flushRender` / `forceRender` parameters from `TuiInputHandler::__construct()`.
4. Delete the forwarding calls in `TuiCoreRenderer::bindInputHandlers()`.
5. Remove `TuiRenderer::forceRender()` (the one at line 236).

### Phase 6: Streaming Optimization

Once the tick-aligned strategy is stable, optimize `streamChunk()`:

- Currently: every chunk calls `flushRender()` synchronously.
- Target: chunks write to `response.streamText` signal → effect runner batches at 30fps.
- The MarkdownWidget/AnsiArtWidget still gets updated on every chunk (text append),
  but the *render* only happens at tick boundaries.
- This reduces renders during streaming from ~20/sec (one per chunk) to exactly 30/sec.

```php
public function streamChunk(string $text): void
{
    // ... widget text update logic stays the same ...
    $this->activeResponse->setText($current . $text);
    $this->markHiddenConversationActivity();
    // No flushRender! Signal emission happens automatically.
    $this->stateStore->set('response.streamText', $this->activeResponse->getText());
}
```

---

## Integration with Symfony TUI

### `requestRender()` / `processRender()` Contract

The Symfony TUI `Tui` object exposes:

```php
$tui->requestRender(bool $force = false): void;  // Marks dirty
$tui->processRender(): void;                      // Executes the render
```

The effect runner wraps both:

```php
$effectRunner = new TuiEffectRunner(
    renderFn: function (bool $force): void {
        $this->tui->requestRender(force: $force);
        $this->tui->processRender();
    },
);
```

### Animation Timer Coordination

Currently, `TuiAnimationManager` runs a `EventLoop::repeat(0.033, ...)` timer that
calls `forceRenderCallback()` on every tick. The effect runner's tick timer at 30fps
overlaps with this.

**Migration path**:
1. Phase 1-4: Both timers coexist. The animation timer writes signals; the effect
   runner's tick picks them up. Minor double-rendering is acceptable.
2. Phase 5: Remove the animation timer's direct `forceRenderCallback()` calls.
   The animation timer only updates signal values; the effect runner's tick timer
   handles rendering.
3. Phase 6: Consider merging the animation timer into the effect runner's tick
   as a registered "onTick" callback:

```php
$effectRunner->onTick(function (): void {
    $this->animationManager->tick();  // Updates breathColor, phrase, etc.
});
```

### Suspension Points

Modal dialogs block via `EventLoop::getSuspension()->suspend()`. During suspension,
the event loop still runs — deferred callbacks and timers fire normally. The effect
runner continues to work.

When a modal is dismissed and `suspend()` returns, the "modal dismissed" signal is
set, triggering an Immediate render. This replaces the current `forceRender()` at
modal teardown.

---

## Testing Strategy

### Unit Tests

1. **Debounce coalescing**: Emit 5 deferred signals → verify exactly 1 render call.
2. **Immediate bypass**: Emit an immediate-priority signal → verify render happens
   synchronously, not deferred.
3. **Tick batching**: Emit tick-priority signals → verify render only at tick boundary.
4. **Priority escalation**: Emit deferred + immediate in same frame → verify immediate
   wins and deferred is cancelled.
5. **Disconnect**: Call `disconnect()` → verify no more renders fire.

### Integration Tests

1. **Streaming**: Send 50 chunks rapidly → count renders. Should be ~30/sec, not 50.
2. **Modal lifecycle**: Open/close permission prompt → verify exactly 2 renders
   (one Immediate each).
3. **Tool call burst**: Call `showToolCall()` + `showToolResult()` in quick succession
   → verify single deferred render.

### Visual Regression

- Compare screenshots before/after migration for each phase.
- No visible changes should occur — only timing differences.

---

## File Location

```
src/UI/Tui/Effect/
├── TuiEffectRunner.php
├── RenderPriority.php
```

## Dependencies

- `TuiStateStore` (plan `01-state-store.md`)
- Signal system (plan `02-signals.md`)
- `Revolt\EventLoop` (already used throughout)

## Estimated Effort

| Phase | Description | Effort |
|-------|-------------|--------|
| 0 | Infrastructure (state store + signals + effect runner) | 3-4 days |
| 1 | Parallel validation | 1 day |
| 2 | TuiCoreRenderer deferred removal | 1 day |
| 3 | TuiToolRenderer removal | 1 day |
| 4 | Sub-renderer removal (ModalManager, Animation, Input, Subagent) | 2-3 days |
| 5 | Dead code cleanup | 0.5 day |
| 6 | Streaming optimization | 1 day |
| **Total** | | **~10 days** |
