<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Primitive;

use Kosmokrator\UI\Tui\Primitive\Display\Sep;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class SepTest extends TestCase
{
    public function test_pipe_renders_dot_separator(): void
    {
        $sep = Sep::pipe();
        $result = $sep->render(new RenderContext(80, 24));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('·', $result[0]);
    }

    public function test_line_renders_full_width(): void
    {
        $sep = Sep::line('─');
        $result = $sep->render(new RenderContext(80, 24));

        $this->assertCount(1, $result);
        $this->assertSame(80, mb_strlen($result[0]));
    }

    public function test_line_custom_char(): void
    {
        $sep = Sep::line('═');
        $result = $sep->render(new RenderContext(10, 24));

        $this->assertCount(1, $result);
        $this->assertSame('══════════', $result[0]);
    }

    public function test_line_zero_columns_returns_empty(): void
    {
        $sep = Sep::line('─');
        $result = $sep->render(new RenderContext(0, 24));

        $this->assertSame([], $result);
    }
}
