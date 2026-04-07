# Dependency Injection for TUI Components

> **Module**: `13-architecture`  
> **Depends on**: —  
> **Status**: Plan  

---

## Problem

TUI components are wired together manually in `TuiCoreRenderer::initialize()` using a dense web of closures that serve as a makeshift dependency injection system. This creates:

1. **Opaque dependency graphs** — every manager receives 5–19 closures instead of typed collaborators
2. **Circular references** — `TuiCoreRenderer` ↔ `TuiAnimationManager` ↔ `SubagentDisplayManager` reference each other through closures capturing `$this`
3. **Untestable wiring** — can't mock collaborators without replacing the entire closure set
4. **Fragile initialization order** — `SubagentDisplayManager` is created before `TuiAnimationManager` but references `$this->animationManager->getBreathColor()` via closure
5. **State access via closures** — `TuiInputHandler` receives 19 closures to read/write state that should be on injectable services

---

## 1. Current Dependency Graph

### 1.1 Object Creation Hierarchy

```
TuiRenderer (factory)
  └─ new TuiCoreRenderer
  └─ new TuiToolRenderer($core)
  └─ new TuiConversationRenderer($core, $tool)
  
TuiCoreRenderer::initialize()
  ├─ new Tui()
  ├─ new ContainerWidget × 4
  ├─ new HistoryStatusWidget
  ├─ new ProgressBarWidget
  ├─ new TextWidget
  ├─ new EditorWidget
  ├─ new SubagentDisplayManager(
  │     conversation,
  │     fn() => $this->animationManager->getBreathColor(),    ← references not-yet-created $animationManager
  │     fn() => $this->flushRender(),
  │     fn() => $this->animationManager->ensureSpinnersRegistered(),
  │   )
  ├─ new TuiAnimationManager(
  │     thinkingBar,
  │     fn() => $this->taskStore !== null && !$this->taskStore->isEmpty(),
  │     fn() => $this->subagentDisplay->hasRunningAgents(),    ← circular: core → animMgr → core::subagentDisplay
  │     fn() => $this->refreshTaskBar(),
  │     fn() => $this->subagentDisplay->tickTreeRefresh(),
  │     fn() => $this->subagentDisplay->cleanup(),
  │     fn() => $this->flushRender(),
  │     fn() => $this->forceRender(),
  │   )
  ├─ new TuiModalManager(
  │     overlay, sessionRoot, tui, input,
  │     fn() => $this->flushRender(),
  │     fn() => $this->forceRender(),
  │   )
  └─ new TuiInputHandler(
        input, conversation, overlay, modalManager,
        15 more closures accessing core state...
      )
```

### 1.2 Closure Inventory

| Class | Closures Received | Purpose |
|-------|------------------|---------|
| `TuiAnimationManager` | 8 | State queries, render triggers, subagent delegation |
| `SubagentDisplayManager` | 3 | Breath color, render, spinner registration |
| `TuiModalManager` | 2 | Render triggers |
| `TuiInputHandler` | 19 | Full state access: prompt suspension, cancellation, mode cycling, messages, scroll, history |
| **Total** | **32** | |

### 1.3 Circular Dependency Map

```
TuiCoreRenderer ──creates──→ SubagentDisplayManager
       ↑                              │
       │         fn() => $this->animationManager->getBreathColor()
       │                              │
       └──────creates──→ TuiAnimationManager
                                │
                 fn() => $this->subagentDisplay->hasRunningAgents()
                 fn() => $this->subagentDisplay->tickTreeRefresh()
                 fn() => $this->subagentDisplay->cleanup()
                                │
                                ↓
                       SubagentDisplayManager  ←── cycle
```

The cycle works only because closures capture `$this` lazily — the `SubagentDisplayManager` closure references `$this->animationManager` which is set *after* construction. This is fragile and invisible in static analysis.

### 1.4 State Ownership

Critical mutable state lives on `TuiCoreRenderer` directly:

