<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Builder;

use Athanor\BatchScope;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Revolt\EventLoop;

/**
 * Single 33ms breathing animation driver.
 *
 * Signal-only: ticks breath counters and computes palette colors.
 * The ThinkingLoaderWidget and CompactingLoaderWidget reactive widgets
 * read these signals and update their CancellableLoaderWidget instances.
 *
 * No longer manages CancellableLoaderWidget instances directly.
 */
final class BreathingDriver
{
    private ?string $timerId = null;

    /** @var \Closure(): void Called every ~0.5s for subagent tree refresh */
    private \Closure $subagentTickCallback;

    /** @var \Closure(): void Renders the TUI */
    private \Closure $renderCallback;

    public function __construct(
        private readonly TuiStateStore $state,
    ) {}

    /**
     * Set the subagent tree refresh callback (called every ~0.5s).
     */
    public function setSubagentTickCallback(\Closure $callback): void
    {
        $this->subagentTickCallback = $callback;
    }

    /**
     * Set the render callback.
     */
    public function setRenderCallback(\Closure $callback): void
    {
        $this->renderCallback = $callback;
    }

    /**
     * Start the breathing driver if not already running.
     */
    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $this->timerId = EventLoop::repeat(0.033, function (): void {
            $this->tick();
        });
    }

    /**
     * Stop the breathing driver.
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
    }

    public function isRunning(): bool
    {
        return $this->timerId !== null;
    }

    /**
     * Execute one tick of the breathing animation.
     *
     * Visible for testing.
     */
    public function tick(): void
    {
        $phase = $this->state->getPhase();
        $hasThinking = ($phase === 'thinking' || $phase === 'tools');
        $hasCompacting = $phase === 'compacting';

        if (! $hasThinking && ! $hasCompacting) {
            return;
        }

        BatchScope::run(function () use ($hasThinking, $hasCompacting): void {
            if ($hasThinking) {
                $this->tickThinking();
            }

            if ($hasCompacting) {
                $this->tickCompacting();
            }
        });

        if (isset($this->renderCallback)) {
            ($this->renderCallback)();
        }
    }

    private function tickThinking(): void
    {
        $this->state->tickBreath();

        $phase = $this->state->getPhase();
        $tick = $this->state->getBreathTick();
        $t = sin($tick * 0.07);

        if ($phase === 'tools') {
            $cr = (int) (200 + 40 * $t);
            $cg = (int) (150 + 30 * $t);
            $cb = (int) (60 + 20 * $t);
        } else {
            $cr = (int) (112 + 40 * $t);
            $cg = (int) (160 + 40 * $t);
            $cb = (int) (208 + 47 * $t);
        }

        $breathColor = Theme::rgb($cr, $cg, $cb);
        $this->state->setBreathColor($breathColor);

        // Live subagent tree — refresh every ~0.5s
        if ($tick % 15 === 0 && isset($this->subagentTickCallback)) {
            ($this->subagentTickCallback)();
        }
    }

    private function tickCompacting(): void
    {
        $this->state->tickCompactingBreath();
    }
}
