<?php

declare(strict_types=1);

namespace Athanor;

/**
 * Derived reactive value. Lazily evaluated and cached.
 * Auto-re-evaluates when any dependency (Signal or Computed) changes.
 *
 * Computed is lazy: the derivation function only runs on the first call
 * to {@see get()}, and re-runs only when {@see get()} is called after
 * the computed has been marked dirty by a dependency change.
 *
 * @template T
 */
final class Computed
{
    /** @var callable(): T */
    private readonly mixed $fn;

    /** @var T|null */
    private mixed $value = null;

    private int $version = 0;

    private bool $dirty = true;

    private bool $initialized = false;

    /** @var list<ReadableSignalInterface|self> */
    private array $dependencies = [];

    /** @var list<Subscriber> */
    private array $subscribers = [];

    private static int $recomputeDepth = 0;

    /**
     * @param  callable(): T  $fn  Pure derivation function
     */
    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    /**
     * Read the computed value. Evaluates lazily on first access or when dirty.
     * Auto-tracks into the current EffectScope (so Computed<Computed<...>> chains work).
     *
     * @return T
     */
    public function get(): mixed
    {
        if ($this->dirty || ! $this->initialized) {
            $this->recompute();
        }

        // Track into parent scope (enables Computed chains)
        $scope = EffectScope::current();
        if ($scope !== null) {
            $scope->track($this);
        }

        return $this->value;
    }

    /**
     * Get the current version counter.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Mark this computed as needing re-evaluation.
     * Called by dependency change notifications. Cascades to downstream dependents.
     */
    public function markDirty(): void
    {
        if ($this->dirty) {
            return; // Already dirty — no need to cascade again
        }

        $this->dirty = true;
        $this->version++;

        // Cascade to downstream dependents (other Computed or Effect subscribers)
        foreach ($this->subscribers as $sub) {
            if ($sub->dependent instanceof self) {
                $sub->dependent->markDirty();
            } elseif ($sub->dependent instanceof Effect) {
                $sub->dependent->notify();
            }
        }
    }

    /**
     * Subscribe to computed value changes via a side-effect callback.
     *
     * @param  callable(mixed): void  $callback
     * @return Effect The effect instance (call ->dispose() to unsubscribe)
     */
    public function subscribe(callable $callback): Effect
    {
        return new Effect(function () use ($callback): void {
            $callback($this->get());
        });
    }

    /**
     * Internal: subscribe a downstream Computed.
     */
    public function subscribeComputed(self $computed): void
    {
        $this->subscribers[] = new Subscriber(
            callback: static fn () => $computed->markDirty(),
            dependent: $computed,
        );
    }

    /**
     * Internal: unsubscribe a downstream Computed.
     */
    public function unsubscribeComputed(self $computed): void
    {
        $this->subscribers = \array_values(\array_filter(
            $this->subscribers,
            static fn (Subscriber $s): bool => $s->dependent !== $computed,
        ));
    }

    /**
     * Internal: subscribe a downstream Effect.
     */
    public function subscribeEffect(Effect $effect): void
    {
        $this->subscribers[] = new Subscriber(
            callback: static fn () => $effect->notify(),
            dependent: $effect,
        );
    }

    /**
     * Internal: unsubscribe a downstream Effect.
     */
    public function unsubscribeEffect(Effect $effect): void
    {
        $this->subscribers = \array_values(\array_filter(
            $this->subscribers,
            static fn (Subscriber $s): bool => $s->dependent !== $effect,
        ));
    }

    /**
     * Force immediate re-evaluation. Called lazily by get() or explicitly for testing.
     *
     * On exception: restores dirty=true so the next get() will retry, then rethrows.
     *
     * @return T
     */
    public function recompute(): mixed
    {
        if (self::$recomputeDepth > 100) {
            throw new \LogicException(
                'Reactive: maximum recomputation depth exceeded (circular dependency?)'
            );
        }

        self::$recomputeDepth++;
        try {
            // Clean up old dependency subscriptions
            $this->cleanupDependencies();

            // Run the derivation inside a tracking scope
            $scope = new EffectScope($this->onTracked(...));
            $this->value = $scope->run($this->fn);
            $this->dirty = false;
            $this->initialized = true;

            return $this->value;
        } catch (\Throwable $e) {
            // Restore dirty so the next get() will retry
            $this->dirty = true;
            throw $e;
        } finally {
            self::$recomputeDepth--;
        }
    }

    /**
     * Called by EffectScope when a dependency is tracked during computation.
     */
    private function onTracked(ReadableSignalInterface|self $dep): void
    {
        $this->dependencies[] = $dep;

        if ($dep instanceof Signal) {
            $dep->subscribeComputed($this);
        } elseif ($dep instanceof self) {
            $dep->subscribeComputed($this);
        }
    }

    private function cleanupDependencies(): void
    {
        foreach ($this->dependencies as $dep) {
            if ($dep instanceof Signal) {
                $dep->unsubscribeComputed($this);
            } elseif ($dep instanceof self) {
                $dep->unsubscribeComputed($this);
            }
        }
        $this->dependencies = [];
    }
}
