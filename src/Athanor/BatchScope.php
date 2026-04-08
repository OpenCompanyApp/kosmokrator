<?php

declare(strict_types=1);

namespace Athanor;

/**
 * Batches multiple signal writes into a single update cycle.
 *
 * When a BatchScope is active, Signal::set() and Computed changes queue
 * their notifications instead of firing immediately. When the batch
 * completes, all pending effects run once (deduplicated).
 *
 * Supports nesting: only the outermost flush triggers notifications.
 *
 * Usage:
 *   BatchScope::run(function () {
 *       $sigA->set(1);
 *       $sigB->set(2);
 *       // Effects fire once after this block completes
 *   });
 *
 * Deferred batching:
 *   BatchScope::setScheduler(fn ($fn) => EventLoop::defer($fn));
 *   BatchScope::deferred(function () {
 *       $sigA->set(1);
 *       // Effects fire on the next event loop tick
 *   });
 *
 * The scheduler is injectable: call {@see setScheduler()} once at boot
 * with a callable that schedules work on your event loop. Without a
 * scheduler, deferred() throws.
 */
final class BatchScope
{
    private static ?self $current = null;

    /** @var (callable(callable): void)|null */
    private static $scheduler = null;

    private int $depth = 0;

    /** @var list<Signal> */
    private array $pendingSignals = [];

    /** @var list<Effect> */
    private array $pendingEffects = [];

    /**
     * Get the current active batch, or null if none.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Set the scheduler callable for deferred batch execution.
     *
     * The scheduler receives a callable and must arrange for it to run
     * asynchronously. For Revolt/Amp:
     *   BatchScope::setScheduler(fn (callable $fn) => EventLoop::defer($fn));
     *
     * For ReactPHP:
     *   BatchScope::setScheduler(fn (callable $fn) => $loop->futureTick($fn));
     *
     * For synchronous testing:
     *   BatchScope::setScheduler(fn (callable $fn) => $fn());
     *
     * @param  (callable(callable): void)|null  $scheduler  Null to clear
     */
    public static function setScheduler(?callable $scheduler): void
    {
        self::$scheduler = $scheduler;
    }

    /**
     * Run a callback inside a batch scope. Nested calls are supported —
     * only the outermost completion triggers the flush.
     */
    public static function run(callable $fn): void
    {
        $batch = self::$current;
        if ($batch === null) {
            $batch = new self;
            self::$current = $batch;
        }

        $batch->depth++;
        try {
            $fn();
        } finally {
            $batch->depth--;
            if ($batch->depth === 0) {
                self::$current = null;
                $batch->flush();
            }
        }
    }

    /**
     * Schedule a deferred batch via the configured scheduler.
     *
     * Signal::set() calls inside $fn will queue notifications.
     * The flush happens asynchronously when the scheduler invokes the callback.
     *
     * @throws \LogicException if no scheduler has been configured
     */
    public static function deferred(callable $fn): void
    {
        if (self::$scheduler === null) {
            throw new \LogicException(
                'BatchScope::deferred() requires a scheduler. '
                .'Call BatchScope::setScheduler() during application bootstrap.'
            );
        }

        (self::$scheduler)(function () use ($fn): void {
            self::run($fn);
        });
    }

    /**
     * Enqueue a signal for batched notification.
     */
    public function enqueue(Signal $signal): void
    {
        $this->pendingSignals[] = $signal;
    }

    /**
     * Enqueue an effect for batched execution.
     */
    public function enqueueEffect(Effect $effect): void
    {
        $this->pendingEffects[] = $effect;
    }

    /**
     * Flush all pending notifications. Called automatically when the
     * outermost batch completes.
     *
     * Order: signal subscribers first (which may mark Computed dirty),
     * then deduplicated effects.
     */
    public function flush(): void
    {
        // Snapshot and clear to prevent re-entrancy during flush
        $signals = $this->pendingSignals;
        $effects = $this->pendingEffects;
        $this->pendingSignals = [];
        $this->pendingEffects = [];

        // First: notify all signal subscribers (may mark Computed dirty)
        foreach ($signals as $signal) {
            foreach ($signal->getSubscribersForFlush() as $sub) {
                $sub->fire($signal->value());
            }
        }

        // Then: deduplicate and run pending effects
        $seen = [];
        foreach ($effects as $effect) {
            $id = \spl_object_id($effect);
            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $effect->run();
            }
        }
    }
}
