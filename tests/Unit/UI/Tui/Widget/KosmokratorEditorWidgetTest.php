<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\KosmokratorEditorWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class KosmokratorEditorWidgetTest extends TestCase
{
    public function test_single_line_prompt_keeps_frame(): void
    {
        $widget = new KosmokratorEditorWidget;
        $widget->setText('hello');
        $widget->setMinVisibleLines(1);
        $widget->setMaxVisibleLines(2);

        $lines = $widget->render(new RenderContext(24, 10));

        $this->assertGreaterThanOrEqual(3, \count($lines));
        $this->assertStringContainsString('─', AnsiUtils::stripAnsiCodes($lines[0]));
        $this->assertStringContainsString('─', AnsiUtils::stripAnsiCodes($lines[\array_key_last($lines)]));
    }

    public function test_multiline_prompt_keeps_frame(): void
    {
        $widget = new KosmokratorEditorWidget;
        $widget->setText("hello\nworld");
        $widget->setMinVisibleLines(1);
        $widget->setMaxVisibleLines(2);

        $lines = $widget->render(new RenderContext(24, 10));

        $this->assertGreaterThanOrEqual(4, \count($lines));
        $this->assertStringContainsString('─', AnsiUtils::stripAnsiCodes($lines[0]));
    }
}