| State | Used By | Via |
|-------|---------|-----|
| `$requestCancellation` | `TuiInputHandler`, `TuiAnimationManager`, `TuiCoreRenderer` | Closure |
| `$promptSuspension` | `TuiInputHandler`, `TuiCoreRenderer` | Closure |
| `$pendingEditorRestore` | `TuiInputHandler`, `TuiCoreRenderer` | Closure |
| `$immediateCommandHandler` | `TuiInputHandler`, `TuiCoreRenderer` | Closure |
| `$currentModeLabel` | `TuiInputHandler`, `TuiCoreRenderer` | Closure |
| `$messageQueue` | `TuiInputHandler`, `TuiCoreRenderer` | Closure |
| `$scrollOffset` | `TuiCoreRenderer` only | Direct |
| `$taskStore` | `TuiCoreRenderer`, `TuiAnimationManager` | Closure |

---

## 2. Design

### 2.1 TuiContainer — Lightweight Service Container

A purpose-built container for TUI services. Not a general-purpose DI container — it knows about the TUI component lifecycle (single initialization, no hot swaps).

```php
namespace Kosmokrator\UI\Tui;

final class TuiContainer
{
    /** @var array<string, object> */
    private array $services = [];
    
    /** @var array<string, \Closure(self): object> */
    private array $factories = [];
    
    /** @var array<string, list<\Closure(object, self): void>> */
    private array $initializers = [];
    
    private bool $initialized = false;
    
    /**
     * Register a service factory (lazy, created on first get).
     */
    public function factory(string $id, \Closure $factory): self;
    
    /**
     * Register a post-creation initializer (runs after factory, in order).
     */
    public function initializer(string $id, \Closure $initializer): self;
    
    /**
     * Get a service, creating it lazily if needed.
     */
    public function get(string $id): object;
    
    /**
     * Check if a service is registered.
     */
    public function has(string $id): bool;
    
    /**
     * Run all initializers. Called once after widget tree is built.
     */
    public function initialize(): void;
}
```

### 2.2 Service Definitions

Replace closures with typed services injected via the container:

```php
// New state services extracted from TuiCoreRenderer

final class TuiRenderContext
{
    public function __construct(
        public readonly Tui $tui,
        public readonly ContainerWidget $session,
        public readonly ContainerWidget $conversation,
        public readonly ContainerWidget $overlay,
        public readonly HistoryStatusWidget $historyStatus,
        public readonly ProgressBarWidget $statusBar,
        public readonly TextWidget $taskBar,
        public readonly ContainerWidget $thinkingBar,
        public readonly EditorWidget $input,
    ) {}
}

final class TuiPromptState
{
    private ?Suspension $suspension = null;
    private ?string $pendingRestore = null;
    
    public function setSuspension(?Suspension $s): void;
    public function getSuspension(): ?Suspension;
    public function setPendingRestore(?string $text): void;
    public function getPendingRestore(): ?string;
}

final class TuiRequestState
{
    private ?DeferredCancellation $cancellation = null;
    
    public function startCancellation(): DeferredCancellation;
    public function getCancellation(): ?Cancellation;
    public function getDeferred(): ?DeferredCancellation;
    public function clear(): void;
}

final class TuiModeState
{
    private string $modeLabel = 'Edit';
    private string $modeColor = "\033[38;2;80;200;120m";
    private string $permissionLabel = 'Guardian ◈';
    private string $permissionColor = "\033[38;2;180;180;200m";
    
    public function getModeLabel(): string;
    public function setMode(string $label, string $color): void;
    public function getPermissionLabel(): string;
    public function setPermission(string $label, string $color): void;
}
```

### 2.3 Service Registration

