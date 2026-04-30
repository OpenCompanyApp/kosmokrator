<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\TuiModalManager;
use Kosmokrator\UI\Tui\TuiScheduler;
use Kosmokrator\UI\Tui\Widget\SwarmDashboardWidget;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;

#[AllowMockObjectsWithoutExpectations]
final class TuiModalManagerTest extends TestCase
{
    private function createManager(
        ?TuiStateStore $state = null,
        ?ContainerWidget $overlay = null,
        ?TuiScheduler $scheduler = null,
        ?\Closure $forceRenderCallback = null,
    ): TuiModalManager {
        $overlay ??= new ContainerWidget;
        $sessionRoot = $this->createMock(AbstractWidget::class);
        $tui = $this->createMock(Tui::class);
        $input = $this->createMock(EditorWidget::class);

        return new TuiModalManager(
            state: $state ?? new TuiStateStore,
            overlay: $overlay,
            sessionRoot: $sessionRoot,
            tui: $tui,
            input: $input,
            renderCallback: fn (): bool => true,
            forceRenderCallback: $forceRenderCallback ?? fn (): bool => true,
            scheduler: $scheduler,
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

    public function test_resume_ask_suspension_consumes_reference_before_resuming(): void
    {
        $manager = $this->createManager();
        $suspension = new RecordingSuspension;

        $property = new \ReflectionProperty(TuiModalManager::class, 'askSuspension');
        $property->setValue($manager, $suspension);

        $this->assertTrue($manager->resumeAskSuspension('first'));
        $this->assertFalse($manager->resumeAskSuspension('second'));
        $this->assertNull($manager->getAskSuspension());
        $this->assertSame(['first'], $suspension->resumed);
    }

    public function test_agents_dashboard_cancels_refresh_timer_before_cleanup_render_errors(): void
    {
        $overlay = new ContainerWidget;
        $cancelled = [];
        $scheduler = TuiScheduler::fromCallbacks(
            static fn (callable $callback, float $intervalSeconds): string => 'dashboard-refresh',
            static function (string $id) use (&$cancelled): void {
                $cancelled[] = $id;
            },
        );
        $manager = $this->createManager(
            overlay: $overlay,
            scheduler: $scheduler,
            forceRenderCallback: static function (): never {
                throw new \RuntimeException('render failed');
            },
        );

        EventLoop::queue(static function () use ($overlay): void {
            $widget = $overlay->all()[0] ?? null;
            self::assertInstanceOf(SwarmDashboardWidget::class, $widget);
            $widget->handleInput("\x1b");
        });

        $summary = $this->dashboardSummary();

        try {
            $manager->showAgentsDashboard($summary, [], static fn (): array => [
                'summary' => $summary,
                'stats' => [],
            ]);
            $this->fail('Expected cleanup render failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('render failed', $e->getMessage());
        }

        $this->assertSame(['dashboard-refresh'], $cancelled);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardSummary(): array
    {
        return [
            'total' => 0,
            'done' => 0,
            'running' => 0,
            'queued' => 0,
            'failed' => 0,
            'retrying' => 0,
            'tokensIn' => 0,
            'tokensOut' => 0,
            'cost' => 0.0,
            'avgCost' => 0.0,
            'elapsed' => 0.0,
            'rate' => 0.0,
            'eta' => 0.0,
            'active' => [],
            'failures' => [],
            'retriedAndRecovered' => 0,
            'byType' => [],
        ];
    }
}

/**
 * @implements Suspension<mixed>
 */
final class RecordingSuspension implements Suspension
{
    /** @var list<mixed> */
    public array $resumed = [];

    public function resume(mixed $value = null): void
    {
        $this->resumed[] = $value;
    }

    public function suspend(): mixed
    {
        return null;
    }

    public function throw(\Throwable $throwable): void
    {
        throw $throwable;
    }
}
