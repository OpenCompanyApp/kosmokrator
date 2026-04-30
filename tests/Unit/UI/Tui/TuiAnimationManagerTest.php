<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\TuiAnimationManager;
use Kosmokrator\UI\Tui\TuiScheduler;
use PHPUnit\Framework\TestCase;

final class TuiAnimationManagerTest extends TestCase
{
    private TuiStateStore $state;

    private bool $refreshCalled;

    private bool $forceRenderCalled;

    private bool $subagentTickCalled;

    private bool $subagentCleanupCalled;

    private function createManager(): TuiAnimationManager
    {
        $this->state = new TuiStateStore;
        $this->refreshCalled = false;
        $this->forceRenderCalled = false;
        $this->subagentTickCalled = false;
        $this->subagentCleanupCalled = false;

        return new TuiAnimationManager(
            state: $this->state,
            subagentTickCallback: function (): void {
                $this->subagentTickCalled = true;
            },
            subagentCleanupCallback: function (): void {
                $this->subagentCleanupCalled = true;
            },
            renderCallback: function (): void {
                $this->refreshCalled = true;
            },
            forceRenderCallback: function (): void {
                $this->forceRenderCalled = true;
            },
        );
    }

    public function test_initial_phase_is_idle(): void
    {
        $manager = $this->createManager();
        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
    }

    public function test_initial_breath_color_is_null(): void
    {
        $manager = $this->createManager();
        $this->assertNull($manager->getBreathColor());
    }

    public function test_initial_thinking_phrase_is_null(): void
    {
        $manager = $this->createManager();
        $this->assertNull($manager->getThinkingPhrase());
    }

    public function test_initial_thinking_start_time_is_zero(): void
    {
        $manager = $this->createManager();
        $this->assertSame(0.0, $manager->getThinkingStartTime());
    }

    public function test_set_phase_to_same_phase_is_noop(): void
    {
        $manager = $this->createManager();
        $manager->setPhase(AgentPhase::Idle);
        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
        $this->assertFalse($this->forceRenderCalled);
    }

