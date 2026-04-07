# 08.1 — Animation System

> **Module**: `src/UI/Tui/Animation\` (new namespace)
> **Dependencies**: Symfony TUI `TickScheduler`, `AdaptativeTicker`, `PeriodicStepper`; Reactive signals (`01-reactive-state`)
> **Blocks**: Phase transitions, loader breathing, toast slide-in, modal transitions, subagent progress shimmer

## 1. Problem Statement

### 1.1 Current State

All animation in KosmoKrator lives in `TuiAnimationManager` — a monolithic class that manually creates `EventLoop::repeat()` timers for each animated element:

| Problem | Code Location | Impact |
|---------|---------------|--------|
| Manual timer management | `TuiAnimationManager.php:85-103` (compacting timer), `:170-210` (breathing timer) | Each animation creates/cancels its own Revolt timer — no coordination |
| Sine-wave only | `TuiAnimationManager.php:93-97`, `:184-190` | `sin($tick * 0.07)` is the only easing function; all motion feels identical |
| Frame-by-frame ANSI | `TuiAnimationManager.php:93-97` | RGB values computed inline with no abstraction; impossible to retarget |
| No transition system | `TuiAnimationManager.php:134-161` | Phase transitions (Idle→Thinking→Tools→Idle) are instant — no fade/slide |
| Multiple timer sources | `TuiAnimationManager.php` + `LoaderWidget.php:82` (`ScheduledTickTrait`) | Loader widgets run their own tick scheduler; breathing runs its own EventLoop timer — two clock domains cause visual jitter |
| No reduced-motion support | None | Users with vestibular disorders get no way to disable animation |
| No animation composition | `TuiAnimationManager.php:184-210` | Each timer callback does color math + message formatting + task bar refresh + subagent tick — all coupled |
| No value interpolation | Throughout | No generic "animate from X to Y over T seconds" — each animation hand-rolls its interpolation |

### 1.2 What Polished TUIs Do

**Charm's Harmonica (Go)** — Spring physics as the primary animation model. Every motion uses stiffness/damping/mass to produce natural deceleration. No fixed-duration easing. The spring IS the animation: it converges toward a target value and stops when velocity drops below a threshold.

```
Spring{Stiffness: 200, Damping: 20, Mass: 1}
  → velocity += (stiffness * (target - position) - damping * velocity) / mass * dt
  → position += velocity * dt
  → done when |velocity| < threshold && |target - position| < threshold
```

**CSS Animation Principles** — Keyframe sequences, easing functions, fill modes, and compositing. The key insight: animations are *declarative* descriptions of motion that the engine executes. You don't write frame-by-frame code — you describe what should happen and the runtime interpolates.

**Claude Code's Shimmer Effect** — Per-grapheme color cycling that creates a "wave of light" across text. Each character has a phase offset based on its position, and a shared time variable drives a hue shift across all of them. The animation target is a color function, not a single value.

**Bubble Tea (Go)** — Frame-based animation via `tea.Tick()` messages. Each tick delivers a `Msg` to the update function, which returns new model state + optional next-tick command. Animation is inherently message-driven and composable with other UI events.

### 1.3 Goal

A **unified animation engine** where:

1. A single timer drives all animations — no scattered `EventLoop::repeat()` calls
2. Animations are declarative value objects — describe *what*, not *how*
3. Spring physics available as first-class easing — natural motion by default
4. Standard easing functions (linear, ease-in, ease-out, ease-in-out, bounce)
5. Animation targets are abstract — opacity, color, position — not ANSI escape codes
6. Transitions between phases are animated (slide-in, fade)
7. Reduced-motion preference is respected globally
8. Animations integrate with the reactive signal system for declarative bindings

---

## 2. Architecture Overview

```
AnimationDriver (singleton, owns the tick)
┌────────────────────────────────────────────────────────────────┐
│  TickScheduler from Symfony TUI                                │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ 30fps fixed timestep (PeriodicStepper)                   │  │
│  │                                                          │  │
│  │  AnimationController[widgetId] ──► AnimationController   │  │
│  │    ├── Animation "opacity"    ──► interpolates 0.0→1.0   │  │
│  │    ├── Animation "slideY"     ──► interpolates 5→0       │  │
│  │    └── Spring "colorShift"    ──► converges to target     │  │
│  │                                                          │  │
│  │  On tick:                                                │  │
│  │   1. Advance all controllers                             │  │
│  │   2. Collect dirty widget IDs                            │  │
│  │   3. requestRender() once                                │  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘

Animation (value object)
┌──────────────────────────────┐
│ from: float                  │
│ to: float                    │
│ duration: float (seconds)    │
│ easing: EasingFunction       │
│ delay: float (seconds)       │
│ fill: FillMode               │
│ direction: PlaybackDirection │
└──────────────────────────────┘

