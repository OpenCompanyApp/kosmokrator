<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Bootstrap;

use Kosmokrator\Bootstrap\BootstrapSignalGuard;
use PHPUnit\Framework\TestCase;

final class BootstrapSignalGuardTest extends TestCase
{
    public function test_exit_code_for_signal_uses_shell_signal_convention(): void
    {
        $this->assertSame(130, BootstrapSignalGuard::exitCodeForSignal(SIGINT));
        $this->assertSame(143, BootstrapSignalGuard::exitCodeForSignal(SIGTERM));
    }

    public function test_block_and_unblock_are_safe_when_pcntl_is_available(): void
    {
        $guard = new BootstrapSignalGuard;

        $guard->block();
        $guard->unblockAndDispatch();

        $this->expectNotToPerformAssertions();
    }
}