```php
final class TuiContainerFactory
{
    public static function create(): TuiContainer
    {
        $c = new TuiContainer();
        
        // ── Widget tree (created first, as concrete objects) ──
        $c->factory(TuiRenderContext::class, fn() => self::buildWidgetTree());
        
        // ── State services (no dependencies) ──
        $c->factory(TuiPromptState::class, fn() => new TuiPromptState());
        $c->factory(TuiRequestState::class, fn() => new TuiRequestState());
        $c->factory(TuiModeState::class, fn() => new TuiModeState());
        
        // ── Managers (depend on state + widgets) ──
        $c->factory(TuiAnimationManager::class, function(TuiContainer $c) {
            $ctx = $c->get(TuiRenderContext::class);
            return new TuiAnimationManager(
                thinkingBar: $ctx->thinkingBar,
                hasTasksProvider: fn() => $c->get(TaskStoreProvider::class)->hasTasks(),
                hasSubagentActivityProvider: fn() => $c->get(SubagentDisplayManager::class)->hasRunningAgents(),
                refreshTaskBarCallback: fn() => $c->get(TuiCoreRenderer::class)->refreshTaskBar(),
                subagentTickCallback: fn() => $c->get(SubagentDisplayManager::class)->tickTreeRefresh(),
                subagentCleanupCallback: fn() => $c->get(SubagentDisplayManager::class)->cleanup(),
                renderCallback: fn() => $c->get(TuiCoreRenderer::class)->flushRender(),
                forceRenderCallback: fn() => $c->get(TuiCoreRenderer::class)->forceRender(),
            );
        });
        
        $c->factory(SubagentDisplayManager::class, function(TuiContainer $c) {
            $ctx = $c->get(TuiRenderContext::class);
            return new SubagentDisplayManager(
                conversation: $ctx->conversation,
                breathColorProvider: fn() => $c->get(TuiAnimationManager::class)->getBreathColor(),
                renderCallback: fn() => $c->get(TuiCoreRenderer::class)->flushRender(),
                ensureSpinners: fn() => $c->get(TuiAnimationManager::class)->ensureSpinnersRegistered(),
            );
        });
        
        $c->factory(TuiModalManager::class, function(TuiContainer $c) {
            $ctx = $c->get(TuiRenderContext::class);
            return new TuiModalManager(
                overlay: $ctx->overlay,
                sessionRoot: $ctx->session,
                tui: $ctx->tui,
                input: $ctx->input,
                renderCallback: fn() => $c->get(TuiCoreRenderer::class)->flushRender(),
                forceRenderCallback: fn() => $c->get(TuiCoreRenderer::class)->forceRender(),
            );
        });
        
        $c->factory(TuiInputHandler::class, function(TuiContainer $c) {
            $ctx = $c->get(TuiRenderContext::class);
            return new TuiInputHandler(
                input: $ctx->input,
                conversation: $ctx->conversation,
                overlay: $ctx->overlay,
                modalManager: $c->get(TuiModalManager::class),
                promptState: $c->get(TuiPromptState::class),
                requestState: $c->get(TuiRequestState::class),
                modeState: $c->get(TuiModeState::class),
                renderContext: $ctx,
            );
        });
        
        // ── Core renderer (depends on everything) ──
        $c->factory(TuiCoreRenderer::class, function(TuiContainer $c) {
            return new TuiCoreRenderer(
                container: $c,
                renderContext: $c->get(TuiRenderContext::class),
                promptState: $c->get(TuiPromptState::class),
                requestState: $c->get(TuiRequestState::class),
                modeState: $c->get(TuiModeState::class),
                animationManager: $c->get(TuiAnimationManager::class),
                modalManager: $c->get(TuiModalManager::class),
                subagentDisplay: $c->get(SubagentDisplayManager::class),
                inputHandler: $c->get(TuiInputHandler::class),
            );
        });
        
        return $c;
    }
    
    private static function buildWidgetTree(): TuiRenderContext
    {
        // All the `new` calls currently in TuiCoreRenderer::initialize()
        $tui = new Tui(KosmokratorStyleSheet::create());
        $session = new ContainerWidget;
        $session->setId('session');
        $session->addStyleClass('session');
        $session->expandVertically(true);
        // ... remaining widget construction ...
        
        return new TuiRenderContext(
            tui: $tui,
            session: $session,
            conversation: $conversation,
            overlay: $overlay,
            historyStatus: $historyStatus,
            statusBar: $statusBar,
            taskBar: $taskBar,
            thinkingBar: $thinkingBar,
            input: $input,
        );
    }
}
```

### 2.4 Interface Extraction

Extract interfaces for testable boundaries:

