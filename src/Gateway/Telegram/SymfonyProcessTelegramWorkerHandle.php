<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Symfony\Component\Process\Process;

final class SymfonyProcessTelegramWorkerHandle implements TelegramGatewayWorkerHandleInterface
{
    public function __construct(
        private readonly Process $process,
    ) {}

    public function pid(): ?int
    {
        $pid = $this->process->getPid();

        return is_int($pid) && $pid > 0 ? $pid : null;
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function terminate(int $signal = SIGTERM): bool
    {
        if (! $this->process->isRunning()) {
            return false;
        }

        $this->process->signal($signal);

        return true;
    }
}
