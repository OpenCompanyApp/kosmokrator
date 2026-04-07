# Signal Primitives — Reactive State Foundation

> **Module**: `src/UI/Tui/Reactive\`
> **Dependencies**: None (pure PHP, no event loop dependency at this layer)
> **Blocks**: Every subsequent TUI overhaul plan depends on this.

## 1. Background: How Signals Work

### Vue 3 Refs / Computed
- `ref(value)` wraps a value. Reading inside a reactive context auto-tracks the dependency.
- `computed(fn)` lazily evaluates, caches the result, re-evaluates only when tracked refs change.
- `watchEffect(fn)` runs `fn` immediately, re-runs on dependency change.
- **Batching**: Vue queues watcher callbacks into a microtask flush; multiple sync mutations trigger one update cycle.

### SolidJS Signals
- `createSignal(value)` returns `[getter, setter]`. The getter tracks the reactive scope it runs inside.
- `createMemo(fn)` is a derived signal — lazy, cached, re-evaluates when dependencies change.
- `createEffect(fn)` runs `fn` and re-runs whenever its dependencies change.
- **Batching**: `batch(fn)` runs `fn` and defers all subscriber notifications until it completes.

### Preact Signals
- `signal(value)` creates a `.value` property. Reading `.value` inside a tracked scope auto-subscribes.
- `computed(fn)` lazily derives from other signals.
- `effect(fn)` auto-tracks and re-runs.
- **Batching**: Mutations inside `batch(fn)` defer all effects until the batch completes.

### Key Insights for PHP
1. **No JS microtask queue** — PHP is synchronous. We must explicitly schedule deferred work via `EventLoop::defer()` or a manual `BatchScope`.
2. **No Proxy/getter magic** — PHP cannot intercept property reads. Dependency tracking requires an explicit tracking context (a static "current effect" pointer).
3. **Generics via PHPDoc** — `@template T` for IDE support; runtime is untyped.

## 2. Architecture

```
Signal<T>          Computed<T>           Effect             BatchScope
┌──────────────┐   ┌──────────────┐    ┌──────────────┐   ┌──────────────┐
│ value: T     │   │ fn: callable │    │ fn: callable │   │ depth: int   │
│ version: int │◄──│ version: int │◄───│ deps: []     │   │ pending: []  │
│ subs: []     │──►│ value: T     │    │ cleanups: [] │   │ flush()      │
└──────────────┘   │ dirty: bool  │    └──────────────┘   └──────────────┘
                   └──────────────┘
                          ▲
                          │ auto-tracked
                   ┌──────┴──────┐
                   │ EffectScope │  (static tracking context)
                   └─────────────┘
```

All dependency tracking flows through a static `EffectScope` that holds the currently-executing effect/computed. When a `Signal::get()` is called inside an active scope, the signal auto-subscribes the scope as a dependency.

## 3. Class Designs

### 3.1 `Signal<T>`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Reactive;

/**
 * Reactive value holder with version counter and subscriber list.
 *
 * @template T
 */
final class Signal
{
    /** @var T */
    private mixed $value;

    private int $version = 0;

    /** @var list<Subscriber> */
    private array $subscribers = [];

    /**
     * @param T $value
     */
    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    /**
     * Read the current value. If called inside an active Effect or Computed,
     * auto-tracks this signal as a dependency.
     *
     * @return T
     */
    public function get(): mixed
    {
        $scope = EffectScope::current();
        if ($scope !== null) {
            $scope->track($this);
        }
        return $this->value;
    }

    /**
     * Write a new value. Increments version and notifies subscribers.
     * If a BatchScope is active, notifications are deferred.
     *
     * @param T $value
     */
    public function set(mixed $value): void
    {
        if ($this->value === $value) {
            return; // No-op for identical values (identity check)
        }
        $this->value = $value;
        $this->version++;
        $this->notify();
    }

    /**
     * Update the value using a transformer callback. Reads the current value,
     * passes it to $callback, and sets the result.
     *
     * @param callable(T): T $callback
     */
    public function update(callable $callback): void
    {
        $this->set($callback($this->value));
    }

    /**
     * Subscribe to value changes. Returns an unsubscribe callable.
     *
     * @param callable(T, T): void $callback Receives (newValue, oldValue)
     * @return callable(): void  Unsubscribe function
     */
    public function subscribe(callable $callback): callable
    {
        $sub = new Subscriber($callback);
        $this->subscribers[] = $sub;

        return function () use ($sub): void {
            $this->subscribers = array_values(array_filter(
                $this->subscribers,
                static fn(Subscriber $s): bool => $s !== $sub,
            ));
        };
    }

    /**
     * Get the current version counter. Useful for cache invalidation checks.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Get the raw value without dependency tracking.
     * Use sparingly — only when tracking is explicitly unwanted.
     *
     * @return T
     */
    public function value(): mixed
    {
        return $this->value;
    }

    private function notify(): void
    {
        $batch = BatchScope::current();
        if ($batch !== null) {
            $batch->enqueue($this);
            return;
        }

        foreach ($this->subscribers as $sub) {
            $sub->fire($this->value);
        }
    }
}
```

