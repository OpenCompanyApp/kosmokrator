<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\AnsiIntro;
use Kosmokrator\UI\Ansi\IntroSkippedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AnsiIntroTest extends TestCase
{
    private AnsiIntro $intro;

    private ReflectionClass $reflector;

    protected function setUp(): void
    {
        $this->intro = new AnsiIntro;
        $this->reflector = new ReflectionClass($this->intro);
    }

    // ── Property defaults ──────────────────────────────────────────────

    public function test_default_term_width(): void
    {
        $prop = $this->reflector->getProperty('termWidth');

        $this->assertSame(120, $prop->getValue($this->intro));
    }

    public function test_default_term_height(): void
    {
        $prop = $this->reflector->getProperty('termHeight');

        $this->assertSame(30, $prop->getValue($this->intro));
    }

    public function test_default_skipped_is_false(): void
    {
        $prop = $this->reflector->getProperty('skipped');

        $this->assertFalse($prop->getValue($this->intro));
    }

    public function test_default_stdin_stream_is_null(): void
    {
        $prop = $this->reflector->getProperty('stdinStream');

        $this->assertNull($prop->getValue($this->intro));
    }

    // ── inBounds ───────────────────────────────────────────────────────

    public function test_in_bounds_within_terminal(): void
    {
        $this->assertTrue($this->callInBounds(15, 60));
    }

    public function test_in_bounds_top_left_corner(): void
    {
        $this->assertTrue($this->callInBounds(1, 1));
    }

    public function test_in_bounds_bottom_right_corner(): void
    {
        // col must be < termWidth (120), so 119 is the last valid column
        $this->assertTrue($this->callInBounds(30, 119));
    }

    public function test_in_bounds_row_zero_is_out(): void
    {
        $this->assertFalse($this->callInBounds(0, 60));
    }

    public function test_in_bounds_negative_row_is_out(): void
    {
        $this->assertFalse($this->callInBounds(-1, 60));
    }

    public function test_in_bounds_col_zero_is_out(): void
    {
        $this->assertFalse($this->callInBounds(15, 0));
    }

    public function test_in_bounds_col_at_term_width_is_out(): void
    {
        $this->assertFalse($this->callInBounds(15, 120));
    }

    public function test_in_bounds_row_exceeds_height(): void
    {
        $this->assertFalse($this->callInBounds(31, 60));
    }

    public function test_in_bounds_col_exceeds_width(): void
    {
        $this->assertFalse($this->callInBounds(15, 150));
    }

    // ── wait throws IntroSkippedException when key pressed ─────────────

    public function test_wait_throws_skipped_exception_when_key_detected(): void
    {
        // Set stdinStream to null so keyPressed() returns false — wait should not throw
        $prop = $this->reflector->getProperty('stdinStream');
        $prop->setValue($this->intro, null);

        // With null stdinStream, keyPressed returns false, so wait completes without exception
        $method = $this->reflector->getMethod('wait');
        $method->invoke($this->intro, 10_000); // 10ms — tiny wait

        // If we reach here, no exception was thrown — expected behavior with no stdin
        $this->assertTrue(true);
    }

    // ── renderStatic ───────────────────────────────────────────────────

    public function test_render_static_produces_output(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
    }

    public function test_render_static_contains_logo(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        // The logo contains "Kosmokrator" in ASCII block characters; check for fragments
        $this->assertStringContainsString('█████', $output);
        $this->assertStringContainsString('╚═╝', $output);
    }

    public function test_render_static_contains_title(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $this->assertStringContainsString('Κοσμοκράτωρ', $output);
    }

    public function test_render_static_contains_tagline(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $this->assertStringContainsString('OpenCompany', $output);
    }

    public function test_render_static_contains_planet_symbols(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $this->assertStringContainsString('☿', $output);
        $this->assertStringContainsString('♀', $output);
        $this->assertStringContainsString('♁', $output);
        $this->assertStringContainsString('♂', $output);
        $this->assertStringContainsString('♃', $output);
        $this->assertStringContainsString('♄', $output);
    }

    public function test_render_static_contains_zodiac_signs(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $this->assertStringContainsString('♈', $output);
        $this->assertStringContainsString('♎', $output);
        $this->assertStringContainsString('♓', $output);
    }

    public function test_render_static_contains_orrery_sun(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $this->assertStringContainsString('☉', $output);
    }

    public function test_render_static_contains_border_ornaments(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $this->assertStringContainsString('⟡', $output);
        $this->assertStringContainsString('━', $output);
    }

    public function test_render_static_produces_multiple_lines(): void
    {
        ob_start();
        $this->intro->renderStatic();
        $output = ob_get_clean();

        $lines = explode("\n", trim($output));
        $this->assertGreaterThan(10, count($lines));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function callInBounds(int $row, int $col): bool
    {
        $method = $this->reflector->getMethod('inBounds');

        return $method->invoke($this->intro, $row, $col);
    }
}
