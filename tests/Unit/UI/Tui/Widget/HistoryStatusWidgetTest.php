<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\Widget\HistoryStatusWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class HistoryStatusWidgetTest extends TestCase
{
    private TuiStateStore $state;

    private HistoryStatusWidget $widget;

    protected function setUp(): void
    {
        $this->state = new TuiStateStore;
        $this->widget = HistoryStatusWidget::of($this->state);
    }

    // ── Visibility logic ───────────────────────────────────────────────

    public function test_render_returns_empty_when_scroll_offset_zero(): void
    {
        $this->state->setScrollOffset(0);
        $this->widget->syncFromSignals();
        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_sync_shows_widget_when_scroll_offset_positive(): void
    {
        $this->state->setScrollOffset(10);
        $this->assertTrue($this->widget->syncFromSignals());

        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);
        $this->assertNotEmpty($result);
    }

    public function test_sync_hides_widget_when_scroll_offset_returns_zero(): void
    {
        $this->state->setScrollOffset(10);
        $this->widget->syncFromSignals();

        $this->state->setScrollOffset(0);
        $this->assertTrue($this->widget->syncFromSignals());

        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_sync_returns_false_when_no_change(): void
    {
        $this->state->setScrollOffset(0);
        $this->widget->syncFromSignals();

        // Second call with same state
        $this->assertFalse($this->widget->syncFromSignals());
    }

    // ── Render output format ───────────────────────────────────────────

    public function test_render_returns_single_line_when_visible(): void
    {
        $this->state->setScrollOffset(10);
        $this->widget->syncFromSignals();

        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);
        $this->assertCount(1, $result);
    }

    public function test_render_contains_browsing_history_label(): void
    {
        $this->state->setScrollOffset(10);
        $this->widget->syncFromSignals();

        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);
        $this->assertStringContainsString('Browsing history', $result[0]);
    }

    public function test_render_shows_scroll_hint_when_no_hidden_activity(): void
    {
        $this->state->setScrollOffset(10);
        $this->state->setHasHiddenActivityBelow(false);
        $this->widget->syncFromSignals();

        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);
        $this->assertStringContainsString('PgUp/PgDn scroll', $result[0]);
    }

    public function test_render_shows_activity_nudge_when_hidden_activity(): void
    {
        $this->state->setScrollOffset(10);
        $this->state->setHasHiddenActivityBelow(true);
        $this->widget->syncFromSignals();

        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);
        $this->assertStringContainsString('new activity below', $result[0]);
    }

    // ── State transitions ──────────────────────────────────────────────

    public function test_scroll_then_return_then_scroll_again(): void
    {
        $context = new RenderContext(80, 24);

        $this->state->setScrollOffset(10);
        $this->widget->syncFromSignals();
        $this->assertNotEmpty($this->widget->render($context));

        $this->state->setScrollOffset(0);
        $this->widget->syncFromSignals();
        $this->assertSame([], $this->widget->render($context));

        $this->state->setScrollOffset(20);
        $this->state->setHasHiddenActivityBelow(true);
        $this->widget->syncFromSignals();
        $result = $this->widget->render($context);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('new activity below', $result[0]);
    }

    public function test_activity_flag_updates_without_scroll_change(): void
    {
        $context = new RenderContext(80, 24);

        $this->state->setScrollOffset(10);
        $this->state->setHasHiddenActivityBelow(false);
        $this->widget->syncFromSignals();
        $this->assertStringContainsString('PgUp/PgDn scroll', $this->widget->render($context)[0]);

        $this->state->setHasHiddenActivityBelow(true);
        $this->widget->syncFromSignals();
        $this->assertStringContainsString('new activity below', $this->widget->render($context)[0]);
    }

    public function test_render_truncates_to_terminal_width(): void
    {
        $this->state->setScrollOffset(10);
        $this->widget->syncFromSignals();

        $context = new RenderContext(20, 24);
        $result = $this->widget->render($context);

        $visible = preg_replace('/\033\[[0-9;]*m/', '', $result[0]);
        $this->assertLessThanOrEqual(20, mb_strlen($visible));
    }
}