### 3.2 `Computed<T>`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Reactive;

/**
 * Derived reactive value. Lazily evaluated and cached.
 * Auto-re-evaluates when any dependency (Signal or Computed) changes.
 *
 * @template T
 */
final class Computed
{
    /** @var callable(): T */
    private readonly mixed $fn;

    /** @var T */
    private mixed $value;

    private int $version = 0;

    private bool $dirty = true;

    private bool $initialized = false;

    /** @var list<Signal|Computed> */
    private array $dependencies = [];

    /** @var list<Subscriber> */
    private array $subscribers = [];

    /**
     * @param callable(): T $fn  Pure derivation function
     */
    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    /**
     * Read the computed value. Evaluates lazily on first access or when dirty.
     * Auto-tracks into the current EffectScope (so Computed<Computed<...>> chains work).
     *
     * @return T
     */
    public function get(): mixed
    {
        if ($this->dirty || !$this->initialized) {
            $this->recompute();
        }

        // Track into parent scope (enables Computed chains)
        $scope = EffectScope::current();
        if ($scope !== null) {
            $scope->track($this);
        }

        return $this->value;
    }

    /**
     * Get the current version counter.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Mark this computed as needing re-evaluation.
     * Called by dependency change notifications.
     */
    public function markDirty(): void
    {
        if ($this->dirty) {
            return; // Already dirty — no need to cascade again
        }
        $this->dirty = true;
        $this->version++;

        // Cascade to downstream dependents
        foreach ($this->subscribers as $sub) {
            if ($sub->dependent instanceof Computed) {
                $sub->dependent->markDirty();
            }
        }
    }

    /**
     * Subscribe to computed value changes.
     *
     * @param callable(T): void $callback
     * @return callable(): void
     */
    public function subscribe(callable $callback): callable
    {
        $sub = new Subscriber($callback);
        $this->subscribers[] = $sub;

        return function () use ($sub): void {
            $this->subscribers = array_values(array_filter(
                $this->subscribers,
                static fn(Subscriber $s): bool => $s !== $sub,
            ));
        };
    }

    /**
     * Force immediate re-evaluation. Useful for testing.
     *
     * @return T
     */
    public function recompute(): mixed
    {
        // Clean up old dependency subscriptions
        $this->cleanupDependencies();

        // Run the derivation inside a tracking scope
        $scope = new EffectScope([$this, 'onTracked']);
        $this->value = $scope->run($this->fn);
        $this->dirty = false;
        $this->initialized = true;

        return $this->value;
    }

    /**
     * Called by EffectScope when a dependency is tracked during computation.
     */
    private function onTracked(Signal|Computed $dep): void
    {
        $this->dependencies[] = $dep;
        // Subscribe to the dependency so we get marked dirty on change
        $dep->subscribeComputed($this);
    }

