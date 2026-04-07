<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

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
    private float $springInitial = 0.0;

    private function __construct() {}

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
            if (!$this->started && ($anim->fill === FillMode::Backwards || $anim->fill === FillMode::Both)) {
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
