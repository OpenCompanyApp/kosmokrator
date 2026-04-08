<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Signal;

use OpenCompany\Signal\BatchScope;
use OpenCompany\Signal\Computed;
use OpenCompany\Signal\Effect;
use OpenCompany\Signal\EffectScope;
use OpenCompany\Signal\ReadableSignalInterface;
use OpenCompany\Signal\Signal;
use PHPUnit\Framework\TestCase;

final class SignalTest extends TestCase
{
    public function test_get_set_basic(): void
    {
        $intSignal = new Signal(0);
        $this->assertSame(0, $intSignal->get());
        $intSignal->set(42);
        $this->assertSame(42, $intSignal->get());

        $strSignal = new Signal('hello');
        $this->assertSame('hello', $strSignal->get());
        $strSignal->set('world');
        $this->assertSame('world', $strSignal->get());

        $nullSignal = new Signal(null);
        $this->assertNull($nullSignal->get());
        $nullSignal->set('not-null');
        $this->assertSame('not-null', $nullSignal->get());
    }

    public function test_set_identity_check(): void
    {
        $signal = new Signal(10);
        $called = false;
        $signal->subscribe(function () use (&$called): void {
            $called = true;
        });

        // Setting the same value should NOT trigger subscribers
        $signal->set(10);
        $this->assertFalse($called);

        // Setting a different value should trigger
        $signal->set(20);
        $this->assertTrue($called);
    }

    public function test_update(): void
    {
        $signal = new Signal(5);
        $signal->update(fn (int $v): int => $v * 3);
        $this->assertSame(15, $signal->get());

        $signal->update(fn (int $v): int => $v + 1);
        $this->assertSame(16, $signal->get());
    }

    public function test_subscribe(): void
    {
        $signal = new Signal(0);
        $received = [];
        $unsubscribe = $signal->subscribe(function (mixed $value) use (&$received): void {
            $received[] = $value;
        });

        $signal->set(1);
        $signal->set(2);
        $this->assertSame([1, 2], $received);

        $this->assertIsCallable($unsubscribe);
    }

    public function test_unsubscribe(): void
    {
        $signal = new Signal(0);
        $count = 0;
        $unsubscribe = $signal->subscribe(function () use (&$count): void {
            $count++;
        });

        $signal->set(1);
        $this->assertSame(1, $count);

        $unsubscribe();
        $signal->set(2);
        $this->assertSame(1, $count); // No additional call
    }

    public function test_version_increments(): void
    {
        $signal = new Signal('a');
        $this->assertSame(0, $signal->getVersion());

        $signal->set('b');
        $this->assertSame(1, $signal->getVersion());

        $signal->set('c');
        $this->assertSame(2, $signal->getVersion());

        // Identity set should NOT increment version
        $signal->set('c');
        $this->assertSame(2, $signal->getVersion());
    }

    public function test_value_no_tracking(): void
    {
        $signal = new Signal(42);

        // value() should read without tracking — verify by running inside an EffectScope
        $tracked = [];
        $scope = new EffectScope(function (ReadableSignalInterface|Computed $dep) use (&$tracked): void {
            $tracked[] = $dep;
        });

        $scope->run(function () use ($signal, &$tracked): void {
            $val = $signal->value(); // Should NOT trigger tracking
            $this->assertSame(42, $val);
        });

        // Nothing should have been tracked since we used value() not get()
        $this->assertEmpty($tracked);
    }

    public function test_batch_scope(): void
    {
        $signal = new Signal(0);
        $notifications = [];

        $signal->subscribe(function (mixed $value) use (&$notifications): void {
            $notifications[] = $value;
        });

        BatchScope::run(function () use ($signal): void {
            $signal->set(1);
            $signal->set(2);
            $signal->set(3);
            // Notifications should be deferred — but subscribers fire on flush
        });

        // After batch completes, notifications should have fired
        $this->assertNotEmpty($notifications);
    }

    public function test_subscribe_effect(): void
    {
        $signal = new Signal('a');
        $effect = new Effect(function (): void {
            // Effect that reads the signal
        });

        // subscribeEffect should not throw
        $signal->subscribeEffect($effect);
        $this->assertTrue(true); // Reached without error
    }

    public function test_subscribe_computed(): void
    {
        $signal = new Signal(1);
        $computed = new Computed(fn (): int => $signal->get() * 2);

        // subscribeComputed should not throw
        $signal->subscribeComputed($computed);
        $this->assertTrue(true); // Reached without error
    }
}
