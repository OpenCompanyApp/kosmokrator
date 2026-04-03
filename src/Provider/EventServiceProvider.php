<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Contracts\Events\Dispatcher;
use Kosmokrator\Agent\Event\LlmResponseReceived;
use Kosmokrator\Agent\Listener\TokenTrackingListener;

/**
 * Registers domain event listeners with the Illuminate Event Dispatcher.
 *
 * Keeps listener wiring centralized and separate from the core agent logic.
 * New listeners for analytics, webhooks, or audit logging can be added here.
 */
class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(TokenTrackingListener::class, fn () => new TokenTrackingListener);
    }

    public function boot(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->make(Dispatcher::class);

        $listener = $this->container->make(TokenTrackingListener::class);
        $dispatcher->listen(LlmResponseReceived::class, [$listener, 'handle']);
    }
}
