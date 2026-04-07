# Plan: TuiStateStore — Centralized Reactive State

> Part of `01-reactive-state/` — depends on `01-signal-computed.md` (Signal & Computed primitives).

## 1. Goal

Extract **all application-level UI state** from the five TUI manager classes into a single `TuiStateStore` that holds every value as a `Signal<T>`. Derived values become `Computed<T>`. Widget-local transient state (DOM-like ephemera) stays in place.

**Before:** State is scattered across private properties in 5 classes, accessed via getter closures passed through constructors.

**After:** Every piece of state lives in `TuiStateStore`. Classes read signals and call `$store->phase->set(...)`. No class owns private UI state.

---

## 2. State Inventory

### 2.1 TuiCoreRenderer — 20 properties

| # | Property | Type | Initial | Category | Notes |
|---|----------|------|---------|----------|-------|
| 1 | `$currentModeLabel` | `string` | `'Edit'` | Status bar | Displayed in status bar |
| 2 | `$currentModeColor` | `string` | `"\033[38;2;80;200;120m"` | Status bar | ANSI escape |
| 3 | `$statusDetail` | `string` | `'Ready'` | Status bar | Token/model info |
| 4 | `$currentPermissionLabel` | `string` | `'Guardian ◈'` | Status bar | Permission mode name |
| 5 | `$currentPermissionColor` | `string` | `"\033[38;2;180;180;200m"` | Status bar | ANSI escape |
| 6 | `$lastStatusTokensIn` | `?int` | `null` | Token tracking | |
| 7 | `$lastStatusTokensOut` | `?int` | `null` | Token tracking | |
| 8 | `$lastStatusCost` | `?float` | `null` | Token tracking | |
| 9 | `$lastStatusMaxContext` | `?int` | `null` | Token tracking | |
| 10 | `$activeResponse` | `MarkdownWidget\|AnsiArtWidget\|null` | `null` | Streaming | Current streaming response widget |
| 11 | `$activeResponseIsAnsi` | `bool` | `false` | Streaming | Whether active response is ANSI art |
| 12 | `$scrollOffset` | `int` | `0` | Scroll | Current scroll position |
| 13 | `$hasHiddenActivityBelow` | `bool` | `false` | Scroll | New activity below viewport |
| 14 | `$pendingEditorRestore` | `?string` | `null` | Prompt | Text to restore into editor |
| 15 | `$requestCancellation` | `?DeferredCancellation` | `null` | Cancellation | Active cancellation token |
| 16 | `$messageQueue` | `string[]` | `[]` | Queue | Queued user messages |
| 17 | `$immediateCommandHandler` | `?\Closure(string): bool` | `null` | Input handler | Current immediate command handler |
| 18 | `$promptSuspension` | `?Suspension` | `null` | Input handler | Active prompt suspension |
| 19 | `$pendingQuestionRecap` | `array` | `[]` | Questions | Pending Q&A recap items |
| 20 | `$taskStore` | `?TaskStore` | `null` | Tasks | Active task store |

**Widget references (NOT state — infrastructure):**
`$tui`, `$session`, `$conversation`, `$historyStatus`, `$statusBar`, `$overlay`, `$taskBar`, `$thinkingBar`, `$input` — these are widget tree nodes, not UI state.

**Sub-managers (NOT state — composition):**
`$subagentDisplay`, `$animationManager`, `$modalManager`, `$inputHandler` — injected dependencies.

**Callbacks (NOT state — behavior):**
`$discoveryBatchFinalizer`, `$toolStateResetCallback` — closure callbacks, not serializable state.

### 2.2 TuiAnimationManager — 12 properties

| # | Property | Type | Initial | Category | Notes |
|---|----------|------|---------|----------|-------|
| 1 | `$currentPhase` | `AgentPhase` | `AgentPhase::Idle` | Phase | Current agent phase |
| 2 | `$thinkingStartTime` | `float` | `0.0` | Animation | When thinking started |
| 3 | `$thinkingPhrase` | `?string` | `null` | Animation | Current thinking phrase |
| 4 | `$thinkingTimerId` | `?string` | `null` | Timer | EventLoop timer ID |
| 5 | `$breathTick` | `int` | `0` | Animation | Breathing animation counter |
| 6 | `$breathColor` | `?string` | `null` | Animation | Current breathing ANSI color |
| 7 | `$compactingStartTime` | `float` | `0.0` | Animation | When compacting started |
| 8 | `$compactingBreathTick` | `int` | `0` | Animation | Compacting breath counter |
| 9 | `$compactingTimerId` | `?string` | `null` | Timer | EventLoop timer ID |
| 10 | `$spinnerIndex` | `int` | `0` | Animation | Round-robin spinner selection |
| 11 | `$spinnersRegistered` | `bool` | `false` | Init | Whether spinners are registered |
| 12 | `$activeSpinnerFrames` | `string[]` | `[]` | Animation | Frames of current spinner |

**Widget references (NOT state):**
`$loader`, `$compactingLoader`, `$thinkingBar` — widget references.

