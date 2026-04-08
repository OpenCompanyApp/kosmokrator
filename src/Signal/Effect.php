<?php

declare(strict_types=1);

namespace OpenCompany\Signal;

/**
 * Side-effect that auto-runs when its tracked dependencies change.
 *
 * Used for wiring signals → widget updates. The callback receives an
 * onCleanup function for registering cleanup logic that runs before
 * the next effect execution.
 *
 * Effects auto-track Signal/Computed reads that happen during execution
 * via the static {@see EffectScope} stack. Dependencies are re-tracked
 * on every execution, so conditional reads are handled correctly.
 *
 * Cycle detection: if an effect re-triggers itself (directly or indirectly)
 * more than 100 times in a single synchronous chain, a LogicException is
 * thrown. This prevents infinite loops from effects that write to signals
 * they also read.
 */
final class Effect
{
    /** @var callable(callable(callable): void): void */
    private readonly mixed $fn;

    /** @var list<ReadableSignalInterface|Computed> */
    private array $dependencies = [];

    /** @var list<callable(): void> */
    private array $cleanups = [];

    private bool $disposed = false;

    private static int $executionDepth = 0;

    /**
     * @param  callable(callable(callable): void): void  $fn  Effect callback.
     *                                                        Receives an onCleanup function: onCleanup(callable $cleanup): void
     */
    public function __construct(callable $fn)
    {
        $this->fn = $fn;
        $this->execute();
    }

    /**
     * Manually trigger a re-execution. Normally called automatically
     * when a dependency changes.
     */
    public function run(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->execute();
    }

    /**
     * Dispose of the effect. Cleans up dependencies and runs final cleanups.
     * After disposal, the effect will never run again.
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $this->runCleanups();
        $this->cleanupDependencies();
    }

    /**
     * Called by a dependency (Signal or Computed) when it changes.
     * Respects BatchScope — if one is active, the effect is enqueued
     * instead of running immediately.
     */
    public function notify(): void
    {
        if ($this->disposed) {
            return;
        }

        $batch = BatchScope::current();
        if ($batch !== null) {
            $batch->enqueueEffect($this);

            return;
        }

        $this->execute();
    }

    /**
     * Called by EffectScope when a dependency is tracked during execution.
     */
    public function onTracked(ReadableSignalInterface|Computed $dep): void
    {
        $this->dependencies[] = $dep;

        if ($dep instanceof Signal) {
            $dep->subscribeEffect($this);
        } elseif ($dep instanceof Computed) {
            $dep->subscribeEffect($this);
        }
    }

    private function execute(): void
    {
        if (self::$executionDepth > 100) {
            throw new \LogicException(
                'Reactive: maximum effect execution depth exceeded (effect cycle detected — '
                .'an effect may be writing to a signal it reads)'
            );
        }

        self::$executionDepth++;
        try {
            // Run previous cleanups before re-execution
            $this->runCleanups();
            $this->cleanupDependencies();

            $onCleanup = function (callable $cleanup): void {
                $this->cleanups[] = $cleanup;
            };

            // Run the effect callback inside a tracking scope
            $scope = new EffectScope($this->onTracked(...));
            $scope->run($this->fn, $onCleanup);
        } finally {
            self::$executionDepth--;
        }
    }

    private function runCleanups(): void
    {
        foreach ($this->cleanups as $cleanup) {
            $cleanup();
        }
        $this->cleanups = [];
    }

    private function cleanupDependencies(): void
    {
        foreach ($this->dependencies as $dep) {
            if ($dep instanceof Signal) {
                $dep->unsubscribeEffect($this);
            } elseif ($dep instanceof Computed) {
                $dep->unsubscribeEffect($this);
            }
        }
        $this->dependencies = [];
    }
}
