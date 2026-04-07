# Startup Optimization

> **Module**: `13-architecture`  
> **Depends on**: none  
> **Status**: Plan

---

## Problem

KosmoKrator's startup path — from `bin/kosmokrator` execution to the first interactive prompt — is dominated by serial blocking work: Composer autoloading, kernel boot with 10 service providers, full widget tree construction, stylesheet compilation, and a multi-second intro animation. The user stares at a blank terminal or a pre-TUI animation screen while the TUI framework is already loaded but the prompt isn't available.

**Estimated current timeline** (cold start, animated intro):

| Phase | Location | Estimated Time |
|-------|----------|---------------|
| Composer autoload | `bin/kosmokrator:9` | 30–80 ms |
| Kernel boot (10 providers) | `Kernel::boot()` | 50–120 ms |
| AgentSessionBuilder::build() — UI init | `AgentSessionBuilder:51` | 10–20 ms |
| TUI widget tree construction | `TuiCoreRenderer::initialize()` | 5–15 ms |
| Intro animation (full) | `AnsiIntro::animate()` | **3–5 seconds** |
| Post-animation pause | `TuiCoreRenderer:283` | **800 ms** |
| LLM client creation | `LlmClientFactory::create()` | 20–50 ms |
| Session DB + history load | `SessionManager` | 10–30 ms |
| System prompt assembly | `InstructionLoader::gather()` | 5–15 ms |
| **Total (animated)** | | **~4–6 seconds** |
| **Total (no animation)** | | **~200–400 ms** |

**Target**: **< 500 ms** from command invocation to interactive prompt (animation skipped or deferred). Animated intro becomes opt-in eye-candy that plays *while* the prompt is already available.

---

## 2. Startup Flow Analysis

### 2.1 Current Sequential Flow

```
bin/kosmokrator
  ├─ require vendor/autoload.php                          ← Composer autoload
  ├─ new Kernel()->boot()                                 ← Full container bootstrap
  │   ├─ loadEnv()
  │   ├─ ConfigServiceProvider::register()                ← YAML + SQLite config
  │   ├─ LoggingServiceProvider::register()
  │   ├─ DatabaseServiceProvider::register()              ← SQLite init, YAML→SQLite migration
  │   ├─ CoreServiceProvider::register()
  │   ├─ LlmServiceProvider::register()                   ← PrismServiceProvider + relay setup
  │   ├─ IntegrationServiceProvider::register()
  │   ├─ ToolServiceProvider::register()                  ← 15+ tool classes
  │   ├─ SessionServiceProvider::register()
  │   ├─ EventServiceProvider::register()
  │   └─ AgentServiceProvider::register()
  │
  └─ Console::run()
      └─ AgentCommand::execute()
          └─ AgentSessionBuilder::build()
              ├─ new UIManager()                          ← Renderer selection
              ├─ $ui->initialize()                        ← Widget tree + Tui::start()
              ├─ $ui->renderIntro($animated)              ← BLOCKING: 3–5s animation
              ├─ $ui->showWelcome()                       ← Orrery + tutorial widgets
              ├─ LlmClientFactory::create()               ← HTTP client + auth validation
              ├─ SessionManager::setProject()             ← SQLite query
              ├─ InstructionLoader::gather()              ← Filesystem scan for instructions
              ├─ LuaDocService::getNamespaceSummary()     ← Lua doc aggregation
              ├─ ContextPipelineFactory::create()         ← Compactor/pruner setup
              └─ return AgentSession
                  
  REPL loop begins: $ui->prompt()
```

**Key observation**: Steps after `renderIntro()` (LLM client, session, instructions, context pipeline) are all serial and add ~100–200 ms *after* the animation. If we defer the animation or overlap it with session setup, we save the entire animation duration from the perceived startup time.

### 2.2 Composer Autoloading Overhead

**Source**: `bin/kosmokrator:9` — `require vendor/autoload.php`

