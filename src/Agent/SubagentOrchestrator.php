<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\Future;
use Amp\Sync\LocalSemaphore;
use Psr\Log\LoggerInterface;

use function Amp\async;

/**
 * Manages the subagent swarm: futures, dependency graphs, sequential groups, and stats.
 * Singleton shared across the entire agent tree via AgentContext.
 */
class SubagentOrchestrator
{
    /** @var array<string, Future<string>> */
    private array $agents = [];

    /** @var array<string, LocalSemaphore> */
    private array $groups = [];

    /** @var array<string, SubagentStats> */
    private array $stats = [];

    /** @var array<string, string> Completed background results not yet consumed */
    private array $pendingResults = [];

    private int $autoIdCounter = 0;

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly int $maxDepth = 3,
    ) {}

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Spawn a subagent. Returns a Future that resolves to the agent's output text.
     *
     * For 'await' mode: caller does $future->await() immediately.
     * For 'background' mode: result is stored in pendingResults when done.
     *
     * @param  callable(AgentContext, string): string  $agentFactory
     */
    public function spawnAgent(
        AgentContext $parentContext,
        string $task,
        AgentType $childType,
        string $mode,
        ?string $id,
        array $dependsOn,
        ?string $group,
        callable $agentFactory,
    ): Future {
        $id ??= $this->generateId();

        if (isset($this->agents[$id])) {
            throw new \InvalidArgumentException("Agent ID '{$id}' already exists. Use a unique ID.");
        }

        $childContext = $parentContext->childContext($childType, $id, $task);

        $stats = new SubagentStats($id);
        $stats->task = mb_substr($task, 0, 200);
        $stats->agentType = $childType->value;
        $stats->group = $group;
        $stats->dependsOn = $dependsOn;
        $stats->parentId = $parentContext->id;
        $stats->depth = $childContext->depth;
        $this->stats[$id] = $stats;

        $this->log->info('Spawning subagent', [
            'id' => $id,
            'parent_id' => $parentContext->id,
            'type' => $childType->value,
            'mode' => $mode,
            'depth' => $childContext->depth,
            'depends_on' => $dependsOn,
            'group' => $group,
            'task' => mb_substr($task, 0, 100),
        ]);

        $future = async(function () use ($childContext, $task, $id, $dependsOn, $group, $mode, $stats, $agentFactory) {
            $lock = null;

            try {
                // 1. Wait for dependencies
                if ($dependsOn !== []) {
                    $stats->status = 'waiting';
                    $this->log->debug("Agent '{$id}' waiting for dependencies", ['depends_on' => $dependsOn]);
                    $depResults = [];

                    foreach ($dependsOn as $depId) {
                        $depFuture = $this->agents[$depId] ?? null;
                        if ($depFuture === null) {
                            throw new \RuntimeException("Unknown dependency agent: '{$depId}'");
                        }

                        $depStart = microtime(true);
                        try {
                            $depResults[$depId] = $depFuture->await();
                            $this->log->debug("Dependency resolved for agent '{$id}'", [
                                'dependency' => $depId,
                                'wait_seconds' => round(microtime(true) - $depStart, 2),
                                'result_length' => strlen($depResults[$depId]),
                            ]);
                        } catch (\Throwable $depError) {
                            // Dependency failed — inject error as result, don't kill this agent
                            $depResults[$depId] = "[FAILED] Agent '{$depId}' failed: {$depError->getMessage()}";
                            $this->log->warning("Dependency '{$depId}' failed for agent '{$id}'", [
                                'error' => $depError->getMessage(),
                                'wait_seconds' => round(microtime(true) - $depStart, 2),
                            ]);
                        }
                    }

                    $task .= "\n\n--- Results from dependency agents ---\n";
                    foreach ($depResults as $did => $dresult) {
                        $depStats = $this->stats[$did] ?? null;
                        $warning = '';
                        if ($depStats !== null && $depStats->status === 'failed') {
                            $warning = ' ⚠ THIS DEPENDENCY FAILED — results may be incomplete or missing.';
                        } elseif (str_starts_with($dresult, '(cancelled)') || str_starts_with($dresult, 'Error:')) {
                            $warning = ' ⚠ THIS DEPENDENCY RETURNED AN ERROR — results may be degraded. Consider doing your own research.';
                        }
                        $task .= "\n[Agent '{$did}']{$warning}:\n{$dresult}\n";
                    }
                }

                // 2. Acquire group semaphore (sequential within group)
                if ($group !== null) {
                    $stats->status = 'queued';
                    $this->log->debug("Agent '{$id}' waiting for group semaphore", ['group' => $group]);
                    $lock = $this->getGroupSemaphore($group)->acquire();
                    $this->log->debug("Agent '{$id}' acquired group semaphore", ['group' => $group]);
                }

                $stats->status = 'running';
                $stats->startTime = microtime(true);

                $result = $agentFactory($childContext, $task);

                // Detect cancelled agents — they return '(cancelled)' without throwing
                if ($result === '(cancelled)') {
                    $stats->status = 'cancelled';
                    $stats->endTime = microtime(true);
                    $this->log->info('Subagent cancelled', [
                        'id' => $id,
                        'depth' => $stats->depth,
                        'elapsed' => round($stats->elapsed(), 2),
                    ]);
                } else {
                    $stats->status = 'done';
                    $stats->endTime = microtime(true);
                }

                $this->log->info('Subagent completed', [
                    'id' => $id,
                    'parent_id' => $stats->parentId,
                    'depth' => $stats->depth,
                    'tools' => $stats->toolCalls,
                    'tokens_in' => $stats->tokensIn,
                    'tokens_out' => $stats->tokensOut,
                    'elapsed' => round($stats->elapsed(), 2),
                    'result_length' => strlen($result),
                ]);

                if ($mode === 'background') {
                    $this->pendingResults[$id] = $result;
                }

                return $result;
            } catch (\Throwable $e) {
                $stats->status = 'failed';
                $stats->error = $e->getMessage();
                $stats->endTime = microtime(true);

                $this->log->error('Subagent failed', [
                    'id' => $id,
                    'parent_id' => $stats->parentId,
                    'depth' => $stats->depth,
                    'error' => $e->getMessage(),
                    'elapsed' => round($stats->elapsed(), 2),
                ]);

                // Inject failure as a pending result so the parent is notified
                if ($mode === 'background') {
                    $this->pendingResults[$id] = "Error: Agent '{$id}' failed — {$e->getMessage()}";
                }

                throw $e;
            } finally {
                if ($lock !== null) {
                    $lock->release();
                    $this->log->debug("Agent '{$id}' released group semaphore", ['group' => $group]);
                }
            }
        });

        $this->agents[$id] = $future;

        return $future;
    }

    /**
     * Drain and return all completed background results.
     *
     * @return array<string, string> id => result text
     */
    public function collectPendingResults(): array
    {
        $results = $this->pendingResults;
        $this->pendingResults = [];

        return $results;
    }

    public function getGroupSemaphore(string $name): LocalSemaphore
    {
        return $this->groups[$name] ??= new LocalSemaphore(1);
    }

    public function getStats(string $id): ?SubagentStats
    {
        return $this->stats[$id] ?? null;
    }

    /**
     * @return array<string, SubagentStats>
     */
    public function allStats(): array
    {
        return $this->stats;
    }

    /**
     * Sum tokens across all subagents (for parent cost display).
     *
     * @return array{in: int, out: int}
     */
    public function totalTokens(): array
    {
        $in = $out = 0;
        foreach ($this->stats as $s) {
            $in += $s->tokensIn;
            $out += $s->tokensOut;
        }

        return ['in' => $in, 'out' => $out];
    }

    public function generateId(): string
    {
        return 'agent-'.++$this->autoIdCounter;
    }
}
