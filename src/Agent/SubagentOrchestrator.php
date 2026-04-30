<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Client\HttpException;
use Amp\Sync\LocalSemaphore;
use Amp\Sync\Lock;
use Kosmokrator\LLM\RetryableHttpException;
use Kosmokrator\LLM\ToolCallMapper;
use Kosmokrator\Session\SubagentOutputStore;
use Kosmokrator\Session\SwarmMetadataStore;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

use function Amp\async;

/**
 * Manages the subagent swarm: futures, dependency graphs, sequential groups, and stats.
 * Singleton shared across the entire agent tree via AgentContext.
 */
class SubagentOrchestrator
{
    /** @var array<string, true> */
    private const ACTIVE_STATUSES = [
        'queued' => true,
        'queued_global' => true,
        'retrying' => true,
        'running' => true,
        'waiting' => true,
    ];

    /** @var array<string, Future<string>> */
    private array $agents = [];

    /** @var array<string, string> Compact terminal results retained after pruning for depends_on */
    private array $dependencyResults = [];

    /** @var array<string, string[]> Dependency graph retained after pruning for cycle detection */
    private array $dependencyGraph = [];

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

    /** @var array<string, true> Agents that yielded their global slot and may reclaim it */
    private array $yieldedGlobalSlots = [];

    /** @var array<string, true> Global slots donated by yielded agents for awaited children or reclaim */
    private array $donatedGlobalSlots = [];

    /**
     * @var list<array{id: string, parent_id: ?string, mode: string, deferred: DeferredFuture<Lock>}>
     */
    private array $globalWaitQueue = [];

    /** @var callable(): ?string|null */
    private $rootSessionIdProvider;

    private int $autoIdCounter = 0;

