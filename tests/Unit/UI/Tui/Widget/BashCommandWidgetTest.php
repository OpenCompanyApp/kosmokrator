<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\BashCommandWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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

    public function test_set_expanded_sets_state(): void
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

    public function test_set_result_stores_output_and_success(): void
    {
        $widget = new BashCommandWidget('echo test');
        $widget->setResult("hello\nworld", true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString('running', $content);
    }

    public function test_set_result_normalizes_tabs(): void
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
            if (str_contains($line, '+8 lines')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected "+8 lines" hint in collapsed output');
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
        // Collapsed mode: header line (truncated) + "running..." = 2 lines minimum
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    public function test_render_failed_output_shows_failure_marker(): void
    {
        $widget = new BashCommandWidget('bad_cmd');
        $widget->setResult('error output', false);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('✗', $content);
    }

    public function test_set_result_strips_cursor_control_sequences(): void
    {
        // Simulate PHPUnit progress output with cursor movement
        $output = "\x1b[1G\x1b[2KOK (10 tests)\x1b[1G\x1b[2KFailures: 5";
        $widget = new BashCommandWidget('test');
        $widget->setResult($output, true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        // Cursor control sequences should be stripped
        $this->assertStringNotContainsString("\x1b[1G", $content);
        $this->assertStringNotContainsString("\x1b[2K", $content);
    }

    public function test_set_result_strips_osc_hyperlinks(): void
    {
        $output = "\x1b]8;;file:///test.php\x1b\\test name\x1b]8;;\x1b\\";
        $widget = new BashCommandWidget('test');
        $widget->setResult($output, true);

        $lines = $widget->render(new RenderContext(80, 24));

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(80, AnsiUtils::visibleWidth($line));
        }
    }

    public function test_set_result_preserves_sgr_color_codes(): void
    {
        $output = "\x1b[31mred text\x1b[0m normal text";
        $widget = new BashCommandWidget('test');
        $widget->setResult($output, true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        // SGR color codes should be preserved
        $this->assertStringContainsString("\x1b[31m", $content);
    }

    public function test_render_never_exceeds_column_width(): void
    {
        // Simulate a very long PHPUnit output line (this was the crash case)
        $longOutput = str_repeat('x', 300);
        $widget = new BashCommandWidget('phpunit');
        $widget->setResult($longOutput, false); // Failure auto-expands

        $lines = $widget->render(new RenderContext(139, 40));

        foreach ($lines as $i => $line) {
            $width = AnsiUtils::visibleWidth($line);
            $this->assertLessThanOrEqual(139, $width, "Line {$i} exceeds 139 columns (width: {$width})");
        }
    }

    public function test_set_result_strips_carriage_returns(): void
    {
        // PHPUnit progress bars use \r to overwrite lines
        $output = str_repeat("0\r", 80);
        $widget = new BashCommandWidget('phpunit');
        $widget->setResult($output, true);

        $lines = $widget->render(new RenderContext(72, 24));

        foreach ($lines as $i => $line) {
            $width = AnsiUtils::visibleWidth($line);
            $this->assertLessThanOrEqual(72, $width, "Line {$i} exceeds 72 columns (width: {$width})");
        }
    }

    public function test_set_result_strips_null_bytes_and_other_c0_controls(): void
    {
        $output = "OK\x00\x01\x02\x0B\x0E\x0F\x7Fdone";
        $widget = new BashCommandWidget('test');
        $widget->setResult($output, true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        // C0 controls should be stripped; visible text remains
        $this->assertStringNotContainsString("\x00", $content);
        $this->assertStringNotContainsString("\x0E", $content);
        $this->assertStringContainsString('OK', $content);
    }

    public function test_render_never_exceeds_narrow_width_with_control_chars(): void
    {
        // Large output mixing \r, \x1b sequences, and long lines
        $output = '';
        for ($i = 0; $i < 200; $i++) {
            $output .= "\r\x1b[K{$i}/200 (".str_repeat('=', $i % 80).")\n";
        }
        $widget = new BashCommandWidget('progress');
        $widget->setResult($output, true);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(72, 24));

        foreach ($lines as $i => $line) {
            $width = AnsiUtils::visibleWidth($line);
            $this->assertLessThanOrEqual(72, $width, "Line {$i} exceeds 72 columns (width: {$width})");
        }
    }
}
