<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Signal;

use Kosmokrator\UI\Tui\Signal\Computed;
use Kosmokrator\UI\Tui\Signal\Signal;
use PHPUnit\Framework\TestCase;

final class ComputedTest extends TestCase
{
    public function test_lazy_evaluation(): void
    {
        $callCount = 0;
        $computed = new Computed(function () use (&$callCount): int {
            $callCount++;

            return 42;
        });

        // Computed should NOT have run yet
        $this->assertSame(0, $callCount);

        // Now get() triggers evaluation
        $this->assertSame(42, $computed->get());
        $this->assertSame(1, $callCount);
    }

    public function test_dirty_propagation(): void
    {
        $signal = new Signal(1);
        $computed = new Computed(fn (): int => $signal->get() * 10);

        $this->assertSame(10, $computed->get());

        // Setting the signal should mark the computed dirty
        $signal->set(5);

        // get() should re-evaluate since it's dirty
        $this->assertSame(50, $computed->get());
    }

    public function test_caching(): void
    {
        $callCount = 0;
        $signal = new Signal(1);
        $computed = new Computed(function () use ($signal, &$callCount): int {
            $callCount++;

            return $signal->get() * 10;
        });

        // First get() runs the computation
        $computed->get();
        $this->assertSame(1, $callCount);

        // Second get() returns cached value (signal hasn't changed)
        $computed->get();
        $this->assertSame(1, $callCount); // Still 1 — cached

        // Change the signal → computed becomes dirty
        $signal->set(2);
        $computed->get();
        $this->assertSame(2, $callCount); // Re-evaluated

        // Another get() without change → cached again
        $computed->get();
        $this->assertSame(2, $callCount);
    }

    public function test_chain(): void
    {
        $signal = new Signal(2);
        $doubled = new Computed(fn (): int => $signal->get() * 2);
        $quadrupled = new Computed(fn (): int => $doubled->get() * 2);

        $this->assertSame(4, $doubled->get());
        $this->assertSame(8, $quadrupled->get());

        // Change the base signal
        $signal->set(3);

        // The chain should propagate
        $this->assertSame(6, $doubled->get());
        $this->assertSame(12, $quadrupled->get());
    }

    public function test_circular_guard(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('maximum recomputation depth exceeded');

        // Create a computed that calls itself via mutual reference
        // We'll use an indirect approach: create a computed whose recompute
        // triggers another recomputation via a signal cycle.
        $signal = new Signal(0);

        // Build a chain of 101+ Computed nodes to trigger the depth guard
        $computeds = [];
        $computeds[0] = new Computed(fn (): int => $signal->get());
        for ($i = 1; $i <= 110; $i++) {
            $prev = $computeds[$i - 1];
            $computeds[$i] = new Computed(fn (): int => $prev->get() + 1);
        }

        // Getting the deepest computed should trigger the depth guard
        $computeds[110]->get();
    }
}
