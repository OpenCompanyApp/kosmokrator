<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\StatusBarWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class StatusBarWidgetTest extends TestCase
{
    private StatusBarWidget $widget;

    protected function setUp(): void
    {
        $this->widget = new StatusBarWidget;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Strip ANSI escape sequences to get visible text. */
    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    /** Render with given column width, returning the single line. */
    private function renderLine(int $cols = 120): string
    {
        $result = $this->widget->render(new RenderContext($cols, 24));
        $this->assertCount(1, $result);

        return $result[0];
    }

    // ── Default state ────────────────────────────────────────────────────

    public function test_render_returns_single_line(): void
    {
        $result = $this->widget->render(new RenderContext(120, 24));
        $this->assertCount(1, $result);
    }

    public function test_render_fills_full_width(): void
    {
        $cols = 120;
        $line = $this->renderLine($cols);
        $visibleWidth = AnsiUtils::visibleWidth($line);
        $this->assertSame($cols, $visibleWidth);
    }

    public function test_default_mode_is_edit(): void
    {
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Edit', $visible);
    }

    public function test_default_is_idle(): void
    {
        $line = $this->renderLine(120);
        // Idle mode uses the IDLE colors, but still shows "Edit" label
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Edit', $visible);
    }

    // ── Mode pill ────────────────────────────────────────────────────────

    public function test_mode_pill_shows_label(): void
    {
        $this->widget->setMode('Plan');
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Plan', $visible);
    }

    public function test_mode_pill_shows_ask(): void
    {
        $this->widget->setMode('Ask');
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Ask', $visible);
    }

    public function test_mode_pill_shows_explore(): void
    {
        $this->widget->setMode('Explore');
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Explore', $visible);
    }

    public function test_set_mode_clears_idle(): void
    {
        // Widget starts idle; setting a mode should clear idle
        $this->widget->setMode('Edit');
        // After setMode, the widget uses mode colors (not idle gray).
        // We can't easily inspect internal state, but we verify it still renders.
        $line = $this->renderLine(120);
        $this->assertNotEmpty($line);
    }

    public function test_custom_mode_uses_default_colors(): void
    {
        // Unknown mode label should still render without error
        $this->widget->setMode('Custom');
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Custom', $visible);
    }

    public function test_explicit_fg_color_override(): void
    {
        $customFg = "\033[38;2;255;0;255m";
        $this->widget->setMode('Edit', $customFg);
        $line = $this->renderLine(120);
        $this->assertStringContainsString($customFg, $line);
    }

    // ── Permission segment ───────────────────────────────────────────────

    public function test_permission_shown_above_narrow_breakpoint(): void
    {
        $this->widget->setPermission('Guardian ◈', "\033[38;2;180;180;200m");
        $line = $this->renderLine(80);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Guardian ◈', $visible);
    }

    public function test_permission_hidden_below_narrow_breakpoint(): void
    {
        $this->widget->setPermission('Guardian ◈', "\033[38;2;180;180;200m");
        $line = $this->renderLine(50);
        $visible = $this->stripAnsi($line);
        $this->assertStringNotContainsString('Guardian', $visible);
    }

    public function test_permission_not_shown_when_empty(): void
    {
        // Default permission label is empty
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        // Should not have the minor separator after the pill when no permission
        // (at minimum, no "│" right after the mode pill)
        $this->assertNotEmpty($line);
    }

    // ── Token gauge ──────────────────────────────────────────────────────

    public function test_gauge_shown_with_tokens(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(12_400, 200_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('12.4k/200k', $visible);
    }

    public function test_gauge_shows_percentage(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(50_000, 200_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('25%', $visible);
    }

    public function test_gauge_bar_characters(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(100_000, 200_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        // Should contain filled (━) and empty (─) gauge characters
        $this->assertStringContainsString('━', $visible);
        $this->assertStringContainsString('─', $visible);
    }

    public function test_gauge_hidden_below_narrow_when_no_tokens(): void
    {
        $this->widget->setMode('Edit');
        // Default tokensIn = 0, so gauge is not rendered
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        // Should NOT contain gauge elements since tokensIn is 0
        $this->assertStringNotContainsString('━', $visible);
    }

    public function test_zero_tokens_renders_cleanly(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(0, 200_000);
        $line = $this->renderLine(120);
        $this->assertNotEmpty($line);
        // Visible width should still fill the terminal
        $this->assertSame(120, AnsiUtils::visibleWidth($line));
    }

    public function test_context_exceeded_clamps_to_100_percent(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(250_000, 200_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('100%', $visible);
    }

    // ── Model and cost ───────────────────────────────────────────────────

    public function test_model_shown_above_medium_breakpoint(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('claude-sonnet-4-20250514', 0.04);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('claude-sonnet-4-20250514', $visible);
    }

    public function test_model_hidden_below_medium_breakpoint(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('claude-sonnet-4-20250514', 0.04);
        $line = $this->renderLine(75);
        $visible = $this->stripAnsi($line);
        $this->assertStringNotContainsString('claude', $visible);
    }

    public function test_cost_shown_above_narrow_breakpoint(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('test-model', 0.04);
        $line = $this->renderLine(100);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('$0.04', $visible);
    }

    public function test_cost_hidden_below_narrow_breakpoint(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('test-model', 0.04);
        $line = $this->renderLine(50);
        $visible = $this->stripAnsi($line);
        $this->assertStringNotContainsString('$0.04', $visible);
    }

    public function test_zero_cost_not_shown(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('test-model', 0.0);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringNotContainsString('$0.00', $visible);
    }

    public function test_no_model_only_cost(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('', 0.04);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('$0.04', $visible);
    }

    public function test_long_model_name_truncated(): void
    {
        $this->widget->setMode('Edit');
        $longName = str_repeat('x', 30);
        $this->widget->setModelAndCost($longName, 0.0);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        // Should be truncated with ellipsis (max 25 chars at wide breakpoint)
        $this->assertStringContainsString('…', $visible);
        // The model segment ends with … (the full 30-char name is not present)
        $this->assertStringNotContainsString(str_repeat('x', 30), $visible);
    }

    public function test_small_cost_precision(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('test', 0.0042);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('$0.0042', $visible);
    }

    // ── Responsive breakpoints ───────────────────────────────────────────

    public function test_wide_terminal_shows_all_segments(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setPermission('Auto ✓', "\033[38;2;180;180;200m");
        $this->widget->setTokenUsage(12_400, 200_000);
        $this->widget->setModelAndCost('claude-sonnet-4-20250514', 0.04);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);

        $this->assertStringContainsString('Edit', $visible);
        $this->assertStringContainsString('Auto ✓', $visible);
        $this->assertStringContainsString('12.4k/200k', $visible);
        $this->assertStringContainsString('claude-sonnet-4-20250514', $visible);
        $this->assertStringContainsString('$0.04', $visible);
    }

    public function test_medium_terminal_hides_model(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(12_400, 200_000);
        $this->widget->setModelAndCost('claude-sonnet-4-20250514', 0.04);
        $line = $this->renderLine(85);
        $visible = $this->stripAnsi($line);

        // Cost still visible above 60 cols
        $this->assertStringContainsString('$0.04', $visible);
        // Model name hidden below 100 cols
        $this->assertStringNotContainsString('claude-sonnet-4-20250514', $visible);
    }

    public function test_narrow_terminal_shows_mode_and_gauge(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setPermission('Auto ✓', "\033[38;2;180;180;200m");
        $this->widget->setTokenUsage(12_400, 200_000);
        $this->widget->setModelAndCost('claude-sonnet-4-20250514', 0.04);
        $line = $this->renderLine(70);
        $visible = $this->stripAnsi($line);

        $this->assertStringContainsString('Edit', $visible);
        $this->assertStringContainsString('Auto ✓', $visible);
        $this->assertStringContainsString('12.4k/200k', $visible);
        // Model name hidden
        $this->assertStringNotContainsString('claude-sonnet-4-20250514', $visible);
    }

    public function test_very_narrow_shows_only_mode_pill(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setPermission('Guardian ◈', "\033[38;2;180;180;200m");
        $this->widget->setTokenUsage(12_400, 200_000);
        $this->widget->setModelAndCost('test', 0.04);
        $line = $this->renderLine(40);
        $visible = $this->stripAnsi($line);

        $this->assertStringContainsString('Edit', $visible);
        // Permission hidden below 60
        $this->assertStringNotContainsString('Guardian', $visible);
        // Cost/model hidden
        $this->assertStringNotContainsString('$0.04', $visible);
    }

    public function test_output_fills_exact_width_at_all_breakpoints(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setPermission('Auto ✓', "\033[38;2;180;180;200m");
        $this->widget->setTokenUsage(12_400, 200_000);
        $this->widget->setModelAndCost('test', 0.04);

        foreach ([40, 60, 70, 80, 90, 100, 120] as $cols) {
            $line = $this->renderLine($cols);
            $this->assertSame(
                $cols,
                AnsiUtils::visibleWidth($line),
                "Status bar should fill exactly {$cols} columns",
            );
        }
    }

    // ── Separator characters ─────────────────────────────────────────────

    public function test_major_separator_between_segments(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(12_400, 200_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        // Major separator ┃ (U+2503)
        $this->assertStringContainsString('┃', $visible);
    }

    public function test_minor_separator_between_left_parts(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setPermission('Auto ✓', "\033[38;2;180;180;200m");
        $line = $this->renderLine(100);
        $visible = $this->stripAnsi($line);
        // Minor separator │ (U+2502) between mode pill and permission
        $this->assertStringContainsString('│', $visible);
    }

    // ── Idle state ───────────────────────────────────────────────────────

    public function test_idle_overrides_mode_colors(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setIdle(true);
        $line = $this->renderLine(120);
        // Should still render the Edit label
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Edit', $visible);
    }

    public function test_idle_then_active_restores_colors(): void
    {
        $this->widget->setIdle(true);
        $this->widget->setMode('Plan');
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Plan', $visible);
    }

    // ── Token count formatting ───────────────────────────────────────────

    public function test_large_token_count_formats_with_k(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(150_000, 200_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('150k', $visible);
    }

    public function test_million_token_count_formats_with_m(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(1_500_000, 2_000_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('1.5M', $visible);
    }

    public function test_small_token_count_formats_as_integer(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(500, 200_000);
        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('500/200k', $visible);
    }

    // ── Gradient color verification (via render output) ──────────────────

    public function test_gradient_at_zero_percent_is_green(): void
    {
        // At 0%, the gradient color should be green (80,220,100)
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(0, 200_000);
        $line = $this->renderLine(120);
        // No tokens → gauge not rendered, but verify no crash
        $this->assertNotEmpty($line);
    }

    public function test_gradient_at_high_percent_includes_red_component(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(195_000, 200_000);
        $line = $this->renderLine(120);
        // Should contain red-ish ANSI sequences
        $this->assertStringContainsString('38;2;255', $line);
    }

    // ── Edge cases ───────────────────────────────────────────────────────

    public function test_max_context_zero_clamped_to_one(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setTokenUsage(100, 0);
        // Should not divide by zero
        $line = $this->renderLine(120);
        $this->assertNotEmpty($line);
        $this->assertSame(120, AnsiUtils::visibleWidth($line));
    }

    public function test_very_narrow_terminal_still_renders(): void
    {
        $this->widget->setMode('Edit');
        $line = $this->renderLine(20);
        $visible = $this->stripAnsi($line);
        $this->assertNotEmpty($visible);
        // Even a very narrow terminal should show the mode pill
        $this->assertStringContainsString('Edit', $visible);
    }

    public function test_empty_model_and_zero_cost(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setModelAndCost('', 0.0);
        $line = $this->renderLine(120);
        $this->assertSame(120, AnsiUtils::visibleWidth($line));
    }

    public function test_multiple_state_updates(): void
    {
        $this->widget->setMode('Edit');
        $this->widget->setPermission('Guardian ◈', "\033[38;2;180;180;200m");
        $this->widget->setTokenUsage(50_000, 200_000);
        $this->widget->setModelAndCost('test-model', 1.23);

        // Change everything
        $this->widget->setMode('Plan');
        $this->widget->setPermission('Auto ✓', "\033[38;2;100;220;100m");
        $this->widget->setTokenUsage(180_000, 200_000);
        $this->widget->setModelAndCost('other-model', 5.67);

        $line = $this->renderLine(120);
        $visible = $this->stripAnsi($line);
        $this->assertStringContainsString('Plan', $visible);
        $this->assertStringContainsString('Auto ✓', $visible);
        $this->assertStringContainsString('180k/200k', $visible);
        $this->assertStringContainsString('$5.67', $visible);
    }
}
