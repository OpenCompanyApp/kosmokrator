<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Amp\CancelledException;
use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\LLM\RetryableHttpException;
use Kosmokrator\LLM\ToolCallMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

class SubagentOrchestratorTest extends TestCase
{
    private SubagentOrchestrator $orchestrator;

    private AgentContext $rootContext;

    protected function setUp(): void
    {
        $this->orchestrator = new SubagentOrchestrator(new NullLogger, 3, maxRetries: 0);
        $this->rootContext = new AgentContext(
            AgentType::General, 0, 3, $this->orchestrator, 'root', '',
        );
    }

    protected function tearDown(): void
    {
        $this->orchestrator->cancelAll();
        $previousHandler = EventLoop::setErrorHandler(function (\Throwable $e) {});
        // Tick the event loop to let cancellation propagate to pending fibers
        \Amp\delay(0.05);
        // Suppress UnhandledFutureError from futures that resolve with errors
        $this->orchestrator->ignorePendingFutures();
        EventLoop::setErrorHandler($previousHandler);
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

        $pending = $this->orchestrator->collectPendingResults('root');
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

        $first = $this->orchestrator->collectPendingResults('root');
        $this->assertCount(1, $first);

        $second = $this->orchestrator->collectPendingResults('root');
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
        // Agent A fails (background mode — returns error string instead of throwing)
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'fail', AgentType::Explore, 'background', 'dep-fail', [], null,
            fn ($ctx, $task) => throw new \RuntimeException('upstream crash'),
        );

        // Agent B depends on A — should still run with error info in the task
        $futureB = $this->orchestrator->spawnAgent(
            $this->rootContext, 'consumer', AgentType::Explore, 'await', 'dep-consumer', ['dep-fail'], null,
            function ($ctx, $task) {
                $this->assertStringContainsString('THIS DEPENDENCY FAILED', $task);
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
            fn ($ctx, $task) => ToolCallMapper::ERROR_PREFIX.'rate limited after 3 attempts',
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

    public function test_concurrency_limits_parallel_agents(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 1, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $running = 0;
        $maxRunning = 0;

        $factory = function ($ctx, $task) use (&$running, &$maxRunning) {
            $running++;
            $maxRunning = max($maxRunning, $running);
            // Yield to allow other fibers to attempt acquire
            \Amp\delay(0.01);
            $running--;

            return 'done';
        };

        $futures = [];
        for ($i = 0; $i < 3; $i++) {
            $futures[] = $orchestrator->spawnAgent(
                $context, "task-{$i}", AgentType::Explore, 'await', "c-{$i}", [], null, $factory,
            );
        }

        // Await all
        foreach ($futures as $f) {
            $f->await();
        }

        $this->assertSame(1, $maxRunning, 'Only 1 agent should run at a time with concurrency=1');
    }

    public function test_concurrency_zero_means_unlimited(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 0, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $running = 0;
        $maxRunning = 0;

        $factory = function ($ctx, $task) use (&$running, &$maxRunning) {
            $running++;
            $maxRunning = max($maxRunning, $running);
            \Amp\delay(0.01);
            $running--;

            return 'done';
        };

        $futures = [];
        for ($i = 0; $i < 5; $i++) {
            $futures[] = $orchestrator->spawnAgent(
                $context, "task-{$i}", AgentType::Explore, 'await', "u-{$i}", [], null, $factory,
            );
        }

        foreach ($futures as $f) {
            $f->await();
        }

        $this->assertGreaterThan(1, $maxRunning, 'With concurrency=0, multiple agents should run in parallel');
    }

    public function test_stats_show_queued_global(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 1, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $observedStatus = null;

        // First agent blocks so second must queue
        $orchestrator->spawnAgent(
            $context, 'blocker', AgentType::Explore, 'await', 'blocker', [], null,
            function ($ctx, $task) use ($orchestrator, &$observedStatus) {
                // While this runs, check second agent's status
                \Amp\delay(0.01);
                $observedStatus = $orchestrator->getStats('waiter')?->status;

                return 'done';
            },
        );

        $orchestrator->spawnAgent(
            $context, 'waiter', AgentType::Explore, 'background', 'waiter', [], null,
            fn ($ctx, $task) => 'done',
        );

        // Wait a tick for both fibers to start
        \Amp\delay(0.05);

        $this->assertSame('queued_global', $observedStatus);
    }

    public function test_global_semaphore_released_on_failure(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 1, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        // First agent fails
        $f1 = $orchestrator->spawnAgent(
            $context, 'fail', AgentType::Explore, 'await', 'fail-g', [], null,
            fn ($ctx, $task) => throw new \RuntimeException('boom'),
        );

        try {
            $f1->await();
        } catch (\RuntimeException) {
        }

        // Second agent should be able to acquire the semaphore (not deadlocked)
        $f2 = $orchestrator->spawnAgent(
            $context, 'after-fail', AgentType::Explore, 'await', 'after-g', [], null,
            fn ($ctx, $task) => 'ok',
        );

        $this->assertSame('ok', $f2->await());
    }

    public function test_get_concurrency_returns_configured_value(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 25, 0);
        $this->assertSame(25, $orchestrator->getConcurrency());
    }

    public function test_retry_succeeds_on_second_attempt(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'flaky task', AgentType::Explore, 'await', 'retry-1', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;
                if ($attempt === 1) {
                    return ToolCallMapper::ERROR_PREFIX.'context overflow after 3 trim attempts';
                }

                return 'success on retry';
            },
        );