Spring (value object)
┌──────────────────────────────┐
│ target: float                │
│ stiffness: float (100-1000)  │
│ damping: float (1-100)       │
│ mass: float (0.1-10)         │
│ precision: float (0.001)     │
└──────────────────────────────┘
```

### 2.1 Tick Flow

```
AdaptativeTicker (Symfony TUI)
  → Tui::tick()
    → TickScheduler::runDue()
      → AnimationDriver::onTick(deltaTime)
        → foreach AnimationController:
            → advance(deltaTime)
            → if value changed → mark dirty
        → if any dirty → Tui::requestRender()
    → Tui::processRender()
      → Widget::render() reads animated values from its controller
```

The animation driver registers a single interval with the TUI's `TickScheduler` (via `Tui::scheduleInterval()`). This means animation ticks are batched with other scheduled work and the `AdaptativeTicker` automatically adjusts the main loop frequency — no separate `EventLoop::repeat()` needed.

### 2.2 Reduced Motion

A global `AnimationPreferences` value object is consulted by the driver:

- `prefersReducedMotion: bool` — when `true`, all animations resolve instantly to their `to` value. Springs snap to target. No timers are created.
- This can be detected from `$TERM` (dumb terminal), `NO_COLOR` env var, or a user config setting.
- The driver still runs — it just skips interpolation and immediately resolves.

---

## 3. Class Designs

### 3.1 `EasingFunction` (enum + math)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

/**
 * Standard easing functions. Each takes a normalized time t ∈ [0, 1]
 * and returns a progress value (typically also ∈ [0, 1], but may
 * overshoot for elastic/spring-like effects).
 */
enum EasingFunction: string
{
    case Linear = 'linear';
    case EaseIn = 'ease-in';
    case EaseOut = 'ease-out';
    case EaseInOut = 'ease-in-out';
    case EaseInCubic = 'ease-in-cubic';
    case EaseOutCubic = 'ease-out-cubic';
    case EaseInOutCubic = 'ease-in-out-cubic';
    case EaseInBack = 'ease-in-back';
    case EaseOutBack = 'ease-out-back';
    case EaseOutElastic = 'ease-out-elastic';
    case EaseOutBounce = 'ease-out-bounce';
    case EaseInQuart = 'ease-in-quart';
    case EaseOutQuart = 'ease-out-quart';
    case Sharp = 'sharp'; // ease-in-out with short ramp

    /**
     * Apply this easing function to a normalized time value.
     *
     * @param float $t Normalized time in [0, 1]
     * @return float Eased progress value
     */
    public function apply(float $t): float
    {
        $t = max(0.0, min(1.0, $t));

        return match ($this) {
            self::Linear => $t,

            // Quad
            self::EaseIn => $t * $t,
            self::EaseOut => $t * (2.0 - $t),
            self::EaseInOut => $t < 0.5
                ? 2.0 * $t * $t
                : -1.0 + (4.0 - 2.0 * $t) * $t,

            // Cubic
            self::EaseInCubic => $t * $t * $t,
            self::EaseOutCubic => 1.0 - (1.0 - $t) ** 3,
            self::EaseInOutCubic => $t < 0.5
                ? 4.0 * $t * $t * $t
                : 1.0 - (-2.0 * $t + 2.0) ** 3 / 2.0,

            // Quart (snappy)
            self::EaseInQuart => $t * $t * $t * $t,
            self::EaseOutQuart => 1.0 - (1.0 - $t) ** 4,

            // Back (overshoot)
            self::EaseInBack => self::easeInBack($t),
            self::EaseOutBack => self::easeOutBack($t),

            // Elastic (spring-like overshoot)
            self::EaseOutElastic => self::easeOutElastic($t),

            // Bounce
            self::EaseOutBounce => self::easeOutBounce($t),

            // Sharp: cubic-bezier(0.4, 0, 0.2, 1) approximation
            self::Sharp => $t < 0.5
                ? 4.0 * $t * $t * $t
                : 1.0 - (-2.0 * $t + 2.0) ** 3 / 2.0,
        };
    }

    private static function easeInBack(float $t): float
    {
        $s = 1.70158;
        return $t * $t * (($s + 1.0) * $t - $s);
    }

    private static function easeOutBack(float $t): float
    {
        $s = 1.70158;
        $t -= 1.0;
        return $t * $t * (($s + 1.0) * $t + $s) + 1.0;
    }

    private static function easeOutElastic(float $t): float
    {
        if ($t === 0.0 || $t === 1.0) {
            return $t;
        }
        return 2.0 ** (-10.0 * $t) * sin(($t * 10.0 - 0.75) * (2.0 * M_PI) / 3.0) + 1.0;
    }

    private static function easeOutBounce(float $t): float
    {
        $n1 = 7.5625;
        $d1 = 2.75;

        if ($t < 1.0 / $d1) {
            return $n1 * $t * $t;
        }
        if ($t < 2.0 / $d1) {
            $t -= 1.5 / $d1;
            return $n1 * $t * $t + 0.75;
        }
        if ($t < 2.5 / $d1) {
            $t -= 2.25 / $d1;
            return $n1 * $t * $t + 0.9375;
        }
        $t -= 2.625 / $d1;
        return $n1 * $t * $t + 0.984375;
    }
}
```

