<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

/**
 * A declarative animation description — a tween from one value to another.
 *
 * Immutable value object. Create via named constructors for common patterns
 * or the constructor for custom animations.
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
