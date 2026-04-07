<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

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
    case Sharp = 'sharp';

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
