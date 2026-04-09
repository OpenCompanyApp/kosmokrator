<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\UI\Tui\Builder\BreathingDriver;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Manages all animation, phase transitions, and timer state for TuiRenderer.
 *
 * Owns the thinking/compacting loaders, breathing animation timers, spinner
 * registration, and phase lifecycle (Thinking → Tools → Idle). TuiRenderer
 * delegates all phase transitions here and reads back animation state via
 * getters for display in the task bar and subagent tree.
 *
 * All mutable scalar state is stored reactively in TuiStateStore signals.
 */
final class TuiAnimationManager
{
    private ?CancellableLoaderWidget $loader = null;

    private ?CancellableLoaderWidget $compactingLoader = null;

    private readonly BreathingDriver $breathingDriver;

    /** @var string[] */
    private array $activeSpinnerFrames = [];

    private bool $spinnersRegistered = false;

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

    private const SPINNERS = [
        'cosmos' => ['✦', '✧', '⊛', '◈', '⊛', '✧'],                       // Pulsing cosmic gem
        'planets' => ['☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆'],            // Planetary orbit
        'elements' => ['🜁', '🜂', '🜃', '🜄'],                               // Alchemical elements
        'stars' => ['⋆', '✧', '★', '✦', '★', '✧'],                       // Twinkling stars
        'ouroboros' => ['◴', '◷', '◶', '◵'],                                 // Serpent cycle
        'oracle' => ['◉', '◎', '◉', '○', '◎', '○'],                       // All-seeing eye
        'runes' => ['ᚠ', 'ᚢ', 'ᚦ', 'ᚨ', 'ᚱ', 'ᚲ', 'ᚷ', 'ᚹ'],         // Elder Futhark runes
        'fate' => ['⚀', '⚁', '⚂', '⚃', '⚄', '⚅'],                     // Dice of fate
        'sigil' => ['᛭', '⊹', '✳', '✴', '✳', '⊹'],                      // Arcane sigil pulse
        'serpent' => ['∿', '≀', '∾', '≀'],                                  // Cosmic serpent wave
        'eclipse' => ['◐', '◓', '◑', '◒'],                                  // Solar eclipse
        'hourglass' => ['⧗', '⧖', '⧗', '⧖'],                                 // Sands of Chronos
        'trident' => ['ψ', 'Ψ', 'ψ', '⊥'],                                 // Poseidon's trident
        'aether' => ['·', '∘', '○', '◌', '○', '∘'],                        // Aetheric ripple
    ];

    private const COMPACTION_PHRASES = [
        '⧫ Condensing the cosmic record...',
        '⧫ Distilling the essence of memory...',
        '⧫ Weaving threads of context...',
        '⧫ Forging a compact chronicle...',
    ];

    /**
     * @param  TuiStateStore  $state  Centralized reactive state store
     * @param  ContainerWidget  $thinkingBar  Container for thinking/compacting loaders
     * @param  \Closure(): void  $subagentTickCallback  Ticks the subagent tree refresh
     * @param  \Closure(): void  $subagentCleanupCallback  Cleans up subagent display state
     * @param  \Closure(): void  $renderCallback  Triggers a TUI render pass (flushRender)
     * @param  \Closure(): void  $forceRenderCallback  Triggers a forced TUI render pass
     */
    public function __construct(
        private readonly TuiStateStore $state,
        private readonly ContainerWidget $thinkingBar,
        private readonly \Closure $subagentTickCallback,
        private readonly \Closure $subagentCleanupCallback,
        private readonly \Closure $renderCallback,
        private readonly \Closure $forceRenderCallback,
    ) {
        $this->breathingDriver = new BreathingDriver($state);
        $this->breathingDriver->setSubagentTickCallback($subagentTickCallback);
        $this->breathingDriver->setRenderCallback($renderCallback);
    }

