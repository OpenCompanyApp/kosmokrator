<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Animation;

use Symfony\Component\Tui\Tui;

/**
 * Central animation engine. Owns a single tick interval registered with the
 * Symfony TUI's TickScheduler. On each tick, advances all registered
 * AnimationControllers and requests a render if any values changed.
 *
 * Replaces all scattered EventLoop::repeat() timers in TuiAnimationManager.
 *
 * Usage:
 *   $driver = new AnimationDriver($tui, $preferences);
 *   $driver->register('my-widget', $controller);
 *   // Driver automatically ticks and renders.
 */
final class AnimationDriver
{
    private const float TICK_INTERVAL = 0.033; // ~30fps

    /** @var array<string, AnimationController> */
    private array $controllers = [];

    private ?string $tickId = null;
    private bool $running = false;
    private float $elapsedTime = 0.0;

    public function __construct(
        private readonly Tui $tui,
        private readonly AnimationPreferences $preferences = new AnimationPreferences(),
    ) {}

    /**
     * Register an AnimationController for a named element.
     */
    public function register(string $id, AnimationController $controller): void
    {
        $this->controllers[$id] = $controller;

        if ($controller->hasActiveAnimations() && !$this->running) {
            $this->start();
        }
    }

    /**
     * Unregister a controller.
     */
    public function unregister(string $id): void
    {
        unset($this->controllers[$id]);

        if (empty($this->controllers) || !$this->hasActiveControllers()) {
            $this->stop();
        }
    }

    /**
     * Get a registered controller.
     */
    public function getController(string $id): ?AnimationController
    {
        return $this->controllers[$id] ?? null;
    }

    /**
     * Convenience: create and register a new controller.
     */
    public function createController(string $id): AnimationController
    {
        $controller = new AnimationController();
        $this->register($id, $controller);
        return $controller;
    }

    /**
     * Start the animation tick loop.
     */
    public function start(): void
    {
        if ($this->running || $this->preferences->prefersReducedMotion) {
            return;
        }

        $this->running = true;
        $this->tickId = $this->tui->scheduleInterval(
            $this->onTick(...),
            self::TICK_INTERVAL,
        );
    }

    /**
     * Stop the animation tick loop.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        if ($this->tickId !== null) {
            $this->tui->cancelInterval($this->tickId);
            $this->tickId = null;
        }
    }

    /**
     * Get the accumulated elapsed time since the driver started ticking.
     * Useful for time-based effects like shimmer that don't use named animations.
     */
    public function getElapsedTime(): float
    {
        return $this->elapsedTime;
    }

    /**
     * Check if the driver is currently ticking.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    private function onTick(): void
    {
        $dt = self::TICK_INTERVAL; // Fixed timestep for deterministic animation
        $this->elapsedTime += $dt;
        $anyDirty = false;
        $reducedMotion = $this->preferences->prefersReducedMotion;

        foreach ($this->controllers as $controller) {
            if ($controller->advance($dt, $reducedMotion)) {
                $anyDirty = true;
            }
        }

        if ($anyDirty) {
            $this->tui->requestRender();
        }

        // Auto-stop if nothing is animating
        if (!$this->hasActiveControllers()) {
            $this->stop();
        }
    }

    private function hasActiveControllers(): bool
    {
        foreach ($this->controllers as $controller) {
            if ($controller->hasActiveAnimations()) {
                return true;
            }
        }
        return false;
    }
}
