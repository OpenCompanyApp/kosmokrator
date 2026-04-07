<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Phase;

use Kosmokrator\UI\Tui\Phase\InvalidTransitionException;
use Kosmokrator\UI\Tui\Phase\Phase;
use Kosmokrator\UI\Tui\Phase\PhaseStateMachine;
use Kosmokrator\UI\Tui\Phase\Transition;
use Kosmokrator\UI\Tui\Signal\Signal;
use PHPUnit\Framework\TestCase;

final class PhaseStateMachineTest extends TestCase
{
    private PhaseStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new PhaseStateMachine();
    }

    // ── Initial state ───────────────────────────────────────────────────

    public function testStartsIdle(): void
    {
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function testStartsWithProvidedSignal(): void
    {
        /** @var Signal<Phase> $signal */
        $signal = new Signal(Phase::Thinking);
        $machine = new PhaseStateMachine($signal);

        $this->assertSame(Phase::Thinking, $machine->current());
    }

    public function testSignalIsAccessible(): void
    {
        $signal = $this->machine->signal();
        $this->assertInstanceOf(Signal::class, $signal);
        $this->assertSame(Phase::Idle, $signal->value());
    }

    public function testProvidedSignalIsSameInstance(): void
    {
        /** @var Signal<Phase> $signal */
        $signal = new Signal(Phase::Idle);
        $machine = new PhaseStateMachine($signal);

        $this->assertSame($signal, $machine->signal());
    }

    // ── Valid transitions ───────────────────────────────────────────────

    public function testIdleToThinking(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->assertSame(Phase::Thinking, $this->machine->current());
    }

    public function testThinkingToTools(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);
        $this->assertSame(Phase::Tools, $this->machine->current());
    }

    public function testThinkingToIdle(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Idle);
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function testToolsToIdle(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);
        $this->machine->transition(Phase::Idle);
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function testIdleToCompacting(): void
    {
        $this->machine->transition(Phase::Compacting);
        $this->assertSame(Phase::Compacting, $this->machine->current());
    }

    public function testCompactingToIdle(): void
    {
        $this->machine->transition(Phase::Compacting);
        $this->machine->transition(Phase::Idle);
        $this->assertSame(Phase::Idle, $this->machine->current());
    }

    public function testFullLoop(): void
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

    public function testTransitionToSamePhaseIsNoOp(): void
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

    public function testIdleToToolsThrows(): void
    {
        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: idle → tools');
        $this->machine->transition(Phase::Tools);
    }

    public function testToolsToThinkingThrows(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: tools → thinking');
        $this->machine->transition(Phase::Thinking);
    }

    public function testCompactingToThinkingThrows(): void
    {
        $this->machine->transition(Phase::Compacting);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: compacting → thinking');
        $this->machine->transition(Phase::Thinking);
    }

    public function testCompactingToToolsThrows(): void
    {
        $this->machine->transition(Phase::Compacting);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: compacting → tools');
        $this->machine->transition(Phase::Tools);
    }

    public function testThinkingToCompactingThrows(): void
    {
        $this->machine->transition(Phase::Thinking);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: thinking → compacting');
        $this->machine->transition(Phase::Compacting);
    }

    public function testToolsToCompactingThrows(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->machine->transition(Phase::Tools);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Invalid phase transition: tools → compacting');
        $this->machine->transition(Phase::Compacting);
    }

    // ── State unchanged after invalid transition ────────────────────────

    public function testStateUnchangedAfterInvalidTransition(): void
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

    public function testCanTransitionReturnsTrueForValid(): void
    {
        $this->assertTrue($this->machine->canTransition(Phase::Thinking));
        $this->assertTrue($this->machine->canTransition(Phase::Compacting));
    }

    public function testCanTransitionReturnsTrueForSamePhase(): void
    {
        $this->assertTrue($this->machine->canTransition(Phase::Idle));
    }

    public function testCanTransitionReturnsFalseForInvalid(): void
    {
        $this->assertFalse($this->machine->canTransition(Phase::Tools));
    }

    public function testCanTransitionFromThinking(): void
    {
        $this->machine->transition(Phase::Thinking);
        $this->assertTrue($this->machine->canTransition(Phase::Tools));
        $this->assertTrue($this->machine->canTransition(Phase::Idle));
        $this->assertFalse($this->machine->canTransition(Phase::Compacting));
    }

    // ── isValidTransition ───────────────────────────────────────────────

    public function testIsValidTransitionChecksSpecificPair(): void
    {
        $this->assertTrue($this->machine->isValidTransition(Phase::Idle, Phase::Thinking));
        $this->assertTrue($this->machine->isValidTransition(Phase::Thinking, Phase::Tools));
        $this->assertTrue($this->machine->isValidTransition(Phase::Tools, Phase::Idle));
        $this->assertFalse($this->machine->isValidTransition(Phase::Idle, Phase::Tools));
        $this->assertFalse($this->machine->isValidTransition(Phase::Tools, Phase::Thinking));
    }

    public function testIsValidTransitionSamePhaseReturnsTrue(): void
    {
        $this->assertTrue($this->machine->isValidTransition(Phase::Idle, Phase::Idle));
        $this->assertTrue($this->machine->isValidTransition(Phase::Thinking, Phase::Thinking));
    }

    // ── Named listeners ─────────────────────────────────────────────────

    public function testOnFiresNamedListener(): void
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

    public function testOnFiresMultipleListenersInOrder(): void
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

    public function testOnDoesNotFireForDifferentTransition(): void
    {
        $fired = false;
        $this->machine->on('execute', function () use (&$fired): void {
            $fired = true;
        });

        $this->machine->transition(Phase::Thinking);
        $this->assertFalse($fired);
    }

    // ── Wildcard listeners ──────────────────────────────────────────────

    public function testOnAnyFiresOnEveryTransition(): void
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

    public function testNamedListenersFireBeforeWildcard(): void
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

    public function testSignalUpdatesOnTransition(): void
    {
        $signal = $this->machine->signal();
        $this->assertSame(Phase::Idle, $signal->value());

        $this->machine->transition(Phase::Thinking);
        $this->assertSame(Phase::Thinking, $signal->value());
    }

    public function testSignalSubscribersAreNotified(): void
    {
        $notified = null;
        $this->machine->signal()->subscribe(function (mixed $phase) use (&$notified): void {
            $notified = $phase;
        });

        $this->machine->transition(Phase::Thinking);

        $this->assertSame(Phase::Thinking, $notified);
    }

    public function testSignalVersionIncrementsOnTransition(): void
    {
        $initialVersion = $this->machine->signal()->getVersion();

        $this->machine->transition(Phase::Thinking);

        $this->assertGreaterThan($initialVersion, $this->machine->signal()->getVersion());
    }

    public function testSignalVersionUnchangedOnNoOp(): void
    {
        $versionBefore = $this->machine->signal()->getVersion();

        $this->machine->transition(Phase::Idle);

        $this->assertSame($versionBefore, $this->machine->signal()->getVersion());
    }

    // ── All transition names ────────────────────────────────────────────

    public function testTransitionNames(): void
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
