<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use KosmoKrator\UI\Tui\Widget\Table\Column;
use KosmoKrator\UI\Tui\Widget\Table\ColumnWidth\Fixed;
use KosmoKrator\UI\Tui\Widget\Table\ColumnWidth\Flex;
use KosmoKrator\UI\Tui\Widget\Table\ColumnWidth\Percentage;
use KosmoKrator\UI\Tui\Widget\Table\Row;
use KosmoKrator\UI\Tui\Widget\Table\SortDirection;
use KosmoKrator\UI\Tui\Widget\Table\SortState;
use KosmoKrator\UI\Tui\Widget\TableWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\TextAlign;

final class TableWidgetTest extends TestCase
{
    // ── Column value object ───────────────────────────────────────────────

    public function test_column_construction_with_defaults(): void
    {
        $column = new Column('name', 'Name');

        $this->assertSame('name', $column->key);
        $this->assertSame('Name', $column->label);
        $this->assertInstanceOf(Flex::class, $column->width);
        $this->assertSame(TextAlign::Left, $column->align);
        $this->assertTrue($column->sortable);
        $this->assertNull($column->formatter);
    }

    public function test_column_custom_align_sortable_formatter_width(): void
    {
        $formatter = fn ($v): string => strtoupper((string) $v);
        $column = new Column(
            key: 'price',
            label: 'Price',
            width: new Fixed(10),
            align: TextAlign::Right,
            sortable: false,
            formatter: $formatter,
        );

        $this->assertSame('price', $column->key);
        $this->assertSame('Price', $column->label);
        $this->assertInstanceOf(Fixed::class, $column->width);
        $this->assertSame(10, $column->width->chars);
        $this->assertSame(TextAlign::Right, $column->align);
        $this->assertFalse($column->sortable);
        $this->assertSame($formatter, $column->formatter);
    }

    public function test_column_with_percentage_width(): void
    {
        $column = new Column('desc', 'Description', width: new Percentage(50));

        $this->assertInstanceOf(Percentage::class, $column->width);
        $this->assertSame(50, $column->width->percent);
    }

    // ── Row value object ──────────────────────────────────────────────────

    public function test_row_construction_and_get(): void
    {
        $row = new Row(['name' => 'Alice', 'age' => 30], id: 'r1');

        $this->assertSame('Alice', $row->get('name'));
        $this->assertSame(30, $row->get('age'));
        $this->assertSame('r1', $row->id);
    }

    public function test_row_get_returns_null_for_missing_key(): void
    {
        $row = new Row(['name' => 'Alice']);

        $this->assertNull($row->get('nonexistent'));
    }

    public function test_row_from_values_static_factory(): void
    {
        $row = Row::fromValues(['Alice', 30], ['name', 'age'], id: 'r1');

        $this->assertSame('Alice', $row->get('name'));
        $this->assertSame(30, $row->get('age'));
        $this->assertSame('r1', $row->id);
    }

    public function test_row_from_values_ignores_extra_values(): void
    {
        $row = Row::fromValues(['Alice', 30, 'extra'], ['name']);

        $this->assertSame('Alice', $row->get('name'));
        $this->assertNull($row->get('age'));
    }

    public function test_row_default_id_is_null(): void
    {
        $row = new Row(['name' => 'Alice']);

        $this->assertNull($row->id);
    }

    public function test_row_style_classes_default_empty(): void
    {
        $row = new Row(['name' => 'Alice']);

        $this->assertSame([], $row->styleClasses);
    }

    // ── SortState ─────────────────────────────────────────────────────────

    public function test_sort_state_toggle_flips_direction(): void
    {
        $ascending = new SortState('name', SortDirection::Ascending);
        $descending = $ascending->toggle();

        $this->assertSame(SortDirection::Descending, $descending->direction);
        $this->assertSame('name', $descending->columnKey);
    }

    public function test_sort_state_toggle_round_trip(): void
    {
        $original = new SortState('name', SortDirection::Ascending);
        $roundTrip = $original->toggle()->toggle();

        $this->assertSame(SortDirection::Ascending, $roundTrip->direction);
    }

    public function test_sort_state_with_column_toggles_same_column(): void
    {
        $state = new SortState('name', SortDirection::Ascending);
        $toggled = $state->withColumn('name');

        $this->assertSame('name', $toggled->columnKey);
        $this->assertSame(SortDirection::Descending, $toggled->direction);
    }

    public function test_sort_state_with_column_starts_ascending_for_new(): void
    {
        $state = new SortState('name', SortDirection::Descending);
        $newState = $state->withColumn('age');

        $this->assertSame('age', $newState->columnKey);
        $this->assertSame(SortDirection::Ascending, $newState->direction);
    }

    // ── TableWidget with empty data ───────────────────────────────────────

    public function test_render_returns_empty_with_no_columns(): void
    {
        $widget = new TableWidget();
        $context = new RenderContext(80, 24);

        $this->assertSame([], $widget->render($context));
    }