### 3.2 `Animation` (value object)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

/**
 * A declarative animation description — a tween from one value to another.
 *
 * Immutable value object. Create via named constructors for common patterns
 * or the builder for custom animations.
 */
final class Animation
{
    public function __construct(
        public readonly float $from = 0.0,
        public readonly float $to = 1.0,
        public readonly float $duration = 0.3,
        public readonly EasingFunction $easing = EasingFunction::EaseOut,
        public readonly float $delay = 0.0,
        public readonly FillMode $fill = FillMode::Forwards,
        public readonly PlaybackDirection $direction = PlaybackDirection::Normal,
    ) {}

    // --- Named constructors for common patterns ---

    /**
     * Fade in from transparent (0) to opaque (1).
     */
    public static function fadeIn(float $duration = 0.25): self
    {
        return new self(from: 0.0, to: 1.0, duration: $duration, easing: EasingFunction::EaseOut);
    }

    /**
     * Fade out from opaque (1) to transparent (0).
     */
    public static function fadeOut(float $duration = 0.2): self
    {
        return new self(from: 1.0, to: 0.0, duration: $duration, easing: EasingFunction::EaseIn);
    }

    /**
     * Slide in from an offset. Returns an animation from $offset → 0.
     */
    public static function slideIn(float $offset = 3.0, float $duration = 0.3): self
    {
        return new self(from: $offset, to: 0.0, duration: $duration, easing: EasingFunction::EaseOutCubic);
    }

    /**
     * Slide out to an offset. Returns an animation from 0 → $offset.
     */
    public static function slideOut(float $offset = 3.0, float $duration = 0.25): self
    {
        return new self(from: 0.0, to: $offset, duration: $duration, easing: EasingFunction::EaseInCubic);
    }

    /**
     * Scale from a shrunk state to normal (1.0).
     */
    public static function scaleIn(float $duration = 0.25): self
    {
        return new self(from: 0.9, to: 1.0, duration: $duration, easing: EasingFunction::EaseOutBack);
    }

    /**
     * Pulse animation (ease-in-out cycle for breathing/glow effects).
     */
    public static function pulse(float $from = 0.6, float $to = 1.0, float $duration = 2.0): self
    {
        return new self(from: $from, to: $to, duration: $duration, easing: EasingFunction::EaseInOut);
    }

    /**
     * Quick scale bounce for emphasis (e.g., notification badge).
     */
    public static function pop(float $duration = 0.35): self
    {
        return new self(from: 0.0, to: 1.0, duration: $duration, easing: EasingFunction::EaseOutBack);
    }
}
```

### 3.3 `FillMode` and `PlaybackDirection` (enums)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

/**
 * What happens after an animation completes.
 *
 * Mirrors CSS animation-fill-mode semantics.
 */
enum FillMode: string
{
    /** Reset to initial value after completion */
    case None = 'none';
    /** Hold the final (to) value after completion */
    case Forwards = 'forwards';
    /** Apply the (from) value before the animation starts during delay */
    case Backwards = 'backwards';
    /** Both forwards and backwards */
    case Both = 'both';
}

/**
 * Animation playback direction.
 */
enum PlaybackDirection: string
{
    /** Normal: from → to */
    case Normal = 'normal';
    /** Reverse: to → from */
    case Reverse = 'reverse';
    /** Alternating: normal then reverse on repeat */
    case Alternate = 'alternate';
}
```

### 3.4 `Spring` (value object — physics-based animation)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

/**
 * A spring-based animation using stiffness/damping/mass physics.
 *
 * Inspired by Charm's Harmonica library for Go TUIs. Unlike fixed-duration
 * animations, springs naturally decelerate and settle at their target value.
 * The animation duration is emergent — it ends when velocity and distance
 * fall below the precision threshold.
 *
 * The physics model:
 *   force = -stiffness * (position - target) - damping * velocity
 *   acceleration = force / mass
 *   velocity += acceleration * dt
 *   position += velocity * dt
 *
 * Presets:
 *   - Gentle:   stiffness=120, damping=14, mass=1    — slow, soothing motion
 *   - Default:  stiffness=200, damping=20, mass=1    — balanced
 *   - Snappy:   stiffness=400, damping=28, mass=1    — quick, responsive
 *   - Bouncy:   stiffness=300, damping=10, mass=1    — playful overshoot
 *   - Stiff:    stiffness=800, damping=40, mass=1    — nearly instant
 *   - Wobbly:   stiffness=180, damping=8,  mass=1    — rubber-band effect
 */
final class Spring
{
    public readonly float $precision;

