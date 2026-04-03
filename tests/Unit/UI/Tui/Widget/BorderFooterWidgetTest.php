<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\BorderFooterWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class BorderFooterWidgetTest extends TestCase
{
    public function test_constructor_default_no_border_color(): void
    {
        $widget = new BorderFooterWidget();
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertCount(1, $result);
    }

    public function test_constructor_with_custom_border_color(): void
    {
        $widget = new BorderFooterWidget("\033[31m");
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertCount(1, $result);
    }

    public function test_render_produces_single_line(): void
    {
        $widget = new BorderFooterWidget();
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertCount(1, $result);
    }

    public function test_render_line_starts_with_border_corner(): void
    {
        $widget = new BorderFooterWidget();
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        // The line should contain └ and ┘ characters
        $this->assertStringContainsString('└', $result[0]);
        $this->assertStringContainsString('┘', $result[0]);
    }

    public function test_render_contains_horizontal_fill(): void
    {
        $widget = new BorderFooterWidget();
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $this->assertStringContainsString('─', $result[0]);
    }

    public function test_render_adapts_to_terminal_width(): void
    {
        $widget = new BorderFooterWidget();
        $narrow = new RenderContext(40, 24);
        $wide = new RenderContext(120, 24);

        $narrowResult = $widget->render($narrow)[0];
        $wideResult = $widget->render($wide)[0];

        // Wider terminal should produce a longer line (in visible width)
        // We strip ANSI sequences for comparison
        $narrowVisible = preg_replace('/\033\[[0-9;]*m/', '', $narrowResult);
        $wideVisible = preg_replace('/\033\[[0-9;]*m/', '', $wideResult);

        $this->assertGreaterThan(mb_strlen($narrowVisible), mb_strlen($wideVisible));
    }

    public function test_render_width_equals_terminal_columns(): void
    {
        $widget = new BorderFooterWidget();
        $columns = 80;
        $context = new RenderContext($columns, 24);
        $result = $widget->render($context);

        // Strip all ANSI escape sequences (including 24-bit color: \033[38;2;r;g;bm)
        $visible = preg_replace('/\033\[[0-9;]*m/', '', $result[0]);
        $this->assertSame($columns, mb_strlen($visible));
    }
}
