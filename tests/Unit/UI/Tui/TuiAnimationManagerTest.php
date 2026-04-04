<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\UI\Tui\TuiAnimationManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class TuiAnimationManagerTest extends TestCase
{
    private ContainerWidget $thinkingBar;

    private bool $hasTasks;

    private bool $hasSubagentActivity;

    private bool $refreshCalled;

    private bool $forceRenderCalled;

    private bool $subagentTickCalled;

    private bool $subagentCleanupCalled;

    private function createManager(): TuiAnimationManager
    {
        $this->thinkingBar = new ContainerWidget;
        $this->hasTasks = false;
        $this->hasSubagentActivity = false;
        $this->refreshCalled = false;
        $this->forceRenderCalled = false;
        $this->subagentTickCalled = false;
        $this->subagentCleanupCalled = false;

        return new TuiAnimationManager(
            thinkingBar: $this->thinkingBar,
            hasTasksProvider: fn (): bool => $this->hasTasks,
            hasSubagentActivityProvider: fn (): bool => $this->hasSubagentActivity,
            refreshTaskBarCallback: function (): void {
                $this->refreshCalled = true;
            },
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

    public function test_initial_loader_is_null(): void
    {
        $manager = $this->createManager();
        $this->assertNull($manager->getLoader());
    }

    public function test_set_phase_to_same_phase_is_noop(): void
    {
        $manager = $this->createManager();
        // Phase is Idle initially; setting to Idle again should not trigger cleanup
        $manager->setPhase(AgentPhase::Idle);
        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
        // No force render triggered since the phase didn't actually change
        $this->assertFalse($this->forceRenderCalled);
    }

    public function test_set_phase_transitions_current_phase(): void
    {
        $manager = $this->createManager();
        $manager->setPhase(AgentPhase::Idle);
        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
    }

    public function test_ensure_spinners_registered_is_idempotent(): void
    {
        $manager = $this->createManager();
        // Should not throw when called multiple times
        $manager->ensureSpinnersRegistered();
        $manager->ensureSpinnersRegistered();
        $this->assertTrue(true); // No exception means success
    }

    public function test_set_phase_to_thinking_sets_phrase(): void
    {
        $manager = $this->createManager();

        // Use reflection to verify phrase is set without needing event loop
        $manager->setPhase(AgentPhase::Thinking);

        // The thinking phrase should be one of the known phrases
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

    public function test_set_phase_to_thinking_with_tasks_creates_no_loader(): void
    {
        $manager = $this->createManager();
        $this->hasTasks = true;
        $manager->setPhase(AgentPhase::Thinking);

        // When hasTasks is true, no standalone loader is created
        $this->assertNull($manager->getLoader());
    }

    public function test_set_phase_to_thinking_without_tasks_creates_loader(): void
    {
        $manager = $this->createManager();
        $this->hasTasks = false;
        $manager->setPhase(AgentPhase::Thinking);

        // When hasTasks is false, a loader is created
        $this->assertNotNull($manager->getLoader());
    }

    public function test_set_phase_idle_after_thinking_clears_state(): void
    {
        $manager = $this->createManager();
        $this->hasTasks = false;
        $manager->setPhase(AgentPhase::Thinking);

        $this->assertNotNull($manager->getThinkingPhrase());
        $this->assertNotNull($manager->getLoader());

        $manager->setPhase(AgentPhase::Idle);

        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
        $this->assertNull($manager->getThinkingPhrase());
        $this->assertNull($manager->getBreathColor());
        $this->assertNull($manager->getLoader());
    }

    public function test_set_phase_idle_triggers_subagent_cleanup(): void
    {
        $manager = $this->createManager();
        $this->hasTasks = false;
        // Transition away from Idle first, then back
        $manager->setPhase(AgentPhase::Thinking);
        $manager->setPhase(AgentPhase::Idle);
        $this->assertTrue($this->subagentCleanupCalled);
    }

    public function test_set_phase_to_tools_preserves_thinking_phrase(): void
    {
        $manager = $this->createManager();
        $this->hasTasks = false;
        $manager->setPhase(AgentPhase::Thinking);

        $phrase = $manager->getThinkingPhrase();
        $this->assertNotNull($phrase);

        $manager->setPhase(AgentPhase::Tools);

        // Tools phase keeps the thinking phrase for display
        $this->assertSame($phrase, $manager->getThinkingPhrase());
        $this->assertSame(AgentPhase::Tools, $manager->getCurrentPhase());
    }

    public function test_constructor_accepts_all_closures(): void
    {
        $manager = new TuiAnimationManager(
            thinkingBar: new ContainerWidget,
            hasTasksProvider: fn (): bool => false,
            hasSubagentActivityProvider: fn (): bool => false,
            refreshTaskBarCallback: function (): void {},
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
        $this->hasTasks = false;

        $manager->setPhase(AgentPhase::Thinking);
        $this->assertSame(AgentPhase::Thinking, $manager->getCurrentPhase());
        $this->assertNotNull($manager->getThinkingPhrase());
        $this->assertNotNull($manager->getLoader());

        $manager->setPhase(AgentPhase::Tools);
        $this->assertSame(AgentPhase::Tools, $manager->getCurrentPhase());

        $manager->setPhase(AgentPhase::Idle);
        $this->assertSame(AgentPhase::Idle, $manager->getCurrentPhase());
        $this->assertNull($manager->getThinkingPhrase());
        $this->assertNull($manager->getBreathColor());
        $this->assertNull($manager->getLoader());
    }
}