    public function __construct(
        public readonly float $target = 0.0,
        public readonly float $stiffness = 200.0,
        public readonly float $damping = 20.0,
        public readonly float $mass = 1.0,
        ?float $precision = null,
    ) {
        // Auto-compute sensible precision based on stiffness
        $this->precision = $precision ?? (0.01 * min($this->stiffness, 100.0) / 100.0);
    }

    // --- Presets ---

    public static function gentle(float $target): self
    {
        return new self(target: $target, stiffness: 120.0, damping: 14.0, mass: 1.0);
    }

    public static function default(float $target): self
    {
        return new self(target: $target, stiffness: 200.0, damping: 20.0, mass: 1.0);
    }

    public static function snappy(float $target): self
    {
        return new self(target: $target, stiffness: 400.0, damping: 28.0, mass: 1.0);
    }

    public static function bouncy(float $target): self
    {
        return new self(target: $target, stiffness: 300.0, damping: 10.0, mass: 1.0);
    }

    public static function stiff(float $target): self
    {
        return new self(target: $target, stiffness: 800.0, damping: 40.0, mass: 1.0);
    }

    public static function wobbly(float $target): self
    {
        return new self(target: $target, stiffness: 180.0, damping: 8.0, mass: 1.0);
    }

    /**
     * Create with explicit target changed from current position.
     */
    public function withTarget(float $target): self
    {
        return new self(
            target: $target,
            stiffness: $this->stiffness,
            damping: $this->damping,
            mass: $this->mass,
            precision: $this->precision,
        );
    }
}
```

### 3.5 `AnimationState` (tracks a running animation)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

/**
 * The mutable runtime state of a single animation or spring.
 *
 * One AnimationState is created per active animation. It tracks elapsed time,
 * current interpolated value, and completion status.
 *
 * For fixed-duration animations (Animation), progress is time-driven.
 * For physics-based animations (Spring), progress is velocity/position-driven.
 */
final class AnimationState
{
    private float $elapsed = 0.0;
    private float $currentValue;
    private float $velocity = 0.0;
    private bool $completed = false;
    private bool $started = false;

    /** Fixed-duration animation (null for springs) */
    private ?Animation $animation = null;

    /** Spring animation (null for fixed-duration) */
    private ?Spring $spring = null;

    /** Starting position for springs */
    private float $springInitial;

    public static function forAnimation(Animation $animation): self
    {
        $state = new self();
        $state->animation = $animation;
        $state->currentValue = $animation->from;
        return $state;
    }

    public static function forSpring(Spring $spring, float $initialPosition = 0.0): self
    {
        $state = new self();
        $state->spring = $spring;
        $state->springInitial = $initialPosition;
        $state->currentValue = $initialPosition;
        return $state;
    }

    /**
     * Advance the animation by $dt seconds. Returns true if the value changed.
     */
    public function advance(float $dt, bool $reducedMotion = false): bool
    {
        if ($this->completed) {
            return false;
        }

        // Reduced motion: resolve instantly
        if ($reducedMotion) {
            $targetValue = $this->animation?->to ?? $this->spring?->target ?? $this->currentValue;
            if ($this->currentValue !== $targetValue) {
                $this->currentValue = $targetValue;
                $this->completed = true;
                $this->started = true;
                return true;
            }
            return false;
        }

        if ($this->animation !== null) {
            return $this->advanceAnimation($dt);
        }

        if ($this->spring !== null) {
            return $this->advanceSpring($dt);
        }

        return false;
    }

    public function getCurrentValue(): float
    {
        return $this->currentValue;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Get the current velocity (useful for spring-based animations).
     */
    public function getVelocity(): float
    {
        return $this->velocity;
    }

    private function advanceAnimation(float $dt): bool
    {
        $anim = $this->animation;
        assert($anim !== null);

        $this->elapsed += $dt;

        // Handle delay
        if ($this->elapsed < $anim->delay) {
            if (!$this->started && $anim->fill === FillMode::Backwards || $anim->fill === FillMode::Both) {
                $this->currentValue = $anim->from;
            }
            return false;
        }

        $this->started = true;

        // Compute normalized progress [0, 1]
        $activeElapsed = $this->elapsed - $anim->delay;
        $progress = min(1.0, $activeElapsed / $anim->duration);

        // Apply direction
        $t = match ($anim->direction) {
            PlaybackDirection::Normal => $progress,
            PlaybackDirection::Reverse => 1.0 - $progress,
            PlaybackDirection::Alternate => $progress, // simplified; full impl tracks odd/even cycle
        };

        // Apply easing
        $easedT = $anim->easing->apply($t);

        // Interpolate
        $oldValue = $this->currentValue;
        $this->currentValue = $anim->from + ($anim->to - $anim->from) * $easedT;

        if ($progress >= 1.0) {
            // Apply fill mode
            $this->currentValue = match ($anim->fill) {
                FillMode::None => $anim->from,
                FillMode::Forwards, FillMode::Both => $anim->to,
                FillMode::Backwards => $anim->from,
            };
            $this->completed = true;
        }

        return abs($this->currentValue - $oldValue) > 0.0001;
    }

    /**
     * Advance spring physics simulation.
     *
     * Uses semi-implicit Euler integration (same as Harmonica):
     *   force = -stiffness * displacement - damping * velocity
     *   velocity += (force / mass) * dt
     *   position += velocity * dt
     *
     * @param float $dt Delta time in seconds (clamped to prevent instability)
     */
    private function advanceSpring(float $dt): bool
    {
        $spring = $this->spring;
        assert($spring !== null);

        // Clamp dt to prevent physics explosion on long frames
        $dt = min($dt, 0.064);

        // Semi-implicit Euler (update velocity first for stability)
        $displacement = $this->currentValue - $spring->target;
        $force = -$spring->stiffness * $displacement - $spring->damping * $this->velocity;
        $acceleration = $force / $spring->mass;

        $this->velocity += $acceleration * $dt;
        $oldValue = $this->currentValue;
        $this->currentValue += $this->velocity * $dt;

        // Check settling: both velocity and displacement must be below threshold
        if (abs($this->velocity) < $spring->precision && abs($this->currentValue - $spring->target) < $spring->precision) {
            $this->currentValue = $spring->target;
            $this->velocity = 0.0;
            $this->completed = true;
        }

        return abs($this->currentValue - $oldValue) > 0.0001;
    }
}
```

