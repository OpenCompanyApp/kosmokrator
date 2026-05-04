<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use PHPUnit\Framework\TestCase;

final class AnimationSignalHandlerTest extends TestCase
{
    public function test_restore_signal_handler_restores_previous_sigint_handler(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('pcntl signal handlers are not available.');
        }

        $original = pcntl_signal_get_handler(SIGINT);
        $previous = static function (): void {};
        $subject = new class
        {
            use AnimationSignalHandler;

            public function install(): void
            {
                $this->installSignalHandler();
            }

            public function restore(): void
            {
                $this->restoreSignalHandler();
            }
        };

        try {
            pcntl_signal(SIGINT, $previous);

            $subject->install();
            $this->assertNotSame($previous, pcntl_signal_get_handler(SIGINT));

            $subject->restore();
            $this->assertSame($previous, pcntl_signal_get_handler(SIGINT));
        } finally {
            pcntl_signal(SIGINT, $original);
        }
    }
}
