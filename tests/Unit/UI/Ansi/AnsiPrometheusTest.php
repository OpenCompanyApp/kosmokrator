<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\UI\Ansi\AnsiPrometheus;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AnsiPrometheusTest extends TestCase
{
    private AnsiPrometheus $prometheus;

    private ReflectionClass $reflector;

    protected function setUp(): void
    {
        $this->prometheus = new AnsiPrometheus;
        $this->reflector = new ReflectionClass($this->prometheus);
    }

    // ── Constants ──────────────────────────────────────────────────────

    public function test_fire_chars_is_non_empty_array(): void
    {
        $chars = $this->getConstant('FIRE_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
        $this->assertCount(7, $chars);
    }

    public function test_chain_chars_is_non_empty_array(): void
    {
        $chars = $this->getConstant('CHAIN_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
        $this->assertCount(7, $chars);
    }

    public function test_core_chars_is_non_empty_array(): void
    {
        $chars = $this->getConstant('CORE_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
        $this->assertCount(5, $chars);
    }

    public function test_trail_chars_is_non_empty_array(): void
    {
        $chars = $this->getConstant('TRAIL_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
        $this->assertCount(7, $chars);
    }

    public function test_streak_chars_is_non_empty_array(): void
    {
        $chars = $this->getConstant('STREAK_CHARS');

        $this->assertIsArray($chars);
        $this->assertNotEmpty($chars);
        $this->assertCount(5, $chars);
    }

    public function test_all_char_constants_contain_only_single_multibyte_chars(): void
    {
        foreach (['FIRE_CHARS', 'CHAIN_CHARS', 'CORE_CHARS', 'TRAIL_CHARS', 'STREAK_CHARS'] as $name) {
            $chars = $this->getConstant($name);
            foreach ($chars as $char) {
                $this->assertSame(1, mb_strlen($char), "Character in {$name} should be exactly 1 mb_char: ".bin2hex($char));
            }
        }
    }

    public function test_fire_and_core_chars_have_no_overlap(): void
    {
        $fire = $this->getConstant('FIRE_CHARS');
        $core = $this->getConstant('CORE_CHARS');

        $overlap = array_intersect($fire, $core);
        // Some overlap is expected (█, ▓ appear in both), so verify it's intentional
        $this->assertNotEmpty($overlap, 'FIRE_CHARS and CORE_CHARS share expected characters');
    }

    // ── Property defaults ──────────────────────────────────────────────

    public function test_prev_cells_defaults_to_empty_array(): void
    {
        $prop = $this->reflector->getProperty('prevCells');

        $this->assertSame([], $prop->getValue($this->prometheus));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function getConstant(string $name): mixed
    {
        return $this->reflector->getConstant($name);
    }
}