        $result = $future->await();
        $this->assertSame('success on retry', $result);
        $this->assertSame(2, $attempt);

        $stats = $orchestrator->getStats('retry-1');
        $this->assertSame('done', $stats->status);
        $this->assertSame(1, $stats->retries);
    }

    public function test_non_retryable_result_skips_retry(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'cancelled task', AgentType::Explore, 'await', 'noretry-1', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;

                return '(cancelled)';
            },
        );

        $result = $future->await();
        $this->assertSame('(cancelled)', $result);
        $this->assertSame(1, $attempt);
        $this->assertSame('cancelled', $orchestrator->getStats('noretry-1')->status);
        $this->assertSame(0, $orchestrator->getStats('noretry-1')->retries);
    }

    public function test_max_retries_exhausted(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'always fails', AgentType::Explore, 'await', 'exhaust-1', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;

                return ToolCallMapper::ERROR_PREFIX.'something broke';
            },
        );

        $result = $future->await();
        $this->assertSame(ToolCallMapper::ERROR_PREFIX.'something broke', $result);
        $this->assertSame(3, $attempt); // original + 2 retries

        $stats = $orchestrator->getStats('exhaust-1');
        $this->assertSame('done', $stats->status);
        $this->assertSame(2, $stats->retries);
    }

    public function test_stats_track_retry_count(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 3, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'flaky', AgentType::Explore, 'await', 'stats-retry', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;
                if ($attempt <= 2) {
                    return ToolCallMapper::ERROR_PREFIX.'transient failure';
                }

                return 'recovered';
            },
        );

        $result = $future->await();
        $this->assertSame('recovered', $result);

        $stats = $orchestrator->getStats('stats-retry');
        $this->assertSame(2, $stats->retries);
        $this->assertSame('done', $stats->status);
    }

    public function test_exception_retry_succeeds(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 1, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'exception retry', AgentType::Explore, 'await', 'exc-retry', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;
                if ($attempt === 1) {
                    throw new \RuntimeException('transient factory error');
                }

                return 'recovered from exception';
            },
        );

        $result = $future->await();
        $this->assertSame('recovered from exception', $result);
        $this->assertSame(2, $attempt);
        $this->assertSame(1, $orchestrator->getStats('exc-retry')->retries);
    }

    public function test_non_retryable_exception_skips_retry(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'bad config', AgentType::Explore, 'await', 'noretry-exc', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;

                throw new \InvalidArgumentException('permanent error');
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('permanent error');
        $future->await();

        // Only 1 attempt — no retry for non-retryable exceptions
        $this->assertSame(1, $attempt);
    }

    public function test_auth_error_401_not_retried(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'auth fail', AgentType::Explore, 'await', 'auth-401', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;
                throw new \RuntimeException('API error (401): Unauthorized');
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error (401)');
        $future->await();

        $this->assertSame(1, $attempt);
    }

    public function test_auth_error_403_not_retried(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'forbidden', AgentType::Explore, 'await', 'auth-403', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;
                throw new \RuntimeException('API error (403): Forbidden');
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error (403)');
        $future->await();

        $this->assertSame(1, $attempt);
    }

    public function test_type_error_not_retried(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'type error', AgentType::Explore, 'await', 'type-err', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;
                throw new \TypeError('str_replace(): Argument #1 must be of type string');
            },
        );

        $this->expectException(\TypeError::class);
        $future->await();

        $this->assertSame(1, $attempt);
    }

    public function test_retryable_http_exception_is_retried(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 2, retryDelayFunction: fn () => 0.0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $attempt = 0;
        $future = $orchestrator->spawnAgent(
            $context, 'rate limited', AgentType::Explore, 'await', 'retry-http', [], null,
            function ($ctx, $task) use (&$attempt) {
                $attempt++;
                if ($attempt === 1) {
                    throw new RetryableHttpException(429, 'Rate limited');
                }

                return 'recovered';
            },
        );

        $result = $future->await();
        $this->assertSame('recovered', $result);
        $this->assertSame(2, $attempt);
    }

    public function test_background_agent_gets_dedicated_cancellation(): void
    {
        $bgCancellation = null;
        $awaitCancellation = 'unset';

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'bg task', AgentType::Explore, 'background', 'bg-cancel', [], null,
            function ($ctx, $task) use (&$bgCancellation) {
                $bgCancellation = $ctx->cancellation;

                return 'done';
            },
        )->await();

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'await task', AgentType::Explore, 'await', 'await-cancel', [], null,
            function ($ctx, $task) use (&$awaitCancellation) {
                $awaitCancellation = $ctx->cancellation;

                return 'done';
            },
        )->await();

        $this->assertNotNull($bgCancellation, 'Background agent should get a dedicated cancellation token');
        $this->assertNotNull($awaitCancellation, 'Await agent should also get a dedicated cancellation token');
    }

    public function test_cancel_all_cancels_background_agents(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $f1 = $orchestrator->spawnAgent(
            $context, 'long bg 1', AgentType::Explore, 'background', 'cancel-1', [], null,
            function ($ctx, $task) {
                \Amp\delay(10, cancellation: $ctx->cancellation);

                return 'should not reach';
            },
        );

        $f2 = $orchestrator->spawnAgent(
            $context, 'long bg 2', AgentType::Explore, 'background', 'cancel-2', [], null,
            function ($ctx, $task) {
                \Amp\delay(10, cancellation: $ctx->cancellation);

                return 'should not reach';
            },
        );

        // Let fibers start
        \Amp\delay(0.01);

        $orchestrator->cancelAll();

        // Background agents resolve with error strings instead of throwing,
        // preventing UnhandledFutureError when no one awaits them.
        $result1 = $f1->await();
        $this->assertStringContainsString('cancel-1', $result1);
        $this->assertStringContainsString('failed', $result1);

        $result2 = $f2->await();
        $this->assertStringContainsString('cancel-2', $result2);
        $this->assertStringContainsString('failed', $result2);

        $this->assertSame('failed', $orchestrator->getStats('cancel-1')->status);
        $this->assertSame('failed', $orchestrator->getStats('cancel-2')->status);
    }

    public function test_cancel_all_cancels_await_agents_too(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $future = $orchestrator->spawnAgent(
            $context, 'await task', AgentType::Explore, 'await', 'await-safe', [], null,
            function ($ctx, $task) {
                \Amp\delay(10, cancellation: $ctx->cancellation);

                return 'completed safely';
            },
        );

        // Awaited subagents are part of the same swarm and should be cancelled on teardown too.
        \Amp\delay(0.01);
        $orchestrator->cancelAll();

        $this->expectException(CancelledException::class);
        $future->await();
    }

    public function test_watchdog_runtime_exception_is_not_retried(): void
    {
        $attempts = 0;
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 3, retryDelayFunction: fn () => 0.0);

        $future = $orchestrator->spawnAgent(
            $this->rootContext,
            'watchdog task',
            AgentType::Explore,
            'await',
            'watchdog-no-retry',
            [],
            null,
            function ($ctx, $task) use (&$attempts) {
                $attempts++;

                throw new \RuntimeException('watchdog: subagent exceeded 10.0s without finishing');
            },
        );

        try {
            $future->await();
            $this->fail('Expected watchdog exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('watchdog:', $e->getMessage());
        }

        $this->assertSame(1, $attempts);
        $this->assertSame('failed', $orchestrator->getStats('watchdog-no-retry')?->status);
    }

    public function test_idle_watchdog_cancels_truly_inactive_agent(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 0, 0.05);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $future = $orchestrator->spawnAgent(
            $context,
            'idle task',
            AgentType::Explore,
            'await',
            'idle-watchdog',
            [],
            null,
            function ($ctx, $task) {
                \Amp\delay(1, cancellation: $ctx->cancellation);

                return 'should not reach';
            },
        );

        try {
            $future->await();
            $this->fail('Expected idle watchdog cancellation');
        } catch (CancelledException $e) {
            $this->assertStringContainsString('watchdog: subagent idle for', $e->getPrevious()?->getMessage() ?? '');
        }

        $this->assertSame('failed', $orchestrator->getStats('idle-watchdog')?->status);
        $this->assertStringContainsString('watchdog: subagent idle for', $orchestrator->getStats('idle-watchdog')?->error ?? '');
    }

    public function test_cancellation_cleanup_after_completion(): void
    {
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'bg', AgentType::Explore, 'background', 'cleanup-1', [], null,
            fn ($ctx, $task) => 'done',
        )->await();

        // cancelAll after completion should not throw — token was cleaned up in finally
        $this->orchestrator->cancelAll();

        $this->assertSame('done', $this->orchestrator->getStats('cleanup-1')->status);
    }

    // --- H1: Background result scoping ---

    public function test_background_results_scoped_by_parent(): void
    {
        // Root spawns bg-A and bg-B — results should appear under 'root'
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'task A', AgentType::Explore, 'background', 'bg-A', [], null,
            fn ($ctx, $task) => 'result-A',
        )->await();

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'task B', AgentType::Explore, 'background', 'bg-B', [], null,
            fn ($ctx, $task) => 'result-B',
        )->await();

        $rootResults = $this->orchestrator->collectPendingResults('root');
        $this->assertCount(2, $rootResults);
        $this->assertSame('result-A', $rootResults['bg-A']);
        $this->assertSame('result-B', $rootResults['bg-B']);
    }

    public function test_nested_agent_cannot_drain_sibling_results(): void
    {
        // Root spawns bg-A (background) and await-C.
        // await-C spawns bg-C1 (background).
        // await-C should only see bg-C1, not bg-A.

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'root child', AgentType::Explore, 'background', 'bg-A', [], null,
            fn ($ctx, $task) => 'result-A',
        );

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'nested parent', AgentType::General, 'await', 'await-C', [], null,
            function ($ctx, $task) {
                // Spawn a child of this agent
                $this->orchestrator->spawnAgent(
                    $ctx, 'nested child', AgentType::Explore, 'background', 'bg-C1', [], null,
                    fn ($ctx2, $task2) => 'result-C1',
                )->await();

                // This agent should only see its own children, not siblings
                $myResults = $this->orchestrator->collectPendingResults('await-C');
                // The child result was already drained by the time we check —
                // but it should have been under 'await-C', not under 'root'

                return 'nested-done';
            },
        )->await();

        // Root should still see bg-A
        $rootResults = $this->orchestrator->collectPendingResults('root');
        $this->assertCount(1, $rootResults);
        $this->assertArrayHasKey('bg-A', $rootResults);
        $this->assertSame('result-A', $rootResults['bg-A']);

        // await-C's bucket should be empty (already drained by await-C itself)
        $cResults = $this->orchestrator->collectPendingResults('await-C');
        $this->assertEmpty($cResults);
    }

    public function test_collect_pending_legacy_drain_all(): void
    {
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'task', AgentType::Explore, 'background', 'legacy-1', [], null,
            fn ($ctx, $task) => 'result',
        )->await();

        // Calling without parentId drains all buckets
        $all = $this->orchestrator->collectPendingResults();
        $this->assertCount(1, $all);
        $this->assertArrayHasKey('legacy-1', $all);
    }

    public function test_background_failure_scoped_by_parent(): void
    {
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'fail task', AgentType::Explore, 'background', 'fail-bg', [], null,
            fn ($ctx, $task) => throw new \RuntimeException('boom'),
        );

        // Let it complete
        \Amp\delay(0.05);

        $results = $this->orchestrator->collectPendingResults('root');
        $this->assertArrayHasKey('fail-bg', $results);
        $this->assertStringContainsString('boom', $results['fail-bg']);
    }

    // --- H2: Cycle detection ---

    public function test_circular_dependency_throws(): void
    {
        // Register A with dependency on B (B doesn't exist yet — registration succeeds, runtime will fail)
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'agent A', AgentType::Explore, 'await', 'circ-A', ['circ-B'], null,
            fn ($ctx, $task) => 'result-A',
        );

        // Registering B with depends_on=['circ-A'] should detect the cycle A→B→A
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular dependency');

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'agent B', AgentType::Explore, 'await', 'circ-B', ['circ-A'], null,
            fn ($ctx, $task) => 'result-B',
        );
    }

    public function test_self_dependency_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular dependency');

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'self dep', AgentType::Explore, 'await', 'self-1', ['self-1'], null,
            fn ($ctx, $task) => 'never',
        );
    }

    public function test_transitive_cycle_detected(): void
    {
        // A → B → C → A (transitive cycle)
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'A', AgentType::Explore, 'await', 'tc-A', ['tc-B'], null,
            fn ($ctx, $task) => 'A',
        );

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'B', AgentType::Explore, 'await', 'tc-B', ['tc-C'], null,
            fn ($ctx, $task) => 'B',
        );

        // C depends on A — closes the cycle A→B→C→A
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular dependency');

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'C', AgentType::Explore, 'await', 'tc-C', ['tc-A'], null,
            fn ($ctx, $task) => 'C',
        );
    }

    public function test_valid_diamond_no_cycle(): void
    {
        // A depends on B and C, both B and C depend on D. No cycle.
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'D', AgentType::Explore, 'background', 'dia-D', [], null,
            fn ($ctx, $task) => 'D',
        );

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'B', AgentType::Explore, 'background', 'dia-B', ['dia-D'], null,
            fn ($ctx, $task) => 'B',
        );

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'C', AgentType::Explore, 'background', 'dia-C', ['dia-D'], null,
            fn ($ctx, $task) => 'C',
        );

        // A depends on B and C — should NOT throw
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'A', AgentType::Explore, 'await', 'dia-A', ['dia-B', 'dia-C'], null,
            fn ($ctx, $task) => 'A',
        );

        $this->assertSame('A', $future->await());
    }

    public function test_cycle_detection_cleans_up_stats(): void
    {
        try {
            $this->orchestrator->spawnAgent(
                $this->rootContext, 'self', AgentType::Explore, 'await', 'clean-1', ['clean-1'], null,
                fn ($ctx, $task) => 'never',
            );
        } catch (\InvalidArgumentException) {
            // expected
        }

        // Stats should be cleaned up — the agent was never actually registered
        $this->assertNull($this->orchestrator->getStats('clean-1'));
    }

    // --- Prune completed agents ---

    public function test_prune_completed_removes_done_agents(): void
    {
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'task', AgentType::Explore, 'await', 'prune-1', [], null,
            fn ($ctx, $task) => 'done',
        )->await();

        $this->assertNotNull($this->orchestrator->getStats('prune-1'));
        $pruned = $this->orchestrator->pruneCompleted();
        $this->assertSame(1, $pruned);
        $this->assertNull($this->orchestrator->getStats('prune-1'));
    }

    public function test_prune_completed_keeps_running_agents(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 1, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        // Start a long-running agent
        $orchestrator->spawnAgent(
            $context, 'long', AgentType::Explore, 'background', 'prune-running', [], null,
            function ($ctx, $task) {
                \Amp\delay(0.1);

                return 'done';
            },
        );

        \Amp\delay(0.01); // Let it start
        $pruned = $orchestrator->pruneCompleted();
        $this->assertSame(0, $pruned);
        $this->assertNotNull($orchestrator->getStats('prune-running'));
        $orchestrator->cancelAll();
        \Amp\delay(0.01);
    }

    public function test_prune_completed_removes_failed_agents(): void
    {
        try {
            $this->orchestrator->spawnAgent(
                $this->rootContext, 'fail', AgentType::Explore, 'await', 'prune-fail', [], null,
                fn ($ctx, $task) => throw new \RuntimeException('boom'),
            )->await();
        } catch (\RuntimeException) {
        }

        $pruned = $this->orchestrator->pruneCompleted();
        $this->assertSame(1, $pruned, 'Failed agents are terminal and should be pruned');
        $this->assertNull($this->orchestrator->getStats('prune-fail'));
    }

    public function test_prune_completed_removes_cancelled_agents(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 10, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        $future = $orchestrator->spawnAgent(
            $context, 'cancel me', AgentType::Explore, 'background', 'prune-cancel', [], null,
            function ($ctx, $task) {
                \Amp\delay(10, cancellation: $ctx->cancellation);

                return 'should not reach';
            },
        );

        \Amp\delay(0.01);
        $orchestrator->cancelAll();

        // Background agents return error strings instead of throwing
        $result = $future->await();
        $this->assertStringContainsString('prune-cancel', $result);

        $this->assertSame('failed', $orchestrator->getStats('prune-cancel')->status);
        $pruned = $orchestrator->pruneCompleted();
        $this->assertSame(1, $pruned, 'Cancelled agents are terminal and should be pruned');
        $this->assertNull($orchestrator->getStats('prune-cancel'));
    }

    public function test_prune_completed_returns_count(): void
    {
        foreach (['p1', 'p2', 'p3'] as $id) {
            $this->orchestrator->spawnAgent(
                $this->rootContext, 'task', AgentType::Explore, 'await', $id, [], null,
                fn ($ctx, $task) => 'done',
            )->await();
        }

        $this->assertSame(3, $this->orchestrator->pruneCompleted());
        $this->assertSame([], $this->orchestrator->allStats());
    }

    // --- Group sequential execution ---

    public function test_group_agents_run_sequentially(): void
    {
        $order = [];

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'first', AgentType::Explore, 'background', 'g1', [], 'serial',
            function ($ctx, $task) use (&$order) {
                \Amp\delay(0.05);
                $order[] = 'first';

                return 'first done';
            },
        );

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'second', AgentType::Explore, 'background', 'g2', [], 'serial',
            function ($ctx, $task) use (&$order) {
                $order[] = 'second';

                return 'second done';
            },
        );

        // Wait for both to complete
        \Amp\delay(0.2);

        // Both should have completed, and first (which has a delay) should finish before second starts
        $this->assertSame('done', $this->orchestrator->getStats('g1')->status);
        $this->assertSame('done', $this->orchestrator->getStats('g2')->status);
        $this->assertSame(['first', 'second'], $order, 'Group agents must run sequentially');
    }

    public function test_different_groups_run_in_parallel(): void
    {
        $startTimes = [];

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'alpha', AgentType::Explore, 'background', 'p1', [], 'group-a',
            function ($ctx, $task) use (&$startTimes) {
                $startTimes['alpha'] = microtime(true);
                \Amp\delay(0.05);

                return 'alpha done';
            },
        );

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'beta', AgentType::Explore, 'background', 'p2', [], 'group-b',
            function ($ctx, $task) use (&$startTimes) {
                $startTimes['beta'] = microtime(true);
                \Amp\delay(0.05);

                return 'beta done';
            },
        );

        \Amp\delay(0.15);

        $this->assertSame('done', $this->orchestrator->getStats('p1')->status);
        $this->assertSame('done', $this->orchestrator->getStats('p2')->status);

        // They should have started nearly simultaneously (within 20ms of each other)
        $diff = abs($startTimes['alpha'] - $startTimes['beta']);
        $this->assertLessThan(0.02, $diff, 'Different groups should run in parallel');
    }

    public function test_group_semaphore_released_on_failure(): void
    {
        $secondResult = null;

        // First agent in group fails
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'failing', AgentType::Explore, 'background', 'f1', [], 'fail-group',
            fn ($ctx, $task) => throw new \RuntimeException('boom'),
        );

        // Second agent in same group should still run after the first fails
        $this->orchestrator->spawnAgent(
            $this->rootContext, 'after-fail', AgentType::Explore, 'background', 'f2', [], 'fail-group',
            function ($ctx, $task) use (&$secondResult) {
                $secondResult = 'recovered';

                return 'recovered';
            },
        );

        \Amp\delay(0.15);

        $this->assertSame('failed', $this->orchestrator->getStats('f1')->status);
        $this->assertSame('done', $this->orchestrator->getStats('f2')->status);
        $this->assertSame('recovered', $secondResult);
    }

    public function test_no_group_runs_without_group_semaphore(): void
    {
        // Agents without a group should run in parallel (limited only by global concurrency)
        $startTimes = [];

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'nog1', AgentType::Explore, 'background', 'ng1', [], null,
            function ($ctx, $task) use (&$startTimes) {
                $startTimes['ng1'] = microtime(true);
                \Amp\delay(0.05);

                return 'ng1 done';
            },
        );

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'nog2', AgentType::Explore, 'background', 'ng2', [], null,
            function ($ctx, $task) use (&$startTimes) {
                $startTimes['ng2'] = microtime(true);
                \Amp\delay(0.05);

                return 'ng2 done';
            },
        );

        \Amp\delay(0.15);

        $this->assertSame('done', $this->orchestrator->getStats('ng1')->status);
        $this->assertSame('done', $this->orchestrator->getStats('ng2')->status);

        // Should have started at roughly the same time
        $diff = abs($startTimes['ng1'] - $startTimes['ng2']);
        $this->assertLessThan(0.02, $diff, 'Agents without group should run in parallel');
    }

    public function test_group_with_dependency(): void
    {
        $order = [];

        // Agent with dependency AND in a group — both must be respected
        $dep = $this->orchestrator->spawnAgent(
            $this->rootContext, 'dep', AgentType::Explore, 'await', 'gd1', [], null,
            function ($ctx, $task) use (&$order) {
                $order[] = 'dep';

                return 'dep done';
            },
        )->await();

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'grouped', AgentType::Explore, 'await', 'gd2', [], 'mygroup',
            function ($ctx, $task) use (&$order) {
                $order[] = 'grouped';

                return 'grouped done';
            },
        )->await();

        // Both ran (dep first since gd2 has no depends_on set here, but they ran sequentially via await)
        $this->assertSame(['dep', 'grouped'], $order);
    }

    /**
     * PHPUnit helper for asserting substring.
     */
    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertStringContainsString($needle, $haystack);
    }

    // --- Cycle detection with pruned agents ---

    public function test_cycle_detection_with_pruned_agent(): void
    {
        // Agent A completes and gets pruned (stats removed from $this->stats).
        // Agent B is then spawned with depends_on=['pruned-A'].
        // The cycle detector should treat pruned agents as leaves (no outgoing deps),
        // and the dependency resolution should throw "Unknown dependency" at runtime.

        $this->orchestrator->spawnAgent(
            $this->rootContext, 'prunable', AgentType::Explore, 'await', 'prune-dep-A', [], null,
            fn ($ctx, $task) => 'result-A',
        )->await();

        // Prune the completed agent
        $this->orchestrator->pruneCompleted();
        $this->assertNull($this->orchestrator->getStats('prune-dep-A'));

        // Spawning B with dependency on pruned-A should NOT throw at spawn time
        // (cycle detector treats pruned agents as leaves)
        $future = $this->orchestrator->spawnAgent(
            $this->rootContext, 'depends-on-pruned', AgentType::Explore, 'await', 'prune-dep-B', ['prune-dep-A'], null,
            fn ($ctx, $task) => 'should not reach',
        );

        // But at runtime, the dependency lookup in $this->agents should fail
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown dependency agent: 'prune-dep-A'");
        $future->await();
    }

    // --- Root agent slot management ---

    public function test_root_agent_slot_management(): void
    {
        $orchestrator = new SubagentOrchestrator(new NullLogger, 3, 1, 0);
        $context = new AgentContext(AgentType::General, 0, 3, $orchestrator, 'root', '');

        // Root agent doesn't hold a slot — spawn a child that takes the only slot
        $childRan = false;
        $future = $orchestrator->spawnAgent(
            $context, 'child task', AgentType::Explore, 'await', 'slot-child', [], null,
            function ($ctx, $task) use (&$childRan) {
                $childRan = true;

                return 'child done';
            },
        );

        $this->assertSame('child done', $future->await());
        $this->assertTrue($childRan);

        // After child completes, slot should be released.
        // Spawn another child to verify no deadlock (slot leak).
        $future2 = $orchestrator->spawnAgent(
            $context, 'second child', AgentType::Explore, 'await', 'slot-child-2', [], null,
            fn ($ctx, $task) => 'child 2 done',
        );

        $this->assertSame('child 2 done', $future2->await());
    }
}
