<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\AnsiTheogony;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AnsiTheogonyTest extends TestCase
{
    private AnsiTheogony $theogony;

    private ReflectionClass $reflector;

    protected function setUp(): void
    {
        $this->theogony = new AnsiTheogony;
        $this->reflector = new ReflectionClass($this->theogony);
    }

    // ── LOGO_LINES ─────────────────────────────────────────────────────

    public function test_logo_lines_has_six_entries(): void
    {
        $lines = $this->getConstant('LOGO_LINES');

        $this->assertIsArray($lines);
        $this->assertCount(6, $lines);
    }

    public function test_logo_lines_all_non_empty(): void
    {
        $lines = $this->getConstant('LOGO_LINES');

        foreach ($lines as $i => $line) {
            $this->assertNotEmpty($line, "LOGO_LINES[{$i}] should not be empty");
            $this->assertIsString($line, "LOGO_LINES[{$i}] should be a string");
        }
    }

    public function test_logo_lines_contain_block_characters(): void
    {
        $lines = $this->getConstant('LOGO_LINES');

        $joined = implode('', $lines);
        $this->assertStringContainsString('█', $joined);
        $this->assertStringContainsString('╗', $joined);
        $this->assertStringContainsString('╚', $joined);
        $this->assertStringContainsString('╔', $joined);
        $this->assertStringContainsString('╝', $joined);
    }

    public function test_logo_lines_have_similar_widths(): void
    {
        $lines = $this->getConstant('LOGO_LINES');

        $widths = array_map(fn (string $line): int => mb_strwidth($line), $lines);
        $min = min($widths);
        $max = max($widths);
        // Logo lines may vary slightly in trailing characters but should be close
        $this->assertLessThanOrEqual(2, $max - $min, 'LOGO_LINES widths should be within 2 columns of each other');
    }

    // ── LOGO_GRADIENTS ─────────────────────────────────────────────────

    public function test_logo_gradients_has_six_entries(): void
    {
        $gradients = $this->getConstant('LOGO_GRADIENTS');

        $this->assertIsArray($gradients);
        $this->assertCount(6, $gradients);
    }

    public function test_logo_gradients_count_matches_logo_lines(): void
    {
        $lines = $this->getConstant('LOGO_LINES');
        $gradients = $this->getConstant('LOGO_GRADIENTS');

        $this->assertCount(count($lines), $gradients);
    }

    public function test_logo_gradients_each_has_three_rgb_components(): void
    {
        $gradients = $this->getConstant('LOGO_GRADIENTS');

        foreach ($gradients as $i => $gradient) {
            $this->assertCount(3, $gradient, "LOGO_GRADIENTS[{$i}] should have 3 RGB components");
            foreach ($gradient as $j => $component) {
                $this->assertIsInt($component, "LOGO_GRADIENTS[{$i}][{$j}] should be an int");
                $this->assertGreaterThanOrEqual(0, $component);
                $this->assertLessThanOrEqual(255, $component);
            }
        }
    }

    public function test_logo_gradients_red_dominant(): void
    {
        $gradients = $this->getConstant('LOGO_GRADIENTS');

        foreach ($gradients as $i => [$r, $g, $b]) {
            $this->assertGreaterThan($g, $r, "LOGO_GRADIENTS[{$i}] red should dominate green");
            $this->assertGreaterThan($b, $r, "LOGO_GRADIENTS[{$i}] red should dominate blue");
        }
    }

    // ── CHAOS_CHARS ────────────────────────────────────────────────────

    public function test_chaos_chars_is_non_empty(): void
    {
        $chars = $this->getConstant('CHAOS_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
    }

    public function test_chaos_chars_all_single_mb_chars(): void
    {
        $chars = $this->getConstant('CHAOS_CHARS');

        foreach ($chars as $i => $char) {
            $this->assertSame(1, mb_strlen($char), "CHAOS_CHARS[{$i}] should be 1 mb_char");
        }
    }

    public function test_chaos_chars_no_duplicates(): void
    {
        $chars = $this->getConstant('CHAOS_CHARS');

        $this->assertCount(count($chars), array_unique($chars), 'CHAOS_CHARS should have no duplicates');
    }

    // ── RAIN_CHARS ─────────────────────────────────────────────────────

    public function test_rain_chars_is_non_empty(): void
    {
        $chars = $this->getConstant('RAIN_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
    }

    public function test_rain_chars_contains_greek_letters(): void
    {
        $chars = $this->getConstant('RAIN_CHARS');
        $joined = implode('', $chars);

        $this->assertStringContainsString('α', $joined);
        $this->assertStringContainsString('μ', $joined);
    }

    public function test_rain_chars_contains_planetary_symbols(): void
    {
        $chars = $this->getConstant('RAIN_CHARS');
        $joined = implode('', $chars);

        $this->assertStringContainsString('☿', $joined);
        $this->assertStringContainsString('♆', $joined);
    }

    // ── SCRAMBLE_CHARS ─────────────────────────────────────────────────

    public function test_scramble_chars_is_non_empty(): void
    {
        $chars = $this->getConstant('SCRAMBLE_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
    }

    public function test_scramble_chars_contains_box_drawing(): void
    {
        $chars = $this->getConstant('SCRAMBLE_CHARS');
        $joined = implode('', $chars);

        $this->assertStringContainsString('╬', $joined);
        $this->assertStringContainsString('═', $joined);
    }

    public function test_scramble_chars_contains_greek_capitals(): void
    {
        $chars = $this->getConstant('SCRAMBLE_CHARS');
        $joined = implode('', $chars);

        $this->assertStringContainsString('Ω', $joined);
        $this->assertStringContainsString('Σ', $joined);
    }

    // ── WYRM ───────────────────────────────────────────────────────────

    public function test_wyrm_is_non_empty_array(): void
    {
        $wyrm = $this->getConstant('WYRM');

        $this->assertIsArray($wyrm);
        $this->assertNotEmpty($wyrm);
        $this->assertCount(17, $wyrm);
    }

    public function test_wyrm_lines_are_strings(): void
    {
        $wyrm = $this->getConstant('WYRM');

        foreach ($wyrm as $i => $line) {
            $this->assertIsString($line, "WYRM[{$i}] should be a string");
            $this->assertNotEmpty($line, "WYRM[{$i}] should not be empty");
        }
    }

    public function test_wyrm_contains_eyes(): void
    {
        $wyrm = $this->getConstant('WYRM');
        $joined = implode('', $wyrm);

        $this->assertStringContainsString('◉', $joined);
    }

    // ── Property defaults ──────────────────────────────────────────────

    public function test_default_term_width(): void
    {
        $prop = $this->reflector->getProperty('termWidth');

        $this->assertSame(120, $prop->getValue($this->theogony));
    }

    public function test_default_term_height(): void
    {
        $prop = $this->reflector->getProperty('termHeight');

        $this->assertSame(30, $prop->getValue($this->theogony));
    }

    // ── inBounds ───────────────────────────────────────────────────────

    public function test_in_bounds_center_of_terminal(): void
    {
        $this->assertTrue($this->callInBounds(15, 60));
    }

    public function test_in_bounds_top_left_corner(): void
    {
        $this->assertTrue($this->callInBounds(1, 1));
    }

    public function test_in_bounds_bottom_edge_included(): void
    {
        $this->assertTrue($this->callInBounds(30, 60));
    }

    public function test_in_bounds_right_edge_excluded(): void
    {
        // col < termWidth (120), so 119 is in bounds, 120 is not
        $this->assertTrue($this->callInBounds(15, 119));
        $this->assertFalse($this->callInBounds(15, 120));
    }

    public function test_in_bounds_row_zero_out(): void
    {
        $this->assertFalse($this->callInBounds(0, 60));
    }

    public function test_in_bounds_col_zero_out(): void
    {
        $this->assertFalse($this->callInBounds(15, 0));
    }

    public function test_in_bounds_negative_values_out(): void
    {
        $this->assertFalse($this->callInBounds(-5, -5));
    }

    public function test_in_bounds_exceeds_dimensions(): void
    {
        $this->assertFalse($this->callInBounds(31, 60));
        $this->assertFalse($this->callInBounds(15, 200));
    }

    // ── inBounds with custom terminal dimensions ───────────────────────

    public function test_in_bounds_with_smaller_terminal(): void
    {
        $this->setTermDimensions(40, 20);

        $this->assertTrue($this->callInBounds(10, 15));
        $this->assertTrue($this->callInBounds(20, 15));  // row 20 <= height 20 is in bounds
        $this->assertTrue($this->callInBounds(10, 39));   // col 39 < width 40 is in bounds
        $this->assertFalse($this->callInBounds(10, 40));  // col 40 >= width 40 is out
        $this->assertFalse($this->callInBounds(21, 15));  // row 21 > height 20 is out
    }

    // ── spawnChaosParticle ─────────────────────────────────────────────

    public function test_spawn_chaos_particle_returns_required_keys(): void
    {
        $particle = $this->callSpawnChaosParticle();

        $this->assertArrayHasKey('row', $particle);
        $this->assertArrayHasKey('col', $particle);
        $this->assertArrayHasKey('vRow', $particle);
        $this->assertArrayHasKey('vCol', $particle);
        $this->assertArrayHasKey('char', $particle);
        $this->assertArrayHasKey('life', $particle);
        $this->assertArrayHasKey('maxLife', $particle);
    }

    public function test_spawn_chaos_particle_row_within_bounds(): void
    {
        $particle = $this->callSpawnChaosParticle();

        $this->assertIsFloat($particle['row']);
        $this->assertGreaterThanOrEqual(1, $particle['row']);
        $this->assertLessThanOrEqual(30, $particle['row']);
    }

    public function test_spawn_chaos_particle_col_within_bounds(): void
    {
        $particle = $this->callSpawnChaosParticle();

        $this->assertIsFloat($particle['col']);
        $this->assertGreaterThanOrEqual(1, $particle['col']);
        $this->assertLessThanOrEqual(119, $particle['col']);
    }

    public function test_spawn_chaos_particle_char_from_chaos_chars(): void
    {
        $chaosChars = $this->getConstant('CHAOS_CHARS');
        $particle = $this->callSpawnChaosParticle();

        $this->assertContains($particle['char'], $chaosChars);
    }

    public function test_spawn_chaos_particle_life_is_positive(): void
    {
        $particle = $this->callSpawnChaosParticle();

        $this->assertIsInt($particle['life']);
        $this->assertGreaterThan(0, $particle['life']);
        $this->assertLessThanOrEqual($particle['maxLife'], $particle['life']);
    }

    public function test_spawn_chaos_particle_max_life_is_fixed(): void
    {
        $particle = $this->callSpawnChaosParticle();

        $this->assertSame(40, $particle['maxLife']);
    }

    public function test_spawn_chaos_particle_velocity_is_small(): void
    {
        $particle = $this->callSpawnChaosParticle();

        $this->assertIsFloat($particle['vRow']);
        $this->assertIsFloat($particle['vCol']);
        $this->assertGreaterThanOrEqual(-0.34, $particle['vRow']);
        $this->assertLessThanOrEqual(0.34, $particle['vRow']);
        $this->assertGreaterThanOrEqual(-0.34, $particle['vCol']);
        $this->assertLessThanOrEqual(0.34, $particle['vCol']);
    }

    public function test_spawn_chaos_particle_multiple_particles_have_varied_positions(): void
    {
        $positions = [];
        for ($i = 0; $i < 20; $i++) {
            $p = $this->callSpawnChaosParticle();
            $positions[] = $p['row'].','.$p['col'];
        }

        // With 20 random particles, positions should not all be identical
        $unique = count(array_unique($positions));
        $this->assertGreaterThan(1, $unique, 'Multiple spawned particles should have varied positions');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function getConstant(string $name): mixed
    {
        return $this->reflector->getConstant($name);
    }

    private function callInBounds(int $row, int $col): bool
    {
        $method = $this->reflector->getMethod('inBounds');

        return $method->invoke($this->theogony, $row, $col);
    }

    private function callSpawnChaosParticle(): array
    {
        $method = $this->reflector->getMethod('spawnChaosParticle');

        return $method->invoke($this->theogony);
    }

    private function setTermDimensions(int $width, int $height): void
    {
        $wProp = $this->reflector->getProperty('termWidth');
        $wProp->setValue($this->theogony, $width);

        $hProp = $this->reflector->getProperty('termHeight');
        $hProp->setValue($this->theogony, $height);
    }
}
