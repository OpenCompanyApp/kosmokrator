<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Agent\SubagentOrchestrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AgentContextTest extends TestCase
{
    private SubagentOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->orchestrator = new SubagentOrchestrator(new NullLogger, 3);
    }

    public function test_can_spawn_when_under_max_depth(): void
    {
        $ctx = new AgentContext(AgentType::General, 0, 3, $this->orchestrator, 'root', '');
        $this->assertTrue($ctx->canSpawn());

        $child = $ctx->childContext(AgentType::Explore, 'child', 'task');
        $this->assertTrue($child->canSpawn());
    }

    public function test_cannot_spawn_at_max_depth(): void
    {
        $ctx = new AgentContext(AgentType::General, 2, 3, $this->orchestrator, 'deep', '');
        $this->assertFalse($ctx->canSpawn());
    }

    public function test_child_context_increments_depth(): void
    {
        $parent = new AgentContext(AgentType::General, 0, 3, $this->orchestrator, 'root', '');
        $child = $parent->childContext(AgentType::Explore, 'child', 'explore task');

        $this->assertSame(1, $child->depth);
        $this->assertSame(AgentType::Explore, $child->type);
        $this->assertSame('child', $child->id);
        $this->assertSame('explore task', $child->task);
    }

    public function test_child_context_rejects_invalid_narrowing(): void
    {
        $parent = new AgentContext(AgentType::Explore, 0, 3, $this->orchestrator, 'root', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("cannot spawn 'general'");
        $parent->childContext(AgentType::General, 'bad', 'task');
    }

    public function test_child_context_shares_orchestrator(): void
    {
        $parent = new AgentContext(AgentType::General, 0, 3, $this->orchestrator, 'root', '');
        $child = $parent->childContext(AgentType::Explore, 'child', 'task');

        $this->assertSame($parent->orchestrator, $child->orchestrator);
    }

    public function test_child_context_preserves_max_depth(): void
    {
        $parent = new AgentContext(AgentType::General, 0, 5, $this->orchestrator, 'root', '');
        $child = $parent->childContext(AgentType::Explore, 'child', 'task');

        $this->assertSame(5, $child->maxDepth);
    }

    public function test_plan_cannot_spawn_general(): void
    {
        $parent = new AgentContext(AgentType::Plan, 0, 3, $this->orchestrator, 'root', '');

        $this->expectException(\InvalidArgumentException::class);
        $parent->childContext(AgentType::General, 'bad', 'task');
    }

    public function test_plan_cannot_spawn_plan(): void
    {
        $parent = new AgentContext(AgentType::Plan, 0, 3, $this->orchestrator, 'root', '');

        $this->expectException(\InvalidArgumentException::class);
        $parent->childContext(AgentType::Plan, 'bad', 'task');
    }
}
