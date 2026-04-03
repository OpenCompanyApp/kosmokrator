<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class AnsiArtWidgetTest extends TestCase
{
    public function test_constructor_default_empty_text(): void
    {
        $widget = new AnsiArtWidget();
        $this->assertSame('', $widget->getText());
    }

    public function test_constructor_with_text(): void
    {
        $widget = new AnsiArtWidget("Hello\nWorld");
        $this->assertSame("Hello\nWorld", $widget->getText());
    }

    public function test_set_text_updates_text(): void
    {
        $widget = new AnsiArtWidget();
        $result = $widget->setText('updated');
        $this->assertSame('updated', $widget->getText());
        $this->assertSame($widget, $result);
    }

    public function test_set_text_returns_static(): void
    {
        $widget = new AnsiArtWidget();
        $returned = $widget->setText('x');
        $this->assertSame($widget, $returned);
    }

    public function test_render_empty_text_returns_empty_array(): void
    {
        $widget = new AnsiArtWidget('');
        $context = new RenderContext(80, 24);
        $this->assertSame([], $widget->render($context));
    }

    public function test_render_default_empty_returns_empty_array(): void
    {
        $widget = new AnsiArtWidget();
        $context = new RenderContext(80, 24);
        $this->assertSame([], $widget->render($context));
    }

    public function test_render_single_line(): void
    {
        $widget = new AnsiArtWidget('Hello World');
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertSame(['Hello World'], $result);
    }

    public function test_render_multiple_lines(): void
    {
        $widget = new AnsiArtWidget("Line 1\nLine 2\nLine 3");
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertSame(['Line 1', 'Line 2', 'Line 3'], $result);
    }

    public function test_render_expands_tabs(): void
    {
        $widget = new AnsiArtWidget("col1\tcol2");
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertSame(['col1   col2'], $result);
    }

    public function test_render_wraps_long_lines(): void
    {
        $longLine = str_repeat('A', 100);
        $widget = new AnsiArtWidget($longLine);
        $context = new RenderContext(40, 24);
        $result = $widget->render($context);

        // Should produce multiple wrapped lines
        $this->assertGreaterThan(1, count($result));
        // Total visible characters should be preserved
        $totalVisible = 0;
        foreach ($result as $line) {
            $totalVisible += mb_strlen($line);
        }
        $this->assertSame(100, $totalVisible);
    }

    public function test_render_does_not_wrap_short_lines(): void
    {
        $widget = new AnsiArtWidget('Short');
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertSame(['Short'], $result);
    }

    public function test_render_returns_fallback_empty_string_for_all_empty_input(): void
    {
        // When text is only newlines, explode produces empty strings;
        // but text is non-empty so the early return won't trigger.
        $widget = new AnsiArtWidget("\n\n");
        $context = new RenderContext(80, 24);
        $result = $widget->render($context);
        $this->assertSame(['', '', ''], $result);
    }
}
