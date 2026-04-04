<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI;

use Kosmokrator\UI\SafeDisplay;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SafeDisplayTest extends TestCase
{
    public function test_successful_callable_is_executed(): void
    {
        $called = false;
        SafeDisplay::call(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_exception_in_callable_is_swallowed(): void
    {
        SafeDisplay::call(function (): never {
            throw new \RuntimeException('boom');
        });

        $this->assertTrue(true); // Reached without exception propagating
    }

    public function test_with_logger_warning_is_logged_on_exception(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $exception = new \RuntimeException('display error');
        $expectedLine = __LINE__ + 2;

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Display call failed',
                $this->callback(function (array $context) use ($exception): bool {
                    return $context['error'] === 'display error'
                        && $context['file'] === $exception->getFile()
                        && $context['line'] === $exception->getLine();
                }),
            );

        SafeDisplay::call(function () use ($exception): never {
            throw $exception;
        }, $logger);
    }

    public function test_without_logger_exception_is_silently_swallowed(): void
    {
        SafeDisplay::call(function (): never {
            throw new \RuntimeException('silent failure');
        }, null);

        $this->assertTrue(true); // No logger, no exception propagation
    }

    public function test_callable_receives_no_arguments(): void
    {
        $receivedArgs = null;
        SafeDisplay::call(function () use (&$receivedArgs): void {
            $receivedArgs = func_get_args();
        });

        $this->assertSame([], $receivedArgs);
    }

    public function test_runtime_exception_is_caught(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        SafeDisplay::call(function (): never {
            throw new \RuntimeException('runtime');
        }, $logger);
    }

    public function test_invalid_argument_exception_is_caught(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        SafeDisplay::call(function (): never {
            throw new \InvalidArgumentException('invalid');
        }, $logger);
    }

    public function test_error_is_caught(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        SafeDisplay::call(function (): never {
            throw new \Error('fatal error');
        }, $logger);
    }
}
