<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Primitive;

use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\Display\Text;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class TextTest extends TestCase
{
    // ── Static text ─────────────────────────────────────────────────────

    public function test_static_text_renders(): void
    {
        $text = Text::of('hello');
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertSame(['hello'], $result);
    }

    public function test_static_empty_text_renders_empty(): void
    {
        $text = Text::of('');
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertSame([], $result);
    }

    // ── Reactive text ───────────────────────────────────────────────────

    public function test_signal_text_renders(): void
    {
        $signal = new Signal('initial');
        $text = Text::of($signal);
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertSame(['initial'], $result);
    }

    public function test_signal_update_triggers_change(): void
    {
        $signal = new Signal('v1');
        $text = Text::of($signal);

        $this->assertTrue($text->syncFromSignals());
        $this->assertFalse($text->syncFromSignals()); // No change

        $signal->set('v2');
        $this->assertTrue($text->syncFromSignals());
        $this->assertFalse($text->syncFromSignals()); // No change again
    }

    public function test_same_value_no_change(): void
    {
        $signal = new Signal('same');
        $text = Text::of($signal);

        $this->assertTrue($text->syncFromSignals());
        $signal->set('same');
        $this->assertFalse($text->syncFromSignals());
    }

    // ── Formatting ──────────────────────────────────────────────────────

    public function test_bold_adds_ansi_code(): void
    {
        $text = Text::of('bold')->bold();
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertStringContainsString("\033[1m", $result[0]);
    }

    public function test_dim_adds_ansi_code(): void
    {
        $text = Text::of('dim')->dim();
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertStringContainsString("\033[2m", $result[0]);
    }

    public function test_color_from_signal(): void
    {
        $textSignal = new Signal('hello');
        $colorSignal = new Signal("\033[31m");
        $text = Text::of($textSignal)->color($colorSignal);
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertStringContainsString("\033[31m", $result[0]);
        $this->assertStringContainsString("\033[0m", $result[0]);
    }

    public function test_color_from_string(): void
    {
        $text = Text::of('hello')->color("\033[32m");
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertStringContainsString("\033[32m", $result[0]);
    }

    public function test_truncation(): void
    {
        $text = Text::of('hello world')->truncate(8);
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        // 7 chars + ellipsis = 8 visible
        $this->assertSame('hello w…', $result[0]);
    }

    public function test_no_truncation_when_under_limit(): void
    {
        $text = Text::of('hi')->truncate(10);
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertSame(['hi'], $result);
    }

    // ── Render context ──────────────────────────────────────────────────

    public function test_render_returns_single_line(): void
    {
        $text = Text::of('single');
        $text->syncFromSignals();

        $result = $text->render(new RenderContext(80, 24));
        $this->assertCount(1, $result);
    }
}