    public function test_set_phase_to_thinking_sets_phrase(): void
    {
        $manager = $this->createManager();
        $manager->setPhase(AgentPhase::Thinking);

        $phrase = $manager->getThinkingPhrase();
        $this->assertNotNull($phrase);

        $expectedPhrases = [
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
        $this->assertContains($phrase, $expectedPhrases);
    }

    public function test_set_phase_to_thinking_updates_start_time(): void
    {
        $manager = $this->createManager();
        $before = microtime(true);
        $manager->setPhase(AgentPhase::Thinking);
        $after = microtime(true);

        $startTime = $manager->getThinkingStartTime();
        $this->assertGreaterThanOrEqual($before, $startTime);
        $this->assertLessThanOrEqual($after, $startTime);
    }

    public function test_set_phase_to_thinking_with_tasks_does_not_signal_loader(): void
    {
        $manager = $this->createManager();
        $this->state->setHasTasks(true);
        $manager->setPhase(AgentPhase::Thinking);

        // When hasTasks is true, the loader signal is not set
        $this->assertFalse($this->state->getHasThinkingLoader());
    }

    public function test_set_phase_to_thinking_without_tasks_signals_loader(): void
    {
        $manager = $this->createManager();
        $this->state->setHasTasks(false);
        $manager->setPhase(AgentPhase::Thinking);

        // When hasTasks is false, the loader signal is set
        $this->assertTrue($this->state->getHasThinkingLoader());
    }

    public function test_set_phase_idle_after_thinking_clears_state(): void
    {
        $manager = $this->createManager();
        $this->state->setHasTasks(false);
        $manager->setPhase(AgentPhase::Thinking);

        $this->assertNotNull($manager->getThinkingPhrase());

        $manager->setPhase(AgentPhase::Idle);

        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
        $this->assertNull($manager->getThinkingPhrase());
        $this->assertNull($manager->getBreathColor());
    }

    public function test_set_phase_idle_triggers_subagent_cleanup(): void
    {
        $manager = $this->createManager();
        $manager->setPhase(AgentPhase::Thinking);
        $manager->setPhase(AgentPhase::Idle);
        $this->assertTrue($this->subagentCleanupCalled);
    }

    public function test_set_phase_to_tools_preserves_thinking_phrase(): void
    {
        $manager = $this->createManager();
        $this->state->setHasTasks(false);
        $manager->setPhase(AgentPhase::Thinking);

        $phrase = $manager->getThinkingPhrase();
        $this->assertNotNull($phrase);

        $manager->setPhase(AgentPhase::Tools);

        $this->assertSame($phrase, $manager->getThinkingPhrase());
        $this->assertSame(AgentPhase::Tools, $manager->getCurrentPhase());
    }

    public function test_constructor_accepts_all_closures(): void
    {
        $manager = new TuiAnimationManager(
            state: new TuiStateStore,
            subagentTickCallback: function (): void {},
            subagentCleanupCallback: function (): void {},
            renderCallback: function (): void {},
            forceRenderCallback: function (): void {},
        );

        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
    }

    public function test_full_phase_lifecycle_thinking_tools_idle(): void
    {
        $manager = $this->createManager();
        $this->state->setHasTasks(false);

        $manager->setPhase(AgentPhase::Thinking);
        $this->assertSame(AgentPhase::Thinking, $manager->getCurrentPhase());
        $this->assertNotNull($manager->getThinkingPhrase());
        $this->assertTrue($this->state->getHasThinkingLoader());

        $manager->setPhase(AgentPhase::Tools);
        $this->assertSame(AgentPhase::Tools, $manager->getCurrentPhase());

        $manager->setPhase(AgentPhase::Idle);
        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
        $this->assertNull($manager->getThinkingPhrase());
        $this->assertNull($manager->getBreathColor());
    }

    public function test_show_compacting_signals_loader(): void
    {
        $manager = $this->createManager();
        $manager->showCompacting();

        $this->assertTrue($this->state->getHasCompactingLoader());
        $this->assertNotNull($this->state->getThinkingPhrase());
    }

    public function test_clear_compacting_clears_signal(): void
    {
        $manager = $this->createManager();
        $manager->showCompacting();
        $manager->clearCompacting();

        $this->assertFalse($this->state->getHasCompactingLoader());
    }

    public function test_clear_compacting_restores_task_suppressed_thinking_without_stopping_driver(): void
    {
        $cancelled = [];
        $scheduler = TuiScheduler::fromCallbacks(
            static fn (callable $callback, float $intervalSeconds): string => 'breath-timer',
            static function (string $id) use (&$cancelled): void {
                $cancelled[] = $id;
            },
        );
        $this->state = new TuiStateStore;
        $manager = new TuiAnimationManager(
            state: $this->state,
            subagentTickCallback: static function (): void {},
            subagentCleanupCallback: static function (): void {},
            renderCallback: static function (): void {},
            forceRenderCallback: static function (): void {},
            scheduler: $scheduler,
        );

        $this->state->setHasTasks(true);
        $manager->setPhase(AgentPhase::Thinking);
        $thinkingPhrase = $manager->getThinkingPhrase();
        $this->assertFalse($this->state->getHasThinkingLoader());

        $manager->showCompacting();
        $manager->clearCompacting();

        $this->assertSame(AgentPhase::Thinking, $manager->getCurrentPhase());
        $this->assertSame($thinkingPhrase, $manager->getThinkingPhrase());
        $this->assertFalse($this->state->getHasThinkingLoader());
        $this->assertFalse($this->state->getHasCompactingLoader());
        $this->assertSame([], $cancelled);
    }

    public function test_clear_compacting_from_idle_stops_driver_and_clears_phrase(): void
    {
        $cancelled = [];
        $scheduler = TuiScheduler::fromCallbacks(
            static fn (callable $callback, float $intervalSeconds): string => 'breath-timer',
            static function (string $id) use (&$cancelled): void {
                $cancelled[] = $id;
            },
        );
        $this->state = new TuiStateStore;
        $manager = new TuiAnimationManager(
            state: $this->state,
            subagentTickCallback: static function (): void {},
            subagentCleanupCallback: static function (): void {},
            renderCallback: static function (): void {},
            forceRenderCallback: static function (): void {},
            scheduler: $scheduler,
        );

        $manager->showCompacting();
        $manager->clearCompacting();

        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
        $this->assertNull($manager->getThinkingPhrase());
        $this->assertSame(['breath-timer'], $cancelled);
    }

    public function test_teardown_stops_breathing_timer_and_clears_loader_signals(): void
    {
        $cancelled = [];
        $scheduler = TuiScheduler::fromCallbacks(
            static fn (callable $callback, float $intervalSeconds): string => 'breath-timer',
            static function (string $id) use (&$cancelled): void {
                $cancelled[] = $id;
            },
        );
        $this->state = new TuiStateStore;
        $manager = new TuiAnimationManager(
            state: $this->state,
            subagentTickCallback: static function (): void {},
            subagentCleanupCallback: static function (): void {},
            renderCallback: static function (): void {},
            forceRenderCallback: static function (): void {},
            scheduler: $scheduler,
        );

        $manager->setPhase(AgentPhase::Thinking);
        $this->assertTrue($this->state->getHasThinkingLoader());

        $manager->teardown();

        $this->assertSame(['breath-timer'], $cancelled);
        $this->assertFalse($this->state->getHasThinkingLoader());
        $this->assertFalse($this->state->getHasCompactingLoader());
        $this->assertNull($this->state->getThinkingPhrase());
        $this->assertNull($this->state->getBreathColor());
    }
}
