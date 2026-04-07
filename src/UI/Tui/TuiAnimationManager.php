<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Performance\RenderScheduler;
use Kosmokrator\UI\Tui\Phase\Phase;
use Kosmokrator\UI\Tui\Phase\PhaseStateMachine;
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
 *
 * Internally backed by a PhaseStateMachine that validates transitions and
 * fires named listeners (think, execute, settle, compact, compactDone, cancel).
 * The public API still accepts AgentPhase for backward compatibility and maps
 * it to the corresponding Phase value before delegating to the machine.
 */
final class TuiAnimationManager
{
    private ?CancellableLoaderWidget $loader = null;

    private ?CancellableLoaderWidget $compactingLoader = null;

    private PhaseStateMachine $machine;

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

    /** Tracks the palette ('blue'|'amber') currently in use by the breathing animation */
    private string $breathPalette = 'blue';

    private ?RenderScheduler $scheduler = null;

    private const THINKING_PHRASES = [
        '◈ Reading files...',
        '♃ Editing code...',
        '⚡ Searching codebase...',
        '♄ Analyzing patterns...',
        '☽ Generating response...',
        '♂ Running commands...',
        '♆ Processing context...',
        '♅ Writing files...',
        '⚡ Applying edits...',
        '☉ Resolving dependencies...',
        '♃ Scanning project...',
        '◈ Evaluating options...',
        '♆ Building understanding...',
        '♄ Computing changes...',
        '☽ Synthesizing results...',
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
     * @param  \Closure(): bool  $hasSubagentActivityProvider  Returns whether subagents are actively running
     * @param  \Closure(): void  $refreshTaskBarCallback  Triggers a task bar refresh
     * @param  \Closure(): void  $subagentTickCallback  Ticks the subagent tree refresh
     * @param  \Closure(): void  $subagentCleanupCallback  Cleans up subagent display state
     * @param  \Closure(): void  $renderCallback  Triggers a TUI render pass (flushRender)
     * @param  \Closure(): void  $forceRenderCallback  Triggers a forced TUI render pass
     */
    public function __construct(
        private readonly ContainerWidget $thinkingBar,
        private readonly \Closure $hasTasksProvider,
        private readonly \Closure $hasSubagentActivityProvider,
        private readonly \Closure $refreshTaskBarCallback,
        private readonly \Closure $subagentTickCallback,
        private readonly \Closure $subagentCleanupCallback,
        private readonly \Closure $renderCallback,
        private readonly \Closure $forceRenderCallback,
    ) {
        $this->machine = new PhaseStateMachine();
        $this->registerMachineListeners();
    }

    /**
     * Inject the RenderScheduler for managed animation ticks.
     *
     * When set, breathing and compacting animations are registered with the
     * scheduler instead of using independent EventLoop::repeat timers.
     * The scheduler manages the tick rate based on activity level.
     */
    public function setScheduler(RenderScheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }

    /**
     * Register transition listeners on the state machine.
     *
     * Each named transition triggers the corresponding animation side effect:
     *   - think       → start breathing animation (blue palette)
     *   - execute     → switch breathing to amber palette
     *   - settle      → stop breathing, clear thinking state
     *   - compact     → start compacting animation (red palette)
     *   - compactDone → stop compacting animation
     *   - cancel      → clear thinking (cancel back to idle from thinking)
     */
    private function registerMachineListeners(): void
    {
        $this->machine->on('think', function (): void {
            $this->scheduler?->setActivityLevel('thinking');
            // enterThinking is called from setPhase() which passes the cancellation
        });

        $this->machine->on('execute', function (): void {
            $this->scheduler?->setActivityLevel('thinking');
            // Switch breathing animation to amber palette (keep loader + phrase intact)
            if ($this->thinkingTimerId !== null) {
                EventLoop::cancel($this->thinkingTimerId);
                $this->thinkingTimerId = null;
            }
            $this->startBreathingAnimation($this->thinkingPhrase ?? '', 'amber');

            ($this->renderCallback)();
        });

        $this->machine->on('settle', function (): void {
            $this->scheduler?->setActivityLevel('idle');
            $this->enterIdle();
        });

        $this->machine->on('cancel', function (): void {
            $this->scheduler?->setActivityLevel('idle');
            $this->enterIdle();
        });

        $this->machine->on('compact', function (): void {
            $this->scheduler?->setActivityLevel('thinking');
            $this->showCompacting();
        });

        $this->machine->on('compactDone', function (): void {
            $this->scheduler?->setActivityLevel('idle');
            $this->clearCompacting();
        });
    }

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
     * Get the current agent phase (backward-compatible AgentPhase enum).
     */
    public function getCurrentPhase(): AgentPhase
    {
        return $this->machineToAgentPhase($this->machine->current());
    }

    /**
     * Expose the internal state machine for reactive composition.
     */
    public function getMachine(): PhaseStateMachine
    {
        return $this->machine;
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
     * Accepts both AgentPhase (backward compat) and Phase (new system).
     * AgentPhase values are mapped to Phase before delegating to the
     * state machine. The cancellation token is created and owned by
     * TuiRenderer; it is passed here so the loader's cancel handler
     * can trigger it.
     *
     * @param  AgentPhase|Phase  $phase  Target phase
     * @param  ?DeferredCancellation  $cancellation  Active cancellation token (for Thinking phase)
     */
    public function setPhase(AgentPhase|Phase $phase, ?DeferredCancellation $cancellation = null): void
    {
        // Normalize to Phase
        $target = $phase instanceof AgentPhase
            ? $this->agentPhaseToPhase($phase)
            : $phase;

        // No-op if already in this phase
        if ($target === $this->machine->current()) {
            return;
        }

        // For the 'think' transition, we need to run enterThinking() before
        // the machine transition so the loader is set up. For other transitions
        // the machine listener handles the side effects.
        $current = $this->machine->current();

        if ($target === Phase::Thinking) {
            // enterThinking sets up the loader + breathing, then we transition
            $this->enterThinking($cancellation);
            $this->machine->transition(Phase::Thinking);
        } elseif ($target === Phase::Tools) {
            $this->machine->transition(Phase::Tools);
        } elseif ($target === Phase::Idle) {
            $this->machine->transition(Phase::Idle);
        } elseif ($target === Phase::Compacting) {
            $this->machine->transition(Phase::Compacting);
        }
    }

    /**
     * Attempt a machine transition directly by Phase.
     *
     * Useful for callers that already have a Phase value. Unlike setPhase(),
     * this method only accepts Phase and does not run the pre-transition
     * thinking setup — it relies entirely on machine listeners.
     *
     * @throws \Kosmokrator\UI\Tui\Phase\InvalidTransitionException if transition is invalid
     */
    public function transition(Phase $target): void
    {
        if ($target === Phase::Thinking && $this->machine->current() !== Phase::Thinking) {
            // enterThinking needs cancellation which isn't available here;
            // callers should use setPhase() for Thinking transitions.
            $this->enterThinking(null);
        }

        $this->machine->transition($target);
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
            $r = Theme::reset();

            // Slow sin wave (~3s full cycle) modulating red tones
            $t = sin($this->compactingBreathTick * 0.07);
            $rr = (int) (208 + 40 * $t);
            $rg = (int) (48 + 16 * $t);
            $rb = (int) (48 + 16 * $t);
            $color = Theme::rgb($rr, $rg, $rb);

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

    // ── Phase mapping helpers ────────────────────────────────────────────

    /**
     * Map AgentPhase → Phase for the state machine.
     */
    private function agentPhaseToPhase(AgentPhase $phase): Phase
    {
        return match ($phase) {
            AgentPhase::Thinking => Phase::Thinking,
            AgentPhase::Tools => Phase::Tools,
            AgentPhase::Idle => Phase::Idle,
        };
    }

    /**
     * Map Phase → AgentPhase for backward-compatible API.
     */
    private function machineToAgentPhase(Phase $phase): AgentPhase
    {
        return match ($phase) {
            Phase::Thinking => AgentPhase::Thinking,
            Phase::Tools => AgentPhase::Tools,
            Phase::Idle => AgentPhase::Idle,
            Phase::Compacting => AgentPhase::Idle, // Compacting has no AgentPhase equivalent — map to Idle
        };
    }

    // ── Private phase entry methods ──────────────────────────────────────

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
        $this->breathPalette = 'blue';

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
        if ($this->scheduler !== null) {
            $this->scheduler->unregister('breathing');
            $this->scheduler->register('breathing', function () use ($phrase): void {
                $this->tickBreathing($phrase, 'blue');
            });
        } else {
            $this->startBreathingAnimation($phrase, 'blue');
        }

        ($this->renderCallback)();
    }

    /**
     * Enter idle phase: cancel all timers and clean up loaders.
     */
    private function enterIdle(): void
    {
        // Unregister scheduler-based breathing animation
        $this->scheduler?->unregister('breathing');
        if ($this->thinkingTimerId !== null) {
            EventLoop::cancel($this->thinkingTimerId);
            $this->thinkingTimerId = null;
        }

        if ($this->compactingTimerId !== null) {
            EventLoop::cancel($this->compactingTimerId);
            $this->compactingTimerId = null;
        }

        if ($this->loader !== null) {
            $this->clearThinkingLoader();
        }

        $this->thinkingPhrase = null;
        $this->breathColor = null;
        $this->breathPalette = 'blue';
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
            $this->thinkingTimerId = null;
        }

        $this->breathPalette = $palette;

        // When the scheduler is available, register the breathing callback there
        // instead of creating an independent timer.
        if ($this->scheduler !== null) {
            $this->scheduler->unregister('breathing');
            $this->scheduler->register('breathing', function () use ($phrase, $palette): void {
                $this->tickBreathing($phrase, $palette);
            });

            return;
        }

        $this->thinkingTimerId = EventLoop::repeat(0.033, function () use ($phrase, $palette) {
            $this->tickBreathing($phrase, $palette);
        });
    }

    /**
     * Single tick of the breathing animation (state update only when using scheduler).
     *
     * When the scheduler is active, the render callback is invoked by the scheduler
     * after all animations tick. When using the standalone timer, render is called
     * at the end of this method.
     */
    private function tickBreathing(string $phrase, string $palette): void
    {
        $this->breathTick++;
        $r = Theme::reset();

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
        $this->breathColor = Theme::rgb($cr, $cg, $cb);

        if ($this->loader !== null && $phrase !== '') {
            $dim = "\033[38;5;245m";
            $message = "{$this->breathColor}{$phrase}{$r}";

            if (! ($this->hasSubagentActivityProvider)()) {
                $elapsed = (int) (microtime(true) - $this->thinkingStartTime);
                $formatted = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $message .= "{$dim} · {$formatted}{$r}";
            }

            $this->loader->setMessage($message);
        }

        if (($this->hasTasksProvider)()) {
            ($this->refreshTaskBarCallback)();
        }

        // Live subagent tree — refresh every ~0.5s (delegated to SubagentDisplayManager)
        if ($this->breathTick % 15 === 0) {
            ($this->subagentTickCallback)();
        }

        // When using independent timer, render directly.
        // When using scheduler, the scheduler handles the render call.
        if ($this->scheduler === null) {
            ($this->renderCallback)();
        }
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
