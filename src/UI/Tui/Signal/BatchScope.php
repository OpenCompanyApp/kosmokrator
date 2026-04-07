<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Signal;

use Revolt\EventLoop;

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
 * For async contexts, use BatchScope::deferred() to schedule the flush
 * on the next event loop tick via EventLoop::defer().
 */
final class BatchScope
{
    private static ?self $current = null;

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
     * Run a callback inside a batch scope. Nested calls are supported —
     * only the outermost completion triggers the flush.
     */
    public static function run(callable $fn): void
    {
        $batch = self::$current;
        if ($batch === null) {
            $batch = new self();
            self::$current = $batch;
        }

        $batch->depth++;
        try {
            $fn();
        } finally {
            $batch->depth--;
            if ($batch->depth === 0) {
                $batch->flush();
                self::$current = null;
            }
        }
    }

    /**
     * Schedule a deferred batch via EventLoop::defer().
     * Signal::set() calls inside $fn will queue notifications.
     * The flush happens on the next event loop tick.
     */
    public static function deferred(callable $fn): void
    {
        EventLoop::defer(function () use ($fn): void {
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
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $effect->run();
            }
        }
    }
}
