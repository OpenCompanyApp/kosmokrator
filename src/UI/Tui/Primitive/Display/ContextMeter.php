<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Display;

use Athanor\Computed;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Reactive context usage meter — colored progress bar.
 *
 * Reads a Computed<float> (0–100) and renders a bar whose color
 * shifts from green → yellow → red as usage increases.
 *
 * Usage:
 *   ContextMeter::of($state->contextPercentComputed())->width(20)
 */
final class ContextMeter extends ReactiveWidget
{
    private float $percent = 0.0;

    private int $barWidth = 20;

    private readonly Computed $percentComputed;

    private function __construct(Computed $percent)
    {
        $this->percentComputed = $percent;
    }

    public static function of(Computed $percent): self
    {
        return new self($percent);
    }

    public function width(int $width): self
    {
        $this->barWidth = $width;

        return $this;
    }

    public function syncFromSignals(): bool
    {
        $new = $this->percentComputed->get();

        if ($new === $this->percent) {
            return false;
        }

        $this->percent = $new;

        return true;
    }

    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $color = Theme::contextColor(min(1.0, $this->percent / 100.0));

        $filled = (int) round($this->percent / 100.0 * $this->barWidth);
        $empty = $this->barWidth - $filled;

        $bar = $color.str_repeat('━', max(0, $filled)).$dim.str_repeat('─', max(0, $empty)).$r;

        return [$bar];
    }
}