The Composer autoload map registers ~200+ classes across the `Kosmokrator\` namespace, plus `Prism\`, `Symfony\Tui\`, `Illuminate\`, `OpenCompany\`, and various tool dependencies. On cold start (no opcache), this typically takes 30–80 ms.

**Mitigations**:
- Production `composer.json` should use `classmap` autoload (already using PSR-4)
- OpCache preloading (if available) eliminates this entirely
- Not worth custom optimization — this is PHP infrastructure

### 2.3 Widget Tree Construction Cost

**Source**: `TuiCoreRenderer::initialize()` (lines 181–269)

The initialize method creates 11 widgets, 2 manager objects, and wires 7+ closures:

```
Tui (with StyleSheet)
├── ContainerWidget(session)
│   ├── ContainerWidget(conversation)
│   ├── HistoryStatusWidget
│   ├── ContainerWidget(overlay)
│   ├── TextWidget(taskBar)
│   ├── ContainerWidget(thinkingBar)
│   ├── EditorWidget(prompt)
│   └── ProgressBarWidget(statusBar)
├── SubagentDisplayManager (with 3 closures)
├── TuiAnimationManager (with 6 closures)
└── TuiModalManager
```

This is lightweight (~5–15 ms) and not a bottleneck. However, the full tree is constructed even though most widgets aren't visible until the user interacts. The `statusBar` is immediately started with a 200K max, and `Keybindings` are parsed upfront.

### 2.4 Intro Animation Blocking Time (CRITICAL)

**Source**: `TuiCoreRenderer::renderIntro()` → `AnsiIntro::animate()`

The animation runs through 9 sequential phases, each with `wait()` delays that check for keypress every 50 ms:

| Phase | Approximate Duration |
|-------|---------------------|
| `phaseStarfield()` | 40–150 stars × 4 ms = 160–600 ms |
| `phaseColumns()` | 30 rows × 8 ms = 240 ms |
| `phaseBorder()` | ~600 ms (border + rows) |
| `phaseLogo()` | 6 lines × chunks × 8 ms = ~500 ms |
| `phaseTitle()` | 200 ms delay + 5 × 80 ms fade = 600 ms |
| `phasePlanets()` | 200 ms + 15 × 60 ms = 1.1 s |
| `phaseTagline()` | 300 ms + char-by-char + 100 ms = ~1.2 s |
| `phaseOrrery()` | orbits + sun + planets = ~1.5 s |
| `phaseZodiac()` | 150 ms + 12 × 80 ms + dots = ~1.5 s |
| `phaseGlow()` | 200 ms + 5 × 60 ms = 500 ms |
| **Post-animation pause** | **800 ms** (`usleep(800000)`) |
| **Static fallback** | `sleep(1)` = 1000 ms |

**Total animated**: ~4–6 seconds of blocking I/O.  
**Total static (no-animation flag)**: `renderStatic()` is instant, but then `sleep(1)` blocks for 1 full second.

The animation is run *before* the REPL loop, *before* LLM client creation, *before* session setup. Everything else waits.

### 2.5 Database Initialization

**Source**: `DatabaseServiceProvider::register()` → `SessionDatabase`, `SettingsRepository`

SQLite databases are opened lazily via container singletons. The actual SQLite connection is deferred until first `make()` call. The `DatabaseServiceProvider` also runs YAML→SQLite key migration on every boot (guarded by a flag check).

**Cost**: 10–30 ms total. Not a bottleneck, but the migration check (`injectSqliteSettings`) does file I/O and YAML parsing on every startup.

### 2.6 LLM Client Initialization

**Source**: `AgentSessionBuilder:56` → `LlmClientFactory::create()`

LLM clients are lazy singletons (resolved via closures), but `LlmClientFactory::create()` is called eagerly in the build path. This creates the Prism manager, resolves the provider config, and potentially validates API keys. For the async client, it also sets up the HTTP client and relay.

**Cost**: 20–50 ms. The actual HTTP connection isn't opened until the first prompt is sent, so this is just object construction.

### 2.7 Theme/Stylesheet Compilation

**Source**: `KosmokratorStyleSheet::create()` (called in `TuiCoreRenderer::initialize():183`)

`KosmokratorStyleSheet::create()` returns a `new StyleSheet([...])` with ~50 style entries. Each entry is a `new Style(...)` with Color objects, Padding objects, and Border objects. The StyleSheet constructor processes all entries and builds an internal lookup map.

**Cost**: ~2–5 ms. Very lightweight, but it runs on every startup and creates ~50+ small objects. Could be cached if we had a serialization path.

---

## 3. Optimization Design

### 3.1 Strategy: Parallel + Deferred Startup

The core insight is that the startup has **two independent tracks**:

1. **UI track**: Initialize TUI → Show intro → Show welcome → Ready for input
2. **Agent track**: Create LLM client → Load session → Build system prompt → Ready for inference

Currently these run serially. The animation blocks the agent track, and the agent setup delays the first prompt.

**Proposed architecture**: Run the agent track asynchronously *while* the animation plays. The TUI prompt becomes available immediately after widget construction; the animation becomes a background decoration rather than a blocking gate.

```
Timeline (animated):
  0ms ──┬─ UI: initialize() + showWelcome()
        │  Prompt READY (user can type)
        ├─ BG: Agent track (LLM, session, context)
        ├─ BG: Intro animation plays
  ~3s ──┴─ Animation completes (or user skipped)