    private int $availableGlobalSlots;

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
        private readonly ?SwarmMetadataStore $metadataStore = null,
        private readonly ?SubagentOutputStore $outputStore = null,
        ?callable $rootSessionIdProvider = null,
    ) {
        $this->availableGlobalSlots = max(0, $concurrency);
        $this->rootSessionIdProvider = $rootSessionIdProvider;
    }

    public function __destruct()
    {
        $this->cancelAll();
        $this->ignorePendingFutures();
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

        if (isset($this->agents[$id]) || array_key_exists($id, $this->dependencyResults)) {
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
        $stats->mode = $mode;
        $stats->task = mb_substr($task, 0, 200);
        $stats->agentType = $childType->value;
        $stats->group = $group;
        $stats->dependsOn = $dependsOn;
        $stats->parentId = $parentContext->id;
        $stats->depth = $childContext->depth;
        $this->stats[$id] = $stats;
        $this->dependencyGraph[$id] = $dependsOn;
        $this->persistStats($stats);

        // Auto-prune completed agents when stats grow beyond threshold to prevent unbounded RAM usage
        if (count($this->stats) > 50) {
            $this->pruneCompleted();
        }

        // Detect circular dependencies before spawning
        if ($dependsOn !== [] && $this->wouldCreateCycle($id, $dependsOn)) {
            unset($this->stats[$id], $this->dependencyGraph[$id]);
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

        $future = async(function () use ($parentContext, $childContext, $task, $id, $dependsOn, $group, $mode, $stats, $agentFactory) {
            $lock = null;
            $watchdogId = null;

            try {
                // 1. Wait for dependencies
                if ($dependsOn !== []) {
                    $stats->status = 'waiting';
                    $stats->markQueueReason('depends on '.implode(', ', $dependsOn));
                    $this->persistStats($stats);
                    $this->log->debug("Agent '{$id}' waiting for dependencies", ['depends_on' => $dependsOn]);
                    $depResults = [];

                    foreach ($dependsOn as $depId) {
                        if (array_key_exists($depId, $this->dependencyResults)) {
                            $depResults[$depId] = $this->dependencyResults[$depId];
                            $this->log->debug("Dependency resolved from retained result for agent '{$id}'", [
                                'dependency' => $depId,
                                'injected_length' => strlen($depResults[$depId]),
                            ]);

                            continue;
                        }

                        $depFuture = $this->agents[$depId] ?? null;
                        if ($depFuture === null) {
                            throw new \RuntimeException("Unknown dependency agent: '{$depId}'");
                        }

                        $depStart = microtime(true);
                        try {
                            $rawDepResult = $depFuture->await();
                            $depResults[$depId] = $this->compactOutputForContext($depId, $rawDepResult);
                            $this->log->debug("Dependency resolved for agent '{$id}'", [
                                'dependency' => $depId,
                                'wait_seconds' => round(microtime(true) - $depStart, 2),
                                'result_length' => strlen($rawDepResult),
                                'injected_length' => strlen($depResults[$depId]),
                            ]);
                        } catch (\Throwable $depError) {
                            // Dependency failed — inject error as result, don't kill this agent
                            $depResults[$depId] = "[FAILED] Agent '{$depId}' failed: ".$this->extractFailureMessage($depError);
                            $this->log->warning("Dependency '{$depId}' failed for agent '{$id}'", [
                                'error' => $this->extractFailureMessage($depError),
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
                        } elseif (str_starts_with($dresult, '(cancelled)') || str_starts_with($dresult, ToolCallMapper::ERROR_PREFIX)) {
                            $warning = ' ⚠ THIS DEPENDENCY RETURNED AN ERROR — results may be degraded. Consider doing your own research.';
                        }
                        $task .= "\n[Agent '{$did}']{$warning}:\n{$dresult}\n";
                    }
                }

                // 2. Acquire global concurrency semaphore
                if ($this->concurrency > 0) {
                    $stats->status = 'queued_global';
                    $stats->markQueueReason('global concurrency limit');
                    $this->persistStats($stats);
                    $this->log->debug("Agent '{$id}' waiting for global semaphore", ['concurrency' => $this->concurrency]);
                    $this->globalLocks[$id] = $this->acquireGlobalSlot($id, $parentContext->id, $mode);
                    $this->log->debug("Agent '{$id}' acquired global semaphore");
                }

                // 3. Acquire group semaphore (sequential within group)
                if ($group !== null) {
                    $stats->status = 'queued';
                    $stats->markQueueReason("group: {$group}");
                    $this->persistStats($stats);
                    $this->log->debug("Agent '{$id}' waiting for group semaphore", ['group' => $group]);
                    $lock = $this->getGroupSemaphore($group)->acquire();
                    $this->log->debug("Agent '{$id}' acquired group semaphore", ['group' => $group]);
                }

                $stats->status = 'running';
                $stats->clearQueueState();
                $stats->startTime = microtime(true);

                $stats->touchActivity();
                $this->persistStats($stats);

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
                        $stats->markRetryDelay($delay);
                        $this->persistStats($stats);
                        $this->log->warning("Retrying agent '{$id}' (attempt {$attempt}/{$this->maxRetries})", [
                            'delay' => round($delay, 1),
                            'last_error' => $lastError,
                        ]);
                        \Amp\delay($delay);
                        $stats->status = 'running';
                        $stats->clearQueueState();
                        $this->persistStats($stats);
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
                    $stats->clearQueueState();
                    $stats->endTime = microtime(true);
                    $this->persistStats($stats);
                    $this->dependencyResults[$id] = $this->compactOutputForContext($id, $result);
                    $this->log->info('Subagent cancelled', [
                        'id' => $id,
                        'depth' => $stats->depth,
                        'elapsed' => round($stats->elapsed(), 2),
                    ]);
                } else {
                    if ($result !== null && $this->shouldSpoolOutput($result)) {
                        $this->spoolOutput($stats, $result);
                    }
                    $stats->status = 'done';
                    $stats->clearQueueState();
                    $stats->endTime = microtime(true);
                    $this->persistStats($stats);
                    $this->dependencyResults[$id] = $this->compactOutputForContext($id, $result);
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
                    $this->pendingResults[$stats->parentId][$id] = $this->dependencyResults[$id];
                }

                return $result;
            } catch (\Throwable $e) {
                $failureResult = ToolCallMapper::ERROR_PREFIX."Agent '{$id}' failed — ".$this->extractFailureMessage($e);
                $stats->status = 'failed';
                $stats->clearQueueState();
                $stats->error = $this->extractFailureMessage($e);
                $stats->endTime = microtime(true);
                $this->persistStats($stats);
                $this->dependencyResults[$id] = $failureResult;

                $this->log->error('Subagent failed', [
                    'id' => $id,
                    'parent_id' => $stats->parentId,
                    'depth' => $stats->depth,
                    'error' => $stats->error,
                    'elapsed' => round($stats->elapsed(), 2),
                ]);

                // Inject failure as a pending result so the parent is notified
                if ($mode === 'background') {
                    $this->pendingResults[$stats->parentId][$id] = $failureResult;

                    // Don't throw — nobody awaits background futures, so an unhandled
                    // exception would become an UnhandledFutureError on GC.
                    return $failureResult;
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
                $heldGlobalLock = $this->globalLocks[$id] ?? null;
                if ($heldGlobalLock !== null) {
                    $heldGlobalLock->release();
                    unset($this->globalLocks[$id]);
                    $this->log->debug("Agent '{$id}' released global semaphore");
                }
                $this->releaseDonatedGlobalSlot($id);
                unset($this->yieldedGlobalSlots[$id]);
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

            if (! array_key_exists($current, $this->dependencyGraph)) {
                // Unknown agent — treat as leaf. Pruned agents remain in dependencyGraph.
                continue;
            }
            $existingDeps = $this->dependencyGraph[$current];
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
        $terminalStates = ['done' => true, 'cancelled' => true, 'failed' => true];
        $pruned = 0;

        // Build set of agent IDs that still have uncollected pending results
        $pendingIds = [];
        foreach ($this->pendingResults as $bucket) {
            foreach ($bucket as $id => $_) {
                $pendingIds[$id] = true;
            }
        }

        foreach ($this->stats as $id => $stats) {
            if (isset($terminalStates[$stats->status]) && ! isset($pendingIds[$id])) {
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
            if ($stats->mode !== 'background') {
                continue;
            }
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

    /**
     * Check whether any background agents are still active for a given parent.
     */
    public function hasActiveBackgroundAgents(?string $parentId = null): bool
    {
        foreach ($this->stats as $stats) {
            if ($stats->mode !== 'background') {
                continue;
            }
            if (! isset(self::ACTIVE_STATUSES[$stats->status])) {
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

        $this->yieldedGlobalSlots[$agentId] = true;
        $lock->release();
        unset($this->globalLocks[$agentId]);
        $this->log->debug("Agent '{$agentId}' yielded global semaphore slot");
    }

    /**
     * Re-acquire a global semaphore slot after yielding. Blocks until a slot is available.
     */
    public function reclaimSlot(string $agentId): void
    {
        if ($this->concurrency <= 0) {
            return;
        }

        if (! isset($this->yieldedGlobalSlots[$agentId])) {
            return;
        }

        if (isset($this->globalLocks[$agentId])) {
            unset($this->yieldedGlobalSlots[$agentId]);

            return;
        }

        if (isset($this->donatedGlobalSlots[$agentId])) {
            unset($this->donatedGlobalSlots[$agentId], $this->yieldedGlobalSlots[$agentId]);
            $this->globalLocks[$agentId] = $this->createGlobalLock($agentId);
            $this->log->debug("Agent '{$agentId}' reclaimed reserved global semaphore slot");

            return;
        }

        $this->log->debug("Agent '{$agentId}' reclaiming global semaphore slot");
        $this->globalLocks[$agentId] = $this->acquireGlobalSlot($agentId, null, 'await');
        unset($this->yieldedGlobalSlots[$agentId]);
        $this->log->debug("Agent '{$agentId}' reclaimed global semaphore slot");
    }

    private function acquireGlobalSlot(string $agentId, ?string $parentId, string $mode): Lock
    {
        if (
            $parentId !== null
            && $mode === 'await'
            && isset($this->yieldedGlobalSlots[$parentId], $this->donatedGlobalSlots[$parentId])
        ) {
            unset($this->donatedGlobalSlots[$parentId]);

            return $this->createGlobalLock($agentId, $parentId);
        }

        if ($this->availableGlobalSlots > 0) {
            $this->availableGlobalSlots--;

            return $this->createGlobalLock($agentId);
        }

        $deferred = new DeferredFuture;
        $this->globalWaitQueue[] = [
            'id' => $agentId,
            'parent_id' => $parentId,
            'mode' => $mode,
            'deferred' => $deferred,
        ];

        return $deferred->getFuture()->await();
    }

    private function createGlobalLock(string $agentId, ?string $returnToAgentId = null): Lock
    {
        return new Lock(function () use ($agentId, $returnToAgentId): void {
            if ($returnToAgentId !== null && isset($this->yieldedGlobalSlots[$returnToAgentId])) {
                $this->log->debug("Agent '{$agentId}' returned global semaphore slot to yielded parent '{$returnToAgentId}'");
                $this->releaseGlobalSlot($returnToAgentId);

                return;
            }

            $this->releaseGlobalSlot(isset($this->yieldedGlobalSlots[$agentId]) ? $agentId : null);
        });
    }

    private function releaseGlobalSlot(?string $yieldedAgentId = null): void
    {
        $nextIndex = null;
        if ($yieldedAgentId !== null) {
            foreach ($this->globalWaitQueue as $index => $waiter) {
                if ($waiter['parent_id'] === $yieldedAgentId && $waiter['mode'] === 'await') {
                    $nextIndex = $index;
                    break;
                }
            }
        }

        if ($nextIndex === null && $yieldedAgentId === null) {
            $nextIndex = array_key_first($this->globalWaitQueue);
        }
        if ($nextIndex === null) {
            if ($yieldedAgentId !== null) {
                $this->donatedGlobalSlots[$yieldedAgentId] = true;

                return;
            }

            $this->availableGlobalSlots++;

            return;
        }

        $waiter = $this->globalWaitQueue[$nextIndex];
        array_splice($this->globalWaitQueue, $nextIndex, 1);
        $waiter['deferred']->complete(
            $this->createGlobalLock(
                $waiter['id'],
                $waiter['parent_id'] === $yieldedAgentId ? $yieldedAgentId : null,
            ),
        );
    }

    private function releaseDonatedGlobalSlot(string $agentId): void
    {
        if (! isset($this->donatedGlobalSlots[$agentId])) {
            return;
        }

        unset($this->donatedGlobalSlots[$agentId]);
        $this->releaseGlobalSlot();
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

    public function persistStats(?SubagentStats $stats): void
    {
        if ($stats === null || $this->metadataStore === null || $this->rootSessionIdProvider === null) {
            return;
        }

        try {
            $rootSessionId = $this->currentRootSessionId();
            if ($rootSessionId === null) {
                return;
            }

            $this->metadataStore->upsertAgent($stats, $rootSessionId);
        } catch (\Throwable $e) {
            $this->log->debug('Failed to persist subagent metadata', [
                'agent_id' => $stats->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function currentRootSessionId(): ?string
    {
        if ($this->rootSessionIdProvider === null) {
            return null;
        }

        $rootSessionId = ($this->rootSessionIdProvider)();

        return is_string($rootSessionId) && $rootSessionId !== '' ? $rootSessionId : null;
    }

    private function shouldSpoolOutput(string $result): bool
    {
        $trimmed = trim($result);

        return $trimmed !== ''
            && ! str_starts_with($trimmed, '(cancelled)')
            && ! str_starts_with($trimmed, ToolCallMapper::ERROR_PREFIX);
    }

    private function spoolOutput(SubagentStats $stats, string $result): void
    {
        if ($this->outputStore === null) {
            return;
        }

        $rootSessionId = $this->currentRootSessionId();
        if ($rootSessionId === null) {
            return;
        }

        try {
            $written = $this->outputStore->write($rootSessionId, $stats->id, $result);
            $stats->markOutput($written['ref'], $written['bytes'], $written['preview']);
        } catch (\Throwable $e) {
            $this->log->debug('Failed to spool subagent output', [
                'agent_id' => $stats->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function compactOutputForContext(string $agentId, string $result): string
    {
        if (! $this->shouldSpoolOutput($result)) {
            return $result;
        }

        $stats = $this->stats[$agentId] ?? null;
        if ($stats?->outputRef !== null) {
            $preview = $stats->outputPreview;
            if ($preview === null || $preview === '') {
                $preview = '(no preview)';
            }

            return "Full output spooled to {$stats->outputRef} ({$stats->outputBytes} bytes).\nPreview:\n{$preview}";
        }

        if (strlen($result) <= 4000) {
            return $result;
        }

        return 'Output truncated for parent context ('.strlen($result)." bytes).\nPreview:\n".mb_substr($result, 0, 4000);
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

        return ErrorSanitizer::sanitize($e->getMessage());
    }

    /**
     * Determine if an error result from runHeadless() is worth retrying.
     *
     * Retryable: ERROR_PREFIX results (context overflow, transient LLM errors).
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

        if (str_starts_with($result, ToolCallMapper::ERROR_PREFIX)) {
            return $this->isRetryableMessage($result);
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

        // Generic RuntimeExceptions are only retried when their message clearly
        // identifies a transient transport/provider condition.
        if ($e instanceof \RuntimeException) {
            return $this->isRetryableMessage($e->getMessage());
        }

        // Everything else (TypeError, LogicException, InvalidArgumentException, etc.) — no retry
        return false;
    }

    private function isRetryableMessage(string $message): bool
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'watchdog:')
            || str_contains($lower, 'unknown dependency')
            || str_contains($lower, 'invalid api key')
            || str_contains($lower, 'authentication')
            || str_contains($lower, 'unauthorized')
            || str_contains($lower, 'forbidden')
            || preg_match('/\b(400|401|403|404|422)\b/', $lower) === 1
        ) {
            return false;
        }

        if (preg_match('/\b(408|409|425|429|5\d{2})\b/', $lower) === 1) {
            return true;
        }

        foreach ([
            'context overflow',
            'rate limit',
            'rate-limited',
            'temporarily unavailable',
            'temporary',
            'transient',
            'overload',
            'overloaded',
            'timeout',
            'timed out',
            'connection reset',
            'connection refused',
            'connection closed',
            'network',
            'econnreset',
            'etimedout',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

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
