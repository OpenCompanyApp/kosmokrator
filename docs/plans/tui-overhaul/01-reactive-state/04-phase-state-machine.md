# 04 — PhaseStateMachine

> Extract the ad-hoc phase transitions in `TuiAnimationManager` into a formal state machine
> with an enum-backed transition table, invalid-transition guards, and signal-based side effects.

## Problem

`TuiAnimationManager::setPhase()` (`src/UI/Tui/TuiAnimationManager.php:168`) is a
thin dispatcher: it routes to `enterThinking()`, `enterTools()`, or `enterIdle()` via a
`match` with **no validation** of the transition. The compaction flow is entirely
separate (`showCompacting()` / `clearCompacting()`) with no relationship to the phase
enum. This means:

1. **No guard against impossible transitions** — `Idle → Tools`, `Tools → Thinking`,
   and `Compacting → Thinking` all silently pass.
2. **Side effects are closures buried in private methods** — the breathing timer
   (`startBreathingAnimation`), loader lifecycle, and render callbacks are all
   interwoven inside the manager, making it hard to test or extend.
3. **Compaction is not a phase** — `showCompacting()`/`clearCompacting()` manage
   their own timer and loader independently of the phase enum, but they _interact_
   with it (compacting happens while the agent is idle between turns).
4. **No observable transition events** — the rest of the TUI can't react to phase
   changes (e.g., a future `TuiStateStore` can't derive a computed from "current
   phase").

## Current Phase System (as-is)

### Phases

Defined in `src/Agent/AgentPhase.php`:

```php
enum AgentPhase: string
{
    case Thinking = 'thinking';
    case Tools    = 'tools';
    case Idle     = 'idle';
}
```

Compaction is handled separately via `showCompacting()` / `clearCompacting()` — it
runs outside the phase enum entirely.

### Valid Transition Flow (observed from `AgentLoop` + `TuiCoreRenderer`)

```
AgentLoop::runLoop()
  │
  ├─ setPhase(Thinking)          ← before callLlm()
  ├─ setPhase(Tools)             ← after callLlm() returns
  ├─ setPhase(Idle)              ← after tool execution finishes
  │
  └─ (loop repeats)
```

Compaction flow (from `ContextManager::performCompaction()`):

```
ContextManager::performCompaction()
  │
  ├─ showCompacting()            ← before compactor->buildPlan()
  └─ clearCompacting()           ← after plan applied / on error
```

The actual valid transition graph is:

```
Idle ──→ Thinking ──→ Tools ──→ Idle    (main loop)
  │                                  │
  └── showCompacting() ──→ clearCompacting()  (nested within Idle)
```

### Side Effects Per Transition

| From → To       | Side Effects                                                         |
|-----------------|----------------------------------------------------------------------|
| Idle → Thinking | Create `CancellableLoaderWidget` (if no tasks), start blue breathing timer at 30fps, pick random spinner, set `thinkingStartTime`, render |
| Thinking → Tools | Cancel blue timer, start amber breathing timer, keep loader alive, render |
| Tools → Idle    | Cancel breathing timer, cancel compacting timer (if any), destroy loader, clear phrase/breathColor, refresh task bar, cleanup subagents, force render |
| (any) → Idle    | Same as above (enterIdle always does the same cleanup)              |
| showCompacting  | Create compacting loader, start red breathing timer at 30fps, render |
| clearCompacting | Cancel compacting timer, destroy compacting loader, force render    |

### Where Transitions Are Triggered

| Call Site | File | Line |
|-----------|------|------|
| `setPhase(Thinking)` | `AgentLoop.php` | 209 |
| `setPhase(Tools)` | `AgentLoop.php` | 215 |
| `setPhase(Idle)` (via `clearThinking`) | `TuiCoreRenderer.php` | 414 |
| `showCompacting()` | `ContextManager.php` | 166 |
| `clearCompacting()` | `ContextManager.php` | 176,217,230,244 |

## Design

### 1. `Phase` enum with Compacting included

Promote Compacting to a first-class phase:

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

/**
 * Formal TUI phase. Extends AgentPhase with Compacting, which was previously
 * handled outside the phase enum.
 */
enum Phase: string
{
    case Idle      = 'idle';
    case Thinking  = 'thinking';
    case Tools     = 'tools';
    case Compacting = 'compacting';
}
```

### 2. Transition table with guards

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

/**
 * Immutable transition definition.
 */
final readonly class Transition
{
    /**
     * @param Phase $from Source phase
     * @param Phase $to   Target phase
     * @param string $name Human-readable transition name (e.g. "think", "execute", "settle")
     */
    public function __construct(
        public Phase $from,
        public Phase $to,
        public string $name,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

use Kosmokrator\UI\Tui\State\Exception\InvalidTransitionException;

/**
 * Validates and executes phase transitions against a fixed transition table.
 *
 * The transition table encodes the agent lifecycle:
 *
 *   Idle ──think──→ Thinking ──execute──→ Tools ──settle──→ Idle
 *     │                                                    │
 *     └──────compact──→ Compacting ──compactDone──→ Idle
 *
 * Compacting can only start from Idle (between agent turns).
 * Transitions are validated before any side effects run.
 */
final class PhaseStateMachine
{
    private Phase $current;

    /** @var array<string, Transition> keyed by "from->to" */
    private array $transitions = [];

    /** @var array<string, array<TransitionListener>> keyed by transition name */
    private array $listeners = [];

    public function __construct(
        private readonly Phase $initial = Phase::Idle,
    ) {
        $this->current = $initial;
        $this->registerTransitions();
    }

    // ── Public API ──────────────────────────────────────────────────────

    public function current(): Phase
    {
        return $this->current;
    }

    /**
     * Attempt a transition to the given phase.
     *
     * @throws InvalidTransitionException if the transition is not in the table
     */
    public function transition(Phase $target): void
    {
        if ($target === $this->current) {
            return;
        }

        $key = $this->transitionKey($this->current, $target);

        if (!isset($this->transitions[$key])) {
            throw InvalidTransitionException::fromTo($this->current, $target);
        }

        $transition = $this->transitions[$key];
        $from = $this->current;
        $this->current = $target;

        $this->fire($transition, $from, $target);
    }

    /**
     * Check whether a transition to the target phase is valid from current state.
     */
    public function canTransition(Phase $target): bool
    {
        return isset($this->transitions[$this->transitionKey($this->current, $target)]);
    }

    // ── Listener registration ───────────────────────────────────────────

    /**
     * Subscribe a listener to a named transition.
     *
     * Multiple listeners can subscribe to the same transition name.
     * Listeners are invoked in registration order.
     */
    public function on(string $transitionName, TransitionListener $listener): void
    {
        $this->listeners[$transitionName][] = $listener;
    }

    /**
     * Subscribe a listener to ANY transition.
     */
    public function onAny(TransitionListener $listener): void
    {
        $this->listeners['*'][] = $listener;
    }

    // ── Transition table ────────────────────────────────────────────────

    private function registerTransitions(): void
    {
        $this->add('think',       Phase::Idle,      Phase::Thinking);
        $this->add('execute',     Phase::Thinking,  Phase::Tools);
        $this->add('settle',      Phase::Tools,     Phase::Idle);
        $this->add('compact',     Phase::Idle,      Phase::Compacting);
        $this->add('compactDone', Phase::Compacting, Phase::Idle);
    }

    private function add(string $name, Phase $from, Phase $to): void
    {
        $t = new Transition($from, $to, $name);
        $this->transitions[$this->transitionKey($from, $to)] = $t;
    }

    // ── Event dispatch ──────────────────────────────────────────────────

    private function fire(Transition $transition, Phase $from, Phase $to): void
    {
        // Named listeners first
        foreach ($this->listeners[$transition->name] ?? [] as $listener) {
            $listener($transition, $from, $to);
        }

        // Wildcard listeners
        foreach ($this->listeners['*'] ?? [] as $listener) {
            $listener($transition, $from, $to);
        }
    }

    private function transitionKey(Phase $from, Phase $to): string
    {
        return "{$from->value}→{$to->value}";
    }
}
```

### 3. Exception

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State\Exception;

final class InvalidTransitionException extends \LogicException
{
    public static function fromTo(
        \Kosmokrator\UI\Tui\State\Phase $from,
        \Kosmokrator\UI\Tui\State\Phase $to,
    ): self {
        return new self(
            "Invalid phase transition: {$from->value} → {$to->value}",
        );
    }
}
```

### 4. `TransitionListener` callable interface

Rather than a rigid interface, use a callable type alias — this is more ergonomic
for signal subscribers:

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

use Kosmokrator\UI\Tui\State\Phase;

/**
 * @see PhaseStateMachine::on()
 *
 * @param Transition $transition The transition definition
 * @param Phase $from Phase before the transition
 * @param Phase $to   Phase after the transition
 */
type TransitionListener = \Closure(Transition $transition, Phase $from, Phase $to): void;
```

> **Note:** Since PHP 8.4 doesn't support `type` aliases, the actual implementation
> uses a docblock type. The callable signature is:
>
> ```php
> /**
>  * @param Transition $transition
>  * @param Phase $from
>  * @param Phase $to
>  */
> \Closure(Transition $transition, Phase $from, Phase $to): void
> ```

### 5. Side-effect subscribers

Side effects are registered as listeners on the machine, **not embedded in the
machine itself**. This keeps the state machine a pure transition engine and allows
the animation/timer logic to live in `TuiAnimationManager` or a future `TuiEffectRunner`.

```php
// In TuiAnimationManager constructor (or a setup method):
$this->machine->on('think', function (Transition $t, Phase $from, Phase $to) {
    $this->enterThinking();
});

$this->machine->on('execute', function (Transition $t, Phase $from, Phase $to) {
    $this->switchToToolsPalette();
});

$this->machine->on('settle', function (Transition $t, Phase $from, Phase $to) {
    $this->enterIdle();
});

$this->machine->on('compact', function (Transition $t, Phase $from, Phase $to) {
    $this->startCompacting();
});

$this->machine->on('compactDone', function (Transition $t, Phase $from, Phase $to) {
    $this->stopCompacting();
});
```

### 6. Breathing animation timer integration

The breathing timer is decoupled from the phase enum itself. Instead, a
`BreathingAnimationController` listens to transitions and manages the timer:

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

use Kosmokrator\UI\Theme;
use Revolt\EventLoop;

/**
 * Owns the breathing animation timer. Subscribes to phase transitions
 * and switches the color palette accordingly.
 *
 * Palette mapping:
 *   Thinking  → blue  (112,160,208) ± sin modulation
 *   Tools     → amber (200,150,60) ± sin modulation
 *   Compacting → red  (208,48,48) ± sin modulation
 */
final class BreathingAnimationController
{
    private const PALETTES = [
        Phase::Thinking->value => [
            'base_r' => 112, 'range_r' => 40,
            'base_g' => 160, 'range_g' => 40,
            'base_b' => 208, 'range_b' => 47,
        ],
        Phase::Tools->value => [
            'base_r' => 200, 'range_r' => 40,
            'base_g' => 150, 'range_g' => 30,
            'base_b' => 60,  'range_b' => 20,
        ],
        Phase::Compacting->value => [
            'base_r' => 208, 'range_r' => 40,
            'base_g' => 48,  'range_g' => 16,
            'base_b' => 48,  'range_b' => 16,
        ],
    ];

    private ?string $timerId = null;

    private int $tick = 0;

    private ?string $breathColor = null;

    private float $startTime = 0.0;

    public function __construct(
        private readonly PhaseStateMachine $machine,
        /** Called every tick with the new breath color */
        private readonly \Closure $onTick,
    ) {
        // Subscribe to all transitions
        $machine->onAny($this->onPhaseChange(...));
    }

    public function getBreathColor(): ?string
    {
        return $this->breathColor;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getTick(): int
    {
        return $this->tick;
    }

    // ── Internal ────────────────────────────────────────────────────────

    private function onPhaseChange(Transition $transition, Phase $from, Phase $to): void
    {
        $this->stopTimer();

        if ($to === Phase::Idle) {
            $this->breathColor = null;
            $this->tick = 0;

            return;
        }

        $this->startTime = microtime(true);
        $this->tick = 0;
        $this->startTimer($to);
    }

    private function startTimer(Phase $phase): void
    {
        $palette = self::PALETTES[$phase->value] ?? null;

        if ($palette === null) {
            return;
        }

        $this->timerId = EventLoop::repeat(0.033, function () use ($palette) {
            $this->tick++;
            $t = sin($this->tick * 0.07);

            $r = (int) ($palette['base_r'] + $palette['range_r'] * $t);
            $g = (int) ($palette['base_g'] + $palette['range_g'] * $t);
            $b = (int) ($palette['base_b'] + $palette['range_b'] * $t);

            $this->breathColor = Theme::rgb($r, $g, $b);
            ($this->onTick)($this->breathColor, $this->tick, $this->startTime);
        });
    }

    private function stopTimer(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
    }
}
```

### 7. Color palette transitions

The three color palettes are driven by a sin wave at 30fps (`0.033s` interval),
modulating RGB channels:

| Phase      | R (base±range) | G (base±range) | B (base±range) | Visual |
|------------|---------------|----------------|----------------|--------|
| Thinking   | 112 ± 40      | 160 ± 40       | 208 ± 47       | Blue   |
| Tools      | 200 ± 40      | 150 ± 30       | 60 ± 20        | Amber  |
| Compacting | 208 ± 40      | 48 ± 16        | 48 ± 16        | Red    |

The formula per channel: `(int) (base + range * sin(tick * 0.07))`

This produces a ~3s full cycle breathing pulse. The transition from one palette to
another is **instant** (no cross-fade) — the new palette starts from `tick = 0`,
so the sin wave begins at 0 and smoothly ramps up.

### 8. Refactored `TuiAnimationManager`

The animation manager becomes a thin coordinator that wires the state machine,
breathing controller, and loader lifecycle together:

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\DeferredCancellation;
use Kosmokrator\UI\Tui\State\BreathingAnimationController;
use Kosmokrator\UI\Tui\State\Phase;
use Kosmokrator\UI\Tui\State\PhaseStateMachine;
use Kosmokrator\UI\Theme;
use Revolt\EventLoop;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Coordinates phase transitions, breathing animation, and loader lifecycle.
 *
 * Delegates transition validation to PhaseStateMachine and color animation
 * to BreathingAnimationController. Owns loader widgets for thinking/compacting.
 */
final class TuiAnimationManager
{
    private readonly PhaseStateMachine $machine;
    private readonly BreathingAnimationController $breathing;
    private ?CancellableLoaderWidget $loader = null;
    private ?CancellableLoaderWidget $compactingLoader = null;
    private ?string $thinkingPhrase = null;
    private int $spinnerIndex = 0;
    private bool $spinnersRegistered = false;

    // ── Spinners & phrases (unchanged) ──────────────────────────────────

    private const THINKING_PHRASES = [
        '◈ Consulting the Oracle at Delphi...',
        '♃ Aligning the celestial spheres...',
        '⚡ Channeling Prometheus\' fire...',
        '♄ Weaving the threads of Fate...',
        '☽ Reading the astral charts...',
        '♂ Invoking the nine Muses...',
        '♆ Traversing the Aether...',
        '♅ Deciphering cosmic glyphs...',
        '⚡ Summoning Athena\'s wisdom...',
        '☉ Attuning to the Music of the Spheres...',
        '♃ Gazing into the cosmic void...',
        '◈ Unraveling the Labyrinth...',
        '♆ Communing with the Titans...',
        '♄ Forging in Hephaestus\' workshop...',
        '☽ Scrying the heavens...',
    ];

    private const SPINNERS = [
        'cosmos' => ['✦', '✧', '⊛', '◈', '⊛', '✧'],
        'planets' => ['☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆'],
        'elements' => ['🜁', '🜂', '🜃', '🜄'],
        'stars' => ['⋆', '✧', '★', '✦', '★', '✧'],
        'ouroboros' => ['◴', '◷', '◶', '◵'],
        'oracle' => ['◉', '◎', '◉', '○', '◎', '○'],
        'runes' => ['ᚠ', 'ᚢ', 'ᚦ', 'ᚨ', 'ᚱ', 'ᚲ', 'ᚷ', 'ᚹ'],
        'fate' => ['⚀', '⚁', '⚂', '⚃', '⚄', '⚅'],
        'sigil' => ['᛭', '⊹', '✳', '✴', '✳', '⊹'],
        'serpent' => ['∿', '≀', '∾', '≀'],
        'eclipse' => ['◐', '◓', '◑', '◒'],
        'hourglass' => ['⧗', '⧖', '⧗', '⧖'],
        'trident' => ['ψ', 'Ψ', 'ψ', '⊥'],
        'aether' => ['·', '∘', '○', '◌', '○', '∘'],
    ];

    private const COMPACTION_PHRASES = [
        '⧫ Condensing the cosmic record...',
        '⧫ Distilling the essence of memory...',
        '⧫ Weaving threads of context...',
        '⧫ Forging a compact chronicle...',
    ];

    // ── Constructor ─────────────────────────────────────────────────────

    /**
     * @param ContainerWidget $thinkingBar
     * @param \Closure(): bool $hasTasksProvider
     * @param \Closure(): bool $hasSubagentActivityProvider
     * @param \Closure(): void $refreshTaskBarCallback
     * @param \Closure(): void $subagentTickCallback
     * @param \Closure(): void $subagentCleanupCallback
     * @param \Closure(): void $renderCallback
     * @param \Closure(): void $forceRenderCallback
     */
    public function __construct(
        private readonly ContainerWidget $thinkingBar,
        private readonly \Closure $hasTasksProvider,
        private readonly \Closure $hasSubagentActivityProvider,
        private readonly \Closure $refreshTaskBarCallback,
        private readonly \Closure $subagentTickCallback,
        private readonly \Closure $subagentCleanupCallback,
        private readonly \Closure $renderCallback,
        private readonly \Closure $forceRenderCallback,
    ) {
        $this->machine = new PhaseStateMachine();
        $this->breathing = new BreathingAnimationController(
            $this->machine,
            $this->onBreathTick(...),
        );

        $this->registerSideEffects();
    }

    // ── Public API ──────────────────────────────────────────────────────

    public function getBreathColor(): ?string
    {
        return $this->breathing->getBreathColor();
    }

    public function getCurrentPhase(): Phase
    {
        return $this->machine->current();
    }

    public function getThinkingPhrase(): ?string
    {
        return $this->thinkingPhrase;
    }

    public function getThinkingStartTime(): float
    {
        return $this->breathing->getStartTime();
    }

    public function getLoader(): ?CancellableLoaderWidget
    {
        return $this->loader;
    }

    /**
     * Transition to a new agent phase (Thinking / Tools / Idle).
     *
     * Compaction is handled separately via beginCompacting() / endCompacting().
     */
    public function setPhase(Phase $phase, ?DeferredCancellation $cancellation = null): void
    {
        // Map Phase::Thinking to the 'think' transition, etc.
        // The machine validates the transition.
        $this->machine->transition($phase);
    }

    /**
     * Start compaction phase. Must be in Idle.
     */
    public function showCompacting(): void
    {
        $this->machine->transition(Phase::Compacting);
    }

    /**
     * End compaction phase. Must be in Compacting.
     */
    public function clearCompacting(): void
    {
        $this->machine->transition(Phase::Idle);
    }

    public function ensureSpinnersRegistered(): void
    {
        if ($this->spinnersRegistered) {
            return;
        }
        foreach (self::SPINNERS as $name => $frames) {
            CancellableLoaderWidget::addSpinner($name, $frames);
        }
        $this->spinnersRegistered = true;
    }

    // ── Side-effect wiring ──────────────────────────────────────────────

    private function registerSideEffects(): void
    {
        // Thinking: create loader + start phrase
        $this->machine->on('think', function (): void {
            $this->createThinkingLoader();
        });

        // Tools: keep loader, breathing palette switches automatically
        // via BreathingAnimationController
        $this->machine->on('execute', function (): void {
            ($this->renderCallback)();
        });

        // Idle: tear down everything
        $this->machine->on('settle', function (): void {
            $this->destroyThinkingLoader();
            $this->thinkingPhrase = null;
            ($this->refreshTaskBarCallback)();
            ($this->subagentCleanupCallback)();
            ($this->forceRenderCallback)();
        });

        // Compacting: create compacting loader
        $this->machine->on('compact', function (): void {
            $this->createCompactingLoader();
        });

        // Compacting done: destroy compacting loader
        $this->machine->on('compactDone', function (): void {
            $this->destroyCompactingLoader();
            ($this->forceRenderCallback)();
        });
    }

    // ── Loader lifecycle ────────────────────────────────────────────────

    private function createThinkingLoader(?DeferredCancellation $cancellation = null): void
    {
        $phrase = self::THINKING_PHRASES[array_rand(self::THINKING_PHRASES)];
        $this->thinkingPhrase = $phrase;
        $hasTasks = ($this->hasTasksProvider)();

        if (!$hasTasks) {
            $this->ensureSpinnersRegistered();

            $spinnerNames = array_keys(self::SPINNERS);
            $spinnerName = $spinnerNames[$this->spinnerIndex % count($spinnerNames)];
            $this->spinnerIndex++;

            $this->loader = new CancellableLoaderWidget($phrase);
            $this->loader->setId('loader');
            $this->loader->setSpinner($spinnerName);
            $this->loader->setIntervalMs(120);
            $this->loader->start();

            $this->loader->onCancel(function () use ($cancellation) {
                $cancellation?->cancel();
            });

            try {
                $this->thinkingBar->add($this->loader);
            } catch (\Throwable) {
                $this->loader->stop();
                $this->loader = null;
            }
        }

        ($this->renderCallback)();
    }

    private function createCompactingLoader(): void
    {
        $phrase = self::COMPACTION_PHRASES[array_rand(self::COMPACTION_PHRASES)];
        $this->ensureSpinnersRegistered();

        $spinnerNames = array_keys(self::SPINNERS);
        $spinnerName = $spinnerNames[$this->spinnerIndex % count($spinnerNames)];
        $this->spinnerIndex++;

        $this->compactingLoader = new CancellableLoaderWidget($phrase);
        $this->compactingLoader->setId('compacting-loader');
        $this->compactingLoader->addStyleClass('compacting');
        $this->compactingLoader->setSpinner($spinnerName);
        $this->compactingLoader->setIntervalMs(120);
        $this->compactingLoader->start();

        try {
            $this->thinkingBar->add($this->compactingLoader);
        } catch (\Throwable) {
            $this->compactingLoader->stop();
            $this->compactingLoader = null;

            return;
        }

        ($this->renderCallback)();
    }

    private function destroyThinkingLoader(): void
    {
        if ($this->loader === null) {
            return;
        }
        $this->loader->setFinishedIndicator('✓');
        $this->loader->stop();
        $this->thinkingBar->remove($this->loader);
        $this->loader = null;
    }

    private function destroyCompactingLoader(): void
    {
        if ($this->compactingLoader === null) {
            return;
        }
        $this->compactingLoader->setFinishedIndicator('✓');
        $this->compactingLoader->stop();
        $this->thinkingBar->remove($this->compactingLoader);
        $this->compactingLoader = null;
    }

    // ── Breathing tick handler ──────────────────────────────────────────

    private function onBreathTick(string $color, int $tick, float $startTime): void
    {
        $phase = $this->machine->current();

        // Update loader message with elapsed time
        if ($phase === Phase::Thinking && $this->loader !== null && $this->thinkingPhrase !== null) {
            $r = Theme::reset();
            $dim = "\033[38;5;245m";
            $message = "{$color}{$this->thinkingPhrase}{$r}";

            if (!($this->hasSubagentActivityProvider)()) {
                $elapsed = (int) (microtime(true) - $startTime);
                $formatted = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $message .= "{$dim} · {$formatted}{$r}";
            }

            $this->loader->setMessage($message);
        }

        if ($phase === Phase::Compacting && $this->compactingLoader !== null) {
            $r = Theme::reset();
            $dim = "\033[38;5;245m";
            // Compacting phrase is set on the loader at creation time;
            // update with elapsed time overlay
            $phrase = self::COMPACTION_PHRASES[0]; // We'll store it
            $elapsed = (int) (microtime(true) - $startTime);
            $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);
            $this->compactingLoader->setMessage("{$color}{$phrase}{$r} {$dim}({$formatted}){$r}");
        }

        // Refresh task bar if tasks exist
        if (($this->hasTasksProvider)()) {
            ($this->refreshTaskBarCallback)();
        }

        // Subagent tree refresh every ~0.5s (every 15th tick at 30fps)
        if ($tick % 15 === 0) {
            ($this->subagentTickCallback)();
        }

        ($this->renderCallback)();
    }
}
```

## Migration Strategy

### Step 1: Introduce `Phase` enum (non-breaking)

Create `src/UI/Tui/State/Phase.php` as a superset of `AgentPhase`. The existing
`AgentPhase` enum remains unchanged in `src/Agent/AgentPhase.php` — it's the domain
enum used by `AgentLoop`. The new `Phase` adds `Compacting` and lives in the TUI
layer.

Add a conversion method or mapping:
```php
// In TuiCoreRenderer or a utility:
public static function fromAgentPhase(AgentPhase $phase): Phase
{
    return match ($phase) {
        AgentPhase::Thinking => Phase::Thinking,
        AgentPhase::Tools    => Phase::Tools,
        AgentPhase::Idle     => Phase::Idle,
    };
}
```

### Step 2: Create state machine + breathing controller

Create `PhaseStateMachine`, `Transition`, `InvalidTransitionException`, and
`BreathingAnimationController` under `src/UI/Tui/State/`.

These are new files with no coupling to existing code.

### Step 3: Refactor `TuiAnimationManager` internals

Replace the ad-hoc `setPhase()` dispatcher with the state machine. The public API
(`setPhase(AgentPhase)`, `showCompacting()`, `clearCompacting()`) remains the same
so `TuiCoreRenderer` doesn't need changes.

### Step 4: Update `TuiCoreRenderer::setPhase()` to use Phase enum

After the internal refactor is stable, update the public API to accept `Phase`
instead of `AgentPhase`, and update `AgentLoop` accordingly.

### Step 5: Connect to TuiStateStore signals (future)

Once the signal system from `01-reactive-state/01-signal-system.md` is in place,
the `PhaseStateMachine` becomes a `Signal<Phase>` and all widgets derive their
display state from computed signals:

```php
$phase = Signal::of(Phase::Idle);
$breathColor = $phase->computed(fn (Phase $p) => /* palette math */);
$loaderVisible = $phase->computed(fn (Phase $p) => $p === Phase::Thinking && !$hasTasks);
```

## Transition Diagram

```
                    ┌──────────┐
          think     │          │    execute
      ┌────────────►│ Thinking ├──────────────┐
      │             │          │              │
      │             └──────────┘              ▼
      │                                  ┌────────┐
  ┌───┴───┐              settle          │        │
  │       │◄─────────────────────────────┤  Tools │
  │ Idle  │                              │        │
  │       │◄─────┐                       └────────┘
  └───┬───┘      │
      │      compactDone
      │          │
      │    ┌─────┴───────┐
      │    │             │
      └───►│ Compacting  │
   compact │             │
           └─────────────┘
```

## File Map

| File | Purpose |
|------|---------|
| `src/UI/Tui/State/Phase.php` | Phase enum (Idle, Thinking, Tools, Compacting) |
| `src/UI/Tui/State/Transition.php` | Readonly transition record |
| `src/UI/Tui/State/PhaseStateMachine.php` | Transition table + guard + listener dispatch |
| `src/UI/Tui/State/BreathingAnimationController.php` | 30fps timer with palette-per-phase |
| `src/UI/Tui/State/Exception/InvalidTransitionException.php` | Thrown on illegal transition |
| `src/UI/Tui/TuiAnimationManager.php` | Refactored: delegates to machine + breathing |

## Open Questions

1. **Compacting phrase storage** — Currently the compacting phrase is chosen randomly
   in `showCompacting()` and used in the timer callback. With the refactored design,
   the phrase needs to be stored as a field so the breathing tick can reference it.
   Proposed: add `private ?string $compactingPhrase = null;` to `TuiAnimationManager`.

2. **Cancellation token threading** — The `DeferredCancellation` for the thinking
   loader's cancel handler is currently passed through `setPhase()`. With the new
   design, the 'think' listener needs access to it. Options:
   - Store it as a field on `TuiAnimationManager` (simple, current approach).
   - Pass it via the transition context (over-engineering for now).

3. **`AgentPhase` vs `Phase` coexistence** — `AgentPhase` is used in `AgentLoop` and
   `CoreRendererInterface`. During migration, `TuiCoreRenderer::setPhase()` maps
   `AgentPhase` → `Phase`. Eventually `AgentPhase` can be deprecated in favor of
   `Phase` with the `Compacting` case added.
