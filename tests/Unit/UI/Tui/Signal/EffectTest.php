<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Signal;

use Kosmokrator\UI\Tui\Signal\BatchScope;
use Kosmokrator\UI\Tui\Signal\Effect;
use Kosmokrator\UI\Tui\Signal\Signal;
use PHPUnit\Framework\TestCase;

final class EffectTest extends TestCase
{
    public function test_auto_runs(): void
    {
        $signal = new Signal(10);
        $ran = false;

        new Effect(function () use ($signal, &$ran): void {
            $signal->get();
            $ran = true;
        });

        $this->assertTrue($ran, 'Effect should run immediately on construction');
    }

    public function test_re_runs_on_change(): void
    {
        $signal = new Signal(1);
        $runCount = 0;

        new Effect(function () use ($signal, &$runCount): void {
            $signal->get();
            $runCount++;
        });

        $this->assertSame(1, $runCount, 'Should have run once on construction');

        $signal->set(2);
        $this->assertSame(2, $runCount, 'Should re-run when dependency changes');

        $signal->set(3);
        $this->assertSame(3, $runCount, 'Should re-run on every change');
    }

    public function test_dispose(): void
    {
        $signal = new Signal(1);
        $runCount = 0;

        $effect = new Effect(function () use ($signal, &$runCount): void {
            $signal->get();
            $runCount++;
        });

        $this->assertSame(1, $runCount);

        $effect->dispose();

        $signal->set(2);
        $this->assertSame(1, $runCount, 'Effect should NOT re-run after dispose');
    }

    public function test_cleanup(): void
    {
        $signal = new Signal(1);
        $cleanups = [];

        $effect = new Effect(function (callable $onCleanup) use ($signal, &$cleanups): void {
            $signal->get();
            $onCleanup(function () use (&$cleanups): void {
                $cleanups[] = 'cleanup';
            });
        });

        $this->assertSame([], $cleanups, 'No cleanup should have run yet');

        // Trigger re-run — cleanup from first run should execute
        $signal->set(2);
        $this->assertCount(1, $cleanups, 'Cleanup should run before re-execution');

        // Dispose — cleanup from second run should execute
        $effect->dispose();
        $this->assertCount(2, $cleanups, 'Cleanup should run on dispose');
    }

    public function test_batch(): void
    {
        $signal = new Signal(0);
        $subscriberNotifications = [];

        // Use a regular subscriber to verify batch deferral
        $signal->subscribe(function (mixed $value) use (&$subscriberNotifications): void {
            $subscriberNotifications[] = $value;
        });

        BatchScope::run(function () use ($signal): void {
            $signal->set(1);
            $signal->set(2);
            $signal->set(3);
            // Subscriber notifications are deferred during batch
        });

        // After batch completes, subscribers should have been notified
        $this->assertNotEmpty($subscriberNotifications, 'Subscribers should be notified after batch');
    }
}