    private function cleanupDependencies(): void
    {
        foreach ($this->dependencies as $dep) {
            $dep->unsubscribeComputed($this);
        }
        $this->dependencies = [];
    }
}
```

**Note**: `Signal` and `Computed` both need `subscribeComputed()` / `unsubscribeComputed()` methods that accept a `Computed` and call `$computed->markDirty()` on change. These are internal methods, separate from the public `subscribe()` API.

Updated `Signal` additions:

```php
/**
 * Internal: subscribe a Computed as a downstream dependent.
 */
public function subscribeComputed(Computed $computed): void
{
    $this->subscribers[] = new Subscriber(
        callback: static fn() => $computed->markDirty(),
        dependent: $computed,
    );
}

/**
 * Internal: unsubscribe a Computed downstream dependent.
 */
public function unsubscribeComputed(Computed $computed): void
{
    $this->subscribers = array_values(array_filter(
        $this->subscribers,
        static fn(Subscriber $s): bool => $s->dependent !== $computed,
    ));
}
```

### 3.3 `Effect`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Reactive;

/**
 * Side-effect that auto-runs when its tracked dependencies change.
 *
 * Used for wiring signals → widget updates. The callback receives an
 * onCleanup function for registering cleanup logic that runs before
 * the next effect execution.
 */
final class Effect
{
    /** @var callable(callable(): void $onCleanup): void */
    private readonly mixed $fn;

    /** @var list<Signal|Computed> */
    private array $dependencies = [];

    /** @var list<callable(): void> */
    private array $cleanups = [];

    private bool $disposed = false;

    /**
     * @param callable(callable(): void $onCleanup): void $fn
     */
    public function __construct(callable $fn)
    {
        $this->fn = $fn;
        $this->execute();
    }

    /**
     * Manually trigger a re-execution. Normally called automatically.
     */
    public function run(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->execute();
    }

    /**
     * Dispose of the effect. Cleans up dependencies and runs final cleanups.
     */
    public function dispose(): void
    {
        $this->disposed = true;
        $this->runCleanups();
        $this->cleanupDependencies();
    }

    /**
     * Called by EffectScope when a dependency is tracked during execution.
     */
    public function onTracked(Signal|Computed $dep): void
    {
        $this->dependencies[] = $dep;
        $dep->subscribeEffect($this);
    }

    /**
     * Called by a dependency when it changes.
     */
    public function notify(): void
    {
        if ($this->disposed) {
            return;
        }

        $batch = BatchScope::current();
        if ($batch !== null) {
            $batch->enqueueEffect($this);
            return;
        }

        $this->execute();
    }

    private function execute(): void
    {
        // Run previous cleanups before re-execution
        $this->runCleanups();
        $this->cleanupDependencies();

        $onCleanup = function (callable $cleanup): void {
            $this->cleanups[] = $cleanup;
        };

        // Run the effect callback inside a tracking scope
        $scope = new EffectScope($this->onTracked(...));
        $scope->run($this->fn, $onCleanup);
    }

    private function runCleanups(): void
    {
        foreach ($this->cleanups as $cleanup) {
            $cleanup();
        }
        $this->cleanups = [];
    }

    private function cleanupDependencies(): void
    {
        foreach ($this->dependencies as $dep) {
            $dep->unsubscribeEffect($this);
        }
        $this->dependencies = [];
    }
}
```

**Signal/Computed additions for Effect support**:

```php
// On Signal and Computed:
/** @var list<Effect> Tracked via Subscriber with dependent=$effect */
// subscribeEffect / unsubscribeEffect use the same Subscriber mechanism
// as subscribeComputed, but the Subscriber::fire calls $effect->notify()

public function subscribeEffect(Effect $effect): void
{
    $this->subscribers[] = new Subscriber(
        callback: static fn() => $effect->notify(),
        dependent: $effect,
    );
}

public function unsubscribeEffect(Effect $effect): void
{
    $this->subscribers = array_values(array_filter(
        $this->subscribers,
        static fn(Subscriber $s): bool => $s->dependent !== $effect,
    ));
}
```

