<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use KosmoKrator\UI\Tui\Widget\ScrollbarState;
use KosmoKrator\UI\Tui\Widget\ScrollbarWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class ScrollbarWidgetTest extends TestCase
{
    private ScrollbarWidget $widget;

    protected function setUp(): void
    {
        $this->widget = new ScrollbarWidget;
    }

    // ── No-state and non-scrollable ───────────────────────────────────────

    public function test_render_returns_empty_when_no_state(): void
    {
        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_render_returns_empty_when_content_fits_viewport(): void
    {
        $state = new ScrollbarState(contentLength: 10, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_render_returns_empty_when_equal_content_and_viewport(): void
    {
        $state = new ScrollbarState(contentLength: 20, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 24);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_render_returns_empty_when_zero_height(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 0);
        $this->assertSame([], $this->widget->render($context));
    }

    // ── Output dimensions ─────────────────────────────────────────────────

    public function test_render_output_count_matches_context_rows(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 24);
        $result = $this->widget->render($context);

        $this->assertCount(24, $result);
    }

    // ── Thumb placement ───────────────────────────────────────────────────

    public function test_render_has_correct_thumb_count(): void
    {
        // content=100, viewport=20 → thumbSize(20) = round(20*20/100) = 4
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $thumbChar = $this->widget::SYMBOLS_DEFAULT['thumb'];
        $thumbCount = 0;
        foreach ($result as $line) {
            if (str_contains($line, $thumbChar)) {
                $thumbCount++;
            }
        }
        $this->assertSame(4, $thumbCount);
    }

    public function test_render_track_fills_non_thumb_rows(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $thumbChar = $this->widget::SYMBOLS_DEFAULT['thumb'];
        $trackChar = $this->widget::SYMBOLS_DEFAULT['track'];

        $trackCount = 0;
        foreach ($result as $line) {
            if (str_contains($line, $trackChar) && !str_contains($line, $thumbChar)) {
                $trackCount++;
            }
        }
        // 20 total - 4 thumb = 16 track
        $this->assertSame(16, $trackCount);
    }

    public function test_thumb_at_top_when_position_zero(): void
    {
        // thumbSize(20) = 4, thumbStart(20) = 0
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $thumbChar = $this->widget::SYMBOLS_DEFAULT['thumb'];
        // First 4 rows should be thumb
        for ($i = 0; $i < 4; $i++) {
            $this->assertStringContainsString($thumbChar, $result[$i], "Row {$i} should be thumb");
        }
        // Row 4+ should be track
        $trackChar = $this->widget::SYMBOLS_DEFAULT['track'];
        $this->assertStringContainsString($trackChar, $result[4], "Row 4 should be track");
    }

    public function test_thumb_at_bottom_when_position_max(): void
    {
        // content=100, viewport=20, position=80 (maxScroll=80, fraction=1.0)
        // thumbSize(20) = 4, thumbStart(20) = 16
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 80);
        $this->widget->setState($state);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $thumbChar = $this->widget::SYMBOLS_DEFAULT['thumb'];
        // Rows 16-19 should be thumb
        for ($i = 16; $i < 20; $i++) {
            $this->assertStringContainsString($thumbChar, $result[$i], "Row {$i} should be thumb");
        }
        // Row 15 should be track
        $trackChar = $this->widget::SYMBOLS_DEFAULT['track'];
        $this->assertStringContainsString($trackChar, $result[15], "Row 15 should be track");
    }

    // ── Symbol sets ───────────────────────────────────────────────────────

    public function test_render_with_modern_symbols(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);
        $this->widget->setSymbols(ScrollbarWidget::SYMBOLS_MODERN);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $this->assertNotEmpty($result);
        // First line should contain the modern thumb character
        $this->assertStringContainsString('■', $result[0]);
    }

    public function test_render_with_dots_symbols(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);
        $this->widget->setSymbols(ScrollbarWidget::SYMBOLS_DOTS);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('●', $result[0]);
    }

    public function test_render_with_custom_symbols(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);
        $this->widget->setSymbols(['track' => '│', 'thumb' => '┃']);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('┃', $result[0]);
        $this->assertStringContainsString('│', $result[4]);
    }

    // ── State management ──────────────────────────────────────────────────

    public function test_set_state_null_hides_scrollbar(): void
    {
        // First show it
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);
        $context = new RenderContext(80, 20);
        $this->assertNotEmpty($this->widget->render($context));

        // Then hide with null
        $this->widget->setState(null);
        $this->assertSame([], $this->widget->render($context));
    }

    public function test_get_state_returns_current_state(): void
    {
        $this->assertNull($this->widget->getState());

        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 30);
        $this->widget->setState($state);

        $this->assertSame($state, $this->widget->getState());
    }

    public function test_render_updates_when_state_changes(): void
    {
        $context = new RenderContext(80, 20);

        // At top
        $stateTop = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($stateTop);
        $resultTop = $this->widget->render($context);

        // At bottom
        $stateBottom = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 80);
        $this->widget->setState($stateBottom);
        $resultBottom = $this->widget->render($context);

        // The results should differ (thumb moved from top to bottom)
        $this->assertNotSame($resultTop, $resultBottom);
    }

    // ── Huge content (minimum thumb) ──────────────────────────────────────

    public function test_minimum_thumb_size_one_row_for_huge_content(): void
    {
        // 10000 lines, 20 visible → thumbSize(20) = 1
        $state = new ScrollbarState(contentLength: 10000, viewportLength: 20, position: 5000);
        $this->widget->setState($state);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        $this->assertCount(20, $result);

        $thumbChar = $this->widget::SYMBOLS_DEFAULT['thumb'];
        $thumbCount = 0;
        foreach ($result as $line) {
            if (str_contains($line, $thumbChar)) {
                $thumbCount++;
            }
        }
        $this->assertSame(1, $thumbCount);
    }

    // ── Each line is exactly one character (plus optional ANSI) ───────────

    public function test_each_line_contains_exactly_one_visible_character(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->widget->setState($state);

        $context = new RenderContext(80, 20);
        $result = $this->widget->render($context);

        foreach ($result as $i => $line) {
            // Strip ANSI escape sequences to get visible content
            $visible = preg_replace('/\033\[[0-9;]*m/', '', $line);
            // Without stylesheet context (no attach), styles won't apply,
            // so visible length is 1 (single Unicode character)
            $this->assertSame(1, mb_strlen($visible), "Row {$i} should be exactly 1 visible character");
        }
    }
}
