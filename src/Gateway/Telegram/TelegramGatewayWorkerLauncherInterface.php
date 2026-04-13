<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Kosmokrator\Gateway\GatewayMessageEvent;

interface TelegramGatewayWorkerLauncherInterface
{
    public function launch(GatewayMessageEvent $event): TelegramGatewayWorkerHandleInterface;
}
