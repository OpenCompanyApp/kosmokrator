<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Signal;

/**
 * Internal subscriber record. Shared by Signal and Computed.
 *
 * @internal
 */
final class Subscriber
{
    /** @var callable(mixed): void */
    public readonly mixed $callback;

    public readonly Signal|Computed|Effect|null $dependent;

    /**
     * @param  callable(mixed): void  $callback
     */
    public function __construct(callable $callback, Signal|Computed|Effect|null $dependent = null)
    {
        $this->callback = $callback;
        $this->dependent = $dependent;
    }

    public function fire(mixed $value): void
    {
        ($this->callback)($value);
    }
}
