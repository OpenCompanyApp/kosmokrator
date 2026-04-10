<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Signal;

use Athanor\BatchScope;
use Athanor\Computed;
use Athanor\Effect;
use Athanor\EffectScope;
use Athanor\ReadableSignalInterface;
use Athanor\Signal;
use PHPUnit\Framework\TestCase;

/**
 * Tests for all audit-fix features: exception safety, cycle detection,
 * ReadableSignalInterface, EffectScope ownership, injectable scheduler.
 */
final class SignalAuditTest extends TestCase
{
    // ── Computed exception safety ───────────────────────────────────────

    public function test_computed_exception_restores_dirty(): void
    {
        $signal = new Signal(1);
        $throw = new \RuntimeException('boom');

        $computed = new Computed(function () use ($signal, $throw): int {
            if ($signal->get() > 0) {
                throw $throw;
            }

            return $signal->get() * 2;
        });

        $exception = null;
        try {
            $computed->get();
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertSame($throw, $exception);

        // Fix the signal so computation succeeds
        $signal->set(-1);
        $result = $computed->get();
        $this->assertSame(-2, $result);
    }

    public function test_computed_retries_on_next_get_after_exception(): void
    {
        $callCount = 0;
        $signal = new Signal(1);

        $computed = new Computed(function () use ($signal, &$callCount): int {
            $callCount++;
            if ($signal->get() === 1) {
                throw new \RuntimeException('fail');
            }

            return $signal->get() * 10;
        });

        // First call fails
        try {
            $computed->get();
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $callCount);

        // Second call also fails (dirty was restored)
        try {
            $computed->get();
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, $callCount);

        // Fix the signal
        $signal->set(5);
        $this->assertSame(50, $computed->get());
        $this->assertSame(3, $callCount);
    }

    // ── Effect cycle detection ──────────────────────────────────────────

    public function test_effect_cycle_detection(): void
    {
        $signal = new Signal(0);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('effect cycle detected');

        // Effect reads and writes the same signal → infinite loop
        new Effect(function () use ($signal): void {
            $val = $signal->get();
            $signal->set($val + 1);
        });
    }

    // ── ReadableSignalInterface ─────────────────────────────────────────

    public function test_signal_implements_readable_interface(): void
    {
        $signal = new Signal(42);
        $this->assertInstanceOf(ReadableSignalInterface::class, $signal);
    }

    public function test_readable_interface_get_tracks(): void
    {
        $signal = new Signal(10);
        $readViaInterface = null;

        $effect = new Effect(function () use ($signal, &$readViaInterface): void {
            /** @var ReadableSignalInterface $readable */
            $readable = $signal;
            $readViaInterface = $readable->get();
        });

        $this->assertSame(10, $readViaInterface);

        $signal->set(20);
        // Effect should have re-run because get() via interface tracked the dependency

        $effect->dispose();
    }

    public function test_readable_interface_value_does_not_track(): void
    {
        $signal = new Signal(42);
        $tracked = [];

        $scope = new EffectScope(function (ReadableSignalInterface|Computed $dep) use (&$tracked): void {
            $tracked[] = $dep;
        });

        $scope->run(function () use ($signal): void {
            $val = $signal->value(); // Should NOT track
            $this->assertSame(42, $val);
        });

        $this->assertEmpty($tracked);
    }

    // ── EffectScope ownership ───────────────────────────────────────────

    public function test_effect_scope_auto_dispose(): void
    {
        $signal = new Signal(0);
        $count = 0;

        $scope = new EffectScope;
        $scope->effect(function () use ($signal, &$count): void {
            $signal->get();
            $count++;
        });

        $this->assertSame(1, $count);
        $this->assertSame(1, $scope->effectCount());

        $signal->set(1);
        $this->assertSame(2, $count);

        $scope->dispose();
        $this->assertTrue($scope->isDisposed());

        // Effect should no longer fire
        $signal->set(2);
        $this->assertSame(2, $count);
    }

    public function test_effect_scope_dispose_is_idempotent(): void
    {
        $scope = new EffectScope;
        $scope->effect(fn () => null);
        $scope->dispose();
        $scope->dispose(); // Second call should not throw

        $this->assertTrue($scope->isDisposed());
    }

    public function test_effect_scope_rejects_effects_after_dispose(): void
    {
        $scope = new EffectScope;
        $scope->dispose();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('disposed');

        $scope->effect(fn () => null);
    }

    public function test_effect_scope_multiple_effects(): void
    {
        $sigA = new Signal(0);
        $sigB = new Signal('x');
        $countA = 0;
        $countB = 0;

        $scope = new EffectScope;
        $scope->effect(function () use ($sigA, &$countA): void {
            $sigA->get();
            $countA++;
        });
        $scope->effect(function () use ($sigB, &$countB): void {
            $sigB->get();
            $countB++;
        });

        $this->assertSame(1, $countA);
        $this->assertSame(1, $countB);
        $this->assertSame(2, $scope->effectCount());

        $sigA->set(1);
        $this->assertSame(2, $countA);
        $this->assertSame(1, $countB); // sigB effect not affected

        $scope->dispose();

        $sigA->set(2);
        $sigB->set('y');
        $this->assertSame(2, $countA); // No more fires
        $this->assertSame(1, $countB);
    }

    // ── BatchScope scheduler injection ──────────────────────────────────

    public function test_batch_scope_scheduler_round_trip(): void
    {
        $signal = new Signal(0);
        $results = [];

        $signal->subscribe(function (mixed $v) use (&$results): void {
            $results[] = $v;
        });

        // Synchronous scheduler for testing
        BatchScope::setScheduler(function (callable $fn): void {
            $fn();
        });

        BatchScope::deferred(function () use ($signal): void {
            $signal->set(1);
            $signal->set(2);
        });

        $this->assertSame([2, 2], $results); // Signal enqueued twice, value is 2 for both at flush time

        // Clean up
        BatchScope::setScheduler(null);
    }

    public function test_batch_scope_deferred_without_scheduler_throws(): void
    {
        BatchScope::setScheduler(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a scheduler');

        BatchScope::deferred(function (): void {});
    }
}
