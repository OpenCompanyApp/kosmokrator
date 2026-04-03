<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\BashCommandWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class BashCommandWidgetTest extends TestCase
{
    public function test_constructor_sets_command(): void
    {
        $widget = new BashCommandWidget('ls -la');

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
    }

    public function test_default_state_is_collapsed(): void
    {
        $widget = new BashCommandWidget('echo test');

        $this->assertFalse($widget->isExpanded());
    }

    public function test_toggle_switches_state(): void
    {
        $widget = new BashCommandWidget('echo test');

        $widget->toggle();
        $this->assertTrue($widget->isExpanded());

        $widget->toggle();
        $this->assertFalse($widget->isExpanded());
    }

    public function test_setExpanded_sets_state(): void
    {
        $widget = new BashCommandWidget('echo test');

        $widget->setExpanded(true);
        $this->assertTrue($widget->isExpanded());

        $widget->setExpanded(false);
        $this->assertFalse($widget->isExpanded());
    }

    public function test_render_without_result_shows_running(): void
    {
        $widget = new BashCommandWidget('echo test');

        $lines = $widget->render(new RenderContext(80, 24));

        $content = implode("\n", $lines);
        $this->assertStringContainsString('running', $content);
    }

    public function test_setResult_stores_output_and_success(): void
    {
        $widget = new BashCommandWidget('echo test');
        $widget->setResult("hello\nworld", true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString('running', $content);
    }

    public function test_setResult_normalizes_tabs(): void
    {
        $widget = new BashCommandWidget('cat file');
        $widget->setResult("col1\tcol2\tcol3", true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString("\t", $content);
    }

    public function test_render_successful_empty_output_shows_no_output(): void
    {
        $widget = new BashCommandWidget('true');
        $widget->setResult('', true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('no output', $content);
    }

    public function test_render_failed_empty_output_shows_command_failed(): void
    {
        $widget = new BashCommandWidget('false');
        $widget->setResult('', false);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('command failed', $content);
    }

    public function test_render_collapsed_shows_preview_lines(): void
    {
        $output = implode("\n", array_map(fn (int $i): string => "line{$i}", range(1, 10)));
        $widget = new BashCommandWidget('cat big.txt');
        $widget->setResult($output, true);

        $lines = $widget->render(new RenderContext(80, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, '+7 lines')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected "+7 lines" hint in collapsed output');
    }

    public function test_render_expanded_shows_all_output(): void
    {
        $output = implode("\n", array_map(fn (int $i): string => "line{$i}", range(1, 10)));
        $widget = new BashCommandWidget('cat big.txt');
        $widget->setResult($output, true);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(80, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'ctrl+o to collapse')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected "ctrl+o to collapse" hint in expanded view');
    }

    public function test_render_wraps_long_command(): void
    {
        $longCommand = str_repeat('arg ', 40);
        $widget = new BashCommandWidget($longCommand);

        $lines = $widget->render(new RenderContext(60, 24));

        $this->assertNotEmpty($lines);
        $this->assertGreaterThan(2, count($lines));
    }

    public function test_render_failed_output_shows_failure_marker(): void
    {
        $widget = new BashCommandWidget('bad_cmd');
        $widget->setResult("error output", false);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('✗', $content);
    }
}
