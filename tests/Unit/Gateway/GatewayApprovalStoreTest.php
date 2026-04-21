<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway;

use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Session\Database;
use PHPUnit\Framework\TestCase;

final class GatewayApprovalStoreTest extends TestCase
{
    public function test_resolve_latest_pending_updates_status(): void
    {
        $db = new Database(':memory:');
        $db->connection()->exec("INSERT INTO sessions (id, project, model, created_at, updated_at) VALUES ('sess-1', '/project', 'z/GLM', '2026-04-11T00:00:00+00:00', '2026-04-11T00:00:00+00:00')");
        $store = new GatewayApprovalStore($db);

        $pending = $store->createPending('telegram', 'telegram:123', 'sess-1', 'bash', ['command' => 'git status'], '123');
        $resolved = $store->resolveLatestPending('telegram', 'telegram:123', 'approved');

        $this->assertNotNull($resolved);
        $this->assertSame($pending->id, $resolved->id);
        $this->assertSame('approved', $resolved->status);
    }
}