### 3.4 `EffectScope` (static tracking context)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Reactive;

/**
 * Static tracking context. Holds a stack of active scopes so that
 * Signal::get() calls inside a Computed or Effect auto-register
 * the signal as a dependency of the current scope.
 */
final class EffectScope
{
    /** @var list<EffectScope> */
    private static array $stack = [];

    /** @var callable(Signal|Computed): void */
    private readonly mixed $onTrack;

    /**
     * @param callable(Signal|Computed): void $onTrack
     */
    public function __construct(callable $onTrack)
    {
        $this->onTrack = $onTrack;
    }

    /**
     * Get the currently active scope, or null if none.
     */
    public static function current(): ?self
    {
        return $stack[count(self::$stack) - 1] ?? null;
    }

    /**
     * Track a dependency into the current scope.
     */
    public function track(Signal|Computed $dep): void
    {
        ($this->onTrack)($dep);
    }

    /**
     * Run a callback inside this scope. Pushes onto the stack.
     *
     * @param callable ...$args  Arguments to pass to $fn
     * @return mixed  Return value of $fn
     */
    public function run(callable $fn, mixed ...$args): mixed
    {
        self::$stack[] = $this;
        try {
            return $fn(...$args);
        } finally {
            array_pop(self::$stack);
        }
    }
}
```

### 3.5 `Subscriber` (internal value object)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Reactive;

/**
 * Internal subscriber record. Shared by Signal and Computed.
 */
final class Subscriber
{
    /** @var callable(mixed): void */
    public readonly mixed $callback;

    public readonly Signal|Computed|Effect|null $dependent;

    /**
     * @param callable(mixed): void $callback
     * @param Signal|Computed|Effect|null $dependent
     */
    public function __construct(callable $callback, Signal|Computed|Effect|null $dependent = null)
    {
        $this->callback = $callback;
        $this->dependent = $dependent;
    }

    public function fire(mixed $value): void
    {
        ($this->callback)($value);
    }
}
```

### 3.6 `BatchScope`

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Reactive;

use Revolt\EventLoop;

/**
 * Batches multiple signal writes into a single update cycle.
 *
 * When a BatchScope is active, Signal::set() and Computed changes queue
 * their notifications instead of firing immediately. When the batch
 * completes, all pending effects run once.
 *
 * Usage:
 *   BatchScope::run(function () {
 *       $sigA->set(1);
 *       $sigB->set(2);
 *       // Effects fire once after this block completes
 *   });
 */
final class BatchScope
{
    private static ?self $current = null;

    private int $depth = 0;

    /** @var list<Effect> */
    private array $pendingEffects = [];

    /** @var list<Signal> */
    private array $pendingSignals = [];

    private bool $deferred = false;

    /**
     * Get the current active batch, or null.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Run a callback inside a batch scope. Nested calls are supported —
     * only the outermost flush triggers notifications.
     */
    public static function run(callable $fn): void
    {
        $batch = self::$current;
        if ($batch === null) {
            $batch = new self();
            self::$current = $batch;
        }

        $batch->depth++;
        try {
            $fn();
        } finally {
            $batch->depth--;
            if ($batch->depth === 0) {
                $batch->flush();
                self::$current = null;
            }
        }
    }

    /**
     * Schedule a deferred batch via EventLoop::defer().
     * Signal::set() calls inside $fn will queue notifications.
     * The flush happens on the next event loop tick.
     */
    public static function deferred(callable $fn): void
    {
        EventLoop::defer(function () use ($fn): void {
            self::run($fn);
        });
    }

    /**
     * Enqueue a signal for batched notification.
     */
    public function enqueue(Signal $signal): void
    {
        $this->pendingSignals[] = $signal;
    }

    /**
     * Enqueue an effect for batched execution.
     */
    public function enqueueEffect(Effect $effect): void
    {
        $this->pendingEffects[] = $effect;
    }