**Constructor closures (NOT state — behavior):**
`$hasTasksProvider`, `$hasSubagentActivityProvider`, `$refreshTaskBarCallback`, `$subagentTickCallback`, `$subagentCleanupCallback`, `$renderCallback`, `$forceRenderCallback`.

### 2.3 TuiToolRenderer — 9 properties

| # | Property | Type | Initial | Category | Notes |
|---|----------|------|---------|----------|-------|
| 1 | `$lastToolArgs` | `array` | `[]` | Tool tracking | Args from last tool call |
| 2 | `$lastToolArgsByName` | `array` | `[]` | Tool tracking | Args keyed by tool name |
| 3 | `$activeBashWidget` | `?BashCommandWidget` | `null` | Widget ref | Current bash command widget |
| 4 | `$toolExecutingLoader` | `?CancellableLoaderWidget` | `null` | Widget ref | Active tool executing loader |
| 5 | `$toolExecutingTimerId` | `?string` | `null` | Timer | EventLoop timer ID |
| 6 | `$toolExecutingStartTime` | `float` | `0.0` | Animation | When tool execution started |
| 7 | `$toolExecutingBreathTick` | `int` | `0` | Animation | Tool exec breath counter |
| 8 | `$toolExecutingPreview` | `?string` | `null` | Streaming | Last preview line from tool output |
| 9 | `$activeDiscoveryBatch` | `?DiscoveryBatchWidget` | `null` | Widget ref | Active batch widget |
| 10 | `$activeDiscoveryItems` | `array` | `[]` | Batch state | Items in current discovery batch |

**Lazy-initialized (NOT state — infrastructure):**
`$diffRenderer`, `$highlighter`.

### 2.4 SubagentDisplayManager — 7 properties

| # | Property | Type | Initial | Category | Notes |
|---|----------|------|---------|----------|-------|
| 1 | `$container` | `?ContainerWidget` | `null` | Widget ref | Wrapper container |
| 2 | `$batchDisplayed` | `bool` | `false` | Display state | Whether batch results are shown |
| 3 | `$loader` | `?CancellableLoaderWidget` | `null` | Widget ref | Active subagent loader |
| 4 | `$treeWidget` | `?TextWidget` | `null` | Widget ref | Active tree widget |
| 5 | `$elapsedTimerId` | `?string` | `null` | Timer | EventLoop timer ID |
| 6 | `$startTime` | `float` | `0.0` | Animation | When subagents started |
| 7 | `$loaderBreathTick` | `int` | `0` | Animation | Loader breath counter |
| 8 | `$cachedLoaderLabel` | `string` | `'Agents running...'` | Display state | Cached label text |
| 9 | `$treeProvider` | `?\Closure` | `null` | Callback | Tree data provider |

### 2.5 TuiInputHandler — 2 properties

| # | Property | Type | Initial | Category | Notes |
|---|----------|------|---------|----------|-------|
| 1 | `$slashCompletion` | `?SelectListWidget` | `null` | Widget ref | Active completion dropdown |
| 2 | `$skillCompletions` | `array` | `[]` | Input state | Available skill completions |

### 2.6 TuiModalManager — 2 properties

| # | Property | Type | Initial | Category | Notes |
|---|----------|------|---------|----------|-------|
| 1 | `$askSuspension` | `?Suspension` | `null` | Modal state | Active ask suspension |
| 2 | `$activeModal` | `bool` | `false` | Modal state | Whether a modal is showing |

---

## 3. Classification: State vs Widget Ref vs Behavior

### 3.1 Moves to TuiStateStore (Signals) — 30 signals

These are **application-level state** that drives rendering decisions and is accessed across class boundaries:

```
From TuiCoreRenderer:
  modeLabel              Signal<string>          'Edit'
  modeColor              Signal<string>          "\033[38;2;80;200;120m"
  permissionLabel        Signal<string>          'Guardian ◈'
  permissionColor        Signal<string>          "\033[38;2;180;180;200m"
  statusDetail           Signal<string>          'Ready'
  tokensIn               Signal<?int>            null
  tokensOut              Signal<?int>            null
  cost                   Signal<?float>          null
  maxContext             Signal<?int>            null
  scrollOffset           Signal<int>             0
  hasHiddenActivityBelow Signal<bool>            false
  pendingEditorRestore   Signal<?string>         null
  messageQueue           Signal<list<string>>    []
  pendingQuestionRecap   Signal<array>           []
  activeResponse         Signal<?object>         null      (MarkdownWidget|AnsiArtWidget)
  activeResponseIsAnsi   Signal<bool>            false

From TuiAnimationManager:
  phase                  Signal<AgentPhase>      AgentPhase::Idle
  thinkingStartTime      Signal<float>           0.0
  thinkingPhrase         Signal<?string>         null
  breathTick             Signal<int>             0
  breathColor            Signal<?string>         null
  compactingStartTime    Signal<float>           0.0
  compactingBreathTick   Signal<int>             0
  spinnerIndex           Signal<int>             0
  spinnersRegistered     Signal<bool>            false

From TuiToolRenderer:
  lastToolArgs           Signal<array>           []
  lastToolArgsByName     Signal<array>           []
  toolExecutingStartTime Signal<float>           0.0
  toolExecutingBreathTick Signal<int>            0
  toolExecutingPreview   Signal<?string>         null
  activeDiscoveryItems   Signal<array>           []

From SubagentDisplayManager:
  batchDisplayed         Signal<bool>            false
  startTime              Signal<float>           0.0
  loaderBreathTick       Signal<int>             0
  cachedLoaderLabel      Signal<string>          'Agents running...'

From TuiInputHandler:
  skillCompletions       Signal<array>           []

From TuiModalManager:
  activeModal            Signal<bool>            false
```

