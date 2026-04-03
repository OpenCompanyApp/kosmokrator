<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Kosmokrator\Command\Slash\SessionFormatter;
use PHPUnit\Framework\TestCase;

class SessionFormatterTest extends TestCase
{
    public function testEmptyTimestampReturnsQuestionMark(): void
    {
        $this->assertSame('?', SessionFormatter::formatAge(''));
    }

    public function testRecentTimestampReturnsJustNow(): void
    {
        $ts = (string) (time() - 30);

        $this->assertSame('just now', SessionFormatter::formatAge($ts));
    }

    public function testFiveMinutesAgoReturns5mAgo(): void
    {
        $ts = (string) (time() - 300);

        $this->assertSame('5m ago', SessionFormatter::formatAge($ts));
    }

    public function testTwoHoursAgoReturns2hAgo(): void
    {
        $ts = (string) (time() - 7200);

        $this->assertSame('2h ago', SessionFormatter::formatAge($ts));
    }

    public function testThreeDaysAgoReturns3dAgo(): void
    {
        $ts = (string) (time() - 259200);

        $this->assertSame('3d ago', SessionFormatter::formatAge($ts));
    }

    public function testCurrentTimeReturnsJustNow(): void
    {
        $ts = (string) time();

        $this->assertSame('just now', SessionFormatter::formatAge($ts));
    }

    public function testFutureTimestampReturnsJustNow(): void
    {
        $ts = (string) (time() + 10);

        $this->assertSame('just now', SessionFormatter::formatAge($ts));
    }
}