Timeline (no-animation / skip):
  0ms ──┬─ UI: initialize() + showWelcome()
        │  Prompt READY (user can type)
  ~1ms ──┴─ Agent track runs (nearly instant)
```

### 3.2 Lazy Widget Initialization

**Current**: `TuiCoreRenderer::initialize()` creates all 11 widgets, 2 managers, and 7+ closures upfront.

**Proposed**: Split into **essential widgets** (created in `initialize()`) and **deferred widgets** (created on first access).

**Essential (initialize)**:
- `Tui` + `StyleSheet`
- `ContainerWidget(session)` — root
- `ContainerWidget(conversation)` — needed for all content
- `EditorWidget(input)` — needed for prompt
- `ProgressBarWidget(statusBar)` — always visible

**Deferred (lazy)**:
- `HistoryStatusWidget` — only shown when scrolling
- `ContainerWidget(overlay)` — only for modals
- `TextWidget(taskBar)` — only when tasks exist
- `ContainerWidget(thinkingBar)` — only during thinking phase
- `SubagentDisplayManager` — only when subagents run
- `TuiAnimationManager` — only when animation starts
- `TuiModalManager` — only when a modal opens

**Implementation**:

```php
// TuiCoreRenderer.php
private ?TuiAnimationManager $animationManager = null;
private ?SubagentDisplayManager $subagentDisplay = null;
private ?TuiModalManager $modalManager = null;

public function getAnimationManager(): TuiAnimationManager
{
    return $this->animationManager ??= new TuiAnimationManager(
        thinkingBar: $this->getThinkingBar(),
        hasTasksProvider: fn () => $this->taskStore !== null && ! $this->taskStore->isEmpty(),
        // ... closures
    );
}

