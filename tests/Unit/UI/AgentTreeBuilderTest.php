<?php

namespace Kosmokrator\Tests\Unit\UI;

use Kosmokrator\UI\AgentTreeBuilder;
use PHPUnit\Framework\TestCase;

class AgentTreeBuilderTest extends TestCase
{
    public function test_build_spawn_tree_seeds_queued_nodes_from_subagent_entries(): void
    {
        $tree = AgentTreeBuilder::buildSpawnTree([
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
}
