<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Kosmokrator\Gateway\GatewayMessageEvent;

final readonly class TelegramSessionRouter
{
    public function __construct(
        private string $mode = 'thread',
    ) {}

    public function routeKeyFor(GatewayMessageEvent $event): string
    {
        if ($event->isPrivate) {
            return 'telegram:'.$event->chatId;
        }

        return match ($this->mode) {
            'chat' => 'telegram:'.$event->chatId,
            'chat_user' => 'telegram:'.$event->chatId.':user:'.($event->userId ?? 'anon'),
            'thread_user' => 'telegram:'.$event->chatId.':'.($event->threadId ?? 'main').':user:'.($event->userId ?? 'anon'),
            default => 'telegram:'.$event->chatId.($event->threadId !== null ? ':'.$event->threadId : ''),
        };
    }
}