### 3.2 Widget References — Stay Local

These are **DOM nodes** — they belong to the widget tree and are managed by their owning class. They are not reactive state; they are infrastructure.

| Class | Properties |
|-------|-----------|
| TuiCoreRenderer | `$tui`, `$session`, `$conversation`, `$historyStatus`, `$statusBar`, `$overlay`, `$taskBar`, `$thinkingBar`, `$input` |
| TuiAnimationManager | `$loader`, `$compactingLoader`, `$thinkingBar` |
| TuiToolRenderer | `$activeBashWidget`, `$toolExecutingLoader`, `$activeDiscoveryBatch`, `$diffRenderer`, `$highlighter` |
| SubagentDisplayManager | `$container`, `$loader`, `$treeWidget` |
| TuiInputHandler | `$slashCompletion` |

### 3.3 Timer IDs — Stay Local

EventLoop timer IDs are **ephemeral handles** to cancel timers. They don't drive rendering — they control side effects. They stay local to the timer-owning class:

| Class | Timer IDs |
|-------|-----------|
| TuiAnimationManager | `$thinkingTimerId`, `$compactingTimerId` |
| TuiToolRenderer | `$toolExecutingTimerId` |
| SubagentDisplayManager | `$elapsedTimerId` |

### 3.4 Closures / Callbacks / Suspensions — Stay Local

These are **behavior references**, not state:
- `$immediateCommandHandler` (TuiCoreRenderer) — callback, not state
- `$promptSuspension` (TuiCoreRenderer) — coroutine primitive
- `$requestCancellation` (TuiCoreRenderer) — coroutine primitive
- `$discoveryBatchFinalizer` (TuiCoreRenderer) — callback
- `$toolStateResetCallback` (TuiCoreRenderer) — callback
- `$taskStore` (TuiCoreRenderer) — data store reference (not UI state)
- `$treeProvider` (SubagentDisplayManager) — callback
- `$askSuspension` (TuiModalManager) — coroutine primitive
- Constructor closures in TuiAnimationManager and TuiInputHandler — behavior injection

### 3.5 Spinner Frames Array — Stay Local

`$activeSpinnerFrames` is a local cache of the selected spinner definition. It's only read within `TuiAnimationManager` and doesn't drive cross-class behavior.

---

## 4. Computed Values

These derive from signals and automatically update when dependencies change:

| Name | Type | Dependencies | Derivation |
|------|------|-------------|------------|
| `isBrowsingHistory` | `bool` | `scrollOffset` | `$scrollOffset > 0` |
| `hasStreamingResponse` | `bool` | `activeResponse` | `$activeResponse !== null` |
| `contextRatio` | `float` | `tokensIn`, `maxContext` | `min(1.0, ($tokensIn ?? 0) / max(1, $maxContext ?? 1))` |
| `thinkingElapsed` | `int` | `thinkingStartTime` | `(int)(microtime(true) - $thinkingStartTime)` |
| `compactingElapsed` | `int` | `compactingStartTime` | `(int)(microtime(true) - $compactingStartTime)` |
| `statusBarMessage` | `string` | `modeLabel`, `modeColor`, `permissionLabel`, `permissionColor`, `statusDetail` | Format string with Theme helpers |
| `hasTasks` | `bool` | (reads TaskStore) | `$taskStore !== null && !$taskStore->isEmpty()` |

> Note: `hasTasks` reads from `TaskStore` which isn't a signal. It will be a computed that reads the taskStore reference. Alternatively, `TaskStore` could emit signals for `isEmpty`.

---

## 5. Migration Map

### TuiCoreRenderer

