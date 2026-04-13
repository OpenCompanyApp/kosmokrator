<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Kosmokrator\Gateway\Telegram\TelegramGatewayWorkerHandleInterface;

final class FakeTelegramWorkerHandle implements TelegramGatewayWorkerHandleInterface
{
    public bool $running = true;

    public bool $terminated = false;

    public function __construct(
        private readonly ?int $pid = 4242,
    ) {}

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function terminate(int $signal = SIGTERM): bool
    {
        $this->terminated = true;
        $this->running = false;

        return true;
    }
}
