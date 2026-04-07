<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\UI\Tui\Toast;

use Kosmokrator\UI\Tui\Signal\Signal;
use Kosmokrator\UI\Tui\Toast\ToastItem;
use Kosmokrator\UI\Tui\Toast\ToastOverlayWidget;
use Kosmokrator\UI\Tui\Toast\ToastPhase;
use Kosmokrator\UI\Tui\Toast\ToastType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class ToastOverlayWidgetTest extends TestCase
{
    private function createRenderContext(int $cols = 80, int $rows = 24): RenderContext
    {
        return new RenderContext($cols, $rows);
    }

    public function testEmptyStackReturnsNoOutput(): void
    {
        $toasts = new Signal([]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());
        $this->assertSame([], $output);
    }

    public function testSingleToastRendersWithBorder(): void
    {
        $toast = ToastItem::info('Test message');
        // Simulate visible state for rendering
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        $this->assertNotEmpty($output);

        // Should have 3 lines: top border, content, bottom border
        $this->assertCount(3, $output);

        // Each line should contain cursor positioning
        foreach ($output as $line) {
            $this->assertStringContainsString("\033[", $line);
        }

        // Should contain the info icon
        $combinedOutput = implode('', $output);
        $this->assertStringContainsString('ℹ', $combinedOutput);
        $this->assertStringContainsString('Test message', $combinedOutput);
    }

    public function testToastWithSuccessType(): void
    {
        $toast = ToastItem::success('File saved');
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        $combinedOutput = implode('', $output);
        $this->assertStringContainsString('✓', $combinedOutput);
        $this->assertStringContainsString('File saved', $combinedOutput);
    }

    public function testToastWithErrorType(): void
    {
        $toast = ToastItem::error('Permission denied');
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        $combinedOutput = implode('', $output);
        $this->assertStringContainsString('✕', $combinedOutput);
        $this->assertStringContainsString('Permission denied', $combinedOutput);
    }

    public function testToastWithWarningType(): void
    {
        $toast = ToastItem::warning('Context high');
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        $combinedOutput = implode('', $output);
        $this->assertStringContainsString('⚠', $combinedOutput);
    }

    public function testDoneToastsAreFilteredOut(): void
    {
        $toast = ToastItem::info('Test');
        $toast->markDone();

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        $this->assertSame([], $output);
    }

    public function testFullyTransparentToastIsSkipped(): void
    {
        $toast = ToastItem::info('Test');
        // Default opacity is 0.0, which is <= 0.01
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        $this->assertSame([], $output, 'Toast with ~0 opacity should not render');
    }

    public function testStackedToastsRender(): void
    {
        $toast1 = ToastItem::info('First');
        $toast1->opacity->set(1.0);
        $toast1->slideOffset->set(0);
        $toast1->phase->set(ToastPhase::Visible);

        $toast2 = ToastItem::success('Second');
        $toast2->opacity->set(1.0);
        $toast2->slideOffset->set(0);
        $toast2->phase->set(ToastPhase::Visible);

        $toast3 = ToastItem::error('Third');
        $toast3->opacity->set(1.0);
        $toast3->slideOffset->set(0);
        $toast3->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast1, $toast2, $toast3]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        // 3 toasts × 3 lines each = 9 lines
        $this->assertCount(9, $output);
    }

    public function testStatusBarHeightOffset(): void
    {
        $toast = ToastItem::info('Test');
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);

        // Default status bar height = 1
        $widget1 = new ToastOverlayWidget($toasts);
        $output1 = $widget1->render($this->createRenderContext(80, 24));

        // Larger status bar height
        $widget2 = new ToastOverlayWidget($toasts);
        $widget2->setStatusBarHeight(3);
        $output2 = $widget2->render($this->createRenderContext(80, 24));

        // Both should render, but at different positions
        $this->assertNotEmpty($output1);
        $this->assertNotEmpty($output2);
        $this->assertNotSame($output1, $output2);
    }

    public function testNarrowViewportClampsToastWidth(): void
    {
        $toast = ToastItem::info('A reasonably long message that should be wrapped');
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext(40, 24));

        // Should still render (width clamped to MIN_TOAST_WIDTH)
        $this->assertNotEmpty($output);

        // Should have more than 3 lines due to wrapping
        $this->assertGreaterThan(3, count($output));
    }

    public function testLongMessageWrapsToMultipleLines(): void
    {
        $toast = ToastItem::info('This is a very long message that definitely exceeds the toast inner width and should wrap to multiple lines');
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Visible);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext(80, 24));

        // More than 3 lines (top + 1 content + bottom) due to wrapping
        $this->assertGreaterThan(3, count($output));

        $combinedOutput = implode('', $output);
        $this->assertStringContainsString('This is a very long message', $combinedOutput);
    }

    public function testFadingToastUsesDimColors(): void
    {
        $toast = ToastItem::info('Fading');
        $toast->opacity->set(0.3); // Below 0.5 threshold
        $toast->slideOffset->set(0);
        $toast->phase->set(ToastPhase::Exiting);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $output = $widget->render($this->createRenderContext());

        $this->assertNotEmpty($output);
    }

    public function testSlideOffsetShiftsPosition(): void
    {
        $toast = ToastItem::info('Sliding');
        $toast->opacity->set(1.0);
        $toast->slideOffset->set(10);
        $toast->phase->set(ToastPhase::Entering);

        $toasts = new Signal([$toast]);
        $widget = new ToastOverlayWidget($toasts);
        $outputWithOffset = $widget->render($this->createRenderContext());

        $toast->slideOffset->set(0);
        $outputNoOffset = $widget->render($this->createRenderContext());

        $this->assertNotEmpty($outputWithOffset);
        $this->assertNotEmpty($outputNoOffset);
        // Positions should differ due to slide offset
        $this->assertNotSame($outputWithOffset, $outputNoOffset);
    }
}