| Private Property | Signal Name | Signal Type |
|-----------------|-------------|-------------|
| `$currentModeLabel` | `modeLabel` | `Signal<string>` |
| `$currentModeColor` | `modeColor` | `Signal<string>` |
| `$statusDetail` | `statusDetail` | `Signal<string>` |
| `$currentPermissionLabel` | `permissionLabel` | `Signal<string>` |
| `$currentPermissionColor` | `permissionColor` | `Signal<string>` |
| `$lastStatusTokensIn` | `tokensIn` | `Signal<?int>` |
| `$lastStatusTokensOut` | `tokensOut` | `Signal<?int>` |
| `$lastStatusCost` | `cost` | `Signal<?float>` |
| `$lastStatusMaxContext` | `maxContext` | `Signal<?int>` |
| `$activeResponse` | `activeResponse` | `Signal<?object>` |
| `$activeResponseIsAnsi` | `activeResponseIsAnsi` | `Signal<bool>` |
| `$scrollOffset` | `scrollOffset` | `Signal<int>` |
| `$hasHiddenActivityBelow` | `hasHiddenActivityBelow` | `Signal<bool>` |
| `$pendingEditorRestore` | `pendingEditorRestore` | `Signal<?string>` |
| `$messageQueue` | `messageQueue` | `Signal<list<string>>` |
| `$pendingQuestionRecap` | `pendingQuestionRecap` | `Signal<array>` |

### TuiAnimationManager

| Private Property | Signal Name | Signal Type |
|-----------------|-------------|-------------|
| `$currentPhase` | `phase` | `Signal<AgentPhase>` |
| `$thinkingStartTime` | `thinkingStartTime` | `Signal<float>` |
| `$thinkingPhrase` | `thinkingPhrase` | `Signal<?string>` |
| `$breathTick` | `breathTick` | `Signal<int>` |
| `$breathColor` | `breathColor` | `Signal<?string>` |
| `$compactingStartTime` | `compactingStartTime` | `Signal<float>` |
| `$compactingBreathTick` | `compactingBreathTick` | `Signal<int>` |
| `$spinnerIndex` | `spinnerIndex` | `Signal<int>` |
| `$spinnersRegistered` | `spinnersRegistered` | `Signal<bool>` |

### TuiToolRenderer

| Private Property | Signal Name | Signal Type |
|-----------------|-------------|-------------|
| `$lastToolArgs` | `lastToolArgs` | `Signal<array>` |
| `$lastToolArgsByName` | `lastToolArgsByName` | `Signal<array>` |
| `$toolExecutingStartTime` | `toolExecutingStartTime` | `Signal<float>` |
| `$toolExecutingBreathTick` | `toolExecutingBreathTick` | `Signal<int>` |
| `$toolExecutingPreview` | `toolExecutingPreview` | `Signal<?string>` |
| `$activeDiscoveryItems` | `activeDiscoveryItems` | `Signal<array>` |

### SubagentDisplayManager

| Private Property | Signal Name | Signal Type |
|-----------------|-------------|-------------|
| `$batchDisplayed` | `batchDisplayed` | `Signal<bool>` |
| `$startTime` | `subagentStartTime` | `Signal<float>` |
| `$loaderBreathTick` | `subagentBreathTick` | `Signal<int>` |
| `$cachedLoaderLabel` | `subagentLoaderLabel` | `Signal<string>` |

### TuiInputHandler

| Private Property | Signal Name | Signal Type |
|-----------------|-------------|-------------|
| `$skillCompletions` | `skillCompletions` | `Signal<array>` |

### TuiModalManager

| Private Property | Signal Name | Signal Type |
|-----------------|-------------|-------------|
| `$activeModal` | `activeModal` | `Signal<bool>` |

---

## 6. PHP Class Sketch

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

use Kosmokrator\Agent\AgentPhase;

/**
 * Centralized reactive state store for the entire TUI layer.
 *
 * Every piece of application-level UI state lives here as a Signal<T>.
 * Derived values are exposed as Computed<T>. Widget references, timer IDs,
 * coroutine primitives, and closures remain local to their owning classes.
 *
 * Usage:
 *   $store->phase->set(AgentPhase::Thinking);
 *   $store->modeLabel->set('Edit');
 *   if ($store->phase->get() === AgentPhase::Idle) { ... }
 */
final class TuiStateStore
{
    // ── Phase & Animation ───────────────────────────────────────────────

    /** Current agent lifecycle phase */
    public readonly Signal $phase; // Signal<AgentPhase>

    /** Monotonic time when thinking started (microtime(true)) */
    public readonly Signal $thinkingStartTime; // Signal<float>

    /** Randomly selected thinking phrase for the loader */
    public readonly Signal $thinkingPhrase; // Signal<?string>

    /** Breathing animation frame counter */
    public readonly Signal $breathTick; // Signal<int>

    /** Current breathing animation ANSI color string */
    public readonly Signal $breathColor; // Signal<?string>

    /** Monotonic time when compacting started */
    public readonly Signal $compactingStartTime; // Signal<float>

    /** Compacting breathing frame counter */
    public readonly Signal $compactingBreathTick; // Signal<int>

    /** Round-robin index for spinner selection */
    public readonly Signal $spinnerIndex; // Signal<int>

    /** Whether custom spinners have been registered with CancellableLoaderWidget */
    public readonly Signal $spinnersRegistered; // Signal<bool>

    // ── Status Bar ──────────────────────────────────────────────────────

    /** Current agent mode label (Edit, Plan, Ask) */
    public readonly Signal $modeLabel; // Signal<string>

