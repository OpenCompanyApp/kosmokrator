<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Primitive;

use Kosmokrator\UI\Tui\Primitive\ReactiveBridge;
use PHPUnit\Framework\TestCase;

final class ReactiveBridgeTest extends TestCase
{
    public function test_start_and_stop(): void
    {
        $bridge = new ReactiveBridge;

        // stop() on a never-started bridge should not crash
        $bridge->stop();
        $this->assertTrue(true); // Verify no exception
    }

    public function test_stop_is_idempotent(): void
    {
        $bridge = new ReactiveBridge;

        // Multiple stops should not throw
        $bridge->stop();
        $bridge->stop();
        $bridge->stop();

        $this->assertTrue(true); // No exception means success
    }
}
