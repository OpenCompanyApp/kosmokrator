<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Manages all animation, phase transitions, and timer state for TuiRenderer.
 *
 * Owns the thinking/compacting loaders, breathing animation timers, spinner
 * registration, and phase lifecycle (Thinking → Tools → Idle). TuiRenderer
 * delegates all phase transitions here and reads back animation state via
 * getters for display in the task bar and subagent tree.
 */
final class TuiAnimationManager
{
    private ?CancellableLoaderWidget $loader = null;

    private ?CancellableLoaderWidget $compactingLoader = null;

    private AgentPhase $currentPhase = AgentPhase::Idle;

    private float $thinkingStartTime = 0.0;

    private ?string $thinkingPhrase = null;

    private ?string $thinkingTimerId = null;

    private int $breathTick = 0;

    private ?string $breathColor = null;

    private float $compactingStartTime = 0.0;

    private int $compactingBreathTick = 0;

    private ?string $compactingTimerId = null;

    /** @var string[] */
    private array $activeSpinnerFrames = [];

    private bool $spinnersRegistered = false;

    private int $spinnerIndex = 0;

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
     * @param  ContainerWidget  $thinkingBar  Container for thinking/compacting loaders
     * @param  \Closure(): bool  $hasTasksProvider  Returns whether the task store has tasks
     * @param  \Closure(): void  $refreshTaskBarCallback  Triggers a task bar refresh
     * @param  \Closure(): void  $subagentTickCallback  Ticks the subagent tree refresh
     * @param  \Closure(): void  $subagentCleanupCallback  Cleans up subagent display state
     * @param  \Closure(): void  $renderCallback  Triggers a TUI render pass (flushRender)
     * @param  \Closure(): void  $forceRenderCallback  Triggers a forced TUI render pass
     */
    public function __construct(
        private readonly ContainerWidget $thinkingBar,
        private readonly \Closure $hasTasksProvider,
        private readonly \Closure $refreshTaskBarCallback,
        private readonly \Closure $subagentTickCallback,
        private readonly \Closure $subagentCleanupCallback,
        private readonly \Closure $renderCallback,
        private readonly \Closure $forceRenderCallback,
    ) {}

    /**
     * Get the current breathing animation color.
     *
     * @return ?string ANSI color escape sequence, or null when idle
     */
    public function getBreathColor(): ?string
    {
        return $this->breathColor;
    }

    /**
     * Get the current agent phase.
     */
    public function getCurrentPhase(): AgentPhase
    {
        return $this->currentPhase;
    }

    /**
     * Get the current thinking phrase displayed in the loader.
     */
    public function getThinkingPhrase(): ?string
    {
        return $this->thinkingPhrase;
    }

    /**
     * Get the thinking start time for elapsed calculations.
     */
    public function getThinkingStartTime(): float
    {
        return $this->thinkingStartTime;
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
        if ($phase === $this->currentPhase) {
            return;
        }

        $previous = $this->currentPhase;
        $this->currentPhase = $phase;

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

        $spinnerNames = array_keys(self::SPINNERS);
        $spinnerName = $spinnerNames[$this->spinnerIndex % count($spinnerNames)];
        $this->spinnerIndex++;

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

        $this->compactingStartTime = microtime(true);
        $this->compactingBreathTick = 0;

        // Breathing pulse at 30fps — red color modulation
        $this->compactingTimerId = EventLoop::repeat(0.033, function () use ($phrase) {
            $this->compactingBreathTick++;
            $r = "\033[0m";

            // Slow sin wave (~3s full cycle) modulating red tones
            $t = sin($this->compactingBreathTick * 0.07);
            $rr = (int) (208 + 40 * $t);
            $rg = (int) (48 + 16 * $t);
            $rb = (int) (48 + 16 * $t);
            $color = "\033[38;2;{$rr};{$rg};{$rb}m";

            if ($this->compactingLoader !== null) {
                $elapsed = (int) (microtime(true) - $this->compactingStartTime);
                $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $dim = "\033[38;5;245m";
                $this->compactingLoader->setMessage("{$color}{$phrase}{$r} {$dim}({$formatted}){$r}");
            }

            ($this->renderCallback)();
        });

        ($this->renderCallback)();
    }

