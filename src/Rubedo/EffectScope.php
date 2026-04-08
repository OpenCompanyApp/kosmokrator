<?php

declare(strict_types=1);

namespace Rubedo;

/**
 * Static tracking context AND effect ownership container.
 *
 * As a tracking context: holds a stack of active scopes so that
 * Signal::get() / Computed::get() calls inside a Computed or Effect
 * auto-register the signal as a dependency of the current scope.
 *
 * As an ownership container: tracks all child effects created via
 * {@see effect()} and disposes them all when {@see dispose()} is called.
 * This prevents memory leaks from long-lived effects.
 *
 * Usage:
 *   $scope = new EffectScope;
 *   $scope->effect(fn () => $signal->get()); // auto-tracked, auto-disposed
 *   $scope->dispose(); // cleans up all child effects
 *
 * @internal The tracking API is used by Signal, Computed, and Effect.
 *           The ownership API is used by application code.
 */
final class EffectScope
{
    /** @var list<self> */
    private static array $stack = [];

    /** @var callable(ReadableSignalInterface|Computed): void */
    private readonly mixed $onTrack;

    /** @var list<Effect> Child effects owned by this scope. */
    private array $effects = [];

    private bool $disposed = false;

    /**
     * @param  callable(ReadableSignalInterface|Computed): void  $onTrack
     */
    public function __construct(?callable $onTrack = null)
    {
        $this->onTrack = $onTrack ?? static fn () => null;
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
    public function track(ReadableSignalInterface|Computed $dep): void
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

    /**
     * Create an effect owned by this scope.
     *
     * The effect is tracked and will be auto-disposed when this scope
     * is disposed. Returns the effect for direct access if needed.
     *
     * @param  callable(callable(callable): void): void  $fn  Effect callback
     */
    public function effect(callable $fn): Effect
    {
        if ($this->disposed) {
            throw new \LogicException('Cannot create effects on a disposed EffectScope');
        }

        $effect = new Effect($fn);
        $this->effects[] = $effect;

        return $effect;
    }

    /**
     * Dispose all child effects. After this, the scope cannot create new effects.
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        foreach ($this->effects as $effect) {
            $effect->dispose();
        }
        $this->effects = [];
    }

    /**
     * Check if this scope has been disposed.
     */
    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /**
     * Get the number of active (non-disposed) child effects.
     */
    public function effectCount(): int
    {
        return \count($this->effects);
    }
}
