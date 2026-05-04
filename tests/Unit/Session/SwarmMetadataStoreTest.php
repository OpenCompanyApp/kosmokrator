<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SwarmMetadataStore;
use PHPUnit\Framework\TestCase;

final class SwarmMetadataStoreTest extends TestCase
{
    public function test_upsert_and_load_round_trip(): void
    {
        $db = new Database(':memory:');
        $this->insertSession($db, 'session-1');
        $store = new SwarmMetadataStore($db);

        $stats = new SubagentStats('agent-1');
        $stats->parentId = 'root';
        $stats->agentType = 'explore';
        $stats->mode = 'background';
        $stats->status = 'retrying';
        $stats->group = 'cheap-providers';
        $stats->dependsOn = ['agent-0'];
        $stats->task = 'Inspect provider cache behavior';
        $stats->toolCalls = 4;
        $stats->tokensIn = 1200;
        $stats->tokensOut = 300;
        $stats->retries = 2;
        $stats->queueReason = 'retry backoff';
        $stats->lastTool = 'grep';
        $stats->currentTool = 'grep';
        $stats->markModel('anthropic', 'claude-sonnet-4-5');
        $stats->lastMessagePreview = 'Found cache adapter mismatch';
        $stats->nextRetryAt = 1_800_000_010.0;
        $stats->markOutput('/tmp/swarm-output/session-1/agent-1.txt', 12345, 'Cache audit summary');
        $stats->lastActivityDescription = 'running tool: grep';
        $stats->startTime = 1_800_000_000.0;
        $stats->lastActivityTime = 1_800_000_005.0;

        $store->upsertAgent($stats, 'session-1');

        $loaded = $store->latestForSession('session-1');

        $this->assertArrayHasKey('agent-1', $loaded);
        $agent = $loaded['agent-1'];
        $this->assertSame('root', $agent->parentId);
        $this->assertSame('explore', $agent->agentType);
        $this->assertSame('background', $agent->mode);
        $this->assertSame('retrying', $agent->status);
        $this->assertSame('cheap-providers', $agent->group);
        $this->assertSame(['agent-0'], $agent->dependsOn);
        $this->assertSame('Inspect provider cache behavior', $agent->task);
        $this->assertSame(4, $agent->toolCalls);
        $this->assertSame(1200, $agent->tokensIn);
        $this->assertSame(300, $agent->tokensOut);
        $this->assertSame(2, $agent->retries);
        $this->assertSame('retry backoff', $agent->queueReason);
        $this->assertSame('grep', $agent->lastTool);
        $this->assertSame('grep', $agent->currentTool);
        $this->assertSame('running tool: grep', $agent->lastActivityDescription);
        $this->assertSame('anthropic', $agent->provider);
        $this->assertSame('claude-sonnet-4-5', $agent->model);
        $this->assertSame('Found cache adapter mismatch', $agent->lastMessagePreview);
        $this->assertSame(1_800_000_010.0, $agent->nextRetryAt);
        $this->assertSame('/tmp/swarm-output/session-1/agent-1.txt', $agent->outputRef);
        $this->assertSame(12345, $agent->outputBytes);
        $this->assertSame('Cache audit summary', $agent->outputPreview);
    }

    public function test_upsert_updates_existing_agent(): void
    {
        $db = new Database(':memory:');
        $this->insertSession($db, 'session-1');
        $store = new SwarmMetadataStore($db);

        $stats = new SubagentStats('agent-1');
        $stats->parentId = 'root';
        $stats->status = 'running';
        $store->upsertAgent($stats, 'session-1');

        $stats->status = 'done';
        $stats->toolCalls = 3;
        $stats->endTime = 1_800_000_100.0;
        $store->upsertAgent($stats, 'session-1');

        $loaded = $store->latestForSession('session-1');

        $this->assertCount(1, $loaded);
        $this->assertSame('done', $loaded['agent-1']->status);
        $this->assertSame(3, $loaded['agent-1']->toolCalls);
        $this->assertSame(1_800_000_100.0, $loaded['agent-1']->endTime);
    }

    public function test_append_event_and_filter_by_agent(): void
    {
        $db = new Database(':memory:');
        $this->insertSession($db, 'session-1');
        $store = new SwarmMetadataStore($db);

        $store->appendEvent('session-1', 'agent-1', 'status', 'running', 'running tool: grep', ['tool' => 'grep']);
        $store->appendEvent('session-1', 'agent-2', 'status', 'done', 'completed');

        $all = $store->eventsForSession('session-1');
        $filtered = $store->eventsForSession('session-1', 'agent-1');

        $this->assertCount(2, $all);
        $this->assertCount(1, $filtered);
        $this->assertSame('agent-1', $filtered[0]['agent_id']);
        $this->assertSame('status', $filtered[0]['event_type']);
        $this->assertSame('running', $filtered[0]['status']);
        $this->assertSame('running tool: grep', $filtered[0]['message']);
        $this->assertSame(['tool' => 'grep'], $filtered[0]['payload']);
    }

    private function insertSession(Database $db, string $id): void
    {
        $stmt = $db->connection()->prepare(
            'INSERT INTO sessions (id, project, model, created_at, updated_at) VALUES (:id, :project, :model, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'project' => '/tmp/project',
            'model' => 'test/model',
            'now' => gmdate('c'),
        ]);
    }
}
