<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\QuestionWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class QuestionWidgetTest extends TestCase
{
    public function test_constructor_with_defaults(): void
    {
        $widget = new QuestionWidget('What is the answer?');

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
    }

    public function test_render_shows_default_title(): void
    {
        $widget = new QuestionWidget('What is the answer?');

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('Question', $lines[0]);
    }

    public function test_render_shows_custom_title(): void
    {
        $widget = new QuestionWidget('What is the answer?', title: 'Prompt');

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertStringContainsString('Prompt', $lines[0]);
    }

    public function test_render_shows_question_text(): void
    {
        $widget = new QuestionWidget('What is the meaning of life?');

        $lines = $widget->render(new RenderContext(80, 24));

        $content = implode("\n", $lines);
        $this->assertStringContainsString('What is the meaning of life?', $content);
    }

    public function test_render_includes_bottom_border_by_default(): void
    {
        $widget = new QuestionWidget('Q');

        $lines = $widget->render(new RenderContext(80, 24));

        $lastLine = $lines[count($lines) - 1];
        $this->assertStringContainsString('└', $lastLine);
        $this->assertStringContainsString('┘', $lastLine);
    }

    public function test_render_hides_bottom_border_when_disabled(): void
    {
        $widget = new QuestionWidget('Q', showBottom: false);

        $lines = $widget->render(new RenderContext(80, 24));

        $lastLine = $lines[count($lines) - 1];
        $this->assertStringNotContainsString('└', $lastLine);
    }

    public function test_render_uses_custom_border_color(): void
    {
        $borderColor = "\033[31m";
        $widget = new QuestionWidget('Q', borderColor: $borderColor);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertStringContainsString($borderColor, $lines[0]);
    }

    public function test_render_uses_custom_title_color(): void
    {
        $titleColor = "\033[32m";
        $widget = new QuestionWidget('Q', titleColor: $titleColor);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertStringContainsString($titleColor, $lines[0]);
    }

    public function test_render_wraps_long_text(): void
    {
        $longText = str_repeat('word ', 30);
        $widget = new QuestionWidget($longText);

        $lines = $widget->render(new RenderContext(40, 24));

        $this->assertGreaterThan(3, count($lines));
    }

    public function test_render_handles_short_text_without_wrapping(): void
    {
        $widget = new QuestionWidget('Short');

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertCount(3, $lines);
    }

    public function test_render_box_structure(): void
    {
        $widget = new QuestionWidget('Hello');

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertStringContainsString('┌', $lines[0]);
        $this->assertStringContainsString('┐', $lines[0]);
        $this->assertStringContainsString('│', $lines[1]);
        $this->assertStringContainsString('└', $lines[2]);
        $this->assertStringContainsString('┘', $lines[2]);
    }
}
