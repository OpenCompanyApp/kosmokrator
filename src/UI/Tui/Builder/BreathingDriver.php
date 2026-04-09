<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Builder;

use Athanor\BatchScope;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Revolt\EventLoop;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;

/**
 * Single 33ms breathing animation driver.
 *
 * Consolidates thinking and compacting animation timers into one driver.
 * The driver ticks active breath counters, computes palette colors, updates
 * loader widgets, and triggers renders — all in a single timer callback.
 *
 * Subagent and tool-executing timers remain independent (different cadences).
 */
final class BreathingDriver
{
    private ?string $timerId = null;

    private ?CancellableLoaderWidget $thinkingLoader = null;

    private ?CancellableLoaderWidget $compactingLoader = null;

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

    public function setThinkingLoader(?CancellableLoaderWidget $loader): void
    {
        $this->thinkingLoader = $loader;
    }

    public function setCompactingLoader(?CancellableLoaderWidget $loader): void
    {
        $this->compactingLoader = $loader;
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
    public function tick(
        ?CancellableLoaderWidget $thinkingLoader = null,
        ?CancellableLoaderWidget $compactingLoader = null,
    ): void {
        $phase = $this->state->getPhase();
        $hasThinking = ($phase === 'thinking' || $phase === 'tools');
        $hasCompacting = $phase === 'compacting';

        if (! $hasThinking && ! $hasCompacting) {
            return;
        }

        BatchScope::run(function () use ($hasThinking, $hasCompacting): void {
            $r = Theme::reset();

            if ($hasThinking) {
                $this->tickThinking($r, $this->thinkingLoader);
            }

            if ($hasCompacting) {
                $this->tickCompacting($r, $this->compactingLoader);
            }
        });

        if (isset($this->renderCallback)) {
            ($this->renderCallback)();
        }
    }

    private function tickThinking(string $r, ?CancellableLoaderWidget $loader): void
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

        if ($loader !== null) {
            $phrase = $this->state->getThinkingPhrase();
            if ($phrase !== null) {
                $dim = "\033[38;5;245m";
                $message = "{$breathColor}{$phrase}{$r}";

                if (! $this->state->getHasSubagentActivity()) {
                    $elapsed = (int) (microtime(true) - $this->state->getThinkingStartTime());
                    $formatted = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                    $message .= "{$dim} · {$formatted}{$r}";
                }

                $loader->setMessage($message);
            }
        }

        // Live subagent tree — refresh every ~0.5s
        if ($tick % 15 === 0 && isset($this->subagentTickCallback)) {
            ($this->subagentTickCallback)();
        }
    }

    private function tickCompacting(string $r, ?CancellableLoaderWidget $loader): void
    {
        $this->state->tickCompactingBreath();

        $tick = $this->state->getCompactingBreathTick();
        $t = sin($tick * 0.07);
        $cr = (int) (208 + 40 * $t);
        $cg = (int) (48 + 16 * $t);
        $cb = (int) (48 + 16 * $t);
        $color = Theme::rgb($cr, $cg, $cb);

        if ($loader !== null) {
            $phrase = $this->state->getThinkingPhrase() ?? '';
            if ($phrase !== '') {
                $elapsed = (int) (microtime(true) - $this->state->getCompactingStartTime());
                $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $dim = "\033[38;5;245m";
                $loader->setMessage("{$color}{$phrase}{$r} {$dim}({$formatted}){$r}");
            }
        }
    }
}
