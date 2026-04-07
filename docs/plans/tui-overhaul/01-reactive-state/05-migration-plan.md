# 05 — Migration Plan: Manual State → Signal-Based Reactive State

> **Goal:** Replace the closure-mediated, imperative state management across the TUI subsystem with a signal/computed reactive model. Each step must leave the TUI fully functional.

---

## Table of Contents

1. [Current Closure Inventory](#1-current-closure-inventory)
2. [Migration Mapping: What Becomes a Signal](#2-migration-mapping-what-becomes-a-signal)
3. [What Stays Imperative](#3-what-stays-imperative)
4. [Step-by-Step Migration](#4-step-by-step-migration)
5. [Cross-Cutting Concerns](#5-cross-cutting-concerns)
6. [Rollback Strategy](#6-rollback-strategy)

---

## 1. Current Closure Inventory

### 1.1 TuiCoreRenderer (`src/UI/Tui/TuiCoreRenderer.php`)

TuiCoreRenderer is the hub. It creates all sub-managers and passes closures into their constructors. The closures it hands out are:

| # | Closure | Created at line | Given to | Closes over | Purpose |
|---|---------|----------------|----------|-------------|---------|
| 1 | `fn () => $this->animationManager->getBreathColor()` | L217 | SubagentDisplayManager | `$this->animationManager` (forward reference) | Provides live breathing color for subagent tree rendering |
| 2 | `fn () => $this->flushRender()` | L218 | SubagentDisplayManager | `$this` | Render pass |
| 3 | `fn () => $this->animationManager->ensureSpinnersRegistered()` | L219 | SubagentDisplayManager | `$this->animationManager` (forward reference) | Spinner registration |
| 4 | `fn () => $this->taskStore !== null && ! $this->taskStore->isEmpty()` | L224 | TuiAnimationManager | `$this->taskStore` | Check for active tasks |
| 5 | `fn () => $this->subagentDisplay->hasRunningAgents()` | L225 | TuiAnimationManager | `$this->subagentDisplay` (forward ref) | Check subagent activity |
| 6 | `fn () => $this->refreshTaskBar()` | L226 | TuiAnimationManager | `$this` | Refresh task bar widget |
| 7 | `fn () => $this->subagentDisplay->tickTreeRefresh()` | L227 | TuiAnimationManager | `$this->subagentDisplay` (forward ref) | Tick subagent tree |
| 8 | `fn () => $this->subagentDisplay->cleanup()` | L228 | TuiAnimationManager | `$this->subagentDisplay` (forward ref) | Cleanup subagent state |
| 9 | `fn () => $this->flushRender()` | L229 | TuiAnimationManager | `$this` | Render pass |
| 10 | `fn () => $this->forceRender()` | L230 | TuiAnimationManager | `$this` | Forced render pass |
| 11 | `fn () => $this->flushRender()` | L251 | TuiModalManager | `$this` | Render pass |
| 12 | `fn () => $this->forceRender()` | L252 | TuiModalManager | `$this` | Forced render pass |
| 13 | `fn (string $msg) => $this->queueMessage($msg)` | L884 | TuiInputHandler | `$this` | Queue user message + display |
| 14 | `fn (string $msg) => $this->messageQueue[] = $msg` | L885 | TuiInputHandler | `$this->messageQueue` | Silent queue |
| 15 | `fn () => $this->immediateCommandHandler` | L886 | TuiInputHandler | `$this->immediateCommandHandler` | Get command handler |
| 16 | `fn () => $this->promptSuspension` | L887 | TuiInputHandler | `$this->promptSuspension` | Get prompt suspension |
| 17 | `fn () => $this->promptSuspension = null` | L888 | TuiInputHandler | `$this->promptSuspension` | Clear prompt suspension |
| 18 | `fn (?string $v) => $this->pendingEditorRestore = $v` | L889 | TuiInputHandler | `$this->pendingEditorRestore` | Set pending editor restore |
| 19 | `fn () => $this->requestCancellation` | L890 | TuiInputHandler | `$this->requestCancellation` | Get cancellation |
| 20 | `fn () => $this->requestCancellation = null` | L891 | TuiInputHandler | `$this->requestCancellation` | Clear cancellation |

**Also receives closures from outside:**

| # | Closure | Set via | Stored as | Purpose |
|---|---------|--------|-----------|---------|
| 21 | `?\Closure(): void` | `setDiscoveryBatchFinalizer()` | `$this->discoveryBatchFinalizer` | Finalizes discovery batch widgets before streaming |
| 22 | `?\Closure(): void` | `setToolStateResetCallback()` | `$this->toolStateResetCallback` | Resets tool state on conversation clear |

### 1.2 TuiAnimationManager (`src/UI/Tui/TuiAnimationManager.php`)

Receives 8 closures in its constructor (items 4–10 above):

| Param | Signature | Called at | Frequency |
|-------|-----------|-----------|-----------|
| `$hasTasksProvider` | `Closure(): bool` | L287 (enterThinking), L416 (breathing timer) | Per phase + per breath tick |
| `$hasSubagentActivityProvider` | `Closure(): bool` | L407 (breathing timer) | Per breath tick (~30fps) |
| `$refreshTaskBarCallback` | `Closure(): void` | L366 (enterIdle), L417 (breathing timer) | Per phase + per breath tick |
| `$subagentTickCallback` | `Closure(): void` | L422 (breathing timer, every 15th tick) | ~2/s |
| `$subagentCleanupCallback` | `Closure(): void` | L367 (enterIdle) | Per phase |
| `$renderCallback` | `Closure(): void` | L235, L324, L342, L425 | Per breath tick + per phase |
| `$forceRenderCallback` | `Closure(): void` | L258, L369 | Per phase cleanup |

### 1.3 TuiInputHandler (`src/UI/Tui/TuiInputHandler.php`)

Receives 17 closures in its constructor. Categorization:

**Action closures** (trigger side effects):
| Param | Signature | Used in |
|-------|-----------|---------|
| `$flushRender` | `Closure(): void` | handleInput, showCommandCompletion, hideSlashCompletion, toggleAllToolResults |
| `$forceRender` | `Closure(): void` | handleInput (Ctrl+L) |
| `$scrollHistoryUp` | `Closure(): void` | handleInput |
| `$scrollHistoryDown` | `Closure(): void` | handleInput |
| `$jumpToLiveOutput` | `Closure(): void` | handleInput |
| `$showMode` | `Closure(string, string): void` | handleInput (cycle_mode) |
| `$queueMessage` | `Closure(string): void` | handleSubmit |
| `$queueMessageSilent` | `Closure(string): void` | handleInput (cycle_mode) |
| `$clearPromptSuspension` | `Closure(null): void` | handleInput, handleSubmit |
| `$setPendingEditorRestore` | `Closure(?string): void` | handleInput |
| `$clearRequestCancellation` | `Closure(null): void` | handleInput, handleCancel |

**State reader closures** (return current value):
| Param | Signature | Used in |
|-------|-----------|---------|
| `$isBrowsingHistory` | `Closure(): bool` | handleInput |
| `$cycleMode` | `Closure(): string` | handleInput |
| `$getImmediateCommandHandler` | `Closure(): (Closure(string): bool)\|null` | handleInput, handleCancel |
| `$getPromptSuspension` | `Closure(): ?Suspension` | handleInput, handleSubmit, handleCancel |
| `$getRequestCancellation` | `Closure(): ?DeferredCancellation` | handleInput, handleCancel, handleSubmit |

### 1.4 TuiToolRenderer (`src/UI/Tui/TuiToolRenderer.php`)

**Receives no closures in constructor.** Takes only `TuiCoreRenderer $core` (L55). All cross-class communication goes through `$this->core->method()` calls directly. No closures to migrate here.

Internal state that could become signals:
- `$activeBashWidget` — nullable widget reference
- `$toolExecutingLoader` — nullable loader widget
- `$lastToolArgs` / `$lastToolArgsByName` — tool arg caches
- `$activeDiscoveryBatch` / `$activeDiscoveryItems` — discovery batch state

---

## 2. Migration Mapping: What Becomes a Signal

### 2.1 Core State → Signals

These are the "leaf" signals — state containers that other parts of the system read reactively.

| Signal Name | Type | Current Location | Current Storage | Reactive Consumers |
|-------------|------|-----------------|-----------------|-------------------|
| `taskStore` | `?TaskStore` | TuiCoreRenderer::$taskStore | Private field | AnimationManager (hasTasks), CoreRenderer (refreshTaskBar) |
| `agentPhase` | `AgentPhase` | TuiAnimationManager::$currentPhase | Private field | AnimationManager, CoreRenderer (setPhase) |
| `breathColor` | `?string` | TuiAnimationManager::$breathColor | Private field | SubagentDisplayManager, CoreRenderer (refreshTaskBar) |
| `thinkingPhrase` | `?string` | TuiAnimationManager::$thinkingPhrase | Private field | CoreRenderer (refreshTaskBar) |
| `thinkingStartTime` | `float` | TuiAnimationManager::$thinkingStartTime | Private field | CoreRenderer (refreshTaskBar elapsed) |
| `currentModeLabel` | `string` | TuiCoreRenderer::$currentModeLabel | Private field | CoreRenderer (refreshStatusBar) |
| `currentModeColor` | `string` | TuiCoreRenderer::$currentModeColor | Private field | CoreRenderer (refreshStatusBar) |
| `currentPermissionLabel` | `string` | TuiCoreRenderer::$currentPermissionLabel | Private field | CoreRenderer (refreshStatusBar) |
| `currentPermissionColor` | `string` | TuiCoreRenderer::$currentPermissionColor | Private field | CoreRenderer (refreshStatusBar) |
| `statusDetail` | `string` | TuiCoreRenderer::$statusDetail | Private field | CoreRenderer (refreshStatusBar) |
| `scrollOffset` | `int` | TuiCoreRenderer::$scrollOffset | Private field | CoreRenderer (historyStatus, applyScrollOffset) |
| `hasHiddenActivityBelow` | `bool` | TuiCoreRenderer::$hasHiddenActivityBelow | Private field | CoreRenderer (refreshHistoryStatus) |
| `promptSuspension` | `?Suspension` | TuiCoreRenderer::$promptSuspension | Private field | InputHandler |
| `requestCancellation` | `?DeferredCancellation` | TuiCoreRenderer::$requestCancellation | Private field | InputHandler, AnimationManager |
| `immediateCommandHandler` | `?Closure` | TuiCoreRenderer::$immediateCommandHandler | Private field | InputHandler |
| `pendingEditorRestore` | `?string` | TuiCoreRenderer::$pendingEditorRestore | Private field | InputHandler |
| `messageQueue` | `string[]` | TuiCoreRenderer::$messageQueue | Private field | InputHandler, CoreRenderer |

### 2.2 Derived State → Computed Signals

These derive their values from leaf signals and should auto-update.

| Computed | Derives From | Current Location |
|----------|-------------|-----------------|
| `isBrowsingHistory` | `scrollOffset > 0` | TuiCoreRenderer::isBrowsingHistory() |
| `hasTasks` | `taskStore !== null && !taskStore->isEmpty()` | Closure #4 → TuiAnimationManager::$hasTasksProvider |
| `hasSubagentActivity` | `subagentDisplay->hasRunningAgents()` | Closure #5 → TuiAnimationManager::$hasSubagentActivityProvider |

### 2.3 Effect Subscriptions (Side Effects)

These are "when X changes, do Y" — the key enabler for removing manual `refreshStatusBar()` calls.

| Effect | Trigger | Action |
|--------|---------|--------|
| Status bar refresh | Any of: currentModeLabel, currentModeColor, currentPermissionLabel, currentPermissionColor, statusDetail | `refreshStatusBar()` + `flushRender()` |
| Task bar refresh | taskStore mutation, breathColor change (during thinking/tools) | `refreshTaskBar()` + `flushRender()` |
| History status refresh | scrollOffset, hasHiddenActivityBelow | `refreshHistoryStatus()` |
| Subagent tree tick | Agent is in Thinking/Tools phase + hasSubagentActivity | `tickTreeRefresh()` |

### 2.4 Closures → Signal Subscriptions

| Current Closure | Becomes | Signal Used |
|----------------|---------|-------------|
| `#4 hasTasksProvider` | `computed('hasTasks')` reader | `taskStore` signal |
| `#5 hasSubagentActivityProvider` | Direct method call or computed | SubagentDisplayManager state |
| `#6 refreshTaskBarCallback` | Effect on `taskStore`, `breathColor`, `agentPhase` | Multiple |
| `#15 getImmediateCommandHandler` | `immediateCommandHandler` signal reader | Signal |
| `#16 getPromptSuspension` | `promptSuspension` signal reader | Signal |
| `#17 clearPromptSuspension` | `promptSuspension.set(null)` | Signal write |
| `#18 setPendingEditorRestore` | `pendingEditorRestore.set($v)` | Signal write |
| `#19 getRequestCancellation` | `requestCancellation` signal reader | Signal |
| `#20 clearRequestCancellation` | `requestCancellation.set(null)` | Signal write |

---

## 3. What Stays Imperative

These closures and patterns **cannot or should not** become reactive signals:

### 3.1 Suspensions (Revolt Coroutines)

`Suspension` objects are one-shot coroutine primitives. They cannot be reactive because:
- They represent a specific point in the event loop's execution
- `resume()` can only be called once
- The control flow is inherently imperative (suspend → wait → resume)

**Stays as closures/methods:**
- `promptSuspension` — Although stored in a signal, the `resume()` call is imperative. The signal only tracks the reference.
- `askSuspension` (TuiModalManager) — Same pattern.

### 3.2 Render Callbacks

`flushRender()` and `forceRender()` are procedural side effects that interact with the Tui framework's render loop. These remain as direct method calls, but invocation can be triggered by effects.

**Stays imperative:** `$renderCallback`, `$forceRenderCallback` remain as `Closure` parameters. Effects will call them.

### 3.3 Event Handlers (Widget Callbacks)

Widget event handlers (`onInput`, `onCancel`, `onChange`, `onSubmit`) are imperative by nature — they receive events and produce side effects. These stay as closures/methods in TuiInputHandler.

**Stays imperative:** All four `handleX()` methods in TuiInputHandler.

### 3.4 Timer Callbacks

`EventLoop::repeat()` callbacks are imperative. They can *read* signals and *trigger* effects, but the timer registration itself stays imperative.

**Stays imperative:** `startBreathingAnimation()`, `showCompacting()`, `showToolExecuting()` timer bodies.

### 3.5 Discovery Batch Finalizer

The `$discoveryBatchFinalizer` closure bridges CoreRenderer → ToolRenderer. It's a callback set at construction time for cross-object coordination. It should remain a closure until `TuiToolRenderer` is refactored to react to `activeDiscoveryBatch` signal changes — which is a later step.

---

## 4. Step-by-Step Migration

Each step is independently mergeable. The TUI must work after every step.

### Phase 0: Foundation

#### Step 0.1 — Create `Signal` and `Computed` primitives

**File:** `src/UI/Tui/Signal/Signal.php` (new)

Create minimal reactive primitives:
- `Signal<T>` — writable state container with subscriber notification
- `Computed<T>` — read-only derived value that auto-tracks dependencies
- `Effect` — side-effect runner that re-executes when tracked signals change

**Testing:** Unit tests for Signal, Computed, and Effect in isolation. No TUI changes yet.

```php
// Target API shape
$phase = Signal::of(AgentPhase::Idle);
$phase->set(AgentPhase::Thinking);

$hasTasks = Computed::of(fn () => $taskStore->get() !== null && !$taskStore->get()->isEmpty());
$hasTasks->get(); // true/false, auto-tracks $taskStore

Effect::create(function () use ($statusBar, $modeLabel, $modeColor) {
    $statusBar->setMessage("{$modeColor->get()}{$modeLabel->get()}");
});
```

#### Step 0.2 — Create `TuiStateStore`

**File:** `src/UI/Tui/TuiStateStore.php` (new)

Central holder for all TUI signals. This is the single source of truth.

```php
final class TuiStateStore
{
    public readonly Signal $agentPhase;
    public readonly Signal $breathColor;
    public readonly Signal $thinkingPhrase;
    public readonly Signal $thinkingStartTime;
    public readonly Signal $currentModeLabel;
    public readonly Signal $currentModeColor;
    public readonly Signal $currentPermissionLabel;
    public readonly Signal $currentPermissionColor;
    public readonly Signal $statusDetail;
    public readonly Signal $scrollOffset;
    public readonly Signal $hasHiddenActivityBelow;
    public readonly Signal $promptSuspension;
    public readonly Signal $requestCancellation;
    public readonly Signal $immediateCommandHandler;
    public readonly Signal $pendingEditorRestore;
    public readonly Signal $taskStore;

    public readonly Computed $isBrowsingHistory;
    public readonly Computed $hasTasks;

    // Construction + wiring
}
```

**Testing:** Unit tests verifying computed derivation. No TUI wiring yet.

---

### Phase 1: Read-Only Signal Migration (Low Risk)

These steps replace private fields with signal reads, but don't change any control flow or add effects yet. Existing closure patterns continue to work.

#### Step 1.1 — Migrate status bar fields to signals

**Target file:** `TuiCoreRenderer.php`

Replace these private fields with reads from `TuiStateStore`:
- `$currentModeLabel` → `$this->state->currentModeLabel->get()`
- `$currentModeColor` → `$this->state->currentModeColor->get()`
- `$currentPermissionLabel` → `$this->state->currentPermissionLabel->get()`
- `$currentPermissionColor` → `$this->state->currentPermissionColor->get()`
- `$statusDetail` → `$this->state->statusDetail->get()`

Update all writers to use `$this->state->currentModeLabel->set($value)`.

**Keep:** The `refreshStatusBar()` method as-is. It still reads from signals and pushes to the widget. Effects come later.

**Verification:**
1. Run existing TUI — status bar displays correctly in all modes
2. Switch modes with Shift+Tab — label/color update
3. Run `/guardian`, `/argus`, `/prometheus` — permission label updates
4. Token counter updates during streaming

#### Step 1.2 — Migrate scroll/history fields to signals

**Target file:** `TuiCoreRenderer.php`

Replace:
- `$scrollOffset` → `$this->state->scrollOffset`
- `$hasHiddenActivityBelow` → `$this->state->hasHiddenActivityBelow`
- `isBrowsingHistory()` → `$this->state->isBrowsingHistory->get()` (computed)

**Verification:**
1. Page Up / Page Down scrolls conversation history
2. History status indicator appears/disappears correctly
3. End key jumps to live output
4. Hidden activity indicator shows when scrolled up during streaming

#### Step 1.3 — Migrate prompt/input fields to signals

**Target file:** `TuiCoreRenderer.php`

Replace:
- `$promptSuspension` → `$this->state->promptSuspension`
- `$pendingEditorRestore` → `$this->state->pendingEditorRestore`
- `$requestCancellation` → `$this->state->requestCancellation`
- `$immediateCommandHandler` → `$this->state->immediateCommandHandler`

Update `prompt()` to write to signal. Update `bindInputHandlers()` closures to read from signals.

**Verification:**
1. Type a message and press Enter — message submitted
2. Ctrl+C during thinking — cancels the request
3. Ctrl+C at prompt — exits
4. Immediate command handler works (Ctrl+A for agents dashboard)
5. Editor text preserved across mode switches

---

### Phase 2: Animation Manager Signal Migration

#### Step 2.1 — Inject TuiStateStore into TuiAnimationManager

**Target file:** `TuiAnimationManager.php`

Replace constructor closures with `TuiStateStore`:
- Remove `$hasTasksProvider` → use `$this->state->hasTasks->get()`
- Keep `$hasSubagentActivityProvider` (external dependency on SubagentDisplayManager)
- Keep `$refreshTaskBarCallback` (becomes effect in Phase 3)
- Keep `$subagentTickCallback`, `$subagentCleanupCallback` (external dependencies)
- Keep `$renderCallback`, `$forceRenderCallback` (imperative)

Migrate internal state to signals:
- `$currentPhase` → `$this->state->agentPhase`
- `$breathColor` → `$this->state->breathColor`
- `$thinkingPhrase` → `$this->state->thinkingPhrase`
- `$thinkingStartTime` → `$this->state->thinkingStartTime`

**Verification:**
1. Thinking animation shows with correct phrase and spinner
2. Breathing color oscillates smoothly (blue during thinking)
3. Phase transitions: Thinking → Tools → Idle
4. Task bar updates during thinking when tasks exist
5. Compacting animation shows/clears correctly

#### Step 2.2 — Remove forward-reference closure hacks

**Target file:** `TuiCoreRenderer.php` (L215–231)

The `SubagentDisplayManager` and `TuiAnimationManager` currently create closures over each other via forward references (the objects don't exist yet at closure creation time). This is fragile.

With signals in `TuiStateStore`, both managers read from shared signals instead:
- `SubagentDisplayManager` reads `$this->state->breathColor->get()` instead of `$this->breathColorProvider` closure
- `TuiAnimationManager` reads `$this->state->hasTasks->get()` instead of `$this->hasTasksProvider` closure

The circular dependency between SubagentDisplayManager ↔ TuiAnimationManager is broken by `TuiStateStore`.

**Verification:**
1. Subagent spawn/running/batch display works
2. Subagent tree refreshes during breathing animation
3. Breathing color applied to subagent tree
4. Subagent cleanup on phase → Idle

---

### Phase 3: Effect-Based Auto-Refresh

This is where the real value appears — removing manual `refreshX()` + `flushRender()` call pairs.

#### Step 3.1 — Status bar auto-refresh effect

**Target file:** `TuiCoreRenderer.php`

Replace the pattern of:
```php
$this->currentModeLabel = $label;
$this->refreshStatusBar();
$this->flushRender();
```

With an effect:
```php
Effect::create(function () {
    $this->refreshStatusBar();
    $this->flushRender();
})->track($this->state->currentModeLabel, $this->state->currentModeColor,
           $this->state->currentPermissionLabel, $this->state->currentPermissionColor,
           $this->state->statusDetail);
```

Then remove manual `refreshStatusBar()` + `flushRender()` calls from:
- `showMode()`
- `setPermissionMode()`
- `showStatus()`
- `refreshRuntimeSelection()`

**Verification:**
1. Status bar updates on mode switch
2. Status bar updates on permission mode switch
3. Status bar updates on token counter change
4. Status bar updates on model/provider change
5. No double-renders or render loops

#### Step 3.2 — Task bar auto-refresh effect

**Target file:** `TuiCoreRenderer.php`

Create effect that refreshes task bar when `taskStore`, `breathColor`, `thinkingPhrase`, or `thinkingStartTime` change.

Remove manual `refreshTaskBar()` calls from:
- `TuiAnimationManager::enterIdle()` (L366)
- `TuiAnimationManager::startBreathingAnimation()` (L417) — this one is in the timer, so keep it there since it's ~30fps
- `TuiToolRenderer::showToolCall()` for task tools
- `TuiToolRenderer::showToolResult()` for task tools

**Important:** The breathing timer's call to `refreshTaskBarCallback` (every tick when tasks exist) must stay imperative — it's driven by a timer, not by state change. But the phase-transition calls (enterIdle) can move to an effect.

**Verification:**
1. Task bar appears when tasks are created
2. Task bar disappears when all tasks complete
3. Task bar shows breathing color during thinking/tools
4. Task bar shows elapsed time during thinking
5. Task bar clears on idle

#### Step 3.3 — History status auto-refresh effect

**Target file:** `TuiCoreRenderer.php`

Create effect:
```php
Effect::create(function () {
    $this->refreshHistoryStatus();
})->track($this->state->scrollOffset, $this->state->hasHiddenActivityBelow);
```

Remove manual `refreshHistoryStatus()` calls from `markHiddenConversationActivity()`, `applyScrollOffset()`.

Keep `flushRender()` in `applyScrollOffset()` (the scroll change itself needs immediate rendering).

**Verification:**
1. Scroll up → history status shows
2. Scroll to bottom → history status hides
3. New content while scrolled → "activity below" indicator
4. Jump to live → indicator clears

---

### Phase 4: TuiInputHandler Signal Migration

#### Step 4.1 — Replace state-reader closures with signal reads

**Target file:** `TuiInputHandler.php`

Replace constructor parameters:
- `$isBrowsingHistory` → `$this->state->isBrowsingHistory->get()`
- `$getPromptSuspension` → `$this->state->promptSuspension->get()`
- `$getRequestCancellation` → `$this->state->requestCancellation->get()`
- `$getImmediateCommandHandler` → `$this->state->immediateCommandHandler->get()`

Keep as closures (imperative actions):
- `$flushRender`, `$forceRender` — render triggers
- `$scrollHistoryUp`, `$scrollHistoryDown`, `$jumpToLiveOutput` — these modify scroll signals then call render
- `$showMode` — sets mode signal + triggers render
- `$queueMessage`, `$queueMessageSilent` — modifies message queue

Actually, `$scrollHistoryUp/Down/JumpToLive` can be refactored to:
1. Write to `$this->state->scrollOffset->set(...)` 
2. The effect from Step 3.3 auto-calls `refreshHistoryStatus()`
3. Only need an explicit `flushRender()` call

**Verification:**
1. All slash command completions work (`/e`, `/g`, `:d`, `$`)
2. Tab completion selects command
3. Escape dismisses completion
4. Enter submits command from completion
5. Shift+Tab cycles mode
6. Page Up/Down scrolls
7. Ctrl+C cancels thinking
8. Ctrl+L forces render

#### Step 4.2 — Replace state-writer closures with signal writes

Replace:
- `$clearPromptSuspension` → `$this->state->promptSuspension->set(null)`
- `$setPendingEditorRestore` → `$this->state->pendingEditorRestore->set($v)`
- `$clearRequestCancellation` → `$this->state->requestCancellation->set(null)`

`$cycleMode` stays as a closure — it reads `currentModeLabel` signal and returns the next mode string. It could become a `Computed`, but it's only called imperatively.

**Verification:** Same as Step 4.1.

#### Step 4.3 — Reduce TuiInputHandler constructor to TuiStateStore + action closures

Final state of TuiInputHandler constructor:

```php
public function __construct(
    private readonly EditorWidget $input,
    private readonly ContainerWidget $conversation,
    private readonly ContainerWidget $overlay,
    private readonly TuiModalManager $modalManager,
    private readonly TuiStateStore $state,
    private readonly \Closure $flushRender,
    private readonly \Closure $forceRender,
    private readonly \Closure $scrollHistoryUp,
    private readonly \Closure $scrollHistoryDown,
    private readonly \Closure $jumpToLiveOutput,
    private readonly \Closure $cycleMode,
    private readonly \Closure $showMode,
    private readonly \Closure $queueMessage,
    private readonly \Closure $queueMessageSilent,
) {}
```

17 closures → 1 TuiStateStore + 9 action closures. The 8 removed closures were all state readers/writers.

**Verification:** Full manual smoke test of all input interactions.

---

### Phase 5: Cleanup & Optimization

#### Step 5.1 — Remove TuiAnimationManager closures for hasTasks/hasSubagentActivity

At this point, `TuiAnimationManager` can read from `TuiStateStore::hasTasks`. The `$hasSubagentActivityProvider` remains as a closure (SubagentDisplayManager is external), but could be replaced with a signal if SubagentDisplayManager exposes one.

**Verification:** Animation phases work correctly.

#### Step 5.2 — Batch render calls with effect deduplication

When multiple signals change in the same tick (e.g., `setPhase()` changes `agentPhase`, `thinkingPhrase`, `thinkingStartTime` simultaneously), the effects should batch into a single render.

Add microtask-based batching to `Effect`:
```php
Effect::flush(); // Called once at end of synchronous batch
```

Or use Revolt `EventLoop::defer()` to coalesce renders.

**Verification:** No visible flicker. Render count stays reasonable during phase transitions.

#### Step 5.3 — Remove `$discoveryBatchFinalizer` and `$toolStateResetCallback` closures

These cross-object callbacks can be replaced by:
- Discovery batch: TuiToolRenderer watches an `activeStreaming` signal from TuiStateStore
- Tool state reset: TuiToolRenderer watches a `conversationCleared` signal

Or keep them as explicit method calls — these are called infrequently and the closure approach is clean.

**Verification:** Discovery batches render correctly. Conversation clear resets all state.

---

## 5. Cross-Cutting Concerns

### 5.1 Forward Reference Problem

`TuiCoreRenderer::initialize()` creates `SubagentDisplayManager` (L215) before `TuiAnimationManager` (L222), but `SubagentDisplayManager`'s constructor receives `fn () => $this->animationManager->getBreathColor()` — a closure over a property that doesn't exist yet.

**Signal solution:** Both managers receive `TuiStateStore`. The `breathColor` signal lives in `TuiStateStore` and is created before either manager. No forward references needed.

### 5.2 Render Batching

Currently, many methods end with `$this->flushRender()`. With effects, multiple signals might change in one call, triggering multiple effects. The `Effect` system must batch renders — only one `flushRender()` per synchronous batch.

**Implementation:** Use a dirty flag + `EventLoop::defer()` to deduplicate renders within the same event loop tick.

### 5.3 Memory / Subscription Cleanup

Effects subscribe to signals. When the TUI tears down, all subscriptions must be cleaned up to prevent memory leaks. `TuiStateStore` should provide a `dispose()` method that clears all subscribers.

### 5.4 Testing Infrastructure

Create a `SignalTestCase` base class that:
1. Sets up a `TuiStateStore` with test values
2. Provides assertion helpers: `assertSignalEquals($signal, $expected)`, `assertEffectFired($effect)`
3. Mocks `flushRender()` / `forceRender()` to count invocations

### 5.5 Performance

The breathing timer fires at ~30fps. Each tick reads `hasTasks`, updates `breathColor`, and potentially calls `refreshTaskBar`. Signals must be cheap to read (O(1) after first computation). `Computed` values cache until dependencies change.

**Critical path:** The timer body in `startBreathingAnimation()` (L384–426) must not trigger more than 1 render per tick. With effects, ensure no cascading updates from `breathColor` → `refreshTaskBar` → render create a second render in the same tick.

---

## 6. Rollback Strategy

Each step is independently revertable:

| Step | Rollback |
|------|----------|
| 0.1–0.2 | Delete new files. No existing code changed. |
| 1.1 | Revert field removals. Signals still exist but aren't read. |
| 1.2 | Revert scroll field changes. |
| 1.3 | Revert prompt/input field changes. |
| 2.1 | Revert AnimationManager constructor. Pass closures again. |
| 2.2 | Revert forward-reference removal. |
| 3.1–3.3 | Remove effects. Add back manual refresh calls. |
| 4.1–4.3 | Revert InputHandler constructor. Pass closures again. |
| 5.1–5.3 | These are cleanup. Reverting = keeping old patterns alongside new. |

**Git strategy:** One branch per phase. Merge after verification. Tag with `tui-reactive-phase-N`.

---

## Dependency Graph

```
Phase 0 (Signal primitives + TuiStateStore)
  ├── Phase 1 (Read-only signal migration — any order within)
  │     ├── Step 1.1 (status bar fields)
  │     ├── Step 1.2 (scroll/history fields)
  │     └── Step 1.3 (prompt/input fields)
  ├── Phase 2 (Animation Manager)
  │     ├── Step 2.1 (inject state store)
  │     └── Step 2.2 (remove forward refs)
  ├── Phase 3 (Effects) ← depends on Phase 1 + 2
  │     ├── Step 3.1 (status bar effect)
  │     ├── Step 3.2 (task bar effect)
  │     └── Step 3.3 (history status effect)
  └── Phase 4 (Input Handler) ← depends on Phase 1.3
        ├── Step 4.1 (reader closures → signal reads)
        ├── Step 4.2 (writer closures → signal writes)
        └── Step 4.3 (constructor cleanup)
              └── Phase 5 (Cleanup) ← depends on all above
```

**Phases 1 and 2 can proceed in parallel** since they touch different files and different signals. Phase 3 must wait for both. Phase 4 only needs Phase 1.3. Phase 5 is final polish.

---

## Estimated Effort

| Phase | Steps | Risk | Effort |
|-------|-------|------|--------|
| Phase 0 | 2 | Low (new code only) | 2–3 days |
| Phase 1 | 3 | Low (field → signal, same behavior) | 1–2 days each |
| Phase 2 | 2 | Medium (animation timing sensitive) | 2–3 days |
| Phase 3 | 3 | Medium-High (effects can cause render loops) | 2–3 days each |
| Phase 4 | 3 | Medium (input handler is complex) | 2 days |
| Phase 5 | 3 | Low (cleanup) | 1 day |

**Total: ~15–20 working days**
