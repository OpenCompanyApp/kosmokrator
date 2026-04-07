<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Performance;

use Revolt\EventLoop;

/**
 * Single master timer that replaces multiple independent `EventLoop::repeat()`
 * timers in the TUI animation pipeline.
 *
 * Before this class, up to 4 independent timers (breathing, compacting,
 * subagent-elapsed, tool-executing) each called `flushRender()` independently,
 * causing redundant terminal repaints when their intervals overlapped. The
 * worst case was 90 render attempts per second (3 × 30fps).
 *
 * RenderScheduler owns **one** `EventLoop::repeat()` timer and an animation
 * registry. On each tick it:
 *   1. Calls every registered animation callback (state update only).
 *   2. Calls the render callback **once**.
 *
 * The tick rate adapts to the current activity level:
 *   - `idle`      → 250ms  (4fps)  — minimal CPU, only cursor/input updates
 *   - `thinking`  →  33ms  (30fps) — breathing animations, loader spinners
 *   - `streaming` →  16ms  (60fps) — smooth text streaming
 *
 * Animations that do not need per-frame updates use the `throttle` parameter
 * to skip ticks (e.g. subagent tree at every 15th tick ≈ 0.5s at 30fps).
 *
 * Usage:
 *   $scheduler = new RenderScheduler($flushRender, $forceRender);
 *   $scheduler->register('breathing', fn() => $this->updateBreathColor());
 *   $scheduler->register('task-bar', fn() => $this->refreshTaskBar());
 *   $scheduler->register('subagent-tree', fn() => $this->tickTree(), throttle: 15);
 *   $scheduler->setActivityLevel('thinking');
 *   $scheduler->start();
 *
 * @see docs/plans/tui-overhaul/13-architecture/05-timer-efficiency.md
 */
final class RenderScheduler
{
    /** Tick interval in seconds for each activity level */
    private const float INTERVAL_IDLE = 0.25;        // 4fps
    private const float INTERVAL_THINKING = 0.033;   // ~30fps
    private const float INTERVAL_STREAMING = 0.016;  // ~60fps

    /** @var string One of 'idle', 'thinking', 'streaming' */
    private string $activityLevel = 'idle';

    private ?string $timerId = null;
    private float $currentInterval = self::INTERVAL_IDLE;

    /** Monotonically increasing tick counter (for throttle calculations) */
    private int $tick = 0;

    /** @var array<string, AnimationEntry> */
    private array $animations = [];

    /** @var \Closure(): void Renders via Tui::requestRender() + processRender() */
    private readonly \Closure $renderCallback;

    /** @var \Closure(): void Renders via Tui::requestRender(force: true) + processRender() */
    private readonly \Closure $forceRenderCallback;

    public function __construct(
        \Closure $renderCallback,
        \Closure $forceRenderCallback,
    ) {
        $this->renderCallback = $renderCallback;
        $this->forceRenderCallback = $forceRenderCallback;
    }

    /**
     * Register an animation callback to be called on every tick.
     *
     * If an entry with the same `$id` already exists it is replaced.
     *
     * @param non-empty-string $id Unique identifier for later unregister
     * @param \Closure(): void $callback Pure state-update closure (no rendering)
     * @param int<1, max> $throttle Call every Nth tick (1 = every tick, 15 = ~0.5s at 30fps)
     */
    public function register(string $id, \Closure $callback, int $throttle = 1): void
    {
        $this->animations[$id] = new AnimationEntry($id, $callback, $throttle);
    }

    /**
     * Unregister a previously registered animation callback.
     *
     * No-op if `$id` is not currently registered.
     */
    public function unregister(string $id): void
    {
        unset($this->animations[$id]);
    }

    /**
     * Check whether an animation with the given ID is currently registered.
     */
    public function isRegistered(string $id): bool
    {
        return isset($this->animations[$id]);
    }

    /**
     * Set the activity level, adjusting the tick interval.
     *
     * If the new level requires a different interval the current timer is
     * cancelled and restarted. An immediate `renderNow()` call fills any
     * gap caused by the restart.
     *
     * @param 'idle'|'thinking'|'streaming' $level
     */
    public function setActivityLevel(string $level): void
    {
        if ($level === $this->activityLevel) {
            return;
        }

        $this->activityLevel = $level;
        $this->restartTimer();
    }

