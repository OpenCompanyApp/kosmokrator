<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Signal;

/**
 * Static tracking context. Holds a stack of active scopes so that
 * Signal::get() / Computed::get() calls inside a Computed or Effect
 * auto-register the signal as a dependency of the current scope.
 *
 * @internal Used by Signal, Computed, and Effect — not intended for direct use.
 */
final class EffectScope
{
    /** @var list<self> */
    private static array $stack = [];

    /** @var callable(Signal|Computed): void */
    private readonly mixed $onTrack;

    /**
     * @param  callable(Signal|Computed): void  $onTrack
     */
    public function __construct(callable $onTrack)
    {
        $this->onTrack = $onTrack;
    }

    /**
     * Get the currently active scope, or null if none.
     */
    public static function current(): ?self
    {
        return self::$stack[\count(self::$stack) - 1] ?? null;
    }

    /**
     * Track a dependency into this scope.
     */
    public function track(Signal|Computed $dep): void
    {
        ($this->onTrack)($dep);
    }

    /**
     * Run a callback inside this scope. Pushes onto the stack,
     * restoring the previous scope on exit (even on exception).
     *
     * @param  mixed  ...$args  Arguments to pass to $fn
     * @return mixed Return value of $fn
     */
    public function run(callable $fn, mixed ...$args): mixed
    {
        self::$stack[] = $this;
        try {
            return $fn(...$args);
        } finally {
            \array_pop(self::$stack);
        }
    }
}
