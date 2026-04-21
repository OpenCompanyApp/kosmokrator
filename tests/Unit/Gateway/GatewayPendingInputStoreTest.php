<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway;

use Kosmokrator\Gateway\GatewayMessageEvent;
use Kosmokrator\Gateway\GatewayPendingInputStore;
use Kosmokrator\Session\Database;
use PHPUnit\Framework\TestCase;

final class GatewayPendingInputStoreTest extends TestCase
{
    public function test_enqueue_count_and_fifo_dequeue(): void
    {
        $store = new GatewayPendingInputStore($db = new Database(':memory:'));

        $first = new GatewayMessageEvent(
            updateId: 1,
            platform: 'telegram',
            chatId: '123',
            threadId: null,
            routeKey: 'telegram:123',
            text: 'first',
            userId: '5',
            username: 'rutger',
            isPrivate: true,
            isReplyToBot: false,
            mentionsBot: false,
            messageId: 11,
        );
        $second = new GatewayMessageEvent(
            updateId: 2,
            platform: 'telegram',
            chatId: '123',
            threadId: null,
            routeKey: 'telegram:123',
            text: 'second',
            userId: '5',
            username: 'rutger',
            isPrivate: true,
            isReplyToBot: false,
            mentionsBot: false,
            messageId: 12,
        );

        $store->enqueue('telegram', 'telegram:123', $first);
        $store->enqueue('telegram', 'telegram:123', $second);

        $this->assertSame(2, $store->count('telegram', 'telegram:123'));

        $next = $store->dequeueNext('telegram', 'telegram:123');
        $this->assertNotNull($next);
        $this->assertSame('first', (string) ($next->payload['text'] ?? null));
        $this->assertSame(1, $store->count('telegram', 'telegram:123'));

        $next = $store->dequeueNext('telegram', 'telegram:123');
        $this->assertNotNull($next);
        $this->assertSame('second', (string) ($next->payload['text'] ?? null));
        $this->assertSame(0, $store->count('telegram', 'telegram:123'));
    }

    public function test_clear_removes_pending_inputs_for_route(): void
    {
        $store = new GatewayPendingInputStore($db = new Database(':memory:'));

        $event = new GatewayMessageEvent(
            updateId: 1,
            platform: 'telegram',
            chatId: '123',
            threadId: null,
            routeKey: 'telegram:123',
            text: 'hello',
            userId: '5',
            username: 'rutger',
            isPrivate: true,
            isReplyToBot: false,
            mentionsBot: false,
        );

        $store->enqueue('telegram', 'telegram:123', $event);
        $store->clear('telegram', 'telegram:123');

        $this->assertSame(0, $store->count('telegram', 'telegram:123'));
        $this->assertNull($store->dequeueNext('telegram', 'telegram:123'));
    }
}
