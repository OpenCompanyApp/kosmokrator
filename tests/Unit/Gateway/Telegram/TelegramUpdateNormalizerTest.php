<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Kosmokrator\Gateway\Telegram\TelegramUpdateNormalizer;
use PHPUnit\Framework\TestCase;

final class TelegramUpdateNormalizerTest extends TestCase
{
    public function test_normalizes_private_message(): void
    {
        $normalizer = new TelegramUpdateNormalizer('kosmokrator_bot');

        $event = $normalizer->normalize([
            'update_id' => 42,
            'message' => [
                'message_id' => 5,
                'text' => 'hello',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 99, 'username' => 'rutger'],
            ],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('telegram:123', $event->routeKey);
        $this->assertTrue($event->isPrivate);
        $this->assertFalse($event->mentionsBot);
    }

    public function test_detects_mentions_and_thread_keys(): void
    {
        $normalizer = new TelegramUpdateNormalizer('kosmokrator_bot');

        $event = $normalizer->normalize([
            'update_id' => 43,
            'message' => [
                'message_id' => 6,
                'message_thread_id' => 777,
                'text' => '@kosmokrator_bot check this',
                'chat' => ['id' => -1001, 'type' => 'supergroup'],
                'from' => ['id' => 100, 'username' => 'alice'],
                'entities' => [
                    ['type' => 'mention', 'offset' => 0, 'length' => 17],
                ],
            ],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('telegram:-1001:777', $event->routeKey);
        $this->assertTrue($event->mentionsBot);
        $this->assertFalse($event->isPrivate);
    }

    public function test_detects_reply_to_bot(): void
    {
        $normalizer = new TelegramUpdateNormalizer('kosmokrator_bot');

        $event = $normalizer->normalize([
            'update_id' => 44,
            'message' => [
                'message_id' => 7,
                'text' => 'follow up',
                'chat' => ['id' => -2001, 'type' => 'group'],
                'from' => ['id' => 101, 'username' => 'bob'],
                'reply_to_message' => [
                    'from' => ['username' => 'kosmokrator_bot'],
                ],
            ],
        ]);

        $this->assertNotNull($event);
        $this->assertTrue($event->isReplyToBot);
    }

    public function test_normalizes_callback_query_events(): void
    {
        $normalizer = new TelegramUpdateNormalizer('kosmokrator_bot');

        $event = $normalizer->normalize([
            'update_id' => 45,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'ga:approve:12',
                'from' => ['id' => 101, 'username' => 'bob'],
                'message' => [
                    'message_id' => 8,
                    'chat' => ['id' => 123, 'type' => 'private'],
                ],
            ],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('ga:approve:12', $event->text);
        $this->assertSame('cbq-1', $event->callbackQueryId);
        $this->assertSame(8, $event->messageId);
    }
}