    /**
     * Flush all pending notifications. Called automatically when the
     * outermost batch completes.
     */
    public function flush(): void
    {
        // First: notify all signal subscribers (may mark Computed dirty)
        foreach ($this->pendingSignals as $signal) {
            foreach ($signal->getSubscribersForFlush() as $sub) {
                $sub->fire($signal->value());
            }
        }

        // Then: deduplicate and run pending effects
        $seen = [];
        foreach ($this->pendingEffects as $effect) {
            $id = spl_object_id($effect);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $effect->run();
            }
        }

        $this->pendingSignals = [];
        $this->pendingEffects = [];
    }
}
```

### 3.7 `Signal::getSubscribersForFlush()`

This is a small internal accessor needed by `BatchScope::flush()`:

```php
/**
 * @internal Used by BatchScope::flush()
 * @return list<Subscriber>
 */
public function getSubscribersForFlush(): array
{
    return $this->subscribers;
}
```

## 4. State That Should Become Signals

All mutable state in `TuiCoreRenderer` and its sub-managers that drives rendering should be wrapped in `Signal<T>`. Derived display values become `Computed<T>`. Render calls become `Effect`s.

### 4.1 TuiCoreRenderer State → Signals

| Current Property | Signal Type | Notes |
|---|---|---|
| `$currentModeLabel` | `Signal<string>` | Set by `showMode()` |
| `$currentModeColor` | `Signal<string>` | ANSI escape for mode badge |
| `$currentPermissionLabel` | `Signal<string>` | Set by `setPermissionMode()` |
| `$currentPermissionColor` | `Signal<string>` | ANSI escape for permission badge |
| `$statusDetail` | `Signal<string>` | Computed from token/model state |
| `$lastStatusTokensIn` | `Signal<?int>` | Set by `showStatus()` |
| `$lastStatusTokensOut` | `Signal<?int>` | Set by `showStatus()` |
| `$lastStatusCost` | `Signal<?float>` | Set by `showStatus()` |
| `$lastStatusMaxContext` | `Signal<?int>` | Set by `showStatus()` |
| `$activeResponse` | `Signal<MarkdownWidget\|AnsiArtWidget\|null>` | Active streaming widget |
| `$activeResponseIsAnsi` | `Signal<bool>` | Whether active response is ANSI art |
| `$scrollOffset` | `Signal<int>` | History scroll position |
| `$hasHiddenActivityBelow` | `Signal<bool>` | Whether new content appeared below scroll |
| `$pendingEditorRestore` | `Signal<?string>` | Editor text to restore after mode switch |
| `$requestCancellation` | `Signal<?DeferredCancellation>` | Active request cancellation token |
| `$messageQueue` | `Signal<list<string>>` | Queued slash commands |
| `$pendingQuestionRecap` | `Signal<list<array>>` | Accumulated Q&A pairs |
| `$taskStore` | `Signal<?TaskStore>` | Task store reference |

### 4.2 TuiCoreRenderer State → Computed

| Computed | Derives From | Notes |
|---|---|---|
| `statusBarMessage` | `modeLabel`, `modeColor`, `permLabel`, `permColor`, `statusDetail` | Replaces `refreshStatusBar()` |
| `isBrowsingHistory` | `scrollOffset` | `scrollOffset > 0` |
| `statusDetailComputed` | `tokensIn`, `maxContext`, model string | Replaces the inline calculation in `showStatus()` |

### 4.3 TuiAnimationManager State → Signals

| Current Property | Signal Type | Notes |
|---|---|---|
| `$currentPhase` | `Signal<AgentPhase>` | Thinking/Tools/Idle |
| `$breathColor` | `Signal<?string>` | Current animation color |
| `$thinkingPhrase` | `Signal<?string>` | Current thinking message |
| `$thinkingStartTime` | `Signal<float>` | For elapsed calculation |
| `$breathTick` | `Signal<int>` | Animation frame counter |
| `$compactingStartTime` | `Signal<float>` | Compacting elapsed |
| `$compactingBreathTick` | `Signal<int>` | Compacting frame counter |
| `$spinnerIndex` | `Signal<int>` | Next spinner allocation index |

### 4.4 TuiToolRenderer State → Signals

| Current Property | Signal Type | Notes |
|---|---|---|
| `$lastToolArgs` | `Signal<array>` | Most recent tool call args |
| `$lastToolArgsByName` | `Signal<array<string, array>>` | Args indexed by tool name |
| `$activeBashWidget` | `Signal<?BashCommandWidget>` | Currently running bash widget |
| `$toolExecutingPreview` | `Signal<?string>` | Last line of executing output |
| `$activeDiscoveryItems` | `Signal<list<array>>` | Current discovery batch items |

### 4.5 TuiModalManager State → Signals

| Current Property | Signal Type | Notes |
|---|---|---|
| `$askSuspension` | `Signal<?Suspension>` | Active ask dialog suspension |
| `$activeModal` | `Signal<bool>` | Whether a modal is showing |

### 4.6 SubagentDisplayManager State → Signals

| Current Property | Signal Type | Notes |
|---|---|---|
| `$batchDisplayed` | `Signal<bool>` | Prevents tree refresh after batch |
| `$loaderBreathTick` | `Signal<int>` | Animation frame counter |
| `$cachedLoaderLabel` | `Signal<string>` | Current loader text |
| `$startTime` | `Signal<float>` | Elapsed time start |

## 5. Effects: Wiring Signals to Widgets

The key pattern: **Effects are the bridge between reactive state and imperative widget APIs.**

```php
// Example: Status bar stays in sync with mode + permission + detail signals
new Effect(function () use ($statusBar, $modeLabel, $modeColor, $permLabel, $permColor, $statusDetail): void {
    $r = Theme::reset();
    $sep = Theme::dim() . "·{$r}";
    $statusBar->setMessage(
        "{$modeColor->get()}{$modeLabel->get()}{$r} {$sep} "
        . "{$permColor->get()}{$permLabel->get()}{$r} {$sep} "
        . $statusDetail->get()
    );
});
// No need for explicit refreshStatusBar() calls — any signal change auto-triggers this.
```

```php
// Example: History status indicator
new Effect(function () use ($historyStatus, $scrollOffset, $hasHiddenActivity): void {
    if ($scrollOffset->get() > 0) {
        $historyStatus->show($hasHiddenActivity->get());
    } else {
        $historyStatus->hide();
    }
});
```

```php
// Example: Render scheduling via EventLoop::defer()
new Effect(function () use ($tui): void {
    $tui->requestRender();
    $tui->processRender();
});
// Or scoped to specific signals to avoid over-rendering.
```

## 6. Batch Updates & Render Scheduling

### Problem
A single agent tick can update 5+ signals (phase, thinking phrase, token count, status detail, task bar). Without batching, each `set()` triggers an immediate Effect execution → 5 renders.

### Solution: `BatchScope::run()`

```php
BatchScope::run(function () use ($self): void {
    $self->currentPhase->set(AgentPhase::Thinking);
    $self->thinkingPhrase->set($phrase);
    $self->tokensIn->set($tokensIn);
    $self->statusDetail->set($detail);
    // All effects fire once after this block.
});
```

### Render Defer Pattern

For async contexts (event loop callbacks), use `BatchScope::deferred()`:

```php
EventLoop::repeat(0.033, function () use ($breathTick, $breathColor, $renderEffect): void {
    BatchScope::run(function () use ($breathTick, $breathColor): void {
        $breathTick->update(fn(int $t) => $t + 1);
        // Compute new breathColor from tick
        $breathColor->set(Theme::rgb($cr, $cg, $cb));
    });
    // Render effect fires exactly once per animation frame
});
```

### Global Render Effect

A single root `Effect` that watches a `renderTrigger` signal and calls `flushRender()`:

```php
$renderTrigger = new Signal(0); // Version counter

