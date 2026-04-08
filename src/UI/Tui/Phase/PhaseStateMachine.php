<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Phase;

use OpenCompany\Signal\Signal;

/**
 * Immutable transition definition.
 */
final readonly class Transition
{
    /**
     * @param  Phase  $from  Source phase
     * @param  Phase  $to  Target phase
     * @param  string  $name  Human-readable transition name (e.g. "think", "execute", "settle")
     */
    public function __construct(
        public Phase $from,
        public Phase $to,
        public string $name,
    ) {}
}

/**
 * Validates and executes phase transitions against a fixed transition table.
 *
 * The transition table encodes the agent lifecycle:
 *
 *   Idle ──think──→ Thinking ──execute──→ Tools ──settle──→ Idle
 *     │               │
 *     │               └──cancel──→ Idle
 *     │
 *     └──compact──→ Compacting ──compactDone──→ Idle
 *
 * Compacting can only start from Idle (between agent turns).
 * Thinking can cancel back to Idle (e.g. on empty response).
 * Transitions are validated before any side effects run.
 *
 * The current phase is stored in a Signal<Phase> so the rest of the
 * reactive system can derive computed values from it.
 */
final class PhaseStateMachine
{
    /**
     * Backing signal. External consumers can subscribe to phase changes
     * or derive computed values from it, but must NOT write to it directly.
     *
     * @var Signal<Phase>
     */
    private readonly Signal $signal;

    /** @var array<string, Transition> keyed by "from→to" */
    private array $transitions = [];

    /**
     * Named transition listeners.
     *
     * Keyed by transition name. Each value is a list of closures:
     *   Closure(Transition, Phase $from, Phase $to): void
     *
     * @var array<string, list<\Closure(Transition, Phase, Phase): void>>
     */
    private array $listeners = [];

    /**
     * Wildcard listeners — invoked on every transition.
     *
     * @var list<\Closure(Transition, Phase, Phase): void>
     */
    private array $anyListeners = [];

    /**
     * @param  Signal<Phase>|null  $signal  Optional pre-created signal. If null,
     *                                      one is created with Phase::Idle.
     */
    public function __construct(?Signal $signal = null)
    {
        $this->signal = $signal ?? self::signalOfPhase(Phase::Idle);
        $this->registerTransitions();
    }

    // ── Public API ──────────────────────────────────────────────────────

    /**
     * Get the backing signal for reactive composition.
     *
     * @return Signal<Phase>
     */
    public function signal(): Signal
    {
        return $this->signal;
    }

    /**
     * Read the current phase (without tracking).
     */
    public function current(): Phase
    {
        return $this->signal->value();
    }

    /**
     * Attempt a transition to the given phase.
     *
     * If the target equals the current phase, this is a no-op.
     * Otherwise, the transition is validated against the table.
     *
     * @throws InvalidTransitionException if the transition is not in the table
     */
    public function transition(Phase $target): void
    {
        $current = $this->current();

        if ($target === $current) {
            return;
        }

        $key = $this->transitionKey($current, $target);

        if (! isset($this->transitions[$key])) {
            throw InvalidTransitionException::fromTo($current, $target);
        }

        $transition = $this->transitions[$key];

        // Update the signal (this propagates to all signal subscribers)
        $this->signal->set($target);

        // Fire transition listeners (separate from signal subscribers)
        $this->fire($transition, $current, $target);
    }

    /**
     * Check whether a transition to the target phase is valid from the current state.
     */
    public function canTransition(Phase $target): bool
    {
        $current = $this->current();

        return $target === $current
            || isset($this->transitions[$this->transitionKey($current, $target)]);
    }

    /**
     * Check whether a transition between two specific phases is valid,
     * regardless of the current state.
     */
    public function isValidTransition(Phase $from, Phase $to): bool
    {
        return $from === $to
            || isset($this->transitions[$this->transitionKey($from, $to)]);
    }

    // ── Listener registration ───────────────────────────────────────────

    /**
     * Subscribe a listener to a named transition.
     *
     * Multiple listeners can subscribe to the same transition name.
     * Listeners are invoked in registration order.
     *
     * @param  string  $transitionName  One of: think, cancel, execute, settle, compact, compactDone
     * @param  \Closure(Transition, Phase, Phase): void  $listener
     */
    public function on(string $transitionName, \Closure $listener): void
    {
        $this->listeners[$transitionName][] = $listener;
    }

    /**
     * Subscribe a listener to ANY transition.
     *
     * Wildcard listeners fire after named listeners, in registration order.
     *
     * @param  \Closure(Transition, Phase, Phase): void  $listener
     */
    public function onAny(\Closure $listener): void
    {
        $this->anyListeners[] = $listener;
    }

    // ── Transition table ────────────────────────────────────────────────

    /**
     * Register all valid transitions.
     *
     * Valid transitions:
     *   - idle → thinking    (think)      — before LLM call
     *   - thinking → tools   (execute)    — after LLM returns tool calls
     *   - thinking → idle    (cancel)     — LLM returns empty / error
     *   - tools → idle       (settle)     — after tool execution finishes
     *   - idle → compacting  (compact)    — before context compaction
     *   - compacting → idle  (compactDone) — after compaction completes
     */
    private function registerTransitions(): void
    {
        $this->add('think', Phase::Idle, Phase::Thinking);
        $this->add('execute', Phase::Thinking, Phase::Tools);
        $this->add('cancel', Phase::Thinking, Phase::Idle);
        $this->add('settle', Phase::Tools, Phase::Idle);
        $this->add('compact', Phase::Idle, Phase::Compacting);
        $this->add('compactDone', Phase::Compacting, Phase::Idle);
    }

    private function add(string $name, Phase $from, Phase $to): void
    {
        $transition = new Transition($from, $to, $name);
        $this->transitions[$this->transitionKey($from, $to)] = $transition;
    }

    // ── Event dispatch ──────────────────────────────────────────────────

    private function fire(Transition $transition, Phase $from, Phase $to): void
    {
        // Named listeners first
        foreach ($this->listeners[$transition->name] ?? [] as $listener) {
            $listener($transition, $from, $to);
        }

        // Wildcard listeners
        foreach ($this->anyListeners as $listener) {
            $listener($transition, $from, $to);
        }
    }

    /**
     * Create a Signal<Phase> with proper type widening.
     *
     * @return Signal<Phase>
     */
    private static function signalOfPhase(Phase $phase): Signal
    {
        return new Signal($phase);
    }

    private function transitionKey(Phase $from, Phase $to): string
    {
        return "{$from->value}→{$to->value}";
    }
}