    public function test_get_selected_row_returns_null_when_empty(): void
    {
        $widget = new TableWidget(
            columns: [new Column('name', 'Name')],
            rows: [],
        );

        $this->assertNull($widget->getSelectedRow());
    }

    // ── TableWidget with single row ───────────────────────────────────────

    public function test_render_single_row_produces_header_separator_body_hint(): void
    {
        $widget = new TableWidget(
            columns: [new Column('name', 'Name')],
            rows: [new Row(['name' => 'Alice'])],
            maxVisible: 10,
        );

        $context = new RenderContext(80, 24);
        $lines = $widget->render($context);

        // Expected: header + separator + 1 body row + 9 blank pad rows + hint = 13
        $this->assertCount(13, $lines);

        // First line is header
        $this->assertStringContainsString('Name', $lines[0]);
        // Second line is separator
        $this->assertStringContainsString('─', $lines[1]);
        // Third line is body (contains Alice)
        $this->assertStringContainsString('Alice', $lines[2]);
        // Last line is hint
        $lastLine = $lines[array_key_last($lines)];
        $this->assertStringContainsString('Navigate', $lastLine);
    }

    public function test_selected_row_is_the_only_row(): void
    {
        $row = new Row(['name' => 'Alice'], id: 'r1');
        $widget = new TableWidget(
            columns: [new Column('name', 'Name')],
            rows: [$row],
        );

        $selected = $widget->getSelectedRow();
        $this->assertNotNull($selected);
        $this->assertSame('Alice', $selected->get('name'));
        $this->assertSame('r1', $selected->id);
    }

    // ── TableWidget with multiple rows ────────────────────────────────────

    public function test_render_multiple_rows_correct_count(): void
    {
        $widget = $this->createMultiRowWidget(5);
        $context = new RenderContext(80, 24);
        $lines = $widget->render($context);

        // header + separator + 5 body rows + 5 blank pad rows + hint = 13
        $this->assertCount(13, $lines);
    }

    public function test_first_row_selected_by_default(): void
    {
        $widget = $this->createMultiRowWidget(3);

        $this->assertSame(0, $widget->getSelectedIndex());
        $selected = $widget->getSelectedRow();
        $this->assertNotNull($selected);
        $this->assertSame('Alice', $selected->get('name'));
    }

    public function test_set_selected_index_changes_selection(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->setSelectedIndex(2);

        $this->assertSame(2, $widget->getSelectedIndex());
        $selected = $widget->getSelectedRow();
        $this->assertNotNull($selected);
        $this->assertSame('Charlie', $selected->get('name'));
    }

    public function test_set_selected_index_clamps_to_valid_range(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->setSelectedIndex(100);

        $this->assertSame(2, $widget->getSelectedIndex());
    }

    public function test_set_selected_index_clamps_negative_to_zero(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->setSelectedIndex(-5);

        $this->assertSame(0, $widget->getSelectedIndex());
    }

    public function test_handle_input_down_moves_selection(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->handleInput("\x1b[B"); // down arrow

        $this->assertSame(1, $widget->getSelectedIndex());
    }