    /**
     * Get the current breathing animation color.
     *
     * @return ?string ANSI color escape sequence, or null when idle
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
        return AgentPhase::from($this->state->getPhase());
    }

    /**
     * Get the current thinking phrase displayed in the loader.
     */
    public function getThinkingPhrase(): ?string
    {
        return $this->state->getThinkingPhrase();
    }

    /**
     * Get the thinking start time for elapsed calculations.
     */
    public function getThinkingStartTime(): float
    {
        return $this->state->getThinkingStartTime();
    }

    /**
     * Get the thinking loader widget, if active.
     */
    public function getLoader(): ?CancellableLoaderWidget
    {
        return $this->loader;
    }

    /**
     * Transition to a new agent phase.
     *
     * Routes to the appropriate enter method based on the target phase.
     * The cancellation token is created and owned by TuiRenderer; it is
     * passed here so the loader's cancel handler can trigger it.
     *
     * @param  AgentPhase  $phase  Target phase
     * @param  ?DeferredCancellation  $cancellation  Active cancellation token (for Thinking phase)
     */
    public function setPhase(AgentPhase $phase, ?DeferredCancellation $cancellation = null): void
    {
        if ($phase->value === $this->state->getPhase()) {
            return;
        }

        $previous = $this->getCurrentPhase();
        $this->state->setPhase($phase->value);

        match ($phase) {
            AgentPhase::Thinking => $this->enterThinking($cancellation),
            AgentPhase::Tools => $this->enterTools($previous),
            AgentPhase::Idle => $this->enterIdle(),
        };
    }

    /**
     * Show the compacting loader with breathing animation.
     */
    public function showCompacting(): void
    {
        $phrase = self::COMPACTION_PHRASES[array_rand(self::COMPACTION_PHRASES)];

        $this->ensureSpinnersRegistered();

        $spinnerIdx = $this->state->allocateSpinner();
        $spinnerNames = array_keys(self::SPINNERS);
        $spinnerName = $spinnerNames[$spinnerIdx % count($spinnerNames)];

        $this->compactingLoader = new CancellableLoaderWidget($phrase);
        $this->compactingLoader->setId('compacting-loader');
        $this->compactingLoader->addStyleClass('compacting');
        $this->compactingLoader->setSpinner($spinnerName);
        $this->compactingLoader->setIntervalMs(120);
        $this->compactingLoader->start();

        try {
            $this->thinkingBar->add($this->compactingLoader);
        } catch (\Throwable) {
            $this->compactingLoader->stop();
            $this->compactingLoader = null;

            return;
        }

        $this->state->setCompactingStartTime(microtime(true));
        $this->state->setCompactingBreathTick(0);

        // Start (or reuse) the breathing driver — it handles compacting ticks too
        $this->breathingDriver->setCompactingLoader($this->compactingLoader);
        $this->breathingDriver->start();
        $this->state->setHasCompactingLoader(true);

        ($this->renderCallback)();
    }

    /**
     * Stop the compacting loader.
     */
    public function clearCompacting(): void
    {
        $this->breathingDriver->setCompactingLoader(null);
        $this->state->setHasCompactingLoader(false);

        if ($this->compactingLoader !== null) {
            $this->compactingLoader->setFinishedIndicator('✓');
            $this->compactingLoader->stop();
            $this->thinkingBar->remove($this->compactingLoader);
            $this->compactingLoader = null;
        }

        ($this->forceRenderCallback)();
    }

    /**
     * Ensure custom spinners are registered with CancellableLoaderWidget.
     *
     * Safe to call multiple times — registration is idempotent.
     */
    public function ensureSpinnersRegistered(): void
    {
        if ($this->spinnersRegistered) {
            return;
        }
        foreach (self::SPINNERS as $name => $frames) {
            CancellableLoaderWidget::addSpinner($name, $frames);
        }
        $this->spinnersRegistered = true;
    }

