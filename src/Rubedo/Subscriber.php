<?php

declare(strict_types=1);

namespace Rubedo;

/**
 * Internal subscriber record. Shared by Signal and Computed.
 *
 * @internal
 */
final class Subscriber
{
    /** @var callable(mixed): void */
    public readonly mixed $callback;

    public readonly ReadableSignalInterface|Computed|Effect|null $dependent;

    /**
     * @param  callable(mixed): void  $callback
     */
    public function __construct(callable $callback, ReadableSignalInterface|Computed|Effect|null $dependent = null)
    {
        $this->callback = $callback;
        $this->dependent = $dependent;
    }

    public function fire(mixed $value): void
    {
        ($this->callback)($value);
    }
}