    /** ANSI color escape for the mode label */
    public readonly Signal $modeColor; // Signal<string>

    /** Permission mode label (Guardian ◈, Argus ◈, Prometheus ◈) */
    public readonly Signal $permissionLabel; // Signal<string>

    /** ANSI color escape for the permission label */
    public readonly Signal $permissionColor; // Signal<string>

    /** Status detail string (token counts, model name) */
    public readonly Signal $statusDetail; // Signal<string>

    // ── Token Tracking ──────────────────────────────────────────────────

    /** Last reported input token count */
    public readonly Signal $tokensIn; // Signal<?int>

    /** Last reported output token count */
    public readonly Signal $tokensOut; // Signal<?int>

    /** Last reported cost */
    public readonly Signal $cost; // Signal<?float>

    /** Last reported max context window size */
    public readonly Signal $maxContext; // Signal<?int>

    // ── Streaming Response ──────────────────────────────────────────────

    /** Currently active streaming response widget (MarkdownWidget|AnsiArtWidget|null) */
    public readonly Signal $activeResponse; // Signal<?object>

    /** Whether the active response widget is ANSI art */
    public readonly Signal $activeResponseIsAnsi; // Signal<bool>

    // ── Scroll ──────────────────────────────────────────────────────────

    /** Current scroll offset (0 = live/bottom) */
    public readonly Signal $scrollOffset; // Signal<int>

    /** Whether new activity has arrived below the current scroll position */
    public readonly Signal $hasHiddenActivityBelow; // Signal<bool>

    // ── Prompt / Input ──────────────────────────────────────────────────

    /** Text to restore into the editor on next prompt() call */
    public readonly Signal $pendingEditorRestore; // Signal<?string>

    /** Queued user messages to inject into the conversation */
    public readonly Signal $messageQueue; // Signal<list<string>>

    /** Available skill completions for $-prefix commands */
    public readonly Signal $skillCompletions; // Signal<array>

    // ── Questions ───────────────────────────────────────────────────────

    /** Pending Q&A recap items awaiting flush */
    public readonly Signal $pendingQuestionRecap; // Signal<array>

    // ── Tool State ──────────────────────────────────────────────────────

    /** Arguments from the most recent tool call */
    public readonly Signal $lastToolArgs; // Signal<array>

    /** Arguments keyed by tool name for cross-referencing in showToolResult */
    public readonly Signal $lastToolArgsByName; // Signal<array>

    /** Monotonic time when tool execution spinner started */
    public readonly Signal $toolExecutingStartTime; // Signal<float>

    /** Tool execution breathing frame counter */
    public readonly Signal $toolExecutingBreathTick; // Signal<int>

    /** Last preview line from streaming tool output */
    public readonly Signal $toolExecutingPreview; // Signal<?string>

    /** Items in the current discovery batch */
    public readonly Signal $activeDiscoveryItems; // Signal<array>

    // ── Subagent Display ────────────────────────────────────────────────

    /** Whether batch results have been shown (prevents timer from recreating tree) */
    public readonly Signal $batchDisplayed; // Signal<bool>

    /** Monotonic time when subagent display started */
    public readonly Signal $subagentStartTime; // Signal<float>

    /** Subagent loader breathing frame counter */
    public readonly Signal $subagentBreathTick; // Signal<int>

    /** Cached label text for the subagent loader */
    public readonly Signal $subagentLoaderLabel; // Signal<string>

    // ── Modal ───────────────────────────────────────────────────────────

    /** Whether a modal dialog is currently active */
    public readonly Signal $activeModal; // Signal<bool>

    // ── Computed Values ─────────────────────────────────────────────────

    /** Whether the user is currently browsing scrollback history */
    public readonly Computed $isBrowsingHistory; // Computed<bool>

    /** Whether a streaming response is in progress */
    public readonly Computed $hasStreamingResponse; // Computed<bool>

    /** Token usage as a ratio 0.0–1.0 */
    public readonly Computed $contextRatio; // Computed<float>

    /** Seconds elapsed since thinking started */
    public readonly Computed $thinkingElapsed; // Computed<int>

    /** Seconds elapsed since compacting started */
    public readonly Computed $compactingElapsed; // Computed<int>

    /** Formatted status bar message string */
    public readonly Computed $statusBarMessage; // Computed<string>