```php
interface TuiAnimationManagerInterface
{
    public function getCurrentPhase(): AgentPhase;
    public function getBreathColor(): ?string;
    public function getThinkingPhrase(): ?string;
    public function getThinkingStartTime(): float;
    public function getLoader(): ?CancellableLoaderWidget;
    public function setPhase(AgentPhase $phase, ?DeferredCancellation $cancellation = null): void;
    public function showCompacting(): void;
    public function clearCompacting(): void;
    public function ensureSpinnersRegistered(): void;
}

interface TuiModalManagerInterface
{
    public function showSettings(array $currentSettings): array;
    public function pickSession(array $items): ?string;
    public function approvePlan(string $currentPermissionMode): ?array;
    public function askUser(string $question): string;
    public function askChoice(string $question, array $choices): string;
    public function getAskSuspension(): ?Suspension;
    public function clearAskSuspension(): void;
    // ... dashboard methods ...
}

interface SubagentDisplayManagerInterface
{
    public function showRunning(array $entries): void;
    public function showSpawn(array $entries): void;
    public function showBatch(array $entries): void;
    public function hasRunningAgents(): bool;
    public function tickTreeRefresh(): void;
    public function cleanup(): void;
    public function setTreeProvider(?\Closure $provider): void;
    public function refreshTree(array $tree): void;
}

interface TuiRenderContextInterface
{
    public function getTui(): Tui;
    public function getConversation(): ContainerWidget;
    public function getOverlay(): ContainerWidget;
    public function getSession(): ContainerWidget;
    public function getInput(): EditorWidget;
}
```

### 2.5 Signal-Based State Elimination of Closures

The biggest win: once state lives on dedicated services instead of `TuiCoreRenderer`, managers can hold direct references instead of closures.

**Before** (TuiAnimationManager — 8 closures):
```php
public function __construct(
    private readonly ContainerWidget $thinkingBar,
    private readonly \Closure $hasTasksProvider,              // fn() => $this->taskStore !== null...
    private readonly \Closure $hasSubagentActivityProvider,   // fn() => $this->subagentDisplay->hasRunningAgents()
    private readonly \Closure $refreshTaskBarCallback,        // fn() => $this->refreshTaskBar()
    private readonly \Closure $subagentTickCallback,          // fn() => $this->subagentDisplay->tickTreeRefresh()
    private readonly \Closure $subagentCleanupCallback,       // fn() => $this->subagentDisplay->cleanup()
    private readonly \Closure $renderCallback,                // fn() => $this->flushRender()
    private readonly \Closure $forceRenderCallback,           // fn() => $this->forceRender()
) {}
```

**After** — with signal-based state and typed references:
```php
public function __construct(
    private readonly ContainerWidget $thinkingBar,
    private readonly TuiCoreRendererInterface $renderer,
    private readonly TaskStoreProvider $taskStore,
    private readonly SubagentDisplayManagerInterface $subagentDisplay,
) {}

// Inside methods — direct method calls instead of closure invocations:
private function enterIdle(): void
{
    // ...
    $this->renderer->refreshTaskBar();           // was: ($this->refreshTaskBarCallback)()
    $this->subagentDisplay->cleanup();           // was: ($this->subagentCleanupCallback)()
    $this->renderer->forceRender();              // was: ($this->forceRenderCallback)()
}
```

**Remaining closures** (for render triggers that need flexible dispatch):
```php
// Only the render callbacks remain as closures — they're the "output boundary"
// and could be replaced by an event bus later if needed
public function __construct(
    private readonly ContainerWidget $thinkingBar,
    private readonly TuiCoreRendererInterface $renderer,
    private readonly TaskStoreProvider $taskStore,
    private readonly SubagentDisplayManagerInterface $subagentDisplay,
) {}
```

**Closure reduction target:**

| Manager | Before | After | Reduction |
|---------|--------|-------|-----------|
| `TuiAnimationManager` | 8 | 0 | −8 |
| `SubagentDisplayManager` | 3 | 0 | −3 |
| `TuiModalManager` | 2 | 0 | −2 |
| `TuiInputHandler` | 19 | 0 | −19 |
| **Total** | **32** | **0** | **−32** |

### 2.6 TuiInputHandler Refactored

The most dramatic simplification. Currently takes 19 closures. After refactoring:

