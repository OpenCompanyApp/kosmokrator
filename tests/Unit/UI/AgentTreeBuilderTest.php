<?php

namespace Kosmokrator\Tests\Unit\UI;

use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\UI\AgentTreeBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AgentTreeBuilderTest extends TestCase
{
    public function test_build_spawn_tree_seeds_queued_nodes_from_subagent_entries(): void
    {
        $builder = new AgentTreeBuilder;
        $tree = $builder->buildSpawnTree([
            [
                'id' => 'fallback-id',
                'args' => [
                    'id' => 'agent-core',
                    'type' => 'general',
                    'task' => 'Inspect the agent loop and session flow',
                ],
            ],
            [
                'args' => [
                    'type' => 'explore',
                    'task' => 'Audit the TUI widgets',
                ],
            ],
        ]);

        $this->assertCount(2, $tree);
        $this->assertSame('agent-core', $tree[0]['id']);
        $this->assertSame('general', $tree[0]['type']);
        $this->assertSame('queued', $tree[0]['status']);
        $this->assertSame('Inspect the agent loop and session flow', $tree[0]['task']);
        $this->assertSame(0, $tree[0]['toolCalls']);
        $this->assertSame([], $tree[0]['children']);

        $this->assertSame('agent-2', $tree[1]['id']);
        $this->assertSame('explore', $tree[1]['type']);
        $this->assertSame('queued', $tree[1]['status']);
        $this->assertSame('Audit the TUI widgets', $tree[1]['task']);
    }

    public function test_build_tree_includes_live_activity_fields(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger);
        $stats = new SubagentStats('agent-live');
        $stats->parentId = 'root';
        $stats->agentType = 'explore';
        $stats->task = 'Read cache files';
        $stats->status = 'running';
        $stats->markQueueReason('global concurrency limit');
        $stats->markTool('grep');
        $stats->markMessagePreview('Scanning src/Agent');
        $stats->nextRetryAt = microtime(true) + 30.0;

        $property = new \ReflectionProperty(SubagentOrchestrator::class, 'stats');
        $property->setValue($orchestrator, ['agent-live' => $stats]);

        $tree = (new AgentTreeBuilder)->buildTree($orchestrator);

        $this->assertSame('global concurrency limit', $tree[0]['queueReason']);
        $this->assertSame('grep', $tree[0]['lastTool']);
        $this->assertSame('Scanning src/Agent', $tree[0]['lastMessagePreview']);
        $this->assertSame($stats->nextRetryAt, $tree[0]['nextRetryAt']);
    }

    public function test_build_tree_preserves_nested_hierarchy_from_indexed_stats(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger);
        $parent = $this->stats('parent', 'running');
        $child = $this->stats('child', 'done', 'parent');

        $this->setOrchestratorStats($orchestrator, $child, $parent);

        $tree = (new AgentTreeBuilder)->buildTree($orchestrator, null);

        $this->assertSame('parent', $tree[0]['id']);
        $this->assertSame('child', $tree[0]['children'][0]['id']);
    }

    public function test_build_tree_orders_agents_by_operational_relevance(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger);

        $done = $this->stats('done', 'done', activity: 40.0);
        $failed = $this->stats('failed', 'failed', activity: 10.0);
        $running = $this->stats('running', 'running', activity: 20.0);
        $retrying = $this->stats('retrying', 'retrying', activity: 1.0);

        $this->setOrchestratorStats($orchestrator, $done, $failed, $running, $retrying);

        $tree = (new AgentTreeBuilder)->buildTree($orchestrator, null);

        $this->assertSame(['retrying', 'running', 'failed', 'done'], array_column($tree, 'id'));
    }

    public function test_build_tree_collapses_large_sibling_groups(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger);

        $this->setOrchestratorStats(
            $orchestrator,
            $this->stats('done-1', 'done', activity: 5.0),
            $this->stats('done-2', 'done', activity: 4.0),
            $this->stats('done-3', 'done', activity: 3.0),
            $this->stats('done-4', 'done', activity: 2.0),
            $this->stats('done-5', 'done', activity: 1.0),
        );

        $tree = (new AgentTreeBuilder)->buildTree($orchestrator, 2);

        $this->assertSame(['done-1', 'done-2', 'summary-root'], array_column($tree, 'id'));
        $this->assertSame('summary', $tree[2]['status']);
        $this->assertSame(3, $tree[2]['hiddenCount']);
        $this->assertSame(['done' => 3], $tree[2]['hiddenStatuses']);
        $this->assertStringContainsString('3 more agents', $tree[2]['task']);
    }

    private function stats(string $id, string $status, string $parentId = 'root', float $activity = 0.0): SubagentStats
    {
        $stats = new SubagentStats($id);
        $stats->parentId = $parentId;
        $stats->agentType = 'explore';
        $stats->task = "Task {$id}";
        $stats->status = $status;
        $stats->startTime = $activity;
        $stats->lastActivityTime = $activity;
        $stats->endTime = $status === 'done' || $status === 'failed' ? $activity : 0.0;

        return $stats;
    }

    private function setOrchestratorStats(SubagentOrchestrator $orchestrator, SubagentStats ...$stats): void
    {
        $indexed = [];
        foreach ($stats as $agentStats) {
            $indexed[$agentStats->id] = $agentStats;
        }

        $property = new \ReflectionProperty(SubagentOrchestrator::class, 'stats');
        $property->setValue($orchestrator, $indexed);
    }
}