    public function __construct()
    {
        // Phase & Animation
        $this->phase = Signal::of(AgentPhase::Idle);
        $this->thinkingStartTime = Signal::of(0.0);
        $this->thinkingPhrase = Signal::of(null);
        $this->breathTick = Signal::of(0);
        $this->breathColor = Signal::of(null);
        $this->compactingStartTime = Signal::of(0.0);
        $this->compactingBreathTick = Signal::of(0);
        $this->spinnerIndex = Signal::of(0);
        $this->spinnersRegistered = Signal::of(false);

        // Status Bar
        $this->modeLabel = Signal::of('Edit');
        $this->modeColor = Signal::of("\033[38;2;80;200;120m");
        $this->permissionLabel = Signal::of('Guardian ◈');
        $this->permissionColor = Signal::of("\033[38;2;180;180;200m");
        $this->statusDetail = Signal::of('Ready');

        // Token Tracking
        $this->tokensIn = Signal::of(null);
        $this->tokensOut = Signal::of(null);
        $this->cost = Signal::of(null);
        $this->maxContext = Signal::of(null);

        // Streaming Response
        $this->activeResponse = Signal::of(null);
        $this->activeResponseIsAnsi = Signal::of(false);

        // Scroll
        $this->scrollOffset = Signal::of(0);
        $this->hasHiddenActivityBelow = Signal::of(false);

        // Prompt / Input
        $this->pendingEditorRestore = Signal::of(null);
        $this->messageQueue = Signal::of([]);
        $this->skillCompletions = Signal::of([]);

        // Questions
        $this->pendingQuestionRecap = Signal::of([]);

        // Tool State
        $this->lastToolArgs = Signal::of([]);
        $this->lastToolArgsByName = Signal::of([]);
        $this->toolExecutingStartTime = Signal::of(0.0);
        $this->toolExecutingBreathTick = Signal::of(0);
        $this->toolExecutingPreview = Signal::of(null);
        $this->activeDiscoveryItems = Signal::of([]);

        // Subagent Display
        $this->batchDisplayed = Signal::of(false);
        $this->subagentStartTime = Signal::of(0.0);
        $this->subagentBreathTick = Signal::of(0);
        $this->subagentLoaderLabel = Signal::of('Agents running...');

        // Modal
        $this->activeModal = Signal::of(false);

        // ── Computed ────────────────────────────────────────────────────
        $this->isBrowsingHistory = Computed::of(
            fn () => $this->scrollOffset->get() > 0,
            [$this->scrollOffset],
        );

        $this->hasStreamingResponse = Computed::of(
            fn () => $this->activeResponse->get() !== null,
            [$this->activeResponse],
        );

        $this->contextRatio = Computed::of(
            fn () => min(1.0, ($this->tokensIn->get() ?? 0) / max(1, $this->maxContext->get() ?? 1)),
            [$this->tokensIn, $this->maxContext],
        );

        $this->thinkingElapsed = Computed::of(
            fn () => (int) (microtime(true) - $this->thinkingStartTime->get()),
            [$this->thinkingStartTime],
        );

        $this->compactingElapsed = Computed::of(
            fn () => (int) (microtime(true) - $this->compactingStartTime->get()),
            [$this->compactingStartTime],
        );

        $this->statusBarMessage = Computed::of(
            function () {
                $r = Theme::reset();
                $sep = Theme::dim() . "·{$r}";
                $mode = $this->modeColor->get() . $this->modeLabel->get() . $r;
                $perm = $this->permissionColor->get() . $this->permissionLabel->get() . $r;
                return "{$mode} {$sep} {$perm} {$sep} " . $this->statusDetail->get();
            },
            [$this->modeLabel, $this->modeColor, $this->permissionLabel, $this->permissionColor, $this->statusDetail],
        );
    }

    /**
     * Reset all state to initial values.
     *
     * Called on session reset (/new, /clear). Widget references, timer IDs,
     * and coroutine primitives are NOT reset here — those are cleaned up by
     * their owning classes.
     */
    public function reset(): void
    {
        // Phase & Animation
        $this->phase->set(AgentPhase::Idle);
        $this->thinkingStartTime->set(0.0);
        $this->thinkingPhrase->set(null);
        $this->breathTick->set(0);
        $this->breathColor->set(null);
        $this->compactingStartTime->set(0.0);
        $this->compactingBreathTick->set(0);
        // spinnerIndex and spinnersRegistered intentionally NOT reset

        // Status Bar
        $this->modeLabel->set('Edit');
        $this->modeColor->set("\033[38;2;80;200;120m");
        $this->permissionLabel->set('Guardian ◈');
        $this->permissionColor->set("\033[38;2;180;180;200m");
        $this->statusDetail->set('Ready');

        // Token Tracking — keep (model context persists across /clear)
        // $this->tokensIn->set(null);
        // $this->tokensOut->set(null);
        // $this->cost->set(null);
        // $this->maxContext->set(null);

        // Streaming Response
        $this->activeResponse->set(null);
        $this->activeResponseIsAnsi->set(false);

        // Scroll
        $this->scrollOffset->set(0);
        $this->hasHiddenActivityBelow->set(false);

        // Prompt / Input
        $this->pendingEditorRestore->set(null);
        $this->messageQueue->set([]);
        $this->skillCompletions->set([]);

        // Questions
        $this->pendingQuestionRecap->set([]);

        // Tool State
        $this->lastToolArgs->set([]);
        $this->lastToolArgsByName->set([]);
        $this->toolExecutingStartTime->set(0.0);
        $this->toolExecutingBreathTick->set(0);
        $this->toolExecutingPreview->set(null);
        $this->activeDiscoveryItems->set([]);

        // Subagent Display
        $this->batchDisplayed->set(false);
        $this->subagentStartTime->set(0.0);
        $this->subagentBreathTick->set(0);
        $this->subagentLoaderLabel->set('Agents running...');

        // Modal
        $this->activeModal->set(false);
    }