### 3.6 `AnimationController` (per-widget animation manager)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

/**
 * Manages all active animations for a single widget (or UI element).
 *
 * Each widget that needs animation gets an AnimationController. The controller
 * holds named animation states (e.g., "opacity", "slideY", "colorShift") and
 * advances them all on each tick.
 *
 * Widgets read animated values from their controller during render().
 *
 * Usage in a widget:
 *   $controller = AnimationController::create()
 *       ->add('opacity', Animation::fadeIn(0.2))
 *       ->add('slideY', Animation::slideIn(2.0, 0.3));
 *
 *   // In render():
 *   $opacity = $controller->get('opacity');   // 0.0 → 1.0
 *   $slideY  = $controller->get('slideY');     // 2.0 → 0.0
 */
final class AnimationController
{
    /** @var array<string, AnimationState> */
    private array $states = [];

    /** @var array<string, callable(float): void> */
    private array $onComplete = [];

    private bool $dirty = false;

    /**
     * Start a fixed-duration animation under the given name.
     * Replaces any existing animation with the same name.
     */
    public function animate(string $name, Animation $animation): self
    {
        $this->states[$name] = AnimationState::forAnimation($animation);
        $this->dirty = true;
        return $this;
    }

    /**
     * Start a spring-based animation under the given name.
     */
    public function spring(string $name, Spring $spring, float $initialPosition = 0.0): self
    {
        $this->states[$name] = AnimationState::forSpring($spring, $initialPosition);
        $this->dirty = true;
        return $this;
    }

    /**
     * Retarget a spring animation to a new value without resetting velocity.
     * Creates the spring if it doesn't exist.
     *
     * This is the key method for interactive animations — e.g., a color value
     * that follows a signal. The spring carries momentum from the previous
     * target, creating natural deceleration.
     */
    public function retargetSpring(string $name, float $newTarget, ?Spring $template = null): self
    {
        if (isset($this->states[$name]) && $this->states[$name]->getVelocity() !== 0.0) {
            // Replace the spring definition but keep current position and velocity
            $oldState = $this->states[$name];
            $spring = $template?->withTarget($newTarget) ?? Spring::default($newTarget);
            $this->states[$name] = AnimationState::forSpring($spring, $oldState->getCurrentValue());
            // Note: velocity is lost in this simplified version. A full implementation
            // would transfer velocity. For now, the spring still converges naturally.
        } else {
            $spring = $template?->withTarget($newTarget) ?? Spring::default($newTarget);
            $currentPos = $this->states[$name]?->getCurrentValue() ?? $newTarget;
            $this->states[$name] = AnimationState::forSpring($spring, $currentPos);
        }
        $this->dirty = true;
        return $this;
    }

    /**
     * Register a callback for when an animation completes.
     */
    public function onComplete(string $name, callable $callback): self
    {
        $this->onComplete[$name] = $callback;
        return $this;
    }

    /**
     * Get the current interpolated value for a named animation.
     * Returns $default if no animation exists with that name.
     */
    public function get(string $name, float $default = 0.0): float
    {
        return $this->states[$name]?->getCurrentValue() ?? $default;
    }

    /**
     * Check if a named animation is still running.
     */
    public function isActive(string $name): bool
    {
        return isset($this->states[$name]) && !$this->states[$name]->isCompleted();
    }