```php
final class TuiInputHandler
{
    public function __construct(
        private readonly EditorWidget $input,
        private readonly ContainerWidget $conversation,
        private readonly ContainerWidget $overlay,
        private readonly TuiModalManagerInterface $modalManager,
        private readonly TuiPromptState $promptState,
        private readonly TuiRequestState $requestState,
        private readonly TuiModeState $modeState,
        private readonly TuiCoreRendererInterface $renderer,
    ) {}
    
    // Inside handleCancel():
    private function handleCancel(): void
    {
        $askSuspension = $this->modalManager->getAskSuspension();
        if ($askSuspension !== null) {
            $this->modalManager->clearAskSuspension();
            $askSuspension->resume('');
            return;
        }
        
        $deferred = $this->requestState->getDeferred();        // was: ($this->getRequestCancellation)()
        if ($deferred !== null) {
            $deferred->cancel();
            $this->requestState->clear();                       // was: ($this->clearRequestCancellation)(null)
            return;
        }
        
        $suspension = $this->promptState->getSuspension();     // was: ($this->getPromptSuspension)()
        if ($suspension !== null) {
            $this->promptState->setSuspension(null);            // was: ($this->clearPromptSuspension)(null)
            $suspension->resume('/quit');
            return;
        }
    }
}
```

### 2.7 Widget Factory Pattern

For widgets created dynamically during the session (tool call widgets, messages, loaders):

```php
final class TuiWidgetFactory
{
    public function __construct(
        private readonly TuiRenderContext $context,
    ) {}
    
    public function createMessageWidget(string $text, string $styleClass): TextWidget;
    public function createResponseWidget(string $initialText, bool $isAnsi): MarkdownWidget|AnsiArtWidget;
    public function createToolCallWidget(string $name, array $args): CollapsibleWidget;
    public function createLoaderWidget(string $phrase, string $spinnerName): CancellableLoaderWidget;
    public function createAnsweredQuestionsWidget(array $recap): AnsweredQuestionsWidget;
}
```

This centralizes widget creation for consistent styling and enables test mocking.

---

## 3. Implementation Plan

### Phase 1: State Extraction (Week 1)

Extract state bags from `TuiCoreRenderer` with zero behavioral changes.

| Step | File | Change |
|------|------|--------|
| 1.1 | New `TuiRenderContext` | Value object holding widget references |
| 1.2 | New `TuiPromptState` | Extract `$promptSuspension`, `$pendingEditorRestore` |
| 1.3 | New `TuiRequestState` | Extract `$requestCancellation` |
| 1.4 | New `TuiModeState` | Extract `$currentModeLabel`, `$currentModeColor`, `$currentPermissionLabel`, `$currentPermissionColor` |
| 1.5 | `TuiCoreRenderer` | Accept state services in constructor, delegate state access |
| 1.6 | Tests | Update existing tests to pass state services |

**Verification**: All existing tests pass. No closures removed yet.

### Phase 2: Container Introduction (Week 1)

Introduce `TuiContainer` alongside existing wiring. Both paths work during migration.

| Step | File | Change |
|------|------|--------|
| 2.1 | New `TuiContainer` | Service container implementation |
| 2.2 | New `TuiContainerFactory` | Factory with all service registrations |
| 2.3 | `TuiRenderer` | Add alternate constructor path using container |
| 2.4 | Tests | Container wiring tests |

**Verification**: `TuiRenderer` can be constructed via container or legacy path.

### Phase 3: Interface Extraction (Week 2)

Extract interfaces for all managers. Existing classes implement them.

| Step | File | Change |
|------|------|--------|
| 3.1 | New `TuiAnimationManagerInterface` | Extract from `TuiAnimationManager` public methods |
| 3.2 | New `TuiModalManagerInterface` | Extract from `TuiModalManager` public methods |
| 3.3 | New `SubagentDisplayManagerInterface` | Extract from `SubagentDisplayManager` public methods |
| 3.4 | New `TuiCoreRendererInterface` | Extract from `CoreRendererInterface` TUI-specific additions |
| 3.5 | All managers | Type-hint against interfaces instead of concrete classes |

**Verification**: All type-hints use interfaces where possible.

### Phase 4: Closure Elimination (Week 2–3)

Replace closures with direct method calls via injected interfaces.