    /**
     * Get the current activity level.
     *
     * @return 'idle'|'thinking'|'streaming'
     */
    public function getActivityLevel(): string
    {
        /** @var 'idle'|'thinking'|'streaming' */
        return $this->activityLevel;
    }

    /**
     * Start the master timer. Safe to call multiple times — only starts once.
     *
     * If there are no registered animations the timer still starts (idle pulse).
     */
    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }
        $this->restartTimer();
    }

    /**
     * Stop the master timer and clear all registered animations.
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
        $this->animations = [];
        $this->tick = 0;
    }

    /**
     * Stop the timer but keep registered animations intact.
     *
     * Useful when you want to pause scheduling without losing the registry.
     */
    public function pause(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Force an immediate render outside the tick cycle.
     *
     * Used for one-shot events (widget added, phase transition, user input).
     * Coexists with the tick-driven render — safe to call at any time.
     */
    public function renderNow(bool $force = false): void
    {
        if ($force) {
            ($this->forceRenderCallback)();
        } else {
            ($this->renderCallback)();
        }
    }

    /**
     * Get the number of registered animations.
     */
    public function animationCount(): int
    {
        return count($this->animations);
    }

    /**
     * Get the current tick interval in seconds.
     */
    public function getCurrentInterval(): float
    {
        return $this->currentInterval;
    }

    /**
     * Get the current tick counter value.
     */
    public function getTick(): int
    {
        return $this->tick;
    }

    /**
     * Manually execute a single tick cycle.
     *
     * Calls every registered animation callback (respecting throttle),
     * then triggers a render. Useful for forcing an update outside the
     * normal timer cycle or for testing.
     */
    public function tick(): void
    {
        ++$this->tick;

        foreach ($this->animations as $animation) {
            if ($this->tick % $animation->throttle === 0) {
                ($animation->callback)();
            }
        }

        ($this->renderCallback)();
    }

    /**
     * Set the tick rate in frames per second.
     *
     * Replaces the activity-level-based interval with an explicit FPS value.
     * The timer is restarted with the new interval. Set to 0 to restore
     * automatic activity-level-based timing.
     *
     * @param int<0, max> $fps Target frames per second (0 = auto via activity level)
     */
    public function setFps(int $fps): void
    {
        if ($fps <= 0) {
            // Restore activity-level-based timing
            $this->restartTimer();

            return;
        }

        $interval = 1.0 / $fps;

        if (abs($interval - $this->currentInterval) < 0.001) {
            return;
        }

        $this->currentInterval = $interval;

        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);

            $this->timerId = EventLoop::repeat($this->currentInterval, function (): void {
                ++$this->tick;

                foreach ($this->animations as $animation) {
                    if ($this->tick % $animation->throttle === 0) {
                        ($animation->callback)();
                    }
                }

                ($this->renderCallback)();
            });
        }
    }

    // ── Internal ────────────────────────────────────────────────────────

    private function restartTimer(): void
    {
        if ($this->timerId !== null) {
            EventLoop::cancel($this->timerId);
        }

        $this->currentInterval = match ($this->activityLevel) {
            'streaming' => self::INTERVAL_STREAMING,
            'thinking' => self::INTERVAL_THINKING,
            default => self::INTERVAL_IDLE,
        };

        $this->timerId = EventLoop::repeat($this->currentInterval, function (): void {
            ++$this->tick;

            foreach ($this->animations as $animation) {
                if ($this->tick % $animation->throttle === 0) {
                    ($animation->callback)();
                }
            }

            ($this->renderCallback)();
        });

        // Fill the gap caused by timer restart with an immediate render
        $this->renderNow();
    }
}

/**
 * @internal
 *
 * Value object representing a registered animation callback.
 */
final class AnimationEntry
{
    /**
     * @param non-empty-string $id Unique identifier
     * @param \Closure(): void $callback Called on every Nth tick
     * @param int<1, max> $throttle Call every Nth tick
     */
    public function __construct(
        public readonly string $id,
        public readonly \Closure $callback,
        public readonly int $throttle = 1,
    ) {}
}