new Effect(function () use ($tui, $renderTrigger): void {
    $renderTrigger->get(); // Track
    $tui->requestRender();
    $tui->processRender();
});

// Anywhere: $renderTrigger->update(fn(int $v) => $v + 1);
```

## 7. Migration Strategy

### Phase 1: Implement and test primitives (this plan)
- Create `src/UI/Tui/Reactive/` with `Signal`, `Computed`, `Effect`, `EffectScope`, `BatchScope`, `Subscriber`
- Full unit test coverage

### Phase 2: Introduce signals in TuiCoreRenderer
- Replace scalar properties with `Signal<T>`
- Add `Computed<T>` for derived values
- Wire `Effect`s for widget updates
- Keep existing imperative code working alongside

### Phase 3: Migrate sub-managers
- `TuiAnimationManager` — phase, breath color, thinking phrase as signals
- `TuiToolRenderer` — tool state as signals
- `TuiModalManager` — modal state as signals
- `SubagentDisplayManager` — display state as signals

### Phase 4: Remove imperative refresh calls
- Delete `refreshStatusBar()`, manual `flushRender()` scattered throughout
- Let effects drive all rendering

## 8. File Layout

```
src/UI/Tui/Reactive/
├── Signal.php
├── Computed.php
├── Effect.php
├── EffectScope.php
├── BatchScope.php
└── Subscriber.php