| Step | File | Change |
|------|------|--------|
| 4.1 | `TuiAnimationManager` | Replace 8 closures with `TuiCoreRendererInterface`, `TaskStoreProvider`, `SubagentDisplayManagerInterface` |
| 4.2 | `SubagentDisplayManager` | Replace 3 closures with `TuiAnimationManagerInterface`, `TuiCoreRendererInterface` |
| 4.3 | `TuiModalManager` | Replace 2 closures with `TuiCoreRendererInterface` |
| 4.4 | `TuiInputHandler` | Replace 19 closures with `TuiPromptState`, `TuiRequestState`, `TuiModeState`, `TuiCoreRendererInterface` |
| 4.5 | `TuiCoreRenderer` | Remove closure-creating factory methods |
| 4.6 | Tests | Update all manager tests |

**Verification**: Zero closures in manager constructors. All tests pass.

### Phase 5: Widget Factory (Week 3)

Extract dynamic widget creation.

| Step | File | Change |
|------|------|--------|
| 5.1 | New `TuiWidgetFactory` | Centralized widget creation |
| 5.2 | `TuiCoreRenderer` | Delegate `new TextWidget()` etc. to factory |
| 5.3 | `TuiToolRenderer` | Delegate widget creation to factory |
| 5.4 | Tests | Widget factory tests |

### Phase 6: Legacy Path Removal (Week 3)

Remove the old constructor path from `TuiRenderer`. Container is the only path.

| Step | File | Change |
|------|------|--------|
| 6.1 | `TuiRenderer` | Remove `new TuiCoreRenderer()` direct construction |
| 6.2 | `TuiCoreRenderer` | Remove legacy constructor overload |
| 6.3 | Dead code cleanup | Remove unused accessors only needed for closure-based wiring |

---

## 4. Test Improvements

### 4.1 Current Testing Pain Points

- `TuiAnimationManagerTest` must hand-craft 8 closures for every test
- `TuiRendererTest` creates `new TuiCoreRenderer` via reflection to test private methods
- Cannot test `TuiInputHandler` in isolation — requires 19 closures
- Cannot mock render triggers — must track boolean flags

### 4.2 After Refactoring

```php
// Before: 8 closures
$manager = new TuiAnimationManager(
    thinkingBar: $bar,
    hasTasksProvider: fn (): bool => $this->hasTasks,
    hasSubagentActivityProvider: fn (): bool => $this->hasSubagentActivity,
    refreshTaskBarCallback: function (): void { $this->refreshCalled = true; },
    subagentTickCallback: function (): void { $this->subagentTickCalled = true; },
    subagentCleanupCallback: function (): void { $this->subagentCleanupCalled = true; },
    renderCallback: function (): void { $this->refreshCalled = true; },
    forceRenderCallback: function (): void { $this->forceRenderCalled = true; },
);

// After: 4 typed services, all mockable
$renderer = $this->createMock(TuiCoreRendererInterface::class);
$renderer->expects($this->once())->method('forceRender');

$taskStore = $this->createMock(TaskStoreProvider::class);
$taskStore->method('hasTasks')->willReturn(true);

$subagentDisplay = $this->createMock(SubagentDisplayManagerInterface::class);

$manager = new TuiAnimationManager(
    thinkingBar: new ContainerWidget,
    renderer: $renderer,
    taskStore: $taskStore,
    subagentDisplay: $subagentDisplay,
);
```

### 4.3 New Test Coverage Enabled

| Test | Previously Impossible? | Reason |
|------|----------------------|--------|
| `TuiInputHandler::handleCancel` — cancellation flow | Yes | Required 19 closures for state access |
| `TuiAnimationManager` — subagent interaction | Partially | Could only set boolean, not verify delegate calls |
| `SubagentDisplayManager` — animation color | Yes | Required real `TuiAnimationManager` instance |
| `TuiCoreRenderer` — mode cycling with mock managers | Yes | Hard-coded manager construction |
| Integration: container resolves all services | Yes | No container to test |

---

## 5. Circular Dependency Resolution

The `TuiCoreRenderer` ↔ `TuiAnimationManager` ↔ `SubagentDisplayManager` cycle resolves through:

