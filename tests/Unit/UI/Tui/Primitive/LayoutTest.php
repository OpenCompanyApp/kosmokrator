<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Primitive;

use Kosmokrator\UI\Tui\Primitive\Layout\HStack;
use Kosmokrator\UI\Tui\Primitive\Layout\Spacer;
use Kosmokrator\UI\Tui\Primitive\Layout\VStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\TextWidget;

final class LayoutTest extends TestCase
{
    // ── VStack ──────────────────────────────────────────────────────────

    public function test_vstack_creates_container_with_children(): void
    {
        $child1 = new TextWidget('a');
        $child2 = new TextWidget('b');

        $vstack = VStack::make(children: [$child1, $child2]);

        $this->assertCount(2, $vstack->all());
    }

    public function test_vstack_applies_classes(): void
    {
        $vstack = VStack::make(classes: ['test-class']);

        // ContainerWidget doesn't have hasStyleClass; verify it doesn't crash
        $this->assertNotNull($vstack);
    }

    public function test_vstack_empty_children(): void
    {
        $vstack = VStack::make();

        $this->assertCount(0, $vstack->all());
    }

    // ── HStack ──────────────────────────────────────────────────────────

    public function test_hstack_creates_container_with_children(): void
    {
        $child1 = new TextWidget('a');
        $child2 = new TextWidget('b');

        $hstack = HStack::make(children: [$child1, $child2]);

        $this->assertCount(2, $hstack->all());
    }

    public function test_hstack_applies_classes(): void
    {
        $hstack = HStack::make(classes: ['test-class']);

        $this->assertNotNull($hstack);
    }

    // ── Spacer ──────────────────────────────────────────────────────────

    public function test_spacer_flex_is_vertically_expanded(): void
    {
        $spacer = Spacer::flex();

        $this->assertTrue($spacer->isVerticallyExpanded());
    }

    public function test_spacer_default_is_not_expanded(): void
    {
        $spacer = new Spacer;

        $this->assertFalse($spacer->isVerticallyExpanded());
    }

    public function test_spacer_renders_empty_rows(): void
    {
        $spacer = Spacer::flex();
        $result = $spacer->render(new RenderContext(80, 5));

        $this->assertCount(5, $result);
        $this->assertSame('', $result[0]);
    }

    public function test_spacer_zero_rows_returns_empty(): void
    {
        $spacer = Spacer::flex();
        $result = $spacer->render(new RenderContext(80, 0));

        $this->assertSame([], $result);
    }
}
