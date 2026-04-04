<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\HistoryStatusWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class HistoryStatusWidgetTest extends TestCase
{
    private HistoryStatusWidget $widget;

    protected function setUp(): void
    {
        $this->widget = new HistoryStatusWidget;
    }

    // ── Visibility / hide logic ──────────────────────────────────────────

    public function test_render_returns_empty_when_not_shown(): void
    {
        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_show_makes_widget_visible(): void
    {
        $this->widget->show(false);
        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);
        $this->assertNotEmpty($result);
    }

    public function test_hide_hides_widget(): void
    {
        $this->widget->show(false);
        $this->widget->hide();
        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_hide_is_noop_when_already_hidden(): void
    {
        // Should not throw or error when called on already-hidden widget
        $this->widget->hide();
        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    // ── Render output format ─────────────────────────────────────────────

    public function test_render_returns_single_line_when_visible(): void
    {
        $this->widget->show(false);
        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);
        $this->assertCount(1, $result);
    }

    public function test_render_contains_browsing_history_label(): void
    {
        $this->widget->show(false);
        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);

        $this->assertStringContainsString('Browsing history', $result[0]);
    }

    public function test_render_shows_scroll_hint_when_no_hidden_activity(): void
    {
        $this->widget->show(false);
        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);

        $this->assertStringContainsString('PgUp/PgDn scroll', $result[0]);
    }

    public function test_render_shows_activity_nudge_when_hidden_activity(): void
    {
        $this->widget->show(true);
        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);

        $this->assertStringContainsString('new activity below', $result[0]);
    }

    public function test_render_contains_vertical_bar_separators(): void
    {
        $this->widget->show(false);
        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);

        $this->assertStringContainsString('│', $result[0]);
    }

    // ── State transitions ────────────────────────────────────────────────

    public function test_show_then_hide_then_show_again(): void
    {
        $context = new RenderContext(80, 24);

        $this->widget->show(false);
        $this->assertNotEmpty($this->widget->render($context));

        $this->widget->hide();
        $this->assertSame([], $this->widget->render($context));

        $this->widget->show(true);
        $result = $this->widget->render($context);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('new activity below', $result[0]);
    }

    public function test_show_without_activity_then_show_with_activity(): void
    {
        $context = new RenderContext(80, 24);

        $this->widget->show(false);
        $this->assertStringContainsString('PgUp/PgDn scroll', $this->widget->render($context)[0]);

        $this->widget->show(true);
        $this->assertStringContainsString('new activity below', $this->widget->render($context)[0]);
    }

    public function test_render_truncates_to_terminal_width(): void
    {
        $this->widget->show(false);
        $context = new RenderContext(20, 24);
        $result = $this->widget->render($context);

        // Strip ANSI to measure visible width
        $visible = preg_replace('/\033\[[0-9;]*m/', '', $result[0]);
        $this->assertLessThanOrEqual(20, mb_strlen($visible));
    }
}