1. **Interface-based references** — managers depend on `TuiCoreRendererInterface`, not the concrete class
2. **Container lazy resolution** — factories resolve services on first `get()`, not at registration time
3. **State extraction** — shared state moved to dedicated services, breaking the need for back-references

The container handles creation order:
```
1. TuiRenderContext (no deps)
2. TuiPromptState, TuiRequestState, TuiModeState (no deps)
3. TuiAnimationManager (needs TuiCoreRendererInterface → resolved lazily)
4. SubagentDisplayManager (needs TuiAnimationManagerInterface → resolved lazily)  
5. TuiModalManager (needs widgets only)
6. TuiInputHandler (needs state services + managers)
7. TuiCoreRenderer (needs everything — created last)
```

Lazy factory closures in the container mean `TuiAnimationManager`'s factory can reference `$c->get(TuiCoreRendererInterface::class)` and it resolves correctly because the concrete `TuiCoreRenderer` is registered under that interface ID.

---

## 6. Risk Assessment

| Risk | Mitigation |
|------|------------|
| Large refactor scope | Phased: each phase is independently shippable |
| Circular resolution fails at runtime | Container tracks resolution stack, throws `CircularDependencyException` with clear cycle path |
| Performance regression from container lookups | Services are singletons — resolved once, cached. Zero overhead after warm-up. |
| Breaking existing tests | Phase 1 keeps all closures working; tests migrate incrementally |
| Widget tree initialization order | `TuiRenderContext` built first as a concrete value object — no lazy resolution for widgets |

---

## 7. File Inventory

### New Files

| File | Purpose |
|------|---------|
| `src/UI/Tui/TuiContainer.php` | Service container |
| `src/UI/Tui/TuiContainerFactory.php` | Container configuration |
| `src/UI/Tui/TuiRenderContext.php` | Widget tree value object |
| `src/UI/Tui/TuiPromptState.php` | Prompt/suspension state |
| `src/UI/Tui/TuiRequestState.php` | Cancellation state |
| `src/UI/Tui/TuiModeState.php` | Mode/permission state |
| `src/UI/Tui/TuiWidgetFactory.php` | Dynamic widget creation |
| `src/UI/Tui/TuiAnimationManagerInterface.php` | Interface |
| `src/UI/Tui/TuiModalManagerInterface.php` | Interface |
| `src/UI/Tui/SubagentDisplayManagerInterface.php` | Interface |
| `src/UI/Tui/TuiCoreRendererInterface.php` | Interface (TUI-specific, not the RendererInterface one) |
| `tests/Unit/UI/Tui/TuiContainerTest.php` | Container tests |
| `tests/Unit/UI/Tui/TuiWidgetFactoryTest.php` | Widget factory tests |

### Modified Files

| File | Change |
|------|--------|
| `src/UI/Tui/TuiCoreRenderer.php` | Accept services in constructor, remove closure creation |
| `src/UI/Tui/TuiAnimationManager.php` | Accept interfaces instead of closures |
| `src/UI/Tui/TuiInputHandler.php` | Accept state services instead of closures |
| `src/UI/Tui/TuiModalManager.php` | Accept interface instead of closures |
| `src/UI/Tui/SubagentDisplayManager.php` | Accept interface instead of closures |
| `src/UI/Tui/TuiToolRenderer.php` | Use widget factory |
| `src/UI/Tui/TuiConversationRenderer.php` | Use widget factory |
| `src/UI/Tui/TuiRenderer.php` | Use `TuiContainerFactory` for construction |
| `tests/Unit/UI/Tui/TuiAnimationManagerTest.php` | Mock interfaces instead of closures |
| `tests/Unit/UI/Tui/TuiRendererTest.php` | Use container for construction |

---

## 8. Success Metrics

| Metric | Before | After |
|--------|--------|-------|
| Closures in constructor params | 32 | 0 |
| Direct `new` calls in `TuiCoreRenderer::initialize()` | 15+ | 0 (moved to factory) |
| Test setup lines for `TuiAnimationManager` | ~20 (8 closures) | ~10 (4 mocks) |
| Test setup lines for `TuiInputHandler` | ~30 (19 closures) | ~10 (5 mocks) |
| Classes testable in isolation | 3/7 | 7/7 |
| Circular dependencies | 3 cycles | 0 |
