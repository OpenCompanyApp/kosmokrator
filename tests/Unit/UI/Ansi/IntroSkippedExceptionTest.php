<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\IntroSkippedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IntroSkippedExceptionTest extends TestCase
{
    public function test_can_be_instantiated(): void
    {
        $exception = new IntroSkippedException();

        $this->assertInstanceOf(IntroSkippedException::class, $exception);
    }

    public function test_is_a_runtime_exception(): void
    {
        $exception = new IntroSkippedException();

        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function test_message_and_code_can_be_set(): void
    {
        $exception = new IntroSkippedException('Intro was skipped', 42);

        $this->assertSame('Intro was skipped', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
    }

    public function test_can_be_caught_as_runtime_exception(): void
    {
        $caught = false;

        try {
            throw new IntroSkippedException('skipped');
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'IntroSkippedException should be catchable as RuntimeException');
    }

    public function test_can_be_thrown_and_caught(): void
    {
        $this->expectException(IntroSkippedException::class);
        $this->expectExceptionMessage('non-interactive terminal');

        throw new IntroSkippedException('non-interactive terminal');
    }
}
