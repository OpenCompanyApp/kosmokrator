<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

interface TelegramGatewayWorkerHandleInterface
{
    public function pid(): ?int;

    public function isRunning(): bool;

    public function terminate(int $signal = SIGTERM): bool;
}
