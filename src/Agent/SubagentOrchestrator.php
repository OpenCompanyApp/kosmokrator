<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Http\Client\HttpException;
use Amp\Sync\LocalSemaphore;
use Amp\Sync\Lock;
use Kosmokrator\LLM\RetryableHttpException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

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

    /** @var array<string, array<string, string>> Completed background results keyed by parent ID: parentId => [agentId => result] */
    private array $pendingResults = [];

    /** @var array<string, DeferredCancellation> Dedicated subagent cancellation tokens */
    private array $cancellations = [];

    /** @var array<string, Lock> Active global semaphore locks keyed by agent ID (for slot yielding) */
    private array $globalLocks = [];

    private int $autoIdCounter = 0;

    private ?LocalSemaphore $globalSemaphore;

    /**
     * @param  LoggerInterface  $log  Logger for orchestrator lifecycle events
     * @param  int  $maxDepth  Maximum nesting depth for subagent trees
     * @param  int  $concurrency  Max parallel agents (0 = unlimited)
     * @param  int  $maxRetries  Retry attempts per agent on transient failures
     * @param  int|float  $watchdogSeconds  Idle watchdog threshold for an individual running agent (0 = disabled)
     * @param  (\Closure(int): float)|null  $retryDelayFunction  Override for retry delay calculation (useful in tests)
     */
    public function __construct(
        private readonly LoggerInterface $log,
        private readonly int $maxDepth = 3,
        private readonly int $concurrency = 10,
        private readonly int $maxRetries = 2,
        private readonly int|float $watchdogSeconds = 600,
        private readonly ?\Closure $retryDelayFunction = null,
    ) {
        // LocalSemaphore requires maxLocks >= 1, so 0 = unlimited (no semaphore)
        $this->globalSemaphore = $concurrency > 0 ? new LocalSemaphore($concurrency) : null;
    }

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

        $deferred = new DeferredCancellation;
        $this->cancellations[$id] = $deferred;

        $agentCancellation = $deferred->getCancellation();
        if ($parentContext->cancellation !== null) {
            $agentCancellation = new CompositeCancellation($parentContext->cancellation, $agentCancellation);
        }

        $childContext = $parentContext->childContext($childType, $id, $task, $agentCancellation);

        $stats = new SubagentStats($id);
        $stats->task = mb_substr($task, 0, 200);
        $stats->agentType = $childType->value;
        $stats->group = $group;
        $stats->dependsOn = $dependsOn;
        $stats->parentId = $parentContext->id;
        $stats->depth = $childContext->depth;
        $this->stats[$id] = $stats;

        // Detect circular dependencies before spawning
        if ($dependsOn !== [] && $this->wouldCreateCycle($id, $dependsOn)) {
            unset($this->stats[$id]);
            throw new \InvalidArgumentException(
                "Circular dependency detected: agent '{$id}' would create a cycle with depends_on=[".implode(', ', $dependsOn).']'
            );
        }

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
            $globalLock = null;
            $watchdogId = null;

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

                // 2. Acquire global concurrency semaphore
                if ($this->globalSemaphore !== null) {
                    $stats->status = 'queued_global';
                    $this->log->debug("Agent '{$id}' waiting for global semaphore", ['concurrency' => $this->concurrency]);
                    $globalLock = $this->globalSemaphore->acquire();
                    $this->globalLocks[$id] = $globalLock;
                    $this->log->debug("Agent '{$id}' acquired global semaphore");
                }

                // 3. Acquire group semaphore (sequential within group)
                if ($group !== null) {
                    $stats->status = 'queued';
                    $this->log->debug("Agent '{$id}' waiting for group semaphore", ['group' => $group]);
                    $lock = $this->getGroupSemaphore($group)->acquire();
                    $this->log->debug("Agent '{$id}' acquired group semaphore", ['group' => $group]);
                }

                $stats->status = 'running';
                $stats->startTime = microtime(true);

                $stats->touchActivity();

                if ($this->watchdogSeconds > 0) {
                    $watchdogId = EventLoop::repeat(min(5.0, max(1.0, $this->watchdogSeconds / 6)), function () use ($id): void {
                        $stats = $this->stats[$id] ?? null;
                        $deferred = $this->cancellations[$id] ?? null;

                        if ($stats === null || $deferred === null || $stats->status !== 'running') {
                            return;
                        }

                        $idleSeconds = $stats->idleSeconds();
                        if ($idleSeconds < $this->watchdogSeconds) {
                            return;
                        }

                        $reason = sprintf(
                            'watchdog: subagent idle for %.1fs with no activity',
                            $idleSeconds,
                        );

                        $this->log->warning('Subagent watchdog firing', [
                            'id' => $id,
                            'elapsed' => round($stats->elapsed(), 2),
                            'idle_seconds' => round($idleSeconds, 2),
                            'limit_seconds' => $this->watchdogSeconds,
                        ]);

                        $deferred->cancel(new \RuntimeException($reason));
                    });
                }

                $result = null;
                $lastError = null;

                for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
                    if ($attempt > 0) {
                        $stats->status = 'retrying';
                        $stats->retries = $attempt;
                        $delay = $this->retryDelay($attempt);
                        $this->log->warning("Retrying agent '{$id}' (attempt {$attempt}/{$this->maxRetries})", [
                            'delay' => round($delay, 1),
                            'last_error' => $lastError,
                        ]);
                        \Amp\delay($delay);
                        $stats->status = 'running';
                    }

                    try {
                        $result = $agentFactory($childContext, $task);
                    } catch (\Throwable $e) {
                        $lastError = $e->getMessage();
                        if ($attempt < $this->maxRetries && $this->isRetryableException($e)) {
                            $this->log->warning("Subagent '{$id}' threw retryable exception", [
                                'attempt' => $attempt + 1,
                                'error' => $e->getMessage(),
                            ]);

                            continue;
                        }

                        throw $e;
                    }

                    if ($this->isRetryableResult($result) && $attempt < $this->maxRetries) {
                        $lastError = $result;
                        $this->log->warning("Subagent '{$id}' returned retryable error", [
                            'attempt' => $attempt + 1,
                            'result' => mb_substr($result, 0, 100),
                        ]);

                        continue;
                    }

                    break;
                }

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
                    'retries' => $stats->retries,
                ]);

                if ($mode === 'background') {
                    $this->pendingResults[$stats->parentId][$id] = $result;
                }

                return $result;
            } catch (\Throwable $e) {
                $stats->status = 'failed';
                $stats->error = $this->extractFailureMessage($e);
                $stats->endTime = microtime(true);

                $this->log->error('Subagent failed', [
                    'id' => $id,
                    'parent_id' => $stats->parentId,
                    'depth' => $stats->depth,
                    'error' => $stats->error,
                    'elapsed' => round($stats->elapsed(), 2),
                ]);

                // Inject failure as a pending result so the parent is notified
                if ($mode === 'background') {
                    $this->pendingResults[$stats->parentId][$id] = "Error: Agent '{$id}' failed — {$stats->error}";
                }

                throw $e;
            } finally {
                if ($watchdogId !== null) {
                    EventLoop::cancel($watchdogId);
                }
                if ($lock !== null) {
                    $lock->release();
                    $this->log->debug("Agent '{$id}' released group semaphore", ['group' => $group]);
                }
                if ($globalLock !== null) {
                    $globalLock->release();
                    unset($this->globalLocks[$id]);
                    $this->log->debug("Agent '{$id}' released global semaphore");
                }
                unset($this->cancellations[$id]);
            }
        });

        $this->agents[$id] = $future;

        return $future;
    }

    /**
     * Detect if adding dependsOn edges from $id would create a cycle.
     * DFS: can any node in $dependsOn reach $id through existing edges?
     *
     * @param  string[]  $dependsOn
     */
    private function wouldCreateCycle(string $id, array $dependsOn): bool
    {
        $visited = [];
        $stack = $dependsOn;

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $id) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $existingDeps = $this->stats[$current]->dependsOn ?? [];
            foreach ($existingDeps as $dep) {
                $stack[] = $dep;
            }
        }

        return false;
    }

    /**
     * Remove completed agents and their stats to free memory.
     *
     * Keeps entries that are still running, waiting, queued, or retrying.
     * Also keeps entries needed for dependency resolution and tree display
     * of recently completed agents (those that failed or were cancelled).
     *
     * Safe to call periodically (e.g., after collecting background results).
     */
    public function pruneCompleted(): int
    {
        $terminalStates = ['done' => true, 'cancelled' => true];
        $pruned = 0;

        foreach ($this->stats as $id => $stats) {
            if (isset($terminalStates[$stats->status])) {
                unset($this->stats[$id], $this->agents[$id]);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->log->debug('Pruned completed agents', ['count' => $pruned, 'remaining' => count($this->stats)]);
        }

        return $pruned;
    }

    /**
     * Drain and return completed background results for a specific parent.
     *
     * @return array<string, string> agentId => result text
     */
    /**
     * Check whether any completed background results are waiting to be collected.
     */
    public function hasPendingResults(?string $parentId = null): bool
    {
        if ($parentId !== null) {
            return ! empty($this->pendingResults[$parentId]);
        }

        return $this->pendingResults !== [];
    }

    /**
     * Check whether any background agents are still running for a given parent.
     */
    public function hasRunningBackgroundAgents(?string $parentId = null): bool
    {
        foreach ($this->stats as $stats) {
            if ($stats->status !== 'running') {
                continue;
            }
            if ($parentId !== null && $stats->parentId !== $parentId) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function collectPendingResults(?string $parentId = null): array
    {
        if ($parentId !== null) {
            $results = $this->pendingResults[$parentId] ?? [];
            unset($this->pendingResults[$parentId]);

            return $results;
        }

        // Legacy: drain all buckets (for contexts that don't pass parentId)
        $all = [];
        foreach ($this->pendingResults as $bucket) {
            $all = array_merge($all, $bucket);
        }
        $this->pendingResults = [];

        return $all;
    }

    /**
     * Temporarily release this agent's global semaphore slot so children can use it.
     * Call reclaimSlot() after the wait completes to re-acquire before resuming work.
     */
    public function yieldSlot(string $agentId): void
    {
        $lock = $this->globalLocks[$agentId] ?? null;
        if ($lock === null) {
            return;
        }

        $lock->release();
        unset($this->globalLocks[$agentId]);
        $this->log->debug("Agent '{$agentId}' yielded global semaphore slot");
    }

    /**
     * Re-acquire a global semaphore slot after yielding. Blocks until a slot is available.
     */
    public function reclaimSlot(string $agentId): void
    {
        if ($this->globalSemaphore === null) {
            return;
        }

        $this->log->debug("Agent '{$agentId}' reclaiming global semaphore slot");
        $lock = $this->globalSemaphore->acquire();
        $this->globalLocks[$agentId] = $lock;
        $this->log->debug("Agent '{$agentId}' reclaimed global semaphore slot");
    }

    /**
     * Get or create a single-lock semaphore for a named group (sequential execution).
     */
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

    public function getConcurrency(): int
    {
        return $this->concurrency;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Cancel all running subagents. Called on session quit / Ctrl+C.
     */
    public function cancelAll(): void
    {
        foreach ($this->cancellations as $id => $deferred) {
            $this->log->info("Cancelling subagent '{$id}'");
            $deferred->cancel();
        }
    }

    /**
     * Suppress unhandled future errors from all pending agent futures.
     * Call after cancelAll() to prevent UnhandledFutureError during cleanup.
     */
    public function ignorePendingFutures(): void
    {
        foreach ($this->agents as $future) {
            $future->ignore();
        }
    }

    public function generateId(): string
    {
        return 'agent-'.++$this->autoIdCounter;
    }

    private function extractFailureMessage(\Throwable $e): string
    {
        if ($e->getPrevious() instanceof \Throwable) {
            $previous = $e->getPrevious()->getMessage();
            if (str_starts_with($previous, 'watchdog:')) {
                return $previous;
            }
        }

        return $e->getMessage();
    }

    /**
     * Determine if an error result from runHeadless() is worth retrying.
     *
     * Retryable: "Error: ..." prefix (context overflow, transient LLM errors).
     * NOT retryable: "(cancelled)", "(forced return: ...)", auth/key errors.
     */
    private function isRetryableResult(string $result): bool
    {
        if (str_starts_with($result, '(cancelled)')) {
            return false;
        }

        if (str_contains($result, '(forced return:')) {
            return false;
        }

        if (str_starts_with($result, 'Error:')) {
            $lower = strtolower($result);

            if (str_contains($lower, 'invalid api key')
                || str_contains($lower, 'authentication')
                || str_contains($lower, 'unauthorized')
                || str_contains($lower, '401')
                || str_contains($lower, '403')
            ) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Determine if a thrown exception is worth retrying at the agent level.
     * Uses a whitelist approach: only known transient failures are retried.
     */
    private function isRetryableException(\Throwable $e): bool
    {
        // LLM retryable HTTP errors (429, 5xx) — always retry
        if ($e instanceof RetryableHttpException) {
            return true;
        }

        // Network-level failures (timeouts, connection resets) — always retry
        if ($e instanceof HttpException) {
            return true;
        }

        // Generic RuntimeExceptions from API calls — retry unless auth-related
        if ($e instanceof \RuntimeException) {
            $msg = strtolower($e->getMessage());

            return ! (
                str_contains($msg, 'watchdog:')
                || str_contains($msg, 'unknown dependency')
                || str_contains($msg, '401')
                || str_contains($msg, '403')
                || str_contains($msg, 'authentication')
                || str_contains($msg, 'unauthorized')
            );
        }

        // Everything else (TypeError, LogicException, InvalidArgumentException, etc.) — no retry
        return false;
    }

    /**
     * Exponential backoff with jitter for agent-level retries.
     */
    private function retryDelay(int $attempt): float
    {
        if ($this->retryDelayFunction !== null) {
            return ($this->retryDelayFunction)($attempt);
        }

        $base = min((int) pow(2, $attempt), 30);

        return (float) ($base + random_int(0, max(1, (int) ($base * 0.3))));
    }
}