private function getThinkingBar(): ContainerWidget
{
    if (! isset($this->thinkingBar)) {
        $this->thinkingBar = new ContainerWidget;
        $this->thinkingBar->setId('thinking-bar');
        $this->session->add($this->thinkingBar);
    }
    return $this->thinkingBar;
}
```

**Estimated savings**: 2–5 ms (minor for initialization, but reduces object count and closure count at startup).

### 3.3 Non-Blocking Intro Animation

**Current**: `renderIntro()` calls `$intro->animate()` which blocks for 3–6 seconds via `usleep()` calls. After animation, `usleep(800000)` adds another 800 ms. Everything else waits.

**Proposed**: Three-tier intro strategy:

#### Tier 1: Instant (default for repeat users)
Skip animation entirely. Show the TUI with the welcome screen immediately. The `KOSMOKRATOR_NO_ANIM=1` env var already exists but requires manual opt-in.

```php
// TuiCoreRenderer::renderIntro()
public function renderIntro(bool $animated): void
{
    $noAnim = getenv('KOSMOKRATOR_NO_ANIM') === '1';
    $skipAnim = ! $animated || $noAnim;
    
    if ($skipAnim) {
        // No animation, no sleep — just clear and continue
        echo "\033[2J\033[H";
        $this->tui->requestRender(force: true);
        $this->renderWelcomeWidgets();
        return;
    }
    
    // Animation runs in background — see 3.4
    $this->renderWelcomeWidgets();
    $this->startBackgroundIntro();
}
```

#### Tier 2: Background animation
The animation plays in a forked process or via Revolt event loop, while the TUI is already interactive. If the user starts typing, the animation terminates.

```php
private function startBackgroundIntro(): void
{
    $pid = pcntl_fork();
    if ($pid === 0) {
        // Child: run the animation (writes to STDOUT)
        $intro = new AnsiIntro;
        $intro->animate();
        exit(0);
    }
    // Parent: continue with TUI startup
    // Register cleanup when user types first key
    $this->introPid = $pid;
}
```

**Alternative** (simpler): Use Revolt's `EventLoop::defer()` to run animation phases as microtasks between TUI render cycles. This avoids process forking but requires refactoring `AnsiIntro::animate()` to be non-blocking (phase-based instead of `usleep`-based).

#### Tier 3: Full animation (opt-in)
Keep the current animated intro available via `--animation` flag (or when `kosmokrator.ui.intro_animated` is explicitly `true`). This preserves the "wow factor" for demos and first-time users.

### 3.4 Parallel Agent Track via Revolt

**Current**: Agent session building runs after `renderIntro()` completes.

**Proposed**: Start agent track asynchronously immediately after TUI `initialize()`, overlapping with any intro animation.

```php
// AgentSessionBuilder::build()
public function build(string $rendererPref, bool $animated): AgentSession
{
    $ui = new UIManager($rendererPref);
    $ui->initialize();
    
    // Start agent track async — returns a Suspension we'll resume later
    $agentFuture = \Amp\async(function () use ($ui) {
        return $this->buildAgentTrack($ui);
    });
    
    // Show intro + welcome while agent track builds
    $ui->renderIntro($animated);
    $ui->showWelcome();
    
    // Await agent track (likely already done by now)
    $session = $agentFuture->await();
    
    return $session;
}
```

Since `AgentSessionBuilder::build()` runs inside Symfony Console's `execute()` method (synchronous context), we need to either:
1. Run the REPL loop inside a Revolt EventLoop (it already uses `EventLoop::getSuspension()` in `prompt()`)
2. Or restructure `AgentCommand::execute()` to use `\Amp\run()`

**The REPL already uses Revolt** (`EventLoop::getSuspension()` in `TuiCoreRenderer::prompt()`), so we just need to ensure the outer `execute()` method enters the event loop early enough.

### 3.5 Remove Static Sleep

**Current** (no-animation path):
```php
// TuiCoreRenderer.php:276-279
if ($noAnim || ! $animated) {
    $intro->renderStatic();
    sleep(1);           // ← 1-second sleep for static intro!
    echo "\033[2J\033[H]";
}
```

**Current** (animated path, post-animation):
```php
// TuiCoreRenderer.php:281-285
$skipped = $intro->animate();
if (! $skipped) {
    usleep(800000);     // ← 800ms pause after animation
}
echo "\033[2J\033[H]";
```

**Proposed**: Remove both delays entirely. The static render should be optional (and instant). The post-animation pause is unnecessary — the TUI takes over immediately.

```php
public function renderIntro(bool $animated): void
{
    $noAnim = getenv('KOSMOKRATOR_NO_ANIM') === '1';
    
    if ($noAnim || ! $animated) {
        // Just clear screen and proceed — no sleep, no static render
        echo "\033[2J\033[H";
    } else {
        $intro = new AnsiIntro;
        $skipped = $intro->animate();
        echo "\033[2J\033[H]";
    }
    
    $this->tui->requestRender(force: true);
    $this->renderWelcomeWidgets();
}
```

**Savings**: 800–1000 ms.

### 3.6 Stylesheet Caching

**Current**: `KosmokratorStyleSheet::create()` creates ~50 `Style` objects with `Color`, `Padding`, and `Border` instances on every startup.

**Proposed**: Cache the compiled `StyleSheet` object in a static variable (in-process cache). Since the stylesheet is immutable for the lifetime of the process, there's no need to rebuild it.

```php
class KosmokratorStyleSheet
{
    private static ?StyleSheet $cache = null;
    
    public static function create(): StyleSheet
    {
        return self::$cache ??= self::build();
    }
    
