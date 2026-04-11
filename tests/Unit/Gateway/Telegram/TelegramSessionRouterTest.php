<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Kosmokrator\Gateway\GatewayMessageEvent;
use Kosmokrator\Gateway\Telegram\TelegramSessionRouter;
use PHPUnit\Framework\TestCase;

final class TelegramSessionRouterTest extends TestCase
{
    public function test_thread_mode_uses_thread_when_present(): void
    {
        $router = new TelegramSessionRouter('thread');
        $event = new GatewayMessageEvent(1, 'telegram', '-100', '77', 'telegram:-100', 'hi', '5', 'alice', false, false, false, 10);

        $this->assertSame('telegram:-100:77', $router->routeKeyFor($event));
    }

    public function test_chat_user_mode_isolates_users_in_shared_chat(): void
    {
        $router = new TelegramSessionRouter('chat_user');
        $event = new GatewayMessageEvent(1, 'telegram', '-100', null, 'telegram:-100', 'hi', '5', 'alice', false, false, false, 10);

        $this->assertSame('telegram:-100:user:5', $router->routeKeyFor($event));
    }

    public function test_private_chats_always_use_chat_scope(): void
    {
        $router = new TelegramSessionRouter('thread_user');
        $event = new GatewayMessageEvent(1, 'telegram', '123', null, 'telegram:123', 'hi', '5', 'alice', true, false, false, 10);

        $this->assertSame('telegram:123', $router->routeKeyFor($event));
    }
}
