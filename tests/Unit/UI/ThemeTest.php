<?php

namespace Kosmokrator\Tests\Unit\UI;

use Kosmokrator\UI\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThemeTest extends TestCase
{
    public function test_rgb_generates_correct_ansi_code(): void
    {
        $this->assertSame("\033[38;2;255;0;128m", Theme::rgb(255, 0, 128));
    }

    public function test_bg_rgb_generates_correct_ansi_code(): void
    {
        $this->assertSame("\033[48;2;40;40;40m", Theme::bgRgb(40, 40, 40));
    }

    public function test_color256_generates_correct_ansi_code(): void
    {
        $this->assertSame("\033[38;5;240m", Theme::color256(240));
    }

    public function test_reset_code(): void
    {
        $this->assertSame("\033[0m", Theme::reset());
    }

    public function test_bold_code(): void
    {
        $this->assertSame("\033[1m", Theme::bold());
    }

    #[DataProvider('toolIconProvider')]
    public function test_tool_icon_known_tools(string $name, string $expected): void
    {
        $this->assertSame($expected, Theme::toolIcon($name));
    }

    public static function toolIconProvider(): array
    {
        return [
            'file_read' => ['file_read', '☽'],
            'file_write' => ['file_write', '☉'],
            'file_edit' => ['file_edit', '♅'],
            'apply_patch' => ['apply_patch', '✎'],
            'bash' => ['bash', '⚡︎'],
            'shell_start' => ['shell_start', '◌'],
            'shell_write' => ['shell_write', '↦'],
            'shell_read' => ['shell_read', '↤'],
            'shell_kill' => ['shell_kill', '✕'],
            'grep' => ['grep', '⊛'],
            'glob' => ['glob', '✧'],
        ];
    }

    public function test_tool_icon_unknown_tool(): void
    {
        $this->assertSame('◈', Theme::toolIcon('unknown'));
    }

    public function test_format_token_count_millions(): void
    {
        $this->assertSame('1.5M', Theme::formatTokenCount(1_500_000));
    }

    public function test_format_token_count_thousands(): void
    {
        $this->assertSame('42k', Theme::formatTokenCount(42_000));
    }

    public function test_format_token_count_exact_million(): void
    {
        $this->assertSame('1M', Theme::formatTokenCount(1_000_000));
    }

    public function test_format_token_count_exact_thousand(): void
    {
        $this->assertSame('1k', Theme::formatTokenCount(1_000));
    }

    public function test_format_token_count_below_thousand(): void
    {
        $this->assertSame('999', Theme::formatTokenCount(999));
    }

    public function test_format_token_count_zero(): void
    {
        $this->assertSame('0', Theme::formatTokenCount(0));
    }

    public function test_context_bar_green_at_low_usage(): void
    {
        $bar = Theme::contextBar(40_000, 200_000); // 20%
        $this->assertStringContainsString(Theme::success(), $bar);
    }

    public function test_context_bar_yellow_at_medium_usage(): void
    {
        $bar = Theme::contextBar(120_000, 200_000); // 60%
        $this->assertStringContainsString(Theme::warning(), $bar);
    }

    public function test_context_bar_red_at_high_usage(): void
    {
        $bar = Theme::contextBar(190_000, 200_000); // 95%
        $this->assertStringContainsString(Theme::error(), $bar);
    }

    public function test_context_bar_boundary_50_percent(): void
    {
        // At 49% — should be green
        $bar49 = Theme::contextBar(49_000, 100_000);
        $this->assertStringContainsString(Theme::success(), $bar49);

        // At 50% — should be yellow
        $bar50 = Theme::contextBar(50_000, 100_000);
        $this->assertStringContainsString(Theme::warning(), $bar50);
    }

    public function test_context_bar_boundary_75_percent(): void
    {
        // At 74% — should be yellow
        $bar74 = Theme::contextBar(74_000, 100_000);
        $this->assertStringContainsString(Theme::warning(), $bar74);

        // At 75% — should be red
        $bar75 = Theme::contextBar(75_000, 100_000);
        $this->assertStringContainsString(Theme::error(), $bar75);
    }

    public function test_context_bar_contains_label_and_percentage(): void
    {
        $bar = Theme::contextBar(50_000, 200_000);
        $this->assertStringContainsString('50k', $bar);
        $this->assertStringContainsString('200k', $bar);
        $this->assertStringContainsString('%', $bar);
    }

    public function test_context_bar_zero_max_context(): void
    {
        // Should not divide by zero
        $bar = Theme::contextBar(100, 0);
        $this->assertIsString($bar);
    }

    public function test_palette_methods_return_ansi_strings(): void
    {
        // All palette methods should return strings starting with ESC
        $methods = ['primary', 'primaryDim', 'accent', 'success', 'warning', 'error',
            'info', 'link', 'code', 'dim', 'dimmer', 'text', 'white',
            'diffAdd', 'diffRemove', 'codeBg'];

        foreach ($methods as $method) {
            $result = Theme::$method();
            $this->assertStringContainsString("\033[", $result, "Theme::{$method}() should return ANSI code");
        }
    }

    public function test_terminal_control_methods(): void
    {
        $this->assertStringContainsString('?25l', Theme::hideCursor());
        $this->assertStringContainsString('?25h', Theme::showCursor());
        $this->assertStringContainsString('2J', Theme::clearScreen());
        $this->assertStringContainsString('5;10H', Theme::moveTo(5, 10));
    }
}
