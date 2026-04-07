<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use KosmoKrator\UI\Tui\Widget\ScrollbarState;
use PHPUnit\Framework\TestCase;

final class ScrollbarStateTest extends TestCase
{
    // ── isScrollable ──────────────────────────────────────────────────────

    public function test_is_scrollable_false_when_content_fits_viewport(): void
    {
        $state = new ScrollbarState(contentLength: 10, viewportLength: 20, position: 0);
        $this->assertFalse($state->isScrollable());
    }

    public function test_is_scrollable_false_when_equal(): void
    {
        $state = new ScrollbarState(contentLength: 20, viewportLength: 20, position: 0);
        $this->assertFalse($state->isScrollable());
    }

    public function test_is_scrollable_false_when_zero_content(): void
    {
        $state = new ScrollbarState(contentLength: 0, viewportLength: 20, position: 0);
        $this->assertFalse($state->isScrollable());
    }

    public function test_is_scrollable_true_when_content_exceeds_viewport(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->assertTrue($state->isScrollable());
    }

    // ── scrollFraction ───────────────────────────────────────────────────

    public function test_scroll_fraction_zero_at_top(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->assertSame(0.0, $state->scrollFraction());
    }

    public function test_scroll_fraction_one_at_bottom(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 80);
        $this->assertSame(1.0, $state->scrollFraction());
    }

    public function test_scroll_fraction_mid_scroll(): void
    {
        // maxScroll = 200 - 50 = 150, fraction = 75 / 150 = 0.5
        $state = new ScrollbarState(contentLength: 200, viewportLength: 50, position: 75);
        $this->assertSame(0.5, $state->scrollFraction());
    }

    public function test_scroll_fraction_zero_when_not_scrollable(): void
    {
        $state = new ScrollbarState(contentLength: 10, viewportLength: 20, position: 0);
        $this->assertSame(0.0, $state->scrollFraction());
    }

    // ── thumbSize ─────────────────────────────────────────────────────────

    public function test_thumb_size_minimum_one(): void
    {
        // Very long content: 20 * 20 / 10000 = 0.04 → rounds to 0 → max(1, 0) = 1
        $state = new ScrollbarState(contentLength: 10000, viewportLength: 20, position: 0);
        $this->assertSame(1, $state->thumbSize(20));
    }

    public function test_thumb_size_full_track_when_zero_content(): void
    {
        $state = new ScrollbarState(contentLength: 0, viewportLength: 20, position: 0);
        $this->assertSame(20, $state->thumbSize(20));
    }

    public function test_thumb_size_proportional(): void
    {
        // trackHeight=20, content=100, viewport=20 → 20*20/100 = 4
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->assertSame(4, $state->thumbSize(20));
    }

    public function test_thumb_size_with_large_viewport_ratio(): void
    {
        // trackHeight=10, content=20, viewport=15 → 10*15/20 = 7.5 → 8
        $state = new ScrollbarState(contentLength: 20, viewportLength: 15, position: 0);
        $this->assertSame(8, $state->thumbSize(10));
    }

    // ── thumbStart ────────────────────────────────────────────────────────

    public function test_thumb_start_zero_at_top(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $this->assertSame(0, $state->thumbStart(20));
    }

    public function test_thumb_start_at_max_at_bottom(): void
    {
        // thumbSize(20) = 4, so maxPos = 20 - 4 = 16
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 80);
        $this->assertSame(16, $state->thumbStart(20));
    }

    public function test_thumb_start_mid_scroll(): void
    {
        // content=200, viewport=50, position=75 → fraction=0.5
        // thumbSize(30) = round(30*50/200) = round(7.5) = 8
        // maxPos = 30 - 8 = 22, thumbStart = round(22 * 0.5) = 11
        $state = new ScrollbarState(contentLength: 200, viewportLength: 50, position: 75);
        $this->assertSame(11, $state->thumbStart(30));
    }

    // ── withPosition ─────────────────────────────────────────────────────

    public function test_with_position_returns_new_instance(): void
    {
        $original = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 0);
        $modified = $original->withPosition(50);

        $this->assertNotSame($original, $modified);
        $this->assertSame(0, $original->position);
        $this->assertSame(50, $modified->position);
        $this->assertSame(100, $modified->contentLength);
        $this->assertSame(20, $modified->viewportLength);
    }

    // ── Immutability of readonly properties ──────────────────────────────

    public function test_properties_are_readonly(): void
    {
        $state = new ScrollbarState(contentLength: 100, viewportLength: 20, position: 30);
        $this->assertSame(100, $state->contentLength);
        $this->assertSame(20, $state->viewportLength);
        $this->assertSame(30, $state->position);
    }
}
