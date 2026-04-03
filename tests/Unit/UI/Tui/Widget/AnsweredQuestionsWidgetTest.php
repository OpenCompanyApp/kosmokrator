<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\AnsweredQuestionsWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class AnsweredQuestionsWidgetTest extends TestCase
{
    public function test_constructor_accepts_entries(): void
    {
        $entries = [
            ['question' => 'What?', 'answer' => 'This', 'answered' => true, 'recommended' => false],
        ];
        $widget = new AnsweredQuestionsWidget($entries);

        $context = new RenderContext(80, 24);
        $lines = $widget->render($context);

        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
    }

    public function test_render_header_shows_answered_count(): void
    {
        $entries = [
            ['question' => 'Q1', 'answer' => 'A1', 'answered' => true, 'recommended' => false],
            ['question' => 'Q2', 'answer' => 'A2', 'answered' => true, 'recommended' => false],
            ['question' => 'Q3', 'answer' => '', 'answered' => false, 'recommended' => false],
        ];
        $widget = new AnsweredQuestionsWidget($entries);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('2/3 answered', $lines[0]);
    }

    public function test_render_with_no_entries(): void
    {
        $widget = new AnsweredQuestionsWidget([]);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('0/0 answered', $lines[0]);
    }

    public function test_render_answered_entry_without_recommended(): void
    {
        $entries = [
            ['question' => 'Q1', 'answer' => 'A1', 'answered' => true, 'recommended' => false],
        ];
        $widget = new AnsweredQuestionsWidget($entries);

        $lines = $widget->render(new RenderContext(120, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'A1')) {
                $found = true;
                $this->assertStringNotContainsString('(Recommended)', $line);
            }
        }
        $this->assertTrue($found, 'Expected to find the answer A1 in rendered output');
    }

    public function test_render_answered_entry_with_recommended(): void
    {
        $entries = [
            ['question' => 'Q1', 'answer' => 'A1', 'answered' => true, 'recommended' => true],
        ];
        $widget = new AnsweredQuestionsWidget($entries);

        $lines = $widget->render(new RenderContext(120, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'A1') && str_contains($line, '(Recommended)')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected to find "(Recommended)" appended to the answer');
    }

    public function test_render_dismissed_entry(): void
    {
        $entries = [
            ['question' => 'Q1', 'answer' => '', 'answered' => false, 'recommended' => false],
        ];
        $widget = new AnsweredQuestionsWidget($entries);

        $lines = $widget->render(new RenderContext(120, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, '(dismissed)')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected to find "(dismissed)" for unanswered entry');
    }

    public function test_render_multiple_entries_have_blank_separators(): void
    {
        $entries = [
            ['question' => 'Q1', 'answer' => 'A1', 'answered' => true, 'recommended' => false],
            ['question' => 'Q2', 'answer' => 'A2', 'answered' => true, 'recommended' => false],
        ];
        $widget = new AnsweredQuestionsWidget($entries);

        $lines = $widget->render(new RenderContext(120, 24));

        $blankCount = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                $blankCount++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $blankCount);
    }

    public function test_render_truncates_to_columns(): void
    {
        $longQuestion = str_repeat('word ', 50);
        $entries = [
            ['question' => $longQuestion, 'answer' => 'short', 'answered' => true, 'recommended' => false],
        ];
        $widget = new AnsweredQuestionsWidget($entries);

        $lines = $widget->render(new RenderContext(40, 24));

        $this->assertNotEmpty($lines);
    }
}