    /**
     * Dequeue and return the next message from the queue, or null.
     *
     * Demonstrates how mutation methods encapsulate signal operations.
     */
    public function dequeueMessage(): ?string
    {
        $queue = $this->messageQueue->get();
        if ($queue === []) {
            return null;
        }
        $message = array_shift($queue);
        $this->messageQueue->set($queue);
        return $message;
    }

    /**
     * Enqueue a message.
     */
    public function enqueueMessage(string $message): void
    {
        $queue = $this->messageQueue->get();
        $queue[] = $message;
        $this->messageQueue->set($queue);
    }

    /**
     * Record token stats and derive the status detail string.
     *
     * Encapsulates the token-tracking + status-detail derivation that was
     * previously inlined in TuiCoreRenderer::showStatus().
     */
    public function updateTokenStats(int $tokensIn, int $tokensOut, float $cost, int $maxContext, string $model): void
    {
        $this->tokensIn->set($tokensIn);
        $this->tokensOut->set($tokensOut);
        $this->cost->set($cost);
        $this->maxContext->set($maxContext);

        $r = Theme::reset();
        $sep = Theme::dim() . "·{$r}";
        $dimWhite = Theme::dimWhite();
        $ratio = $this->contextRatio->get();
        $ctxColor = Theme::contextColor($ratio);

        $inLabel = Theme::formatTokenCount($tokensIn);
        $maxLabel = Theme::formatTokenCount($maxContext);

        $this->statusDetail->set("{$ctxColor}{$inLabel}/{$maxLabel}{$r} {$sep} {$dimWhite}{$model}{$r}");
    }
}
```

---

## 7. How Manager Classes Change

### 7.1 TuiCoreRenderer

```php
// Before
private string $currentModeLabel = 'Edit';

public function showMode(string $label, string $color = ''): void
{
    $this->currentModeLabel = $label;
    if ($color !== '') {
        $this->currentModeColor = $color;
    }
    $this->refreshStatusBar();
    $this->flushRender();
}

// After
public function __construct(private readonly TuiStateStore $state) {}

public function showMode(string $label, string $color = ''): void
{
    $this->state->modeLabel->set($label);
    if ($color !== '') {
        $this->state->modeColor->set($color);
    }
    $this->refreshStatusBar();
    $this->flushRender();
}
```

No more private UI state properties. All reads become `$this->state->modeLabel->get()`.

### 7.2 TuiAnimationManager

```php
// Before
private AgentPhase $currentPhase = AgentPhase::Idle;

public function setPhase(AgentPhase $phase, ?DeferredCancellation $cancellation = null): void
{
    if ($phase === $this->currentPhase) { return; }
    $previous = $this->currentPhase;
    $this->currentPhase = $phase;
    // ...
}

// After
public function __construct(
    private readonly TuiStateStore $state,
    // ... widget refs and callbacks stay
) {}

public function setPhase(AgentPhase $phase, ?DeferredCancellation $cancellation = null): void
{
    if ($phase === $this->state->phase->get()) { return; }
    $previous = $this->state->phase->get();
    $this->state->phase->set($phase);
    // ...
}
```

### 7.3 TuiToolRenderer

```php
// Before
private array $lastToolArgs = [];

// After — reads and writes through store
$this->state->lastToolArgs->set($args);
$args = $this->state->lastToolArgs->get();
```

### 7.4 TuiInputHandler

```php
// Before — receives 20 closures to access shared state

// After — receives TuiStateStore directly
public function __construct(
    private readonly TuiStateStore $state,
    private readonly EditorWidget $input,
    private readonly ContainerWidget $conversation,
    private readonly ContainerWidget $overlay,
    private readonly TuiModalManager $modalManager,
    private readonly \Closure $flushRender,
    private readonly \Closure $forceRender,
    // scroll, mode, prompt, cancellation delegates stay as closures
    // because they involve widget operations (scrolling, focus)
    // OR they read from state:
    //   $this->state->isBrowsingHistory->get() replaces ($this->isBrowsingHistory)()
    //   $this->state->modeLabel->get() replaces cycleMode → showMode indirect
) {}
```

---

## 8. Effect System Integration (Preview)

With signals in place, the `TuiEffectRunner` (planned in `03-tui-effect-runner.md`) can register effects:

```php
// Auto-refresh status bar when any status-related signal changes
$effects->effect(
    fn () => $store->statusBarMessage->get(),
    function (string $message) use ($statusBar) {
        $statusBar->setMessage($message);
    },
    [$store->statusBarMessage],
);

