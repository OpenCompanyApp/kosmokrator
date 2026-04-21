<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway;

use Kosmokrator\Gateway\GatewaySessionStore;
use Kosmokrator\Session\Database;
use PHPUnit\Framework\TestCase;

final class GatewaySessionStoreTest extends TestCase
{
    public function test_save_and_find_route_link(): void
    {
        $db = new Database(':memory:');
        $db->connection()->exec("INSERT INTO sessions (id, project, model, created_at, updated_at) VALUES ('sess-1', '/project', 'z/GLM', '2026-04-11T00:00:00+00:00', '2026-04-11T00:00:00+00:00')");
        $store = new GatewaySessionStore($db);

        $store->save('telegram', 'telegram:123', 'sess-1', '123', metadata: ['username' => 'rutger']);

        $link = $store->find('telegram', 'telegram:123');

        $this->assertNotNull($link);
        $this->assertSame('sess-1', $link->sessionId);
        $this->assertSame('123', $link->chatId);
        $this->assertSame(['username' => 'rutger'], $link->metadata);
    }

    public function test_delete_removes_route_link(): void
    {
        $db = new Database(':memory:');
        $db->connection()->exec("INSERT INTO sessions (id, project, model, created_at, updated_at) VALUES ('sess-1', '/project', 'z/GLM', '2026-04-11T00:00:00+00:00', '2026-04-11T00:00:00+00:00')");
        $store = new GatewaySessionStore($db);

        $store->save('telegram', 'telegram:123', 'sess-1', '123');
        $store->delete('telegram', 'telegram:123');

        $this->assertNull($store->find('telegram', 'telegram:123'));
    }
}