    /**
     * Stop the compacting loader and its breathing timer.
     */
    public function clearCompacting(): void
    {
        if ($this->compactingTimerId !== null) {
            EventLoop::cancel($this->compactingTimerId);
            $this->compactingTimerId = null;
        }

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
        $hasTasks = ($this->hasTasksProvider)();

        $this->thinkingStartTime = microtime(true);
        $this->breathTick = 0;
        $this->thinkingPhrase = $phrase;

        // Only show the standalone loader when there are no tasks —
        // when tasks exist, the breathing animation on in-progress tasks IS the indicator
        if (! $hasTasks) {
            $this->ensureSpinnersRegistered();

            $spinnerNames = array_keys(self::SPINNERS);
            $spinnerName = $spinnerNames[$this->spinnerIndex % count($spinnerNames)];
            $this->activeSpinnerFrames = self::SPINNERS[$spinnerName];
            $this->spinnerIndex++;

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
        // Switch breathing animation to amber palette (keep loader + phrase intact)
        if ($this->thinkingTimerId !== null) {
            EventLoop::cancel($this->thinkingTimerId);
            $this->thinkingTimerId = null;
        }
        $this->startBreathingAnimation($this->thinkingPhrase ?? '', 'amber');

        ($this->renderCallback)();
    }

    /**
     * Enter idle phase: cancel all timers and clean up loaders.
     */
    private function enterIdle(): void
    {
        if ($this->thinkingTimerId !== null) {
            EventLoop::cancel($this->thinkingTimerId);
            $this->thinkingTimerId = null;
        }

        if ($this->loader !== null) {
            $this->clearThinkingLoader();
        }

        $this->thinkingPhrase = null;
        $this->breathColor = null;
        ($this->refreshTaskBarCallback)();
        ($this->subagentCleanupCallback)();

        ($this->forceRenderCallback)();
    }

    /**
     * Start a 30fps breathing animation timer with the given color palette.
     *
     * @param  string  $phrase  Loader message text (empty for tools phase)
     * @param  string  $palette  'blue' for thinking, 'amber' for tools
     */
    private function startBreathingAnimation(string $phrase, string $palette): void
    {
        if ($this->thinkingTimerId !== null) {
            EventLoop::cancel($this->thinkingTimerId);
        }

        $this->thinkingTimerId = EventLoop::repeat(0.033, function () use ($phrase, $palette) {
            $this->breathTick++;
            $r = "\033[0m";

            $t = sin($this->breathTick * 0.07);

            if ($palette === 'amber') {
                // Warm amber tones for tool execution
                $cr = (int) (200 + 40 * $t);
                $cg = (int) (150 + 30 * $t);
                $cb = (int) (60 + 20 * $t);
            } else {
                // Blue tones for thinking
                $cr = (int) (112 + 40 * $t);
                $cg = (int) (160 + 40 * $t);
                $cb = (int) (208 + 47 * $t);
            }
            $this->breathColor = "\033[38;2;{$cr};{$cg};{$cb}m";

            if ($this->loader !== null && $phrase !== '') {
                $elapsed = (int) (microtime(true) - $this->thinkingStartTime);
                $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $dim = "\033[38;5;245m";
                $this->loader->setMessage("{$this->breathColor}{$phrase}{$r} {$dim}({$formatted}){$r}");
            }

            if (($this->hasTasksProvider)()) {
                ($this->refreshTaskBarCallback)();
            }

            // Live subagent tree — refresh every ~0.5s (delegated to SubagentDisplayManager)
            if ($this->breathTick % 15 === 0) {
                ($this->subagentTickCallback)();
            }

            ($this->renderCallback)();
        });
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
    }
}
