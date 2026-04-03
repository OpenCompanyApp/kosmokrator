<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent\Listener;

use Kosmokrator\Agent\Event\LlmResponseReceived;
use Kosmokrator\Agent\Listener\TokenTrackingListener;
use PHPUnit\Framework\TestCase;

class TokenTrackingListenerTest extends TestCase
{
    private TokenTrackingListener $listener;

    protected function setUp(): void
    {
        $this->listener = new TokenTrackingListener;
    }

    public function test_initial_totals_are_zero(): void
    {
        $this->assertSame(0, $this->listener->getTotalIn());
        $this->assertSame(0, $this->listener->getTotalOut());
        $this->assertSame(0, $this->listener->getTotalCacheRead());
        $this->assertSame(0, $this->listener->getTotalCacheWrite());
    }

    public function test_handle_accumulates_tokens(): void
    {
        $this->listener->handle(new LlmResponseReceived(100, 50, 10, 5, 'model'));
        $this->listener->handle(new LlmResponseReceived(200, 80, 20, 15, 'model'));

        $this->assertSame(300, $this->listener->getTotalIn());
        $this->assertSame(130, $this->listener->getTotalOut());
        $this->assertSame(30, $this->listener->getTotalCacheRead());
        $this->assertSame(20, $this->listener->getTotalCacheWrite());
    }

    public function test_reset_clears_all_totals(): void
    {
        $this->listener->handle(new LlmResponseReceived(100, 50, 10, 5, 'model'));
        $this->listener->reset();

        $this->assertSame(0, $this->listener->getTotalIn());
        $this->assertSame(0, $this->listener->getTotalOut());
        $this->assertSame(0, $this->listener->getTotalCacheRead());
        $this->assertSame(0, $this->listener->getTotalCacheWrite());
    }

    public function test_handle_with_zero_tokens(): void
    {
        $this->listener->handle(new LlmResponseReceived(0, 0, 0, 0, 'model'));

        $this->assertSame(0, $this->listener->getTotalIn());
        $this->assertSame(0, $this->listener->getTotalOut());
    }
}
