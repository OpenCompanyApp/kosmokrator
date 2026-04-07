<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\CommandPaletteWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class CommandPaletteWidgetTest extends TestCase
{
    // ── Fuzzy matching scoring ──────────────────────────────────────────

    public function test_fuzzy_score_empty_query_returns_positive(): void
    {
        $score = CommandPaletteWidget::fuzzyScore('', '/edit');
        $this->assertSame(1, $score);
    }

    public function test_fuzzy_score_exact_prefix_match(): void
    {
        $score = CommandPaletteWidget::fuzzyScore('edit', '/edit');
        // 'e' at word boundary (+1+3=4), 'd' consecutive (+1+2=3), 'i' consecutive (+1+2=3), 't' consecutive (+1+2=3) = 13
        $this->assertSame(13, $score);
    }

    public function test_fuzzy_score_partial_match(): void
    {
        $score = CommandPaletteWidget::fuzzyScore('comp', '/compact');
        // 'c' at word boundary (+1+3=4), 'o' consecutive (+1+2=3), 'm' consecutive (+1+2=3), 'p' consecutive (+1+2=3) = 13
        $this->assertSame(13, $score);
    }

    public function test_fuzzy_score_no_match_returns_zero(): void
    {
        $score = CommandPaletteWidget::fuzzyScore('xyz', '/edit');
        $this->assertSame(0, $score);
    }

    public function test_fuzzy_score_case_insensitive(): void
    {
        $scoreLower = CommandPaletteWidget::fuzzyScore('edit', '/EDIT');
        $scoreUpper = CommandPaletteWidget::fuzzyScore('EDIT', '/edit');
        $this->assertSame($scoreLower, $scoreUpper);
        $this->assertGreaterThan(0, $scoreLower);
    }

    public function test_fuzzy_score_consecutive_bonus(): void
    {
        $consecutive = CommandPaletteWidget::fuzzyScore('comp', '/compact');
        $scattered = CommandPaletteWidget::fuzzyScore('cmpt', '/compact');
        // Consecutive characters should score higher than scattered ones
        $this->assertGreaterThan($scattered, $consecutive);
    }

    public function test_fuzzy_score_word_boundary_bonus(): void
    {
        // 'c' at word boundary (after '/') should score higher than 'c' in the middle
        $boundaryScore = CommandPaletteWidget::fuzzyScore('c', '/compact');
        $this->assertGreaterThan(1, $boundaryScore);
    }

    public function test_fuzzy_score_description_also_matched(): void
    {
        $score = CommandPaletteWidget::fuzzyScore('switch mode', '/edit Switch to edit mode');
        $this->assertGreaterThan(0, $score);
    }

    // ── Show / Hide ─────────────────────────────────────────────────────

    public function test_initial_state_is_not_visible(): void
    {
        $widget = $this->createWidget();
        $this->assertFalse($widget->isVisible());
    }

    public function test_show_makes_visible(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $this->assertTrue($widget->isVisible());
    }

    public function test_hide_makes_not_visible(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $this->assertTrue($widget->isVisible());

        $widget->hide();
        $this->assertFalse($widget->isVisible());
    }

    public function test_show_resets_query(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $widget->handleInput('a');
        $widget->handleInput('b');
        $this->assertSame('ab', $widget->getQuery());

        $widget->show();
        $this->assertSame('', $widget->getQuery());
    }

    public function test_show_resets_selected_index(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[B"); // down
        $this->assertSame(2, $widget->getSelectedIndex());

        $widget->show();
        $this->assertSame(0, $widget->getSelectedIndex());
    }

    // ── Navigation ──────────────────────────────────────────────────────

    public function test_input_type_builds_query(): void
    {
        $widget = $this->createWidget();
        $widget->show();

        $widget->handleInput('e');
        $widget->handleInput('d');
        $this->assertSame('ed', $widget->getQuery());
    }

    public function test_backspace_removes_last_char(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $widget->handleInput('a');
        $widget->handleInput('b');
        $widget->handleInput("\x7f");

        $this->assertSame('a', $widget->getQuery());
    }

    public function test_backspace_on_empty_query_is_noop(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $widget->handleInput("\x7f");

        $this->assertSame('', $widget->getQuery());
    }

    public function test_ctrl_u_clears_query(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $widget->handleInput('a');
        $widget->handleInput('b');
        $widget->handleInput("\x15"); // Ctrl+U

        $this->assertSame('', $widget->getQuery());
    }

    public function test_down_arrow_increments_index(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();

        $widget->handleInput("\x1b[B"); // down
        $this->assertSame(1, $widget->getSelectedIndex());
    }

    public function test_up_arrow_decrements_index(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[A"); // up

        $this->assertSame(0, $widget->getSelectedIndex());
    }

    public function test_down_wraps_to_top(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        // There are 3 items, go down 3 times to wrap
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");

        $this->assertSame(0, $widget->getSelectedIndex());
    }

    public function test_up_wraps_to_bottom(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $widget->handleInput("\x1b[A"); // up from index 0

        $this->assertSame(2, $widget->getSelectedIndex()); // wraps to last item (3 items → index 2)
    }

    public function test_escape_hides_palette(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $widget->handleInput("\x1b"); // Escape

        $this->assertFalse($widget->isVisible());
    }

    public function test_ctrl_c_hides_palette(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $widget->handleInput("\x03"); // Ctrl+C

        $this->assertFalse($widget->isVisible());
    }

    public function test_enter_hides_palette(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $widget->handleInput("\n");

        $this->assertFalse($widget->isVisible());
    }

    public function test_enter_executes_callback(): void
    {
        $executed = null;
        $widget = $this->createWidgetWithItems();
        $widget->onExecute(function (string $action) use (&$executed): void {
            $executed = $action;
        });
        $widget->show();
        $widget->handleInput("\n");

        $this->assertSame('/edit', $executed);
    }

    public function test_enter_executes_selected_item_after_navigation(): void
    {
        $executed = null;
        $widget = $this->createWidgetWithItems();
        $widget->onExecute(function (string $action) use (&$executed): void {
            $executed = $action;
        });
        $widget->show();
        $widget->handleInput("\x1b[B"); // down to index 1
        $widget->handleInput("\n");

        $this->assertSame('/plan', $executed);
    }

    public function test_handle_input_returns_false_when_not_visible(): void
    {
        $widget = $this->createWidget();
        $result = $widget->handleInput('a');

        $this->assertFalse($result);
    }

    public function test_handle_input_returns_true_when_visible(): void
    {
        $widget = $this->createWidget();
        $widget->show();
        $result = $widget->handleInput('a');

        $this->assertTrue($result);
    }

    // ── Filtering ───────────────────────────────────────────────────────

    public function test_typing_filters_items(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();

        // Type "edi" which should match /edit
        $widget->handleInput('e');
        $widget->handleInput('d');
        $widget->handleInput('i');

        $filtered = $widget->getFilteredItems();
        $labels = array_map(fn(array $item): string => $item['label'], $filtered);

        $this->assertContains('/edit', $labels);
    }

    // ── Render ──────────────────────────────────────────────────────────

    public function test_render_returns_empty_when_not_visible(): void
    {
        $widget = $this->createWidget();
        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertSame([], $lines);
    }

    public function test_render_returns_bordered_output_when_visible(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);

        // First line should be a top border
        $this->assertStringContainsString('┌', $lines[0]);
        // Last line should be a bottom border
        $this->assertStringContainsString('└', $lines[count($lines) - 1]);
    }

    public function test_render_shows_search_prompt(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $lines = $widget->render(new RenderContext(80, 24));

        // Find the search line (inside border, it's index 1)
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, '>')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected search prompt ">" in render output');
    }

    public function test_render_shows_query_text(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $widget->handleInput('e');
        $widget->handleInput('d');
        $lines = $widget->render(new RenderContext(80, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'ed')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected query "ed" in render output');
    }

    public function test_render_shows_no_matching_when_empty_results(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $widget->handleInput('z');
        $widget->handleInput('z');
        $widget->handleInput('z');
        $lines = $widget->render(new RenderContext(80, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'No matching')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected "No matching commands" for empty results');
    }

    public function test_render_shows_help_line(): void
    {
        $widget = $this->createWidgetWithItems();
        $widget->show();
        $lines = $widget->render(new RenderContext(80, 24));

        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'navigate') && str_contains($line, 'select')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected help line with navigation instructions');
    }

    // ── Helper methods ──────────────────────────────────────────────────

    private function createWidget(): CommandPaletteWidget
    {
        return new CommandPaletteWidget();
    }

    private function createWidgetWithItems(): CommandPaletteWidget
    {
        $widget = new CommandPaletteWidget();
        $widget->setItems([
            ['label' => '/edit', 'description' => 'Switch to edit mode', 'category' => 'Modes', 'action' => '/edit'],
            ['label' => '/plan', 'description' => 'Switch to plan mode', 'category' => 'Modes', 'action' => '/plan'],
            ['label' => '/ask', 'description' => 'Switch to ask mode', 'category' => 'Modes', 'action' => '/ask'],
        ]);

        return $widget;
    }
}
