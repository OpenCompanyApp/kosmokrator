<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Kosmokrator\Gateway\GatewayMessageEvent;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class SymfonyProcessTelegramWorkerLauncher implements TelegramGatewayWorkerLauncherInterface
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function launch(GatewayMessageEvent $event): TelegramGatewayWorkerHandleInterface
    {
        $phpBinary = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
        $console = $this->projectRoot.'/bin/kosmokrator';
        $payload = base64_encode(json_encode($event->toArray(), JSON_THROW_ON_ERROR));

        $process = new Process([
            $phpBinary,
            $console,
            'gateway:telegram:worker',
            '--event='.$payload,
        ], $this->projectRoot);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();

        return new SymfonyProcessTelegramWorkerHandle($process);
    }
}
