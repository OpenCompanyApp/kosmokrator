<?php

declare(strict_types=1);

namespace Rubedo;

/**
 * Read-only view of a reactive signal.
 *
 * Expose this interface to consumers that should read but never write.
 * The mutable {@see Signal} class implements this interface.
 *
 * @template T
 */
interface ReadableSignalInterface
{
    /**
     * Read the current value. Inside an active Effect or Computed,
     * auto-tracks this signal as a dependency.
     *
     * @return T
     */
    public function get(): mixed;

    /**
     * Get the raw value without dependency tracking.
     *
     * @return T
     */
    public function value(): mixed;

    /**
     * Get the version counter (increments on each value change).
     */
    public function getVersion(): int;
}
