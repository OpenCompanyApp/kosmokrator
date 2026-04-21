<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\TuiModalManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;

#[AllowMockObjectsWithoutExpectations]
final class TuiModalManagerTest extends TestCase
{
    private function createManager(): TuiModalManager
    {
        $overlay = new ContainerWidget;
        $sessionRoot = $this->createMock(AbstractWidget::class);
        $tui = $this->createMock(Tui::class);
        $input = $this->createMock(EditorWidget::class);

        return new TuiModalManager(
            state: new TuiStateStore,
            overlay: $overlay,
            sessionRoot: $sessionRoot,
            tui: $tui,
            input: $input,
            renderCallback: fn (): bool => true,
            forceRenderCallback: fn (): bool => true,
        );
    }

    public function test_initial_ask_suspension_is_null(): void
    {
        $manager = $this->createManager();
        $this->assertNull($manager->getAskSuspension());
    }

    public function test_clear_ask_suspension_sets_to_null(): void
    {
        $manager = $this->createManager();
        // Even without setting a suspension, clearing should not error
        $manager->clearAskSuspension();
        $this->assertNull($manager->getAskSuspension());
    }

    public function test_constructor_accepts_dependencies(): void
    {
        $manager = $this->createManager();
        // The object was created without error — verify initial state
        $this->assertNull($manager->getAskSuspension());
    }

    public function test_pick_session_returns_null_for_empty_items(): void
    {
        $manager = $this->createManager();
        $result = $manager->pickSession([]);
        $this->assertNull($result);
    }

    public function test_clear_ask_suspension_is_idempotent(): void
    {
        $manager = $this->createManager();
        $manager->clearAskSuspension();
        $manager->clearAskSuspension();
        $this->assertNull($manager->getAskSuspension());
    }
}
