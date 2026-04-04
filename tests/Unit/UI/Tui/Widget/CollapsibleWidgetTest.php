<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class CollapsibleWidgetTest extends TestCase
{
    public function test_constructor_normalizes_tabs_in_content(): void
    {
        $widget = new CollapsibleWidget(
            header: '✓',
            content: "line1\tline2",
            lineCount: 2,
        );

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString("\t", $content);
    }

    public function test_default_state_is_collapsed(): void
    {
        $widget = new CollapsibleWidget('✓', "line1\nline2\nline3\nline4\nline5", 5);

        $this->assertFalse($widget->isExpanded());
    }

    public function test_toggle_switches_state(): void
    {
        $widget = new CollapsibleWidget('✓', 'content', 1);

        $this->assertFalse($widget->isExpanded());

        $widget->toggle();
        $this->assertTrue($widget->isExpanded());

        $widget->toggle();
        $this->assertFalse($widget->isExpanded());
    }

    public function test_set_expanded_explicitly_sets_state(): void
    {
        $widget = new CollapsibleWidget('✓', 'content', 1);

        $widget->setExpanded(true);
        $this->assertTrue($widget->isExpanded());

        $widget->setExpanded(false);
        $this->assertFalse($widget->isExpanded());
    }

    public function test_render_collapsed_shows_preview_lines(): void
    {
        $content = "line1\nline2\nline3\nline4\nline5";
        $widget = new CollapsibleWidget('✓', $content, 5);

        $lines = $widget->render(new RenderContext(80, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, '+2 lines')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected "+2 lines" hint in collapsed view');
    }

    public function test_render_expanded_shows_all_lines(): void
    {
        $content = "line1\nline2\nline3\nline4\nline5";
        $widget = new CollapsibleWidget('✓', $content, 5);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(80, 24));

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('ctrl+o to reveal', $line);
        }
    }

    public function test_render_collapsed_no_hint_when_content_fits(): void
    {
        $content = "line1\nline2";
        $widget = new CollapsibleWidget('✓', $content, 2);

        $lines = $widget->render(new RenderContext(80, 24));

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('lines (ctrl+o to reveal)', $line);
        }
    }

    public function test_render_with_preview_width_truncation(): void
    {
        $longLine = str_repeat('x', 200);
        $widget = new CollapsibleWidget('✓', $longLine, 1, previewWidth: 50);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'ctrl+o to reveal')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected "ctrl+o to reveal" hint when char-truncated');
    }

    public function test_render_header_appears_in_first_line(): void
    {
        $widget = new CollapsibleWidget('★', 'content', 1);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('★', $lines[0]);
    }
}