tests/Unit/UI/Tui/Reactive/
├── SignalTest.php
├── ComputedTest.php
├── EffectTest.php
├── EffectScopeTest.php
├── BatchScopeTest.php
└── IntegrationTest.php
```

## 9. Unit Test Plan

### 9.1 `SignalTest`

| Test | Description |
|---|---|
| `testGetReturnsInitialValue` | `new Signal(42)->get() === 42` |
| `testSetUpdatesValue` | `$s->set(10); assert $s->get() === 10` |
| `testSetDoesNotNotifyOnSameValue` | `$s->set(1); $s->set(1);` — subscriber fires once |
| `testVersionIncrementsOnSet` | Initial version 0, after set → 1 |
| `testVersionUnchangedOnSameValue` | `$s->set(1); $s->set(1);` — version stays 1 |
| `testSubscribeCallbackFires` | Subscribe, set new value, callback receives new value |
| `testUnsubscribeStopsNotifications` | Call unsubscribe closure, set new value, callback not called |
| `testMultipleSubscribers` | All receive notification |
| `testUpdateCallback` | `update(fn($v) => $v + 1)` on Signal(5) → 6 |
| `testValueReturnsRawWithoutTracking` | No EffectScope dependency registered |

### 9.2 `ComputedTest`

| Test | Description |
|---|---|
| `testLazyEvaluation` | Computed fn not called until first `get()` |
| `testCachedValue` | `get()` twice without dependency change → fn called once |
| `testRecomputeOnDependencyChange` | `$a->set(2); $c->get()` returns updated value |
| `testChainedComputed` | Computed A depends on Signal, Computed B depends on Computed A |
| `testVersionTracksRecomputations` | Version increments each time deps change |
| `testComputedInComputedTracking` | Computed B reads Computed A, both track into parent Effect |
| `testMultipleDependencies` | `$a + $b` recomputes when either changes |
| `testNoRecomputeWhenNotDirty` | Set to same value → dirty flag stays false |

### 9.3 `EffectTest`

| Test | Description |
|---|---|
| `testRunsImmediatelyOnConstruction` | Effect fn called in constructor |
| `testReRunsOnDependencyChange` | `$s->set(2)` triggers effect again |
| `testAutoTracksDependencies` | Effect reads Signal A and B → tracks both |
| `testRetracksOnReRun` | Conditional dependency: `if ($flag->get()) $a->get()` |
| `testCleanupRunsBeforeNextExecution` | Cleanup from run 1 fires before run 2 |
| `testDisposeStopsExecution` | `dispose()`, then set dep → no re-run |
| `testDisposeRunsCleanups` | Final cleanups fire on dispose |
| `testNestedEffects` | Effect A reads Signal, Effect B reads Computed of that Signal |
| `testEffectReadsComputed` | Effect depends on Computed → re-runs when Computed changes |

### 9.4 `EffectScopeTest`

| Test | Description |
|---|---|
| `testCurrentReturnsNullOutsideScope` | No active scope |
| `testCurrentReturnsActiveScope` | Inside `$scope->run()` |
| `testNestedScopesStack` | Scope A inside Scope B → current is B, then A |
| `testTrackCallbackCalled` | `Signal::get()` inside scope triggers `onTrack` |
| `testNoTrackOutsideScope` | `Signal::get()` without scope → no tracking |

### 9.5 `BatchScopeTest`

| Test | Description |
|---|---|
| `testMultipleSetsTriggerOneEffectRun` | Set signal A and B in batch → effect runs once |
| `testNestedBatch` | Nested `BatchScope::run()` — only outermost flushes |
| `testNoBatchWhenNoScope` | Without batch, each set triggers immediately |
| `testFlushOrder` | Signal subscribers before effects |
| `testDeduplicatedEffects` | Same effect queued twice → runs once |

### 9.6 `IntegrationTest`

| Test | Description |
|---|---|
| `testComputedChain` | Signal → Computed A → Computed B → Effect: one change cascades |
| `testBatchWithComputedAndEffect` | Batch set signal, computed auto-dirties, effect runs once |
| `testDisposeBreaksChain` | Dispose mid-effect → no further notifications |
| `testMemoryCleanup` | Verify no circular references after dispose (weak reference check) |
| `testStatusbarPattern` | Simulate the real status bar pattern: mode + permission + tokens → computed message → effect sets widget |

## 10. Edge Cases & Design Decisions

### Equality Check
`Signal::set()` uses `===` (strict identity) for same-value detection. For objects, this means setting a new instance always triggers. For scalars, `1 === 1` is `true`. This matches SolidJS behavior.

**Custom equality**: If needed later, add an optional `SignalOptions{equals: callable}` parameter to the constructor. Not in v1.

### Circular Dependencies
If a Computed writes back to one of its dependencies, infinite recursion results. This is a programmer error. We add a recursion guard:

```php
private static int $recomputeDepth = 0;

