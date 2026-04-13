<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Kosmokrator\Gateway\GatewayMessageEvent;
use Kosmokrator\Gateway\Telegram\TelegramGatewayWorkerHandleInterface;
use Kosmokrator\Gateway\Telegram\TelegramGatewayWorkerLauncherInterface;

final class FakeTelegramWorkerLauncher implements TelegramGatewayWorkerLauncherInterface
{
    /** @var list<GatewayMessageEvent> */
    public array $launched = [];

    public ?FakeTelegramWorkerHandle $lastHandle = null;

    /** @var (\Closure(GatewayMessageEvent, FakeTelegramWorkerHandle): void)|null */
    public ?\Closure $onLaunch = null;

    public function launch(GatewayMessageEvent $event): TelegramGatewayWorkerHandleInterface
    {
        $this->launched[] = $event;
        $this->lastHandle = new FakeTelegramWorkerHandle(4242 + count($this->launched));

        if ($this->onLaunch !== null) {
            ($this->onLaunch)($event, $this->lastHandle);
        }

        return $this->lastHandle;
    }
}
