<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Phase;

use Kosmokrator\UI\Tui\Phase\InvalidTransitionException;
use Kosmokrator\UI\Tui\Phase\Phase;
use Kosmokrator\UI\Tui\Phase\PhaseStateMachine;
use Kosmokrator\UI\Tui\Phase\Transition;
use OpenCompany\Signal\Signal;
use PHPUnit\Framework\TestCase;

final class PhaseStateMachineTest extends TestCase
{
    private PhaseStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new PhaseStateMachine;
    }

    // ── Initial state ───────────────────────────────────────────────────

    public function test_starts_idle(): void
    {
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function test_starts_with_provided_signal(): void
    {
        /** @var Signal<Phase> $signal */
        $signal = new Signal(Phase::Thinking);
        $machine = new PhaseStateMachine($signal);

        $this->assertSame(Phase::Thinking, $machine->current());
    }

    public function test_signal_is_accessible(): void
    {
        $signal = $this->machine->signal();
        $this->assertInstanceOf(Signal::class, $signal);
        $this->assertSame(Phase::Idle, $signal->value());
    }

    public function test_provided_signal_is_same_instance(): void
    {
        /** @var Signal<Phase> $signal */
        $signal = new Signal(Phase::Idle);
        $machine = new PhaseStateMachine($signal);

        $this->assertSame($signal, $machine->signal());
    }

    // ── Valid transitions ───────────────────────────────────────────────

    public function test_idle_to_thinking(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->assertSame(Phase::Thinking, $this->machine->current());
    }

    public function test_thinking_to_tools(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);
        $this->assertSame(Phase::Tools, $this->machine->current());
    }

    public function test_thinking_to_idle(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Idle);
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function test_tools_to_idle(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);
        $this->machine->transition(Phase::Idle);
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function test_idle_to_compacting(): void
    {
        $this->machine->transition(Phase::Compacting);
        $this->assertSame(Phase::Compacting, $this->machine->current());
    }

    public function test_compacting_to_idle(): void
    {
        $this->machine->transition(Phase::Compacting);
        $this->machine->transition(Phase::Idle);
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function test_full_loop(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);
        $this->machine->transition(Phase::Idle);
        $this->machine->transition(Phase::Compacting);
        $this->machine->transition(Phase::Idle);
        $this->machine->transition(Phase::Thinking);
        $this->assertSame(Phase::Thinking, $this->machine->current());
    }

    // ── No-op on same phase ─────────────────────────────────────────────

    public function test_transition_to_same_phase_is_no_op(): void
    {
        $fired = false;
        $this->machine->onAny(function () use (&$fired): void {
            $fired = true;
        });

        $this->machine->transition(Phase::Idle);
        $this->assertSame(Phase::Idle, $this->machine->current());
        $this->assertFalse($fired, 'No listeners should fire on same-phase transition');
    }

    // ── Invalid transitions ─────────────────────────────────────────────

    public function test_idle_to_tools_throws(): void
    {
        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: idle → tools');
        $this->machine->transition(Phase::Tools);
    }

    public function test_tools_to_thinking_throws(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: tools → thinking');
        $this->machine->transition(Phase::Thinking);
    }

    public function test_compacting_to_thinking_throws(): void
    {
        $this->machine->transition(Phase::Compacting);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: compacting → thinking');
        $this->machine->transition(Phase::Thinking);
    }

    public function test_compacting_to_tools_throws(): void
    {
        $this->machine->transition(Phase::Compacting);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: compacting → tools');
        $this->machine->transition(Phase::Tools);
    }

    public function test_thinking_to_compacting_throws(): void
    {
        $this->machine->transition(Phase::Thinking);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: thinking → compacting');
        $this->machine->transition(Phase::Compacting);
    }

    public function test_tools_to_compacting_throws(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: tools → compacting');
        $this->machine->transition(Phase::Compacting);
    }

    // ── State unchanged after invalid transition ────────────────────────

    public function test_state_unchanged_after_invalid_transition(): void
    {
        $this->machine->transition(Phase::Thinking);

        try {
            $this->machine->transition(Phase::Compacting);
        } catch (InvalidTransitionException) {
            // expected
        }

        $this->assertSame(Phase::Thinking, $this->machine->current());
    }

    // ── canTransition ───────────────────────────────────────────────────

    public function test_can_transition_returns_true_for_valid(): void
    {
        $this->assertTrue($this->machine->canTransition(Phase::Thinking));
        $this->assertTrue($this->machine->canTransition(Phase::Compacting));
    }

    public function test_can_transition_returns_true_for_same_phase(): void
    {
        $this->assertTrue($this->machine->canTransition(Phase::Idle));
    }

    public function test_can_transition_returns_false_for_invalid(): void
    {
        $this->assertFalse($this->machine->canTransition(Phase::Tools));
    }

    public function test_can_transition_from_thinking(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->assertTrue($this->machine->canTransition(Phase::Tools));
        $this->assertTrue($this->machine->canTransition(Phase::Idle));
        $this->assertFalse($this->machine->canTransition(Phase::Compacting));
    }

    // ── isValidTransition ───────────────────────────────────────────────

    public function test_is_valid_transition_checks_specific_pair(): void
    {
        $this->assertTrue($this->machine->isValidTransition(Phase::Idle, Phase::Thinking));
        $this->assertTrue($this->machine->isValidTransition(Phase::Thinking, Phase::Tools));
        $this->assertTrue($this->machine->isValidTransition(Phase::Tools, Phase::Idle));
        $this->assertFalse($this->machine->isValidTransition(Phase::Idle, Phase::Tools));
        $this->assertFalse($this->machine->isValidTransition(Phase::Tools, Phase::Thinking));
    }

    public function test_is_valid_transition_same_phase_returns_true(): void
    {
        $this->assertTrue($this->machine->isValidTransition(Phase::Idle, Phase::Idle));
        $this->assertTrue($this->machine->isValidTransition(Phase::Thinking, Phase::Thinking));
    }

    // ── Named listeners ─────────────────────────────────────────────────

    public function test_on_fires_named_listener(): void
    {
        $received = null;
        $this->machine->on('think', function (Transition $t, Phase $from, Phase $to) use (&$received): void {
            $received = ['transition' => $t, 'from' => $from, 'to' => $to];
        });

        $this->machine->transition(Phase::Thinking);

        $this->assertNotNull($received);
        $this->assertSame('think', $received['transition']->name);
        $this->assertSame(Phase::Idle, $received['from']);
        $this->assertSame(Phase::Thinking, $received['to']);
        $this->assertSame(Phase::Idle, $received['transition']->from);
        $this->assertSame(Phase::Thinking, $received['transition']->to);
    }

    public function test_on_fires_multiple_listeners_in_order(): void
    {
        $order = [];
        $this->machine->on('think', function () use (&$order): void {
            $order[] = 'first';
        });
        $this->machine->on('think', function () use (&$order): void {
            $order[] = 'second';
        });

        $this->machine->transition(Phase::Thinking);

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_on_does_not_fire_for_different_transition(): void
    {
        $fired = false;
        $this->machine->on('execute', function () use (&$fired): void {
            $fired = true;
        });

        $this->machine->transition(Phase::Thinking);
        $this->assertFalse($fired);
    }

    // ── Wildcard listeners ──────────────────────────────────────────────

    public function test_on_any_fires_on_every_transition(): void
    {
        $transitions = [];
        $this->machine->onAny(function (Transition $t, Phase $from, Phase $to) use (&$transitions): void {
            $transitions[] = $t->name;
        });

        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);
        $this->machine->transition(Phase::Idle);

        $this->assertSame(['think', 'execute', 'settle'], $transitions);
    }

    public function test_named_listeners_fire_before_wildcard(): void
    {
        $order = [];
        $this->machine->on('think', function () use (&$order): void {
            $order[] = 'named';
        });
        $this->machine->onAny(function () use (&$order): void {
            $order[] = 'wildcard';
        });

        $this->machine->transition(Phase::Thinking);

        $this->assertSame(['named', 'wildcard'], $order);
    }

    // ── Signal integration ──────────────────────────────────────────────

    public function test_signal_updates_on_transition(): void
    {
        $signal = $this->machine->signal();
        $this->assertSame(Phase::Idle, $signal->value());

        $this->machine->transition(Phase::Thinking);
        $this->assertSame(Phase::Thinking, $signal->value());
    }

    public function test_signal_subscribers_are_notified(): void
    {
        $notified = null;
        $this->machine->signal()->subscribe(function (mixed $phase) use (&$notified): void {
            $notified = $phase;
        });

        $this->machine->transition(Phase::Thinking);

        $this->assertSame(Phase::Thinking, $notified);
    }

    public function test_signal_version_increments_on_transition(): void
    {
        $initialVersion = $this->machine->signal()->getVersion();

        $this->machine->transition(Phase::Thinking);

        $this->assertGreaterThan($initialVersion, $this->machine->signal()->getVersion());
    }

    public function test_signal_version_unchanged_on_no_op(): void
    {
        $versionBefore = $this->machine->signal()->getVersion();

        $this->machine->transition(Phase::Idle);

        $this->assertSame($versionBefore, $this->machine->signal()->getVersion());
    }

    // ── All transition names ────────────────────────────────────────────

    public function test_transition_names(): void
    {
        $names = [];
        $this->machine->onAny(function (Transition $t) use (&$names): void {
            $names[] = $t->name;
        });

        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Idle); // cancel
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);
        $this->machine->transition(Phase::Idle);
        $this->machine->transition(Phase::Compacting);
        $this->machine->transition(Phase::Idle);

        $this->assertSame(
            ['think', 'cancel', 'think', 'execute', 'settle', 'compact', 'compactDone'],
            $names,
        );
    }
}
