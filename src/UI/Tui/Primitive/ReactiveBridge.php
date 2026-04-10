<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive;

use Athanor\Effect;
use Athanor\EffectScope;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Tui;

/**
 * Single Effect that replaces all manual flushRender() / triggerRender() calls.
 *
 * Touches every display signal inside an Effect callback. When any tracked
 * signal changes, the Effect re-runs and calls requestRender() to schedule
 * a new frame. One Effect replaces 17+ scattered render trigger sites.
 */
final class ReactiveBridge
{
    private ?Effect $effect = null;

    private ?EffectScope $scope = null;

    /**
     * Start the reactive render loop.
     *
     * Reading each signal inside the Effect callback auto-tracks it.
     * When any tracked signal changes, the Effect re-runs and calls
     * requestRender() to schedule a new frame.
     */
    public function start(Tui $tui, TuiStateStore $store): void
    {
        $this->stop();

        $this->scope = new EffectScope;

        $this->effect = $this->scope->effect(function () use ($tui, $store): void {
            // Touch every display signal — auto-tracked as dependencies.
            // Any future set() on any of these re-runs this Effect.

            // Status bar (message is computed from modeLabel + permissionLabel + statusDetail)
            $store->statusBarMessageComputed()->get();
            $store->tokensInSignal()->get();
            $store->maxContextSignal()->get();

            // Animation / loaders
            $store->breathColorSignal()->get();
            $store->breathTickSignal()->get();
            $store->hasThinkingLoaderSignal()->get();
            $store->hasCompactingLoaderSignal()->get();
            $store->thinkingPhraseSignal()->get();
            $store->compactingBreathTickSignal()->get();

            // Tasks
            $store->hasTasksSignal()->get();

            // Subagents
            $store->hasRunningAgentsSignal()->get();
            $store->cachedLoaderLabelSignal()->get();
            $store->batchDisplayedSignal()->get();
            $store->loaderBreathTickSignal()->get();
            $store->startTimeSignal()->get();

            // Scroll / history
            $store->scrollOffsetSignal()->get();
            $store->isBrowsingHistoryComputed()->get();
            $store->hasHiddenActivityBelowSignal()->get();

            // Modal
            $store->activeModalSignal()->get();

            // Tool execution
            $store->toolExecutingPreviewSignal()->get();
            $store->hasSubagentActivitySignal()->get();

            // Manual render triggers (addConversationWidget, etc.)
            $store->renderTriggerSignal()->get();

            $tui->requestRender();
        });
    }

    /**
     * Stop the reactive render loop.
     */
    public function stop(): void
    {
        $this->scope?->dispose();
        $this->scope = null;
        $this->effect = null;
    }
}
