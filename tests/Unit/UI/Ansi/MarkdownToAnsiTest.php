<?php

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\MarkdownToAnsi;
use Kosmokrator\UI\Theme;
use PHPUnit\Framework\TestCase;

class MarkdownToAnsiTest extends TestCase
{
    private MarkdownToAnsi $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownToAnsi();
    }

    public function test_plain_paragraph(): void
    {
        $output = $this->renderer->render('Hello world');

        $this->assertStringContainsString('Hello world', $output);
    }

    public function test_heading_level_1(): void
    {
        $output = $this->renderer->render('# Title');

        $this->assertStringContainsString('# Title', $output);
        // h1/h2 use bold + white
        $this->assertStringContainsString(Theme::bold(), $output);
    }

    public function test_heading_level_2(): void
    {
        $output = $this->renderer->render('## Subtitle');

        $this->assertStringContainsString('## Subtitle', $output);
        $this->assertStringContainsString(Theme::white(), $output);
    }

    public function test_heading_level_3(): void
    {
        $output = $this->renderer->render('### Section');

        $this->assertStringContainsString('### Section', $output);
        // h3+ use info color
        $this->assertStringContainsString(Theme::info(), $output);
    }

    public function test_bold_text(): void
    {
        $output = $this->renderer->render('**bold text**');

        $this->assertStringContainsString('bold text', $output);
        $this->assertStringContainsString(Theme::bold(), $output);
    }

    public function test_italic_text(): void
    {
        $output = $this->renderer->render('*italic text*');

        $this->assertStringContainsString('italic text', $output);
        // Italic is ANSI 3m
        $this->assertStringContainsString("\033[3m", $output);
    }

    public function test_inline_code(): void
    {
        $output = $this->renderer->render('Use `code` here');

        $this->assertStringContainsString('`code`', $output);
        $this->assertStringContainsString(Theme::code(), $output);
    }

    public function test_strikethrough(): void
    {
        $output = $this->renderer->render('~~struck~~');

        $this->assertStringContainsString('struck', $output);
        // Strikethrough is ANSI 9m
        $this->assertStringContainsString("\033[9m", $output);
    }

    public function test_fenced_code_block(): void
    {
        $md = "```php\n\$x = 1;\n```";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('php', $output);
        $this->assertStringContainsString('─', $output); // border
    }

    public function test_fenced_code_block_unknown_language(): void
    {
        $md = "```\nsome code\n```";
        $output = $this->renderer->render($md);

        // Should not crash, renders as plain text
        $this->assertStringContainsString('some code', $output);
    }

    public function test_unordered_list(): void
    {
        $md = "- item1\n- item2";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('•', $output);
        $this->assertStringContainsString('item1', $output);
        $this->assertStringContainsString('item2', $output);
    }

    public function test_ordered_list(): void
    {
        $md = "1. first\n2. second";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('1.', $output);
        $this->assertStringContainsString('2.', $output);
        $this->assertStringContainsString('first', $output);
        $this->assertStringContainsString('second', $output);
    }

    public function test_nested_list(): void
    {
        $md = "- outer\n  - inner";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('outer', $output);
        $this->assertStringContainsString('inner', $output);
        // Nested bullet uses ◦
        $this->assertStringContainsString('◦', $output);
    }

    public function test_blockquote(): void
    {
        $md = '> quoted text';
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('quoted text', $output);
        $this->assertStringContainsString('│', $output);
    }

    public function test_nested_blockquote(): void
    {
        $md = "> level 1\n>\n> > level 2";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('level 1', $output);
        $this->assertStringContainsString('level 2', $output);
    }

    public function test_link(): void
    {
        $md = '[click me](https://example.com)';
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('click me', $output);
        $this->assertStringContainsString('https://example.com', $output);
        $this->assertStringContainsString(Theme::link(), $output);
    }

    public function test_image(): void
    {
        $md = '![alt text](https://example.com/img.png)';
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('alt text', $output);
        $this->assertStringContainsString('https://example.com/img.png', $output);
    }

    public function test_thematic_break(): void
    {
        $md = "before\n\n---\n\nafter";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('━', $output);
    }

    public function test_table(): void
    {
        $md = "| A | B |\n|---|---|\n| 1 | 2 |";
        $output = $this->renderer->render($md);

        // Table delegates to AnsiTableRenderer — check box chars
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('│', $output);
        $this->assertStringContainsString('A', $output);
        $this->assertStringContainsString('1', $output);
    }

    public function test_task_list_checked(): void
    {
        $md = "- [x] done task";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('☑', $output);
        $this->assertStringContainsString('done task', $output);
    }

    public function test_task_list_unchecked(): void
    {
        $md = "- [ ] todo task";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('☐', $output);
        $this->assertStringContainsString('todo task', $output);
    }

    public function test_empty_input(): void
    {
        $output = $this->renderer->render('');

        $this->assertSame('', $output);
    }

    public function test_multiple_paragraphs(): void
    {
        $md = "Paragraph one.\n\nParagraph two.";
        $output = $this->renderer->render($md);

        $this->assertStringContainsString('Paragraph one', $output);
        $this->assertStringContainsString('Paragraph two', $output);
    }

    public function test_render_resets_state_between_calls(): void
    {
        $output1 = $this->renderer->render('First call');
        $output2 = $this->renderer->render('Second call');

        $this->assertStringContainsString('Second call', $output2);
        $this->assertStringNotContainsString('First call', $output2);
    }
}
