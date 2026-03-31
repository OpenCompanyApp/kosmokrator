<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Agent\SubagentOrchestrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SubagentOrchestratorTest extends TestCase
{
    private SubagentOrchestrator $orchestrator;

    private AgentContext $rootContext;

    protected function setUp(): void
    {
        $this->orchestrator = new SubagentOrchestrator(new NullLogger, 3);
        $this->rootContext = new AgentContext(
            AgentType::General, 0, 3, $this->orchestrator, 'root', '',
        );
    }

    public function test_spawn_returns_future(): void
    {
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'do stuff', AgentType::Explore, 'await', 'test-1', [], null,
            fn ($ctx, $task) => "result: {$task}",
        );

        $this->assertSame('result: do stuff', $future->await());
    }

    public function test_await_mode_blocks_until_complete(): void
    {
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'task', AgentType::Explore, 'await', 'a1', [], null,
            fn ($ctx, $task) => 'done',
        );

        $this->assertSame('done', $future->await());
    }

    public function test_background_stores_pending_result(): void
    {
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'bg task', AgentType::Explore, 'background', 'bg-1', [], null,
            fn ($ctx, $task) => 'bg result',
        );
        $future->await(); // Wait for it to actually complete

        $pending = $this->orchestrator->collectPendingResults();
        $this->assertArrayHasKey('bg-1', $pending);
        $this->assertSame('bg result', $pending['bg-1']);
    }

    public function test_collect_pending_drains(): void
    {
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'task', AgentType::Explore, 'background', 'x', [], null,
            fn ($ctx, $task) => 'result',
        );
        $future->await();

        $first = $this->orchestrator->collectPendingResults();
        $this->assertCount(1, $first);

        $second = $this->orchestrator->collectPendingResults();
        $this->assertEmpty($second);
    }

    public function test_depends_on_unknown_throws(): void
    {
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'task', AgentType::Explore, 'await', 'dep-test', ['nonexistent'], null,
            fn ($ctx, $task) => 'never',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown dependency agent: 'nonexistent'");
        $future->await();
    }

    public function test_depends_on_waits_for_dependency(): void
    {
        $order = [];

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'first', AgentType::Explore, 'background', 'dep-a', [], null,
            function ($ctx, $task) use (&$order) {
                $order[] = 'a';

                return 'result-a';
            },
        );

        $futureB = $this->orchestrator->spawnAgent(
            $this->rootContext, 'second', AgentType::Explore, 'await', 'dep-b', ['dep-a'], null,
            function ($ctx, $task) use (&$order) {
                $order[] = 'b';
                $this->assertStringContains('result-a', $task);

                return 'result-b';
            },
        );

        $result = $futureB->await();
        $this->assertSame('result-b', $result);
        $this->assertSame(['a', 'b'], $order);
    }

    public function test_generate_id_increments(): void
    {
        $id1 = $this->orchestrator->generateId();
        $id2 = $this->orchestrator->generateId();
        $this->assertSame('agent-1', $id1);
        $this->assertSame('agent-2', $id2);
    }

    public function test_total_tokens_sums_all_agents(): void
    {
        $f1 = $this->orchestrator->spawnAgent(
            $this->rootContext, 't1', AgentType::Explore, 'await', 's1', [], null,
            fn ($ctx, $task) => 'r1',
        );
        $f1->await();

        $f2 = $this->orchestrator->spawnAgent(
            $this->rootContext, 't2', AgentType::Explore, 'await', 's2', [], null,
            fn ($ctx, $task) => 'r2',
        );
        $f2->await();

        // Manually set tokens on stats
        $this->orchestrator->getStats('s1')->addTokens(100, 50);
        $this->orchestrator->getStats('s2')->addTokens(200, 80);

        $totals = $this->orchestrator->totalTokens();
        $this->assertSame(300, $totals['in']);
        $this->assertSame(130, $totals['out']);
    }

    public function test_failed_agent_sets_error_in_stats(): void
    {
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'fail', AgentType::Explore, 'await', 'fail-1', [], null,
            fn ($ctx, $task) => throw new \RuntimeException('boom'),
        );

        try {
            $future->await();
        } catch (\RuntimeException) {
            // expected
        }

        $stats = $this->orchestrator->getStats('fail-1');
        $this->assertSame('failed', $stats->status);
        $this->assertSame('boom', $stats->error);
    }

    public function test_failed_dependency_does_not_kill_dependent(): void
    {
        // Agent A fails
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'fail', AgentType::Explore, 'background', 'dep-fail', [], null,
            fn ($ctx, $task) => throw new \RuntimeException('upstream crash'),
        );

        // Agent B depends on A — should still run with error marker
        $futureB = $this->orchestrator->spawnAgent(
            $this->rootContext, 'consumer', AgentType::Explore, 'await', 'dep-consumer', ['dep-fail'], null,
            function ($ctx, $task) {
                $this->assertStringContainsString('[FAILED]', $task);
                $this->assertStringContainsString('upstream crash', $task);

                return 'recovered';
            },
        );

        $result = $futureB->await();
        $this->assertSame('recovered', $result);
        $this->assertSame('done', $this->orchestrator->getStats('dep-consumer')->status);
    }

    public function test_degraded_dependency_gets_warning(): void
    {
        // Agent A returns an error string (not an exception)
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'degrade', AgentType::Explore, 'background', 'dep-deg', [], null,
            fn ($ctx, $task) => 'Error: rate limited after 3 attempts',
        );

        // Agent B depends on A — should see warning marker
        $futureB = $this->orchestrator->spawnAgent(
            $this->rootContext, 'consumer', AgentType::Explore, 'await', 'dep-consumer2', ['dep-deg'], null,
            function ($ctx, $task) {
                $this->assertStringContainsString('DEPENDENCY RETURNED AN ERROR', $task);
                $this->assertStringContainsString('rate limited', $task);

                return 'handled';
            },
        );

        $this->assertSame('handled', $futureB->await());
    }

    public function test_stats_track_parent_id_and_depth(): void
    {
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'child task', AgentType::Explore, 'await', 'child-1', [], null,
            fn ($ctx, $task) => 'done',
        )->await();

        $stats = $this->orchestrator->allStats();
        $this->assertSame('root', $stats['child-1']->parentId);
        $this->assertSame(1, $stats['child-1']->depth);
    }

    public function test_nested_stats_track_hierarchy(): void
    {
        // Root spawns child, child spawns grandchild
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'parent task', AgentType::General, 'await', 'parent-1', [], null,
            function ($ctx, $task) {
                $this->orchestrator->spawnAgent(
                    $ctx, 'grandchild task', AgentType::Explore, 'await', 'grandchild-1', [], null,
                    fn ($ctx2, $task2) => 'leaf result',
                )->await();

                return 'parent result';
            },
        )->await();

        $stats = $this->orchestrator->allStats();

        // Parent: depth 1, parentId = root
        $this->assertSame('root', $stats['parent-1']->parentId);
        $this->assertSame(1, $stats['parent-1']->depth);

        // Grandchild: depth 2, parentId = parent-1
        $this->assertSame('parent-1', $stats['grandchild-1']->parentId);
        $this->assertSame(2, $stats['grandchild-1']->depth);
    }

    /**
     * PHPUnit helper for asserting substring.
     */
    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertStringContainsString($needle, $haystack);
    }
}
