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
        $console = $this->projectRoot.'/bin/kosmo';
        $payload = base64_encode(json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        $logPath = $this->projectRoot.'/storage/logs/telegram-gateway-worker.log';

        if (! is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0777, true);
        }

        $process = new Process([
            $phpBinary,
            $console,
            'gateway:telegram:worker',
            '--event='.$payload,
        ], $this->projectRoot);
        $process->setTimeout(null);
        $process->start(function (string $type, string $buffer) use ($logPath): void {
            if ($buffer === '') {
                return;
            }

            $prefix = $type === Process::ERR ? '[stderr] ' : '[stdout] ';
            file_put_contents(
                $logPath,
                sprintf('[%s] %s%s', date(DATE_ATOM), $prefix, $buffer),
                FILE_APPEND,
            );
        });

        return new SymfonyProcessTelegramWorkerHandle($process);
    }
}
