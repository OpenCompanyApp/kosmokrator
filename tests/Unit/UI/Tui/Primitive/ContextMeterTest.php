<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Primitive;

use Athanor\Computed;
use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\Display\ContextMeter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class ContextMeterTest extends TestCase
{
    public function test_renders_bar_at_zero_percent(): void
    {
        $percent = new Computed(fn () => 0.0);
        $meter = ContextMeter::of($percent)->width(10);
        $meter->syncFromSignals();

        $result = $meter->render(new RenderContext(80, 24));
        $this->assertCount(1, $result);
        // 0% filled = all empty dashes
        $this->assertStringContainsString('──────────', $result[0]);
    }

    public function test_renders_bar_at_100_percent(): void
    {
        $percent = new Computed(fn () => 100.0);
        $meter = ContextMeter::of($percent)->width(10);
        $meter->syncFromSignals();

        $result = $meter->render(new RenderContext(80, 24));
        $this->assertStringContainsString('━━━━━━━━━━', $result[0]);
    }

    public function test_renders_bar_at_50_percent(): void
    {
        $percent = new Computed(fn () => 50.0);
        $meter = ContextMeter::of($percent)->width(10);
        $meter->syncFromSignals();

        $result = $meter->render(new RenderContext(80, 24));
        $this->assertStringContainsString('━━━━━', $result[0]);
        $this->assertStringContainsString('─────', $result[0]);
    }

    public function test_change_detected_on_percent_update(): void
    {
        $signal = new Signal(25.0);
        $percent = new Computed(fn () => $signal->get());
        $meter = ContextMeter::of($percent);

        $this->assertTrue($meter->syncFromSignals());
        $this->assertFalse($meter->syncFromSignals()); // No change

        $signal->set(75.0);
        $this->assertTrue($meter->syncFromSignals());
    }

    public function test_custom_width(): void
    {
        $percent = new Computed(fn () => 50.0);
        $meter = ContextMeter::of($percent)->width(4);
        $meter->syncFromSignals();

        $result = $meter->render(new RenderContext(80, 24));
        $this->assertCount(1, $result);
        // 50% of 4 = 2 filled, 2 empty
        $this->assertStringContainsString('━━', $result[0]);
        $this->assertStringContainsString('──', $result[0]);
    }
}
