<?php

declare(strict_types=1);

namespace OpenCompany\Signal;

/**
 * Reactive value holder with version counter and subscriber list.
 *
 * Reading via {@see get()} inside an active EffectScope (i.e. inside
 * a Computed or Effect callback) auto-tracks this signal as a dependency.
 *
 * Writing via {@see set()} only notifies subscribers when the new value
 * is strictly different from the current value.
 *
 * Identity semantics: set() uses strict === comparison. For scalars and
 * null, this means same-value === same-identity. For arrays, rebuilding
 * an array (e.g. [...$arr, $new]) always creates a new reference, so
 * set() always notifies — even if the contents are logically identical.
 * For objects, set($sameObject) is always a no-op (same reference).
 * This is by design: any mutation operation should produce a new value.
 *
 * @template T
 */
final class Signal implements ReadableSignalInterface
{
    /** @var T */
    private mixed $value;

    private int $version = 0;

    /** @var list<Subscriber> */
    private array $subscribers = [];

    /**
     * @param  T  $value
     */
    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    /**
     * Read the current value. If called inside an active EffectScope
     * (i.e. inside a Computed or Effect callback), auto-tracks this
     * signal as a dependency.
     *
     * @return T
     */
    public function get(): mixed
    {
        $scope = EffectScope::current();
        if ($scope !== null) {
            $scope->track($this);
        }

        return $this->value;
    }

    /**
     * Write a new value. Increments version and notifies subscribers,
     * but only if the value actually changed (strict === check).
     * When a BatchScope is active, notifications are deferred.
     *
     * @param  T  $value
     */
    public function set(mixed $value): void
    {
        if ($this->value === $value) {
            return;
        }

        $this->value = $value;
        $this->version++;
        $this->notify();
    }

    /**
     * Update the value using a transformer callback. Reads the current
     * value (without tracking), applies the callback, and sets the result.
     *
     * Note: for array signals, update() always creates a new array
     * reference, so set() always notifies subscribers regardless of
     * whether the contents changed.
     *
     * @param  callable(T): T  $callback
     */
    public function update(callable $callback): void
    {
        $this->set($callback($this->value));
    }

    /**
     * Subscribe to value changes. Returns an unsubscribe callable.
     *
     * @param  callable(mixed): void  $callback  Receives the new value
     * @return callable(): void Unsubscribe function
     */
    public function subscribe(callable $callback): callable
    {
        $sub = new Subscriber($callback);
        $this->subscribers[] = $sub;

        return function () use ($sub): void {
            $this->subscribers = \array_values(\array_filter(
                $this->subscribers,
                static fn (Subscriber $s): bool => $s !== $sub,
            ));
        };
    }

    /**
     * Internal: subscribe a Computed as a downstream dependent.
     * When this signal changes, the computed is marked dirty.
     */
    public function subscribeComputed(Computed $computed): void
    {
        $this->subscribers[] = new Subscriber(
            callback: static fn () => $computed->markDirty(),
            dependent: $computed,
        );
    }

    /**
     * Internal: unsubscribe a Computed downstream dependent.
     */
    public function unsubscribeComputed(Computed $computed): void
    {
        $this->subscribers = \array_values(\array_filter(
            $this->subscribers,
            static fn (Subscriber $s): bool => $s->dependent !== $computed,
        ));
    }

    /**
     * Internal: subscribe an Effect as a downstream dependent.
     * When this signal changes, the effect is notified.
     */
    public function subscribeEffect(Effect $effect): void
    {
        $this->subscribers[] = new Subscriber(
            callback: static fn () => $effect->notify(),
            dependent: $effect,
        );
    }

    /**
     * Internal: unsubscribe an Effect downstream dependent.
     */
    public function unsubscribeEffect(Effect $effect): void
    {
        $this->subscribers = \array_values(\array_filter(
            $this->subscribers,
            static fn (Subscriber $s): bool => $s->dependent !== $effect,
        ));
    }

    /**
     * Get the current version counter. Useful for cache invalidation checks.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Get the raw value without dependency tracking.
     * Use sparingly — only when tracking is explicitly unwanted.
     *
     * @return T
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @internal Used by BatchScope::flush()
     *
     * @return list<Subscriber>
     */
    public function getSubscribersForFlush(): array
    {
        return $this->subscribers;
    }

    private function notify(): void
    {
        $batch = BatchScope::current();
        if ($batch !== null) {
            $batch->enqueue($this);

            return;
        }

        foreach ($this->subscribers as $sub) {
            $sub->fire($this->value);
        }
    }
}
