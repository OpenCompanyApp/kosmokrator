<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Kosmokrator\Command\Slash\SessionFormatter;
use PHPUnit\Framework\TestCase;

class SessionFormatterTest extends TestCase
{
    public function test_empty_timestamp_returns_question_mark(): void
    {
        $this->assertSame('?', SessionFormatter::formatAge(''));
    }

    public function test_recent_timestamp_returns_just_now(): void
    {
        $ts = (string) (time() - 30);

        $this->assertSame('just now', SessionFormatter::formatAge($ts));
    }

    public function test_five_minutes_ago_returns5m_ago(): void
    {
        $ts = (string) (time() - 300);

        $this->assertSame('5m ago', SessionFormatter::formatAge($ts));
    }

    public function test_two_hours_ago_returns2h_ago(): void
    {
        $ts = (string) (time() - 7200);

        $this->assertSame('2h ago', SessionFormatter::formatAge($ts));
    }

    public function test_three_days_ago_returns3d_ago(): void
    {
        $ts = (string) (time() - 259200);

        $this->assertSame('3d ago', SessionFormatter::formatAge($ts));
    }

    public function test_current_time_returns_just_now(): void
    {
        $ts = (string) time();

        $this->assertSame('just now', SessionFormatter::formatAge($ts));
    }

    public function test_future_timestamp_returns_just_now(): void
    {
        $ts = (string) (time() + 10);

        $this->assertSame('just now', SessionFormatter::formatAge($ts));
    }
}