    private static function build(): StyleSheet
    {
        return new StyleSheet([
            // ... all style entries (existing code)
        ]);
    }
}
```

This is a trivial change but eliminates repeated object allocation when `create()` is called multiple times (e.g., during testing). For single-startup, savings are ~2–5 ms.

**Future**: If we want cross-process caching, we could serialize the StyleSheet to a temp file with an mtime check on `KosmokratorStyleSheet.php`. This would require `StyleSheet` to be serializable.

### 3.7 Deferred Service Provider Registration

**Current**: `Kernel::boot()` registers and boots all 10 service providers synchronously.

**Proposed**: Split providers into **critical** (needed before UI) and **deferred** (needed before first prompt):

**Critical** (register synchronously):
1. `ConfigServiceProvider` — needed for everything
2. `LoggingServiceProvider` — needed for error reporting
3. `DatabaseServiceProvider` — needed for settings

**Deferred** (register lazily or in parallel):
4. `CoreServiceProvider` — needed for agent
5. `LlmServiceProvider` — needed for first prompt (but not UI)
6. `IntegrationServiceProvider` — needed for tools
7. `ToolServiceProvider` — needed for agent
8. `SessionServiceProvider` — needed for history
9. `EventServiceProvider` — needed for agent
10. `AgentServiceProvider` — needed for agent

```php
public function boot(): void
{
    $this->container = new LaravelApp($this->basePath);
    Container::setInstance($this->container);
    $this->loadEnv();
    
    // Critical providers first
    $this->registerProviders([
        new ConfigServiceProvider($this->container, $this->basePath),
        new LoggingServiceProvider($this->container),
        new DatabaseServiceProvider($this->container),
    ]);
    
    // UI can be created here (after config + DB)
}

public function bootDeferred(): void
{
    $this->registerProviders([
        new CoreServiceProvider($this->container, $this->basePath),
        new LlmServiceProvider($this->container),
        new IntegrationServiceProvider($this->container, $this->basePath),
        new ToolServiceProvider($this->container),
        new SessionServiceProvider($this->container),
        new EventServiceProvider($this->container),
        new AgentServiceProvider($this->container),
    ]);
}
```

The `bootDeferred()` call would happen in `AgentSessionBuilder::build()` or via Revolt async.

**Savings**: 20–50 ms (provider registration is already fast due to lazy singletons, but YAML parsing and Prism setup add up).

### 3.8 Startup-Time Telemetry

Add a lightweight timing mechanism to measure actual startup phases:

```php
// bin/kosmokrator (already has KOSMOKRATOR_START)
define('KOSMOKRATOR_START', microtime(true));

// In each startup phase:
$timing = new StartupTiming(microtime(true));
$timing->mark('autoload');
$timing->mark('kernel.boot');
$timing->mark('ui.initialize');
$timing->mark('ui.intro');
$timing->mark('agent.build');
$timing->mark('prompt.ready');

