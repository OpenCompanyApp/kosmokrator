<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\KosmokratorStyleSheet;
use Kosmokrator\UI\Tui\Widget\KosmokratorMarkdownWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class KosmokratorMarkdownWidgetTest extends TestCase
{
    public function test_long_code_lines_wrap_instead_of_truncating(): void
    {
        $widget = new KosmokratorMarkdownWidget("```text\nabcdefghijklmnopqrstuvwxyz\n```");

        $lines = $widget->render(new RenderContext(12, 20));
        $plain = array_map(AnsiUtils::stripAnsiCodes(...), $lines);

        $this->assertContains('  abcdefghij', $plain);
        $this->assertContains('  klmnopqrst', $plain);
        $this->assertContains('  uvwxyz', $plain);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(12, AnsiUtils::visibleWidth($line));
        }
    }

    public function test_append_text_preserves_materialized_content(): void
    {
        $widget = new KosmokratorMarkdownWidget('hello');

        $widget->appendText(' world');

        $this->assertSame('hello world', $widget->getText());
        $plain = array_map(
            AnsiUtils::stripAnsiCodes(...),
            $widget->render(new RenderContext(80, 20)),
        );

        $this->assertContains('hello world', $plain);
    }

    public function test_heading_levels_render_distinctly_with_kosmo_stylesheet(): void
    {
        $terminal = new VirtualTerminal(80, 16);
        $tui = new Tui(KosmokratorStyleSheet::create(), terminal: $terminal);
        $widget = new KosmokratorMarkdownWidget("# Alpha\n\n## Beta\n\n### Gamma");

        try {
            $tui->add($widget);
            $tui->start();
            $tui->tick();

            $raw = $terminal->getOutput();
            $screen = new ScreenBuffer(80, 16);
            $screen->write($raw);

            $this->assertStringContainsString('# Alpha', $screen->getScreen());
            $this->assertStringContainsString('## Beta', $screen->getScreen());
            $this->assertStringContainsString('### Gamma', $screen->getScreen());
            $this->assertStringContainsString("\033[38;2;255;200;80m", $raw);
            $this->assertStringContainsString("\033[4m", $raw);
            $this->assertStringContainsString("\033[38;2;255;60;40m", $raw);
            $this->assertStringContainsString("\033[38;2;112;160;208m", $raw);
        } finally {
            $tui->stop();
        }
    }

    public function test_heading_spacing_avoids_duplicate_blank_lines(): void
    {
        $widget = new KosmokratorMarkdownWidget("Intro\n\n## Next");

        $plain = array_map(
            AnsiUtils::stripAnsiCodes(...),
            $widget->render(new RenderContext(80, 20)),
        );

        $this->assertSame(['Intro', '', '## Next'], $plain);
    }

    public function test_rich_markdown_blocks_remain_width_safe(): void
    {
        $widget = new KosmokratorMarkdownWidget(implode("\n", [
            '## Heading with a deliberately long title that must wrap',
            '',
            '- item with `inline code` and **bold**',
            '> quoted text',
            '',
            '---',
        ]));

        $lines = $widget->render(new RenderContext(24, 20));
        $plain = array_map(AnsiUtils::stripAnsiCodes(...), $lines);

        $this->assertContains('## Heading with a', $plain);
        $this->assertContains('deliberately long title', $plain);
        $this->assertContains('that must wrap', $plain);
        $this->assertContains('• item with inline code', $plain);
        $this->assertContains('  and bold', $plain);
        $this->assertContains('│ quoted text', $plain);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(24, AnsiUtils::visibleWidth($line));
        }
    }
}
