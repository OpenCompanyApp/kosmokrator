<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

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
     * Create a copy with a new target value, preserving physical parameters.
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
