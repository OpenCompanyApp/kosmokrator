<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Container\Container;

/**
 * Base class for service providers that register bindings into the
 * Laravel IoC container during the Kernel boot sequence.
 */
abstract class ServiceProvider
{
    public function __construct(protected readonly Container $container) {}

    /** Register bindings into the container. */
    abstract public function register(): void;

    /** Called after all providers have registered. Optional. */
    public function boot(): void {}
}
