<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\TabItem;
use Kosmokrator\UI\Tui\Widget\TabsWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class TabsWidgetTest extends TestCase
{
    private function createWidget(array $labels = ['Files', 'Branches', 'Commits']): TabsWidget
    {
        $items = TabItem::fromLabels($labels);

        return new TabsWidget($items);
    }

    // ── Navigation ────────────────────────────────────────────────────────────

    public function test_navigation_right(): void
    {
        $widget = $this->createWidget();
        $this->assertSame(0, $widget->getActiveIndex());

        $widget->handleInput("\x1b[C"); // Right arrow
        $this->assertSame(1, $widget->getActiveIndex());

        $widget->handleInput("\x1b[C"); // Right arrow again
        $this->assertSame(2, $widget->getActiveIndex());

        // Wrap around
        $widget->handleInput("\x1b[C");
        $this->assertSame(0, $widget->getActiveIndex());
    }

    public function test_navigation_left(): void
    {
        $widget = $this->createWidget();
        $this->assertSame(0, $widget->getActiveIndex());

        // Left from first wraps to last
        $widget->handleInput("\x1b[D"); // Left arrow
        $this->assertSame(2, $widget->getActiveIndex());

        $widget->handleInput("\x1b[D"); // Left again
        $this->assertSame(1, $widget->getActiveIndex());
    }

    public function test_navigation_home(): void
    {
        $widget = $this->createWidget();
        $widget->setActiveIndex(2);
        $this->assertSame(2, $widget->getActiveIndex());

        $widget->handleInput("\x1b[H"); // Home
        $this->assertSame(0, $widget->getActiveIndex());
    }

    public function test_navigation_end(): void
    {
        $widget = $this->createWidget();
        $this->assertSame(0, $widget->getActiveIndex());

        $widget->handleInput("\x1b[F"); // End
        $this->assertSame(2, $widget->getActiveIndex());
    }

    public function test_navigation_number_shortcut(): void
    {
        $widget = $this->createWidget();

        $widget->handleInput('2');
        $this->assertSame(1, $widget->getActiveIndex());

        $widget->handleInput('3');
        $this->assertSame(2, $widget->getActiveIndex());

        $widget->handleInput('1');
        $this->assertSame(0, $widget->getActiveIndex());
    }

    public function test_navigation_number_out_of_range(): void
    {
        $widget = $this->createWidget();

        // '5' is out of range (only 3 tabs) — should not change
        $widget->handleInput('5');
        $this->assertSame(0, $widget->getActiveIndex());
    }

    // ── Active Tab Tracking ───────────────────────────────────────────────────

    public function test_active_tab_tracking_via_set_active_index(): void
    {
        $widget = $this->createWidget();

        $widget->setActiveIndex(1);
        $this->assertSame(1, $widget->getActiveIndex());
        $this->assertSame('branches', $widget->getActiveTabId());

        $widget->setActiveIndex(2);
        $this->assertSame(2, $widget->getActiveIndex());
        $this->assertSame('commits', $widget->getActiveTabId());
    }

    public function test_active_tab_tracking_via_set_active_tab(): void
    {
        $widget = $this->createWidget();

        $widget->setActiveTab('branches');
        $this->assertSame(1, $widget->getActiveIndex());
        $this->assertSame('branches', $widget->getActiveTabId());

        $widget->setActiveTab('commits');
        $this->assertSame(2, $widget->getActiveIndex());
    }

    public function test_active_tab_clamping(): void
    {
        $widget = $this->createWidget();

        // Negative index clamped to 0
        $widget->setActiveIndex(-5);
        $this->assertSame(0, $widget->getActiveIndex());

        // Index beyond count clamped to last
        $widget->setActiveIndex(100);
        $this->assertSame(2, $widget->getActiveIndex());
    }

    public function test_active_tab_id_empty(): void
    {
        $widget = new TabsWidget();
        $this->assertNull($widget->getActiveTabId());
    }

    // ── onTabChange Callback ──────────────────────────────────────────────────

    public function test_on_tab_change_callback(): void
    {
        $widget = $this->createWidget();
        $changes = [];
        $widget->onTabChange(function (string $tabId, int $tabIndex) use (&$changes): void {
            $changes[] = ['id' => $tabId, 'index' => $tabIndex];
        });

        $widget->handleInput("\x1b[C"); // Right → branches
        $this->assertCount(1, $changes);
        $this->assertSame(['id' => 'branches', 'index' => 1], $changes[0]);

        $widget->handleInput("\x1b[C"); // Right → commits
        $this->assertCount(2, $changes);
        $this->assertSame(['id' => 'commits', 'index' => 2], $changes[1]);
    }

    public function test_on_tab_change_not_called_on_same_tab(): void
    {
        $widget = $this->createWidget();
        $callCount = 0;
        $widget->onTabChange(function () use (&$callCount): void {
            $callCount++;
        });

        // setActiveIndex to same index should not trigger callback
        $widget->setActiveIndex(0);
        $this->assertSame(0, $callCount);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function test_render_empty_tabs(): void
    {
        $widget = new TabsWidget();
        $context = new RenderContext(80, 24);

        $this->assertSame([], $widget->render($context));
    }

    public function test_render_returns_single_line(): void
    {
        $widget = $this->createWidget();
        $context = new RenderContext(80, 24);

        $lines = $widget->render($context);
        $this->assertCount(1, $lines);
    }

    public function test_render_contains_tab_labels(): void
    {
        $widget = $this->createWidget();
        $context = new RenderContext(80, 24);

        $lines = $widget->render($context);
        $output = $lines[0];

        // Labels should appear in output
        $this->assertStringContainsString('Files', $output);
        $this->assertStringContainsString('Branches', $output);
        $this->assertStringContainsString('Commits', $output);
    }

    public function test_render_contains_shortcut_hints(): void
    {
        $widget = $this->createWidget();
        $context = new RenderContext(80, 24);

        $lines = $widget->render($context);
        $output = $lines[0];

        // Shortcuts should be visible
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString('3', $output);
    }

    public function test_render_with_fill_dashes(): void
    {
        $widget = $this->createWidget();
        $context = new RenderContext(80, 24);

        $lines = $widget->render($context);
        $output = $lines[0];

        // Should contain fill dashes (─)
        $this->assertStringContainsString('─', $output);
    }

    // ── Focus State ───────────────────────────────────────────────────────────

    public function test_focus_state(): void
    {
        $widget = $this->createWidget();

        $this->assertFalse($widget->isFocused());

        $widget->setFocused(true);
        $this->assertTrue($widget->isFocused());

        $widget->setFocused(false);
        $this->assertFalse($widget->isFocused());
    }

    public function test_render_focused_differs_from_unfocused(): void
    {
        $widget = $this->createWidget();
        $context = new RenderContext(80, 24);

        $widget->setFocused(false);
        $unfocusedOutput = $widget->render($context)[0];

        $widget->setFocused(true);
        $focusedOutput = $widget->render($context)[0];

        // Focused output should differ (borderAccent is applied)
        $this->assertNotSame($unfocusedOutput, $focusedOutput);
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public function test_set_tabs(): void
    {
        $widget = new TabsWidget();
        $this->assertSame([], $widget->render(new RenderContext(80, 24)));

        $items = TabItem::fromLabels(['Tab A', 'Tab B']);
        $widget->setTabs($items);

        $this->assertSame(0, $widget->getActiveIndex());
        $this->assertSame('tab-a', $widget->getActiveTabId());

        $lines = $widget->render(new RenderContext(80, 24));
        $this->assertCount(1, $lines);
    }

    public function test_set_tabs_resets_active_index_if_out_of_range(): void
    {
        $widget = $this->createWidget(['One', 'Two', 'Three']);
        $widget->setActiveIndex(2);
        $this->assertSame(2, $widget->getActiveIndex());

        // Replace with fewer tabs — active index should be clamped
        $widget->setTabs(TabItem::fromLabels(['A', 'B']));
        $this->assertSame(1, $widget->getActiveIndex());
    }

    public function test_set_divider(): void
    {
        $widget = $this->createWidget();
        $widget->setDivider(' | ');

        $context = new RenderContext(80, 24);
        $lines = $widget->render($context);

        $this->assertStringContainsString('|', $lines[0]);
    }

    public function test_handle_input_empty_tabs(): void
    {
        $widget = new TabsWidget();

        // Should not throw
        $widget->handleInput("\x1b[C");
        $this->assertSame(0, $widget->getActiveIndex());
    }
}