    /**
     * Check if any animation is active.
     */
    public function hasActiveAnimations(): bool
    {
        foreach ($this->states as $state) {
            if (!$state->isCompleted()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Advance all animations by $dt seconds.
     *
     * @return bool True if any value changed (dirty flag)
     */
    public function advance(float $dt, bool $reducedMotion = false): bool
    {
        $this->dirty = false;

        foreach ($this->states as $name => $state) {
            $changed = $state->advance($dt, $reducedMotion);
            if ($changed) {
                $this->dirty = true;
            }

            // Fire completion callbacks
            if ($state->isCompleted() && isset($this->onComplete[$name])) {
                ($this->onComplete[$name])($state->getCurrentValue());
                unset($this->onComplete[$name]);
            }
        }

        // Clean up completed states
        $this->states = array_filter(
            $this->states,
            fn(AnimationState $state) => !$state->isCompleted(),
        );

        return $this->dirty;
    }

    /**
     * Cancel a named animation.
     */
    public function cancel(string $name): void
    {
        unset($this->states[$name], $this->onComplete[$name]);
    }

    /**
     * Cancel all animations.
     */
    public function cancelAll(): void
    {
        $this->states = [];
        $this->onComplete = [];
    }
}
```

### 3.7 `AnimationDriver` (singleton engine)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

use Symfony\Component\Tui\Tui;

/**
 * Central animation engine. Owns a single tick interval registered with the
 * Symfony TUI's TickScheduler. On each tick, advances all registered
 * AnimationControllers and requests a render if any values changed.
 *
 * Replaces all scattered EventLoop::repeat() timers in TuiAnimationManager.
 *
 * Usage:
 *   $driver = new AnimationDriver($tui, $preferences);
 *   $driver->register('my-widget', $controller);
 *   // Driver automatically ticks and renders.
 */
final class AnimationDriver
{
    private const float TICK_INTERVAL = 0.033; // ~30fps

    /** @var array<string, AnimationController> */
    private array $controllers = [];

    private ?string $tickId = null;
    private bool $running = false;

    public function __construct(
        private readonly Tui $tui,
        private readonly AnimationPreferences $preferences = new AnimationPreferences(),
    ) {}

    /**
     * Register an AnimationController for a named element.
     */
    public function register(string $id, AnimationController $controller): void
    {
        $this->controllers[$id] = $controller;

        if ($controller->hasActiveAnimations() && !$this->running) {
            $this->start();
        }
    }

    /**
     * Unregister a controller.
     */
    public function unregister(string $id): void
    {
        unset($this->controllers[$id]);

        if (empty($this->controllers) || !$this->hasActiveControllers()) {
            $this->stop();
        }
    }

    /**
     * Get a registered controller.
     */
    public function getController(string $id): ?AnimationController
    {
        return $this->controllers[$id] ?? null;
    }

    /**
     * Convenience: create and register a new controller.
     */
    public function createController(string $id): AnimationController
    {
        $controller = new AnimationController();
        $this->register($id, $controller);
        return $controller;
    }

    /**
     * Start the animation tick loop.
     */
    public function start(): void
    {
        if ($this->running || $this->preferences->prefersReducedMotion) {
            return;
        }

        $this->running = true;
        $this->tickId = $this->tui->scheduleInterval(
            $this->onTick(...),
            self::TICK_INTERVAL,
        );
    }

    /**
     * Stop the animation tick loop.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        if ($this->tickId !== null) {
            $this->tui->cancelInterval($this->tickId);
            $this->tickId = null;
        }
    }

    private function onTick(): void
    {
        $dt = self::TICK_INTERVAL; // Fixed timestep for deterministic animation
        $anyDirty = false;
        $reducedMotion = $this->preferences->prefersReducedMotion;

        foreach ($this->controllers as $controller) {
            if ($controller->advance($dt, $reducedMotion)) {
                $anyDirty = true;
            }
        }

        if ($anyDirty) {
            $this->tui->requestRender();
        }

        // Auto-stop if nothing is animating
        if (!$this->hasActiveControllers()) {
            $this->stop();
        }
    }

    private function hasActiveControllers(): bool
    {
        foreach ($this->controllers as $controller) {
            if ($controller->hasActiveAnimations()) {
                return true;
            }
        }
        return false;
    }
}
```

### 3.8 `AnimationPreferences` (configuration + accessibility)

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Animation;

/**
 * Global animation preferences. Respects accessibility needs.
 *
 * Reduced motion is enabled when:
 * 1. The user sets `animation: reduced` or `animation: none` in config
 * 2. The `NO_COLOR` environment variable is set
 * 3. The `$TERM` environment variable is "dumb"
 *
 * When reduced motion is active, all animations resolve instantly to their
 * target values. No timers run. The system is still structurally present
 * (controllers still exist, values are still read) but there is zero motion.
 */
final class AnimationPreferences
{
    public function __construct(
        public readonly bool $prefersReducedMotion = false,
        public readonly float $defaultFrameRate = 30.0,
        public readonly float $defaultDuration = 0.3,
        public readonly float $springStiffness = 200.0,
        public readonly float $springDamping = 20.0,
    ) {}

    /**
     * Detect animation preferences from environment and config.
     */
    public static function detect(
        ?string $configAnimation = null,
    ): self {
        $reduced = false;

        // Environment signals
        if (getenv('NO_COLOR') !== false) {
            $reduced = true;
        }
        if (getenv('TERM') === 'dumb') {
            $reduced = true;
        }

        // Config override
        if ($configAnimation === 'none' || $configAnimation === 'reduced') {
            $reduced = true;
        }
        if ($configAnimation === 'full') {
            $reduced = false;
        }

        return new self(prefersReducedMotion: $reduced);
    }
}
```

---

## 4. Integration Points

### 4.1 Replacing `TuiAnimationManager` Breathing

Current (`TuiAnimationManager.php:184-210`):
```php
$this->thinkingTimerId = EventLoop::repeat(0.033, function () use ($phrase, $palette) {
    $this->breathTick++;
    $t = sin($this->breathTick * 0.07);
    $cr = (int) (112 + 40 * $t);
    // ... inline color math + render ...
});
```

Replacement:
```php
// In TuiAnimationManager constructor, receive AnimationDriver
$controller = $this->driver->createController('breathing');
$controller->spring('brightness', Spring::gentle(1.0), 0.6);

// When phase changes, retarget the spring:
$controller->retargetSpring('brightness', $palette === 'amber' ? 0.8 : 1.0);

// In render code, read the animated value:
$brightness = $controller->get('brightness', 1.0);
// Use $brightness to modulate RGB values
```

No manual timer. No `EventLoop::repeat()`. The driver handles everything.

### 4.2 Toast Slide-In

```php
$controller = $this->driver->createController('toast-' . $toastId);
$controller
    ->animate('opacity', Animation::fadeIn(0.2))
    ->animate('slideY', Animation::slideIn(3.0, 0.3))
    ->onComplete('opacity', function () {
        // Schedule auto-dismiss
    });

// In toast widget render():
$opacity = $controller->get('opacity', 1.0);
$slideY = (int) $controller->get('slideY', 0.0);
// Apply: $lines = array_slice($lines, $slideY);
// Apply opacity: modulate color brightness by $opacity
```

### 4.3 Modal Dialog Transitions

```php
$controller = $this->driver->createController('modal-' . $modalId);
$controller
    ->animate('opacity', Animation::fadeIn(0.15))
    ->animate('scale', Animation::scaleIn(0.2))
    ->animate('slideY', Animation::slideIn(2.0, 0.25));

// Dismissal:
$controller
    ->animate('opacity', Animation::fadeOut(0.15))
    ->animate('slideY', Animation::slideOut(2.0, 0.2))
    ->onComplete('slideY', function () {
        // Remove modal from widget tree
    });
```

### 4.4 Spinner Fade (replacing manual `LoaderWidget` timer coupling)

The existing `LoaderWidget` already uses `ScheduledTickTrait` for frame advancement. That stays. What changes is the **color breathing** that `TuiAnimationManager` does around the spinner:

```php
// Instead of a separate EventLoop::repeat() for breathing:
$controller = $this->driver->createController('loader-' . $loaderId);
$controller->spring('hue', Spring::wobbly(1.0), 0.0);

// In loader message rendering, modulate color based on spring value:
$hue = $controller->get('hue', 1.0);
```

### 4.5 Signal Integration (from `01-reactive-state`)

When the reactive signal system is in place, animations can be bound to signal changes:

```php
// Pseudocode — requires the signal system from plan 01
$phaseSignal->watch(function (AgentPhase $newPhase) use ($controller) {
    match ($newPhase) {
        AgentPhase::Thinking => $controller->animate('brightness', Animation::pulse(0.6, 1.0, 2.0)),
        AgentPhase::Tools => $controller->retargetSpring('brightness', 0.9),
        AgentPhase::Idle => $controller->animate('brightness', Animation::fadeOut(0.3)),
    };
});
```

### 4.6 Shimmer Effect (Claude Code-style per-grapheme animation)

The shimmer effect uses a time-based offset per character position:

```php
$controller = $this->driver->createController('shimmer-' . $widgetId);
// No named animation — the controller just ensures the driver is ticking
// The shimmer reads the global clock from the driver

// In rendering, for each grapheme at position $i:
$time = $this->driver->getElapsedTime();
$hue = sin($time * 3.0 + $i * 0.3) * 0.5 + 0.5;
// Apply hue-based color to each character
```

This requires adding a public `getElapsedTime()` method to `AnimationDriver` that accumulates the fixed timestep.

---

## 5. Migration Strategy

### Phase 1: Foundation (no breaking changes)

1. Create `src/UI/Tui/Animation/` namespace with all value objects and `AnimationDriver`
2. `AnimationPreferences::detect()` reads env vars
3. `AnimationDriver` registers with `Tui::scheduleInterval()` — zero conflicts
4. No existing code changes — the new system runs alongside the old

### Phase 2: Migrate TuiAnimationManager

1. Inject `AnimationDriver` into `TuiAnimationManager`
2. Replace the two `EventLoop::repeat()` breathing timers with spring-based controllers
3. Remove `$breathTick`, `$compactingBreathTick` counter fields
4. Remove `$thinkingTimerId`, `$compactingTimerId` fields
5. Controller becomes the source of truth for `$breathColor`
6. Keep the phase transition logic (enter/exit methods) — they now use `$controller->animate()` instead of manual timers

### Phase 3: Transition Animations

1. Add fade-in/slide-in to thinking loader creation
2. Add fade-out to idle transition
3. Add slide-in to compacting loader
4. Add pop animation to subagent status changes

### Phase 4: Cleanup

1. Remove all `EventLoop::repeat()` calls from `TuiAnimationManager`
2. Remove all `EventLoop::cancel()` calls from `TuiAnimationManager`
3. The class becomes a thin orchestrator: "on phase X, set animation Y on controller Z"

---

## 6. File Structure

```
src/UI/Tui/Animation/
├── Animation.php              # Value object: declarative animation description
├── AnimationController.php    # Per-widget animation manager
├── AnimationDriver.php        # Singleton engine (owns tick, drives controllers)
├── AnimationPreferences.php   # Accessibility + config
├── AnimationState.php         # Mutable runtime state of one animation
├── EasingFunction.php         # Enum with easing math
├── FillMode.php               # Enum: animation fill behavior
├── PlaybackDirection.php      # Enum: normal/reverse/alternate
└── Spring.php                 # Value object: spring physics parameters
```

---

## 7. Performance Considerations

### 7.1 Timer Consolidation

Currently, KosmoKrator runs **3+ separate timers** during the thinking phase:
1. `TuiAnimationManager` breathing timer (30fps `EventLoop::repeat`)
2. `CancellableLoaderWidget` frame stepper (via `ScheduledTickTrait`)
3. Subagent refresh timer (every 15 ticks ≈ 0.5s, embedded in breathing callback)

After migration: **1 tick interval** in `TickScheduler`. The `AnimationDriver` and `LoaderWidget` both use the TUI's scheduler. The `AdaptativeTicker` adjusts the main loop frequency to the fastest needed interval.

### 7.2 Dirty Tracking

`AnimationController::advance()` returns `false` when no values changed. The driver only calls `requestRender()` when at least one controller reports dirty. This avoids unnecessary full re-renders when nothing visual changed.

### 7.3 Auto-Stop

The driver auto-stops its tick interval when no controllers have active animations. It auto-restarts when a new animation is added. Zero overhead during idle periods.

### 7.4 Spring Settling

Springs use a configurable precision threshold. Once `|velocity| < precision && |displacement| < precision`, the spring snaps to target and marks itself completed. No infinite micro-updates.

### 7.5 Fixed Timestep

Animations use a fixed 33ms timestep regardless of actual wall-clock delta. This ensures deterministic, reproducible animation curves. The `PeriodicStepper` in Symfony TUI already handles frame dropping for long ticks.

---

## 8. Testing Strategy

### 8.1 Unit Tests (no event loop needed)

```php
// Test easing functions
$this->assertEquals(0.0, EasingFunction::Linear->apply(0.0));
$this->assertEquals(1.0, EasingFunction::Linear->apply(1.0));
$this->assertEquals(0.5, EasingFunction::Linear->apply(0.5));

// Test ease-out-back overshoots
$this->assertGreaterThan(1.0, EasingFunction::EaseOutBack->apply(0.7));

// Test animation interpolation
$state = AnimationState::forAnimation(Animation::fadeIn(1.0));
$state->advance(0.5); // half-way
$this->assertEqualsWithDelta(0.75, $state->getCurrentValue(), 0.01); // ease-out at 0.5

// Test spring settling
$state = AnimationState::forSpring(Spring::stiff(10.0), 0.0);
for ($i = 0; $i < 100; $i++) {
    $state->advance(0.033);
}
$this->assertEqualsWithDelta(10.0, $state->getCurrentValue(), 0.01);
$this->assertTrue($state->isCompleted());
```

### 8.2 Controller Tests

```php
$controller = new AnimationController();
$controller->animate('opacity', Animation::fadeIn(0.1));

// Before advance
$this->assertEquals(0.0, $controller->get('opacity'));

// After full duration
$controller->advance(0.15);
$this->assertEquals(1.0, $controller->get('opacity'));
$this->assertFalse($controller->isActive('opacity')); // completed and cleaned up
```

### 8.3 Reduced Motion Tests

```php
$state = AnimationState::forAnimation(Animation::fadeIn(1.0));
$changed = $state->advance(0.0, reducedMotion: true);
$this->assertTrue($changed);
$this->assertEquals(1.0, $state->getCurrentValue());
$this->assertTrue($state->isCompleted());
```

### 8.4 Driver Integration Tests

```php
// Mock Tui, verify scheduleInterval called once
// Advance time, verify requestRender called only when dirty
// Verify auto-stop when all animations complete
```