    /**
     * Enter thinking phase: create loader, start breathing animation.
     *
     * @param  ?DeferredCancellation  $cancellation  Token for the loader's cancel handler
     */
    private function enterThinking(?DeferredCancellation $cancellation): void
    {
        $this->clearThinkingLoader();

        $phrase = self::THINKING_PHRASES[array_rand(self::THINKING_PHRASES)];
        $hasTasks = $this->state->getHasTasks();

        $this->state->setThinkingStartTime(microtime(true));
        $this->state->setBreathTick(0);
        $this->state->setThinkingPhrase($phrase);

        // Only show the standalone loader when there are no tasks —
        // when tasks exist, the breathing animation on in-progress tasks IS the indicator
        if (! $hasTasks) {
            $this->ensureSpinnersRegistered();

            $spinnerIdx = $this->state->allocateSpinner();
            $spinnerNames = array_keys(self::SPINNERS);
            $spinnerName = $spinnerNames[$spinnerIdx % count($spinnerNames)];
            $this->activeSpinnerFrames = self::SPINNERS[$spinnerName];

            $this->loader = new CancellableLoaderWidget($phrase);
            $this->loader->setId('loader');
            $this->loader->setSpinner($spinnerName);
            $this->loader->setIntervalMs(120);
            $this->loader->start();

            $this->loader->onCancel(function () use ($cancellation) {
                $cancellation?->cancel();
            });

            try {
                $this->thinkingBar->add($this->loader);
            } catch (\Throwable) {
                $this->loader->stop();
                $this->loader = null;
            }
        }

        // Breathing pulse at 30fps — animates loader text OR in-progress task color
        $this->startBreathingAnimation($phrase, 'blue');

        // Pass the loader (or null if tasks exist) to the driver for message updates
        $this->breathingDriver->setThinkingLoader($this->loader);
        $this->state->setHasThinkingLoader($this->loader !== null);

        ($this->renderCallback)();
    }

    /**
     * Transition from thinking to tools phase: keep loader alive, switch to amber palette.
     *
     * The loader continues animating throughout tool execution so the user sees
     * activity. It is removed in enterIdle() or replaced in the next enterThinking().
     */
    private function enterTools(AgentPhase $previous): void
    {
        // The BreathingDriver reads the phase signal automatically — no restart needed.
        // The palette shifts from blue to amber based on the current phase.
        ($this->renderCallback)();
    }

    /**
     * Enter idle phase: cancel all timers and clean up loaders.
     */
    /**
     * Enter idle state: stop breathing driver, clear loaders, reset signals.
     */
    private function enterIdle(): void
    {
        $this->breathingDriver->stop();

        if ($this->compactingLoader !== null) {
            $this->compactingLoader->setFinishedIndicator('✓');
            $this->compactingLoader->stop();
            $this->thinkingBar->remove($this->compactingLoader);
            $this->compactingLoader = null;
        }

        if ($this->loader !== null) {
            $this->clearThinkingLoader();
        }

        $this->state->setThinkingPhrase(null);
        $this->state->setBreathColor(null);
        ($this->subagentCleanupCallback)();

        ($this->forceRenderCallback)();
    }

    /**
     * Start the breathing animation via the BreathingDriver.
     *
     * @param  string  $phrase  Loader message text (empty for tools phase)
     * @param  string  $palette  'blue' for thinking, 'amber' for tools
     */
    private function startBreathingAnimation(string $phrase, string $palette): void
    {
        $this->state->setThinkingPhrase($phrase);
        $this->state->setThinkingStartTime(microtime(true));
        $this->breathingDriver->start();
    }

    private function clearThinkingLoader(): void
    {
        if ($this->loader === null) {
            return;
        }

        $this->loader->setFinishedIndicator('✓');
        $this->loader->stop();
        $this->thinkingBar->remove($this->loader);
        $this->loader = null;
        $this->breathingDriver->setThinkingLoader(null);
        $this->state->setHasThinkingLoader(false);
    }
}
