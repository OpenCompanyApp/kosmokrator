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
            'bash' => ['bash', '⚡'],
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

    public function test_max_context_for_claude_model(): void
    {
        $this->assertSame(200_000, Theme::maxContextForModel('claude-4-sonnet'));
    }

    public function test_max_context_for_glm_model(): void
    {
        $this->assertSame(128_000, Theme::maxContextForModel('glm-4'));
    }

    public function test_max_context_for_gpt4_model(): void
    {
        $this->assertSame(128_000, Theme::maxContextForModel('gpt-4-turbo'));
    }

    public function test_max_context_for_unknown_model(): void
    {
        $this->assertSame(200_000, Theme::maxContextForModel('llama-3'));
    }

    public function test_max_context_case_insensitive(): void
    {
        $this->assertSame(200_000, Theme::maxContextForModel('CLAUDE-4'));
    }

    public function test_context_bar_green_at_low_usage(): void
    {
        $bar = Theme::contextBar(50_000, 200_000); // 25%
        // Should contain success (green) color
        $this->assertStringContainsString(Theme::success(), $bar);
    }

    public function test_context_bar_yellow_at_medium_usage(): void
    {
        $bar = Theme::contextBar(150_000, 200_000); // 75%
        $this->assertStringContainsString(Theme::warning(), $bar);
    }

    public function test_context_bar_red_at_high_usage(): void
    {
        $bar = Theme::contextBar(190_000, 200_000); // 95%
        $this->assertStringContainsString(Theme::error(), $bar);
    }

    public function test_context_bar_boundary_60_percent(): void
    {
        // At 59% — should be green
        $bar59 = Theme::contextBar(59_000, 100_000);
        $this->assertStringContainsString(Theme::success(), $bar59);

        // At 60% — should be yellow
        $bar60 = Theme::contextBar(60_000, 100_000);
        $this->assertStringContainsString(Theme::warning(), $bar60);
    }

    public function test_context_bar_boundary_85_percent(): void
    {
        // At 84% — should be yellow
        $bar84 = Theme::contextBar(84_000, 100_000);
        $this->assertStringContainsString(Theme::warning(), $bar84);

        // At 85% — should be red
        $bar85 = Theme::contextBar(85_000, 100_000);
        $this->assertStringContainsString(Theme::error(), $bar85);
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
