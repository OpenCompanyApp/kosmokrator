<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

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
     *
     * @param string|null $configAnimation Value from user config: 'none', 'reduced', or 'full'
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

        // Config override (takes precedence)
        if ($configAnimation === 'none' || $configAnimation === 'reduced') {
            $reduced = true;
        }
        if ($configAnimation === 'full') {
            $reduced = false;
        }

        return new self(prefersReducedMotion: $reduced);
    }
}
