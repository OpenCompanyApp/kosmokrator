<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\UI\Tui\Builder\BreathingDriver;
use Kosmokrator\UI\Tui\Composition\ThinkingLoaderWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;

/**
 * Manages animation state and breathing timers for the TUI.
 *
 * Signal-only: sets phase, breathColor, thinkingPhrase signals.
 * The actual CancellableLoaderWidget instances are managed by
 * ThinkingLoaderWidget and CompactingLoaderWidget (ReactiveWidgets).
 *
 * Owns the BreathingDriver for color/tick animation.
 */
final class TuiAnimationManager
{
    private readonly BreathingDriver $breathingDriver;

    private ?string $phaseBeforeCompacting = null;

    private ?string $phraseBeforeCompacting = null;

    private bool $thinkingLoaderBeforeCompacting = false;

    private const THINKING_PHRASES = [
        '◈ Consulting the Oracle at Delphi...',
        '♃ Aligning the celestial spheres...',
        '⚡ Channeling Prometheus\' fire...',
        '♄ Weaving the threads of Fate...',
        '☽ Reading the astral charts...',
        '♂ Invoking the nine Muses...',
        '♆ Traversing the Aether...',
        '♅ Deciphering cosmic glyphs...',
        '⚡ Summoning Athena\'s wisdom...',
        '☉ Attuning to the Music of the Spheres...',
        '♃ Gazing into the cosmic void...',
        '◈ Unraveling the Labyrinth...',
        '♆ Communing with the Titans...',
        '♄ Forging in Hephaestus\' workshop...',
        '☽ Scrying the heavens...',
    ];

    private const COMPACTION_PHRASES = [
        '⧫ Condensing the cosmic record...',
        '⧫ Distilling the essence of memory...',
        '⧫ Weaving threads of context...',
        '⧫ Forging a compact chronicle...',
    ];

    /**
     * @param  TuiStateStore  $state  Centralized reactive state store
     * @param  \Closure(): void  $subagentTickCallback  Ticks the subagent tree refresh
     * @param  \Closure(): void  $subagentCleanupCallback  Cleans up subagent display state
     * @param  \Closure(): void  $renderCallback  Triggers a TUI render pass (flushRender)
     * @param  \Closure(): void  $forceRenderCallback  Triggers a forced TUI render pass
     */
    public function __construct(
        private readonly TuiStateStore $state,
        private readonly \Closure $subagentTickCallback,
        private readonly \Closure $subagentCleanupCallback,
        private readonly \Closure $renderCallback,
        private readonly \Closure $forceRenderCallback,
        ?TuiScheduler $scheduler = null,
    ) {
        $this->breathingDriver = new BreathingDriver($state, $scheduler ?? TuiScheduler::fallback());
        $this->breathingDriver->setSubagentTickCallback($subagentTickCallback);
        $this->breathingDriver->setRenderCallback($renderCallback);
    }

    /**
     * Get the current breathing animation color.
     */
    public function getBreathColor(): ?string
    {
        return $this->state->getBreathColor();
    }

    /**
     * Get the current agent phase.
     */
    public function getCurrentPhase(): AgentPhase
    {
        $phase = $this->state->getPhase();

        return $phase === 'compacting' ? AgentPhase::Idle : AgentPhase::from($phase);
    }

    /**
     * Get the current thinking phrase.
     */
    public function getThinkingPhrase(): ?string
    {
        return $this->state->getThinkingPhrase();
    }

    /**
     * Get the thinking start time.
     */
    public function getThinkingStartTime(): float
    {
        return $this->state->getThinkingStartTime();
    }

    /**
     * Transition to a new agent phase.
     *
     * Sets signals only. The ThinkingLoaderWidget and CompactingLoaderWidget
     * reactive widgets handle actual widget lifecycle.
     */
    public function setPhase(AgentPhase $phase, ?DeferredCancellation $cancellation = null): void
    {
        if ($phase->value === $this->state->getPhase()) {
            return;
        }

        $this->state->setPhase($phase->value);

        match ($phase) {
            AgentPhase::Thinking => $this->enterThinking($cancellation),
            AgentPhase::Tools => $this->enterTools(),
            AgentPhase::Idle => $this->enterIdle(),
        };
    }