    public function test_handle_input_up_moves_selection_back(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[A"); // up

        $this->assertSame(1, $widget->getSelectedIndex());
    }

    public function test_handle_input_up_at_top_stays_at_zero(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->handleInput("\x1b[A"); // up arrow

        $this->assertSame(0, $widget->getSelectedIndex());
    }

    public function test_handle_input_down_at_bottom_stays_at_last(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->setSelectedIndex(2);
        $widget->handleInput("\x1b[B"); // down arrow

        $this->assertSame(2, $widget->getSelectedIndex());
    }

    public function test_handle_input_home_jumps_to_first(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->setSelectedIndex(2);
        $widget->handleInput("\x1b[H"); // home

        $this->assertSame(0, $widget->getSelectedIndex());
    }

    public function test_handle_input_end_jumps_to_last(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->handleInput("\x1b[F"); // end

        $this->assertSame(2, $widget->getSelectedIndex());
    }

    // ── Sorting ───────────────────────────────────────────────────────────

    public function test_cycle_sort_via_handle_input_s(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->handleInput('s');

        $sortState = $widget->getSortState();
        $this->assertNotNull($sortState);
        $this->assertSame('name', $sortState->columnKey);
        $this->assertSame(SortDirection::Ascending, $sortState->direction);
    }

    public function test_cycle_sort_toggles_direction_on_second_press(): void
    {
        $widget = $this->createMultiRowWidget(3);
        $widget->handleInput('s');
        $widget->handleInput('s');

        $sortState = $widget->getSortState();
        $this->assertNotNull($sortState);
        $this->assertSame(SortDirection::Descending, $sortState->direction);
    }

    public function test_sort_by_column_via_digit_shortcut(): void
    {
        $widget = $this->createMultiRowWidgetWithTwoSortableColumns();
        $widget->handleInput('2');

        $sortState = $widget->getSortState();
        $this->assertNotNull($sortState);
        $this->assertSame('age', $sortState->columnKey);
        $this->assertSame(SortDirection::Ascending, $sortState->direction);
    }

    public function test_sort_by_column_digit_toggles_same_column(): void
    {
        $widget = $this->createMultiRowWidgetWithTwoSortableColumns();
        $widget->handleInput('1'); // Sort by name ascending
        $widget->handleInput('1'); // Toggle to descending

        $sortState = $widget->getSortState();
        $this->assertNotNull($sortState);
        $this->assertSame(SortDirection::Descending, $sortState->direction);
    }

    public function test_get_sort_state_returns_null_initially(): void
    {
        $widget = $this->createMultiRowWidget(3);

        $this->assertNull($widget->getSortState());
    }

    public function test_sorting_reorders_rows(): void
    {
        $widget = new TableWidget(
            columns: [new Column('score', 'Score')],
            rows: [
                new Row(['score' => 30]),
                new Row(['score' => 10]),
                new Row(['score' => 20]),
            ],
        );

        $widget->handleInput('s'); // Sort ascending

        $viewRows = $widget->getViewRows();
        $this->assertSame(10, $viewRows[0]->get('score'));
        $this->assertSame(20, $viewRows[1]->get('score'));
        $this->assertSame(30, $viewRows[2]->get('score'));
    }

    public function test_sorting_descending_reorders_rows(): void
    {
        $widget = new TableWidget(
            columns: [new Column('score', 'Score')],
            rows: [
                new Row(['score' => 10]),
                new Row(['score' => 30]),
                new Row(['score' => 20]),
            ],
        );

        $widget->handleInput('s'); // Ascending
        $widget->handleInput('s'); // Descending

        $viewRows = $widget->getViewRows();
        $this->assertSame(30, $viewRows[0]->get('score'));
        $this->assertSame(20, $viewRows[1]->get('score'));
        $this->assertSame(10, $viewRows[2]->get('score'));
    }

    // ── Render at 80 cols ─────────────────────────────────────────────────

    public function test_render_output_lines_within_80_columns(): void
    {
        $widget = new TableWidget(
            columns: [
                new Column('name', 'Name'),
                new Column('provider', 'Provider'),
                new Column('context', 'Context'),
                new Column('cost', 'Cost'),
            ],
            rows: $this->createSampleRows(),
            maxVisible: 10,
        );

        $context = new RenderContext(80, 24);
        $lines = $widget->render($context);

        foreach ($lines as $i => $line) {
            // Strip ANSI escape sequences for visible width measurement
            $visible = preg_replace('/\033\[[0-9;]*m/', '', $line);
            $this->assertLessThanOrEqual(
                80,
                mb_strlen($visible),
                "Line {$i} exceeds 80 columns: " . $visible,
            );
        }
    }

    public function test_render_with_long_values_stays_within_width(): void
    {
        $widget = new TableWidget(
            columns: [new Column('description', 'Description')],
            rows: [new Row(['description' => str_repeat('x', 200)])],
            maxVisible: 5,
        );

        $context = new RenderContext(80, 24);
        $lines = $widget->render($context);

        foreach ($lines as $i => $line) {
            $visible = preg_replace('/\033\[[0-9;]*m/', '', $line);
            $this->assertLessThanOrEqual(
                80,
                mb_strlen($visible),
                "Line {$i} exceeds 80 columns with long content",
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Create a widget with 3 rows: Alice (30), Bob (25), Charlie (35).
     */
    private function createMultiRowWidget(int $rowCount): TableWidget
    {
        $allRows = [
            new Row(['name' => 'Alice', 'age' => 30]),
            new Row(['name' => 'Bob', 'age' => 25]),
            new Row(['name' => 'Charlie', 'age' => 35]),
        ];

        return new TableWidget(
            columns: [new Column('name', 'Name')],
            rows: array_slice($allRows, 0, $rowCount),
        );
    }

    private function createMultiRowWidgetWithTwoSortableColumns(): TableWidget
    {
        return new TableWidget(
            columns: [
                new Column('name', 'Name'),
                new Column('age', 'Age'),
            ],
            rows: [
                new Row(['name' => 'Alice', 'age' => 30]),
                new Row(['name' => 'Bob', 'age' => 25]),
                new Row(['name' => 'Charlie', 'age' => 35]),
            ],
        );
    }

    /**
     * @return list<Row>
     */
    private function createSampleRows(): array
    {
        return [
            new Row(['name' => 'claude-3.5', 'provider' => 'Anthropic', 'context' => '200k', 'cost' => '$3/$15']),
            new Row(['name' => 'gpt-4o', 'provider' => 'OpenAI', 'context' => '128k', 'cost' => '$5/$15']),
            new Row(['name' => 'gemini-2', 'provider' => 'Google', 'context' => '1M', 'cost' => '$1.25/$5']),
            new Row(['name' => 'llama-3', 'provider' => 'Meta', 'context' => '8k', 'cost' => 'Free']),
            new Row(['name' => 'mistral-large', 'provider' => 'Mistral', 'context' => '32k', 'cost' => '$2/$6']),
        ];
    }
}
