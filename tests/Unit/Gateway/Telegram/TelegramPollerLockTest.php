<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Kosmokrator\Gateway\Telegram\TelegramPollerLock;
use PHPUnit\Framework\TestCase;

final class TelegramPollerLockTest extends TestCase
{
    public function test_second_acquire_for_same_token_fails(): void
    {
        $lock = TelegramPollerLock::acquire('same-token');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Another Telegram gateway worker is already polling this bot token.');

        try {
            TelegramPollerLock::acquire('same-token');
        } finally {
            unset($lock);
        }
    }
}