public function recompute(): mixed
{
    if (self::$recomputeDepth > 100) {
        throw new \LogicException('Reactive: maximum recomputation depth exceeded (circular dependency?)');
    }
    self::$recomputeDepth++;
    try {
        // ... normal recomputation
    } finally {
        self::$recomputeDepth--;
    }
}
```

### Memory Leaks
- All subscriber arrays hold strong references. Effects must be `dispose()`d when no longer needed.
- `TuiCoreRenderer::teardown()` should dispose all root effects.
- Future optimization: use `WeakMap` or weak references for downstream computed subscriptions.

### Thread Safety
Not a concern — PHP is single-threaded and KosmoKrator uses Revolt's cooperative scheduling. No locks needed.

### PHP Version
Target PHP 8.4+. Use `mixed` type for signal values, `readonly` for constructor promotion, intersection types for `Signal|Computed`.

## 11. API Cheat Sheet

```php
use Kosmokrator\UI\Tui\Reactive\{Signal, Computed, Effect, BatchScope};

// Create signals
$count = new Signal(0);
$name  = new Signal('world');

// Create computed
$greeting = new Computed(fn() => "Hello, {$name->get()}! ({$count->get()})");

// Create effect
$eff = new Effect(function () use ($greeting): void {
    echo $greeting->get() . "\n";
});
// Prints: "Hello, world! (0)"

// Update — effect re-runs automatically
$name->set('KosmoKrator');
// Prints: "Hello, KosmoKrator! (0)"

// Batch — effect runs once
BatchScope::run(function () use ($count, $name): void {
    $count->set(1);
    $name->set('PHP');
});
// Prints: "Hello, PHP! (1)" (once, not twice)

// Dispose
$eff->dispose();
$name->set('ignored'); // No output
```
