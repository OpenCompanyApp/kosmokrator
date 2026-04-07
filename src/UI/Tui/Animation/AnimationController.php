<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

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
 *   $controller = new AnimationController();
 *   $controller
 *       ->animate('opacity', Animation::fadeIn(0.2))
 *       ->animate('slideY', Animation::slideIn(2.0, 0.3));
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
        $spring = $template?->withTarget($newTarget) ?? Spring::default($newTarget);

        if (isset($this->states[$name]) && !$this->states[$name]->isCompleted()) {
            // Preserve current position; the spring converges from there
            $currentPos = $this->states[$name]->getCurrentValue();
            $this->states[$name] = AnimationState::forSpring($spring, $currentPos);
        } else {
            $currentPos = isset($this->states[$name]) ? $this->states[$name]->getCurrentValue() : $newTarget;
            $this->states[$name] = AnimationState::forSpring($spring, $currentPos);
        }
        $this->dirty = true;
        return $this;
    }

    /**
     * Register a callback for when an animation completes.
     *
     * @param callable(float): void $callback Receives the final value
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
        if (!isset($this->states[$name])) {
            return $default;
        }
        return $this->states[$name]->getCurrentValue();
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