// Log on first prompt:
$log->info('Startup timing', $timing->toArray());
```

This lets us measure the actual impact of each optimization and detect regressions.

---

## 4. Implementation Plan

### Phase 1: Quick Wins (1 day)

| # | Change | Est. Savings | Risk |
|---|--------|-------------|------|
| 1.1 | Remove `sleep(1)` from static intro path | 1000 ms | None |
| 1.2 | Remove `usleep(800000)` post-animation pause | 800 ms | Minor (aesthetic) |
| 1.3 | Static stylesheet cache (`self::$cache`) | 2–5 ms | None |
| 1.4 | Default `KOSMOKRATOR_NO_ANIM=1` for repeat starts | 3000–5000 ms | Config change |

**Total Phase 1 savings**: ~1800–5800 ms (effectively eliminates the animation delay).

### Phase 2: Parallel Startup (2–3 days)

| # | Change | Est. Savings | Risk |
|---|--------|-------------|------|
| 2.1 | Wrap `AgentCommand::execute()` in `\Amp\run()` | — | Medium (event loop lifecycle) |
| 2.2 | Run agent track async after `initialize()` | 100–200 ms | Medium (DI container state) |
| 2.3 | Background intro animation via `EventLoop::defer()` | 3000–5000 ms | Medium (terminal I/O conflicts) |
| 2.4 | Startup timing telemetry | — | None |

**Total Phase 2 savings**: 100–200 ms of wall time (agent setup overlaps with animation).

### Phase 3: Lazy Initialization (2–3 days)

| # | Change | Est. Savings | Risk |
|---|--------|-------------|------|
| 3.1 | Lazy widget creation (overlay, thinkingBar, taskBar) | 2–5 ms | Low |
| 3.2 | Lazy TuiAnimationManager creation | 1–2 ms | Low |
| 3.3 | Lazy TuiModalManager creation | 1–2 ms | Low |
| 3.4 | Lazy SubagentDisplayManager creation | 1–2 ms | Low |
| 3.5 | Deferred service provider boot | 20–50 ms | Medium (dependency ordering) |

**Total Phase 3 savings**: ~25–60 ms (minor per-call, but reduces peak memory at startup).

### Phase 4: Advanced (future)

| # | Change | Est. Savings | Risk |
|---|--------|-------------|------|
| 4.1 | OpCache preloading config | 30–80 ms | Server config |
| 4.2 | Compiled classmap autoload | 10–30 ms | Build step needed |
| 4.3 | Cross-process stylesheet cache | 2–5 ms | Serialization needed |
| 4.4 | SQLite connection pooling | 5–15 ms | Architecture change |
| 4.5 | Instruction file caching | 5–10 ms | Cache invalidation |

---

## 5. Risk Analysis

### 5.1 Terminal I/O Conflicts (Phase 2)

Running the intro animation while the TUI is active means two processes write to STDOUT simultaneously. The TUI uses alternate screen buffer and cursor positioning, while the intro uses raw ANSI escape codes.

**Mitigation**: Run the intro *before* `Tui::start()`. The animation writes to the primary screen, then clears it, then the TUI takes over the alternate screen. This is how it works today — we just need to ensure the agent track runs in parallel (via Revolt async), not that the animation and TUI run simultaneously.

### 5.2 DI Container State (Phase 2)

Laravel's Container is mutable state. If we defer provider registration, some bindings may not be available when the UI tries to use them. The UI currently doesn't access the container directly (it receives objects via `setTaskStore()` etc.), so this should be safe.

**Mitigation**: Assert that all UI methods work without agent-track services. Only `prompt()` and rendering should work; LLM calls will naturally wait for the agent track to complete.

### 5.3 Animation Skip Detection (Phase 1)

Changing the default to `KOSMOKRATOR_NO_ANIM=1` (or making the static path the default) changes the user experience. Some users may prefer the animation.

**Mitigation**: Use a smart default:
- First run ever (no config): show animation (onboarding "wow")
- Subsequent runs: skip animation, show TUI instantly
- `--animation` flag or `kosmokrator.ui.intro_animated: true` to re-enable

```php
// AgentCommand::execute()
$hasSeenIntro = $settings->get('global', 'intro.shown') === '1';
$animated = $input->getOption('animation') 
    || ($config->get('kosmokrator.ui.intro_animated', !$hasSeenIntro));
```

---

## 6. Target Metrics

| Metric | Current (no-anim) | Current (animated) | Target |
|--------|-------------------|--------------------|--------|
| Time to interactive prompt | 200–400 ms | 4–6 s | **< 500 ms** |
| Time to first LLM call | 250–450 ms | 4.1–6.1 s | **< 600 ms** |
| Object count at startup | ~100 | ~100 | **< 60** |
| Peak memory at startup | ~12 MB | ~12 MB | **< 10 MB** |

The 500 ms target is achievable with Phase 1 alone (removing the static sleep and post-animation pause). Phase 2 adds resilience by ensuring the agent track never blocks the prompt.

---

## 7. Key Files

| File | Role |
|------|------|
| `bin/kosmokrator` | Entry point, autoload timing |
| `src/Kernel.php` | Container bootstrap, provider registration |
| `src/Command/AgentCommand.php` | REPL lifecycle, animation flag |
| `src/Agent/AgentSessionBuilder.php` | Session construction sequence |
| `src/UI/Tui/TuiCoreRenderer.php` | Widget tree init, `renderIntro()`, `initialize()` |
| `src/UI/Tui/TuiRenderer.php` | Thin coordinator |
| `src/UI/Tui/KosmokratorStyleSheet.php` | Style compilation |
| `src/UI/Ansi/AnsiIntro.php` | Animation phases and timing |
| `src/UI/Tui/TuiAnimationManager.php` | Animation state management |
| `src/Provider/DatabaseServiceProvider.php` | SQLite init, migration |
| `src/Provider/LlmServiceProvider.php` | Prism + relay setup |
