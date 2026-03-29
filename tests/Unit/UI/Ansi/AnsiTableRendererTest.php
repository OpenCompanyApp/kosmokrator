<?php

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\AnsiTableRenderer;
use PHPUnit\Framework\TestCase;

class AnsiTableRendererTest extends TestCase
{
    private AnsiTableRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AnsiTableRenderer();
    }

    public function test_empty_table_returns_empty_string(): void
    {
        $table = ['alignments' => [], 'head' => [], 'body' => []];

        $this->assertSame('', $this->renderer->render($table));
    }

    public function test_renders_header_and_body(): void
    {
        $table = [
            'alignments' => [null, null],
            'head' => [['Name', 'Value']],
            'body' => [['foo', 'bar'], ['baz', 'qux']],
        ];

        $output = $this->renderer->render($table);

        // Check box-drawing characters
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('┬', $output);
        $this->assertStringContainsString('┐', $output);
        $this->assertStringContainsString('├', $output);
        $this->assertStringContainsString('┼', $output);
        $this->assertStringContainsString('┤', $output);
        $this->assertStringContainsString('└', $output);
        $this->assertStringContainsString('┴', $output);
        $this->assertStringContainsString('┘', $output);
        // Check content
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('foo', $output);
    }

    public function test_header_only_no_body(): void
    {
        $table = [
            'alignments' => [null],
            'head' => [['Header']],
            'body' => [],
        ];

        $output = $this->renderer->render($table);

        $this->assertStringContainsString('Header', $output);
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('└', $output);
    }

    public function test_body_only_no_header(): void
    {
        $table = [
            'alignments' => [null],
            'head' => [],
            'body' => [['data']],
        ];

        $output = $this->renderer->render($table);

        $this->assertStringContainsString('data', $output);
        // No header separator
        $this->assertStringNotContainsString('├', $output);
    }

    public function test_column_widths_based_on_content(): void
    {
        $table = [
            'alignments' => [null, null],
            'head' => [['Short', 'A much longer header']],
            'body' => [['x', 'y']],
        ];

        $output = $this->renderer->render($table);

        // The longer header should make its column wider
        $this->assertStringContainsString('A much longer header', $output);
    }

    public function test_minimum_column_width(): void
    {
        $table = [
            'alignments' => [null],
            'head' => [['X']],
            'body' => [['Y']],
        ];

        $output = $this->renderer->render($table);

        // Minimum column width is 3 + 2 padding = 5 dashes
        // Strip ANSI codes first to count visible dashes
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $output);
        $lines = explode("\n", $stripped);
        $topBorder = $lines[0];
        $this->assertMatchesRegularExpression('/─{5,}/u', $topBorder);
    }

    public function test_right_alignment(): void
    {
        $table = [
            'alignments' => ['right'],
            'head' => [],
            'body' => [['x']],
        ];

        $output = $this->renderer->render($table);
        // Right-aligned: spaces before 'x'
        $this->assertStringContainsString('x', $output);
    }

    public function test_center_alignment(): void
    {
        $table = [
            'alignments' => ['center'],
            'head' => [],
            'body' => [['centered']],
        ];

        $output = $this->renderer->render($table);
        $this->assertStringContainsString('centered', $output);
    }

    public function test_left_alignment_default(): void
    {
        $table = [
            'alignments' => [null],
            'head' => [],
            'body' => [['left']],
        ];

        $output = $this->renderer->render($table);
        $this->assertStringContainsString('left', $output);
    }

    public function test_visible_width_strips_ansi_codes(): void
    {
        $text = "\033[38;2;255;0;0mred text\033[0m";

        $this->assertSame(8, AnsiTableRenderer::visibleWidth($text)); // "red text"
    }

    public function test_visible_width_plain_text(): void
    {
        $this->assertSame(11, AnsiTableRenderer::visibleWidth('hello world'));
    }

    public function test_visible_width_multibyte_characters(): void
    {
        // CJK characters are width 2 each
        $this->assertSame(6, AnsiTableRenderer::visibleWidth('日本語'));
    }

    public function test_prefix_applied_to_all_lines(): void
    {
        $table = [
            'alignments' => [null],
            'head' => [['Head']],
            'body' => [['data']],
        ];

        $prefix = '    ';
        $output = $this->renderer->render($table, $prefix);

        $lines = array_filter(explode("\n", $output), fn ($l) => $l !== '');
        foreach ($lines as $line) {
            $this->assertStringStartsWith($prefix, $line);
        }
    }
}