// Auto-update history indicator when scroll state changes
$effects->effect(
    fn () => [$store->scrollOffset->get(), $store->hasHiddenActivityBelow->get()],
    function (array $v) use ($historyStatus) {
        if ($v[0] > 0) {
            $historyStatus->show($v[1]);
        } else {
            $historyStatus->hide();
        }
    },
    [$store->scrollOffset, $store->hasHiddenActivityBelow],
);
```

---

## 9. What Stays Local

### Widget-Local Transient State (NEVER goes into TuiStateStore)

These are rendering ephemera — they exist only within the paint cycle:

| Class | Properties | Why Local |
|-------|-----------|-----------|
| TuiAnimationManager | `$loader`, `$compactingLoader` | Widget lifecycle — add/remove from tree |
| TuiAnimationManager | `$thinkingTimerId`, `$compactingTimerId` | EventLoop handle — cancel on phase exit |
| TuiToolRenderer | `$toolExecutingLoader`, `$toolExecutingTimerId` | Widget lifecycle + timer handle |
| TuiToolRenderer | `$activeBashWidget`, `$activeDiscoveryBatch` | Widget lifecycle |
| TuiToolRenderer | `$diffRenderer`, `$highlighter` | Lazy-initialized services |
| SubagentDisplayManager | `$container`, `$loader`, `$treeWidget` | Widget lifecycle |
| SubagentDisplayManager | `$elapsedTimerId` | EventLoop handle |
| TuiInputHandler | `$slashCompletion` | Widget lifecycle for dropdown |

### Coroutine Primitives (NEVER go into TuiStateStore)

These are Amp/Revolt concurrency primitives, not serializable state:

| Property | Class | Type |
|----------|-------|------|
| `$requestCancellation` | TuiCoreRenderer | `?DeferredCancellation` |
| `$promptSuspension` | TuiCoreRenderer | `?Suspension` |
| `$askSuspension` | TuiModalManager | `?Suspension` |
| `$immediateCommandHandler` | TuiCoreRenderer | `?\Closure` |

### Callbacks (NEVER go into TuiStateStore)

| Property | Class |
|----------|-------|
| `$discoveryBatchFinalizer` | TuiCoreRenderer |
| `$toolStateResetCallback` | TuiCoreRenderer |
| `$treeProvider` | SubagentDisplayManager |
| Constructor closures | TuiAnimationManager (7) |
| Constructor closures | TuiInputHandler (20 → reduced to ~12 after migration) |

---

## 10. Migration Steps

### Phase 1: Introduce TuiStateStore (non-breaking)

1. Create `src/UI/Tui/State/TuiStateStore.php` with all signals and computed values.
2. Create `src/UI/Tui/State/Signal.php` and `src/UI/Tui/State/Computed.php` (from plan `01-signal-computed.md`).
3. Instantiate `TuiStateStore` in `TuiCoreRenderer::initialize()`.
4. Pass store to all managers — but **don't remove private properties yet**.
5. Make managers write to both the private property AND the signal (dual-write).
6. Verify nothing breaks.

### Phase 2: Migrate readers (one class at a time)

1. **TuiAnimationManager** — replace `$this->currentPhase` reads with `$this->state->phase->get()`. Remove `$currentPhase`.
2. **TuiCoreRenderer** — replace status bar property reads with store reads. Remove the 16 private properties.
3. **TuiToolRenderer** — replace `$lastToolArgs` etc. Remove 6 private properties.
4. **SubagentDisplayManager** — replace `$batchDisplayed` etc. Remove 4 private properties.
5. **TuiInputHandler** — remove closures that just read state (e.g., `isBrowsingHistory`), replace with `$this->state->isBrowsingHistory->get()`. Keep closures that involve widget operations.
6. **TuiModalManager** — replace `$activeModal` with `$this->state->activeModal->get()`.

### Phase 3: Remove dual-write

After all readers are migrated, remove the private properties. Each class now reads/writes exclusively through `TuiStateStore`.

### Phase 4: Add effects

Wire up `TuiEffectRunner` to react to signal changes and update widgets automatically. Remove manual `refreshStatusBar()` / `flushRender()` calls where effects replace them.

---

## 11. Dependency Graph

```
TuiStateStore
  ├── TuiCoreRenderer (reads + writes all status/mode/scroll/streaming signals)
  ├── TuiAnimationManager (reads + writes phase/breath/thinking signals)
  ├── TuiToolRenderer (reads + writes tool/discovery signals)
  ├── SubagentDisplayManager (reads + writes subagent display signals)
  ├── TuiInputHandler (reads scroll/mode/completions signals)
  ├── TuiModalManager (reads + writes activeModal signal)
  └── TuiEffectRunner (reads signals → triggers widget updates)
```

No circular dependencies: `TuiStateStore` has zero knowledge of its consumers. All manager classes depend on `TuiStateStore`, not on each other's state.

---

## 12. Testing Strategy

1. **Unit test TuiStateStore** — verify all signal initial values, computed derivations, reset() behavior.
2. **Snapshot test computed values** — `statusBarMessage` at various mode/permission combinations.
3. **Integration test** — set signals, verify that manager classes see the updated values through `->get()`.
4. **No change to existing tests** during Phase 1 (dual-write ensures backward compat).
