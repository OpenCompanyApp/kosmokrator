<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Signal;

use Athanor\Computed;
use Athanor\EffectScope;
use Athanor\Signal;
use PHPUnit\Framework\TestCase;

final class EffectScopeTest extends TestCase
{
    public function test_current(): void
    {
        // Outside any scope, current() should be null
        $this->assertNull(EffectScope::current());

        $scope = new EffectScope(function (): void {});
        $insideResult = null;

        $scope->run(function () use (&$insideResult): void {
            $insideResult = EffectScope::current();
        });

        $this->assertSame($scope, $insideResult, 'current() should return active scope inside run()');
        $this->assertNull(EffectScope::current(), 'current() should be null after run() completes');
    }

    public function test_track(): void
    {
        $tracked = [];
        $scope = new EffectScope(function (Signal|Computed $dep) use (&$tracked): void {
            $tracked[] = $dep;
        });

        $signal = new Signal(42);
        $computed = new Computed(fn (): int => $signal->get() + 1);

        $scope->run(function () use ($signal, $computed): void {
            $signal->get();
            $computed->get();
        });

        $this->assertCount(2, $tracked);
        $this->assertSame($signal, $tracked[0]);
        $this->assertSame($computed, $tracked[1]);
    }

    public function test_run(): void
    {
        $stack = [];
        $scope1 = new EffectScope(function () use (&$stack): void {
            $stack[] = 'scope1-track';
        });
        $scope2 = new EffectScope(function () use (&$stack): void {
            $stack[] = 'scope2-track';
        });

        $scope1->run(function () use ($scope2, &$stack): void {
            $stack[] = 'enter-scope1';

            $scope2->run(function () use (&$stack): void {
                $stack[] = 'enter-scope2';
            });

            $stack[] = 'back-in-scope1';
        });

        $stack[] = 'outside';

        $this->assertSame([
            'enter-scope1',
            'enter-scope2',
            'back-in-scope1',
            'outside',
        ], $stack);
    }
}
