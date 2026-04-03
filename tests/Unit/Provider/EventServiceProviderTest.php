<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Provider;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Kosmokrator\Agent\Event\LlmResponseReceived;
use Kosmokrator\Agent\Listener\TokenTrackingListener;
use Kosmokrator\Provider\EventServiceProvider;
use PHPUnit\Framework\TestCase;

class EventServiceProviderTest extends TestCase
{
    public function test_registers_token_tracking_listener_as_singleton(): void
    {
        $container = new Container;
        $container->singleton('events', fn () => new EventDispatcher($container));
        $container->alias('events', Dispatcher::class);

        $provider = new EventServiceProvider($container);
        $provider->register();
        $provider->boot();

        $listener = $container->make(TokenTrackingListener::class);
        $this->assertInstanceOf(TokenTrackingListener::class, $listener);
        $this->assertSame($listener, $container->make(TokenTrackingListener::class));
    }

    public function test_dispatching_event_reaches_listener(): void
    {
        $container = new Container;
        $container->singleton('events', fn () => new EventDispatcher($container));
        $container->alias('events', Dispatcher::class);

        $provider = new EventServiceProvider($container);
        $provider->register();
        $provider->boot();

        $dispatcher = $container->make(Dispatcher::class);
        $dispatcher->dispatch(new LlmResponseReceived(100, 50, 10, 5, 'test/model'));

        $listener = $container->make(TokenTrackingListener::class);
        $this->assertSame(100, $listener->getTotalIn());
        $this->assertSame(50, $listener->getTotalOut());
        $this->assertSame(10, $listener->getTotalCacheRead());
        $this->assertSame(5, $listener->getTotalCacheWrite());
    }
}