    /**
     * Show the compacting loader by setting the signal.
     */
    public function showCompacting(): void
    {
        if ($this->state->getPhase() !== 'compacting') {
            $this->phaseBeforeCompacting = $this->state->getPhase();
            $this->phraseBeforeCompacting = $this->state->getThinkingPhrase();
            $this->thinkingLoaderBeforeCompacting = $this->state->getHasThinkingLoader();
        }

        $phrase = self::COMPACTION_PHRASES[array_rand(self::COMPACTION_PHRASES)];
        $this->state->setPhase('compacting');
        $this->state->setThinkingPhrase($phrase);
        $this->state->setCompactingStartTime(microtime(true));
        $this->state->setCompactingBreathTick(0);
        $this->state->setHasCompactingLoader(true);
        $this->breathingDriver->start();
        ($this->renderCallback)();
    }

    /**
     * Hide the compacting loader by clearing the signal.
     */
    public function clearCompacting(): void
    {
        $this->state->setHasCompactingLoader(false);
        if ($this->state->getPhase() === 'compacting') {
            $restoredPhase = $this->phaseBeforeCompacting ?? 'idle';
            $this->state->setPhase($restoredPhase);
            $this->state->setThinkingPhrase($restoredPhase === 'idle' ? null : $this->phraseBeforeCompacting);
            $this->state->setHasThinkingLoader($restoredPhase !== 'idle' && $this->thinkingLoaderBeforeCompacting);
        }

        $hasActiveAnimationPhase = in_array($this->state->getPhase(), ['thinking', 'tools'], true);
        if (! $this->state->getHasThinkingLoader() && ! $hasActiveAnimationPhase) {
            $this->breathingDriver->stop();
        }

        $this->phaseBeforeCompacting = null;
        $this->phraseBeforeCompacting = null;
        $this->thinkingLoaderBeforeCompacting = false;
        ($this->forceRenderCallback)();
    }

    /**
     * Ensure custom spinners are registered.
     *
     * Delegated to ThinkingLoaderWidget. Kept for backward compat.
     */
    public function ensureSpinnersRegistered(): void
    {
        ThinkingLoaderWidget::registerSpinners();
    }

    /**
     * Stop all animation timers and clear transient loader signals.
     */
    public function teardown(): void
    {
        $this->breathingDriver->stop();
        $this->state->setHasThinkingLoader(false);
        $this->state->setHasCompactingLoader(false);
        $this->state->setThinkingPhrase(null);
        $this->state->setBreathColor(null);
        $this->phaseBeforeCompacting = null;
        $this->phraseBeforeCompacting = null;
        $this->thinkingLoaderBeforeCompacting = false;
    }

    /**
     * Enter thinking phase: set signals, start breathing animation.
     */
    private function enterThinking(?DeferredCancellation $cancellation): void
    {
        $phrase = self::THINKING_PHRASES[array_rand(self::THINKING_PHRASES)];
        $hasTasks = $this->state->getHasTasks();

        $this->state->setThinkingStartTime(microtime(true));
        $this->state->setBreathTick(0);
        $this->state->setThinkingPhrase($phrase);

        // Only signal the loader when there are no tasks — when tasks exist,
        // the breathing animation on in-progress tasks IS the indicator
        if (! $hasTasks) {
            $this->state->setHasThinkingLoader(true);
        }

        $this->startBreathingAnimation($phrase);

        ($this->renderCallback)();
    }

    /**
     * Enter tools phase: keep animation running with amber palette.
     */
    private function enterTools(): void
    {
        ($this->renderCallback)();
    }

    /**
     * Enter idle phase: stop breathing driver, clear signals.
     */
    private function enterIdle(): void
    {
        $this->breathingDriver->stop();

        $this->state->setHasThinkingLoader(false);
        $this->state->setHasCompactingLoader(false);
        $this->state->setThinkingPhrase(null);
        $this->state->setBreathColor(null);

        ($this->subagentCleanupCallback)();
        ($this->forceRenderCallback)();
    }

    /**
     * Start the breathing animation via the BreathingDriver.
     */
    private function startBreathingAnimation(string $phrase): void
    {
        $this->state->setThinkingPhrase($phrase);
        $this->state->setThinkingStartTime(microtime(true));
        $this->breathingDriver->start();
    }
}
