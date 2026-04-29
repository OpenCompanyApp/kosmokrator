<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\KosmokratorMarkdownWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

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
}
