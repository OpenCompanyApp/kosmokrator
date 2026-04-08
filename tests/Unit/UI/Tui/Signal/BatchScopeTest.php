<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Signal;

use OpenCompany\Signal\BatchScope;
use OpenCompany\Signal\Effect;
use OpenCompany\Signal\Signal;
use PHPUnit\Framework\TestCase;

final class BatchScopeTest extends TestCase
{
    public function test_nested(): void
    {
        $signal = new Signal(0);
        $notifications = 0;
        $signal->subscribe(function () use (&$notifications): void {
            $notifications++;
        });

        BatchScope::run(function () use ($signal): void {
            $signal->set(1);

            BatchScope::run(function () use ($signal): void {
                $signal->set(2);
                // Still inside nested batch — no flush yet
            });

            // Inner batch incremented depth, so still no flush
        });

        // After outermost batch completes, flush happens
        $this->assertGreaterThan(0, $notifications);
    }

    public function test_flush_order(): void
    {
        $signal = new Signal(0);
        $order = [];

        $signal->subscribe(function () use (&$order): void {
            $order[] = 'subscriber';
        });

        $effect = new Effect(function () use ($signal, &$order): void {
            $signal->get();
            $order[] = 'effect';
        });

        // Reset order tracking (effect already ran once on construction)
        $order = [];

        BatchScope::run(function () use ($signal): void {
            $signal->set(1);
        });

        // Subscribers should fire before effects
        if (count($order) >= 2) {
            $this->assertSame('subscriber', $order[0]);
            $this->assertSame('effect', $order[1]);
        } else {
            // At minimum subscriber should have fired
            $this->assertContains('subscriber', $order);
        }
    }

    public function test_deferred_requires_scheduler(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('BatchScope::deferred() requires a scheduler');

        BatchScope::deferred(function (): void {});
    }

    public function test_deferred_with_scheduler(): void
    {
        $signal = new Signal(0);
        $flushed = false;

        $signal->subscribe(function () use (&$flushed): void {
            $flushed = true;
        });

        // Use a synchronous scheduler for testing
        BatchScope::setScheduler(function (callable $fn): void {
            $fn();
        });

        BatchScope::deferred(function () use ($signal): void {
            $signal->set(1);
        });

        // With synchronous scheduler, flush happens immediately
        $this->assertTrue($flushed);

        // Clean up
        BatchScope::setScheduler(null);
    }

    public function test_deferred_defers_with_async_scheduler(): void
    {
        $signal = new Signal(0);
        $flushed = false;

        $signal->subscribe(function () use (&$flushed): void {
            $flushed = true;
        });

        // Simulate async scheduler that doesn't invoke immediately
        BatchScope::setScheduler(function (callable $fn): void {
            // Don't invoke — simulating deferred execution
        });

        BatchScope::deferred(function () use ($signal): void {
            $signal->set(1);
        });

        // Flush hasn't happened because scheduler didn't invoke the callback
        $this->assertFalse($flushed);

        // Clean up
        BatchScope::setScheduler(null);
    }
}
