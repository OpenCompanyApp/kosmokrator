# TableWidget — Implementation Plan

> **File**: `src/UI/Tui/Widget/TableWidget.php` + supporting value objects
> **Depends on**: FocusManager (Symfony TUI), KosmokratorStyleSheet, AnsiUtils
> **Blocks**: Settings workspace, model picker, session list, file results, swarm dashboard

---

## 1. Problem Statement

KosmoKrator's TUI currently has no reusable interactive table component. Multiple features need structured multi-column data display with keyboard navigation:

- **Settings workspace** (`SettingsWorkspaceWidget`) — uses a hand-rolled two-column layout with `renderItem()` doing manual `str_pad()` alignment.
- **Model picker** — currently a `SelectListWidget` showing only name + description; needs columns for provider, context window, cost, and speed.
- **Session list** — needs columns for date, model, token count, cost, status.
- **File results** (`DiscoveryBatchWidget`) — lists of glob/grep matches that would benefit from sortable columns (file, line, match preview).
- **Swarm dashboard** (`SwarmDashboardWidget`) — hard-codes agent rows with manual `str_pad()` alignment for type, task, progress, elapsed.

Each of these either avoids tabular display entirely or implements ad-hoc columnar layouts. A shared `TableWidget` centralizes column layout, scrolling, sorting, styling, and keyboard handling.

## 2. Research: Existing Table Implementations

### 2.1 Ratatui (Rust) — `ratatui::widgets::Table`

Key design decisions from the Ratatui source:

- **Constraint-based widths**: Each column gets a `Constraint` enum variant — `Length(n)` (fixed), `Max(n)`, `Min(n)`, `Percentage(pct)`, `Ratio(a, b)`, `Fill(n)` (flex grow). The `Layout` solver distributes remaining space after fixed columns are satisfied. This is Ratatui's most powerful layout concept.
- **Separation of widget and state**: `Table` holds columns + rows + styling. Scroll offset and selected index live in the app state. The widget is a pure renderer — it doesn't own scroll position.
- **Row highlighting**: `highlight_style` (applied to the selected row) and `highlight_symbol` (a prefix like `▶ ` shown on the selected row). The non-selected area uses the base `Row::style`.
- **Column spacing**: `column_spacing(n)` sets the gap between columns (default 1).
- **Header row**: Rendered separately with `header_style`. Optional — tables without headers are valid.
- **Row as `Line<Span>`**: Each row is a vector of `Line` objects (rich text spans), enabling per-cell styling.

**Lesson**: The constraint system is elegant but complex. For PHP TUI, a simpler `fixed`/`flex`/`ratio` model covers 95% of cases. Row highlighting via a style + prefix symbol is the right pattern.

### 2.2 php-tui — `TableWidget`

php-tui's table implementation (from the stalled project):

- **Widget is a pure data holder**: `TableWidget` holds `headers: Line[]`, `rows: Row[]`, `widths: Constraint[]`, `columnSpacing: int`, `headerStyle`, `rowStyle`, `highlightStyle`, `highlightSymbol`. All render logic is in a separate `TableRenderer`.
- **Constraints**: `Constraint::percentage(n)`, `Constraint::length(n)`, `Constraint::min(n)`, `Constraint::max(n)`, `Constraint::Fill(n)`. Layout solver in `TableRenderer::calculateColumnWidths()`.
- **Cell-level spans**: `Row` contains `Cell[]`, each `Cell` contains `Line[]` (rich text). Enables per-cell colors.
- **No interactivity**: No scrolling, no selection, no keyboard handling. Pure display widget.

**Lesson**: The data model (Column → Row → Cell) is clean. But lacking interactivity, it can't serve as-is. We need focusable + scrollable + sortable.

### 2.3 Textual (Python) — `DataTable`

Textual's `DataTable` widget:

- **Rich data model**: `DataTable` with columns (`Column`), rows (`Row`), and cells (`Cell`). Supports adding/removing rows and columns dynamically.
- **Cursor types**: `CellCursor` (move cell-by-cell), `RowCursor` (move row-by-row), `ColumnCursor` (move column-by-column). Configurable via `cursor_type`.
- **Sorting**: `sort()` method takes column keys + reverse flag. Uses the underlying data for sorting, not the display string.
- **Labels**: Columns have `label` (display) and `key` (identifier). Rows have `key` too.
- **Styling**: CSS-based — `datatable--cursor`, `datatable--hover`, `datatable--odd`/`datatable--even` for zebra striping, per-column and per-row styling.
- **Features**: Frozen columns, multi-select, inline editing, lazy row loading.

**Lesson**: The cursor type concept is powerful (row vs cell navigation). Column keys separate identity from display. CSS-based styling for rows/columns maps well to our stylesheet system.

### 2.4 Laravel Prompts — `DataTablePrompt`

Already in vendor (see `vendor/laravel/prompts/src/DataTablePrompt.php`):

- **P90 width calculation**: `DataTableRenderer::computeColumnWidths()` uses the 90th percentile of content widths per column to avoid outlier rows dominating column size.
- **Proportional shrink**: When total columns exceed `$maxWidth`, columns shrink proportionally.
- **Search/filter**: Built-in search mode with `/` key, customizable `filter` closure.
- **Scroll window**: Uses `Scrolling` trait with configurable `$scroll` (visible row count).
- **Box drawing**: `┌─┬─┐`, `│`, `├─┼─┤`, `└─┴─┘` borders.

**Lesson**: The P90 width heuristic is excellent for dynamic data. The box-drawing border approach is compatible with our existing `AnsiTableRenderer` and `MarkdownWidget::renderTable()`.

## 3. Current Architecture: How It Fits

### 3.1 Widget System

```
AbstractWidget (vendor/symfony/tui/.../Widget/AbstractWidget.php)
├── DirtyWidgetTrait — render caching via revision counter
├── FocusableInterface — isFocused(), setFocused(), handleInput(), getKeybindings()
│   └── FocusableTrait — $focused bool, invalidate() on change
│   └── KeybindingsTrait — getDefaultKeybindings(), onInput(), resolution chain
├── Event dispatching — on(EventClass, callback), dispatch(AbstractEvent)
│   └── SelectEvent — row selected via Enter
│   └── CancelEvent — Escape pressed
│   └── SelectionChangeEvent — highlighted row changed
└── Element styling — resolveElement(string $element): Style
                       applyElement(string $element, string $text): string
```

### 3.2 Existing Table-Like Patterns

| Widget | Column approach | Selection | Sort | Scrolling |
|--------|----------------|-----------|------|-----------|
| `SettingsListWidget` | 2-column manual `str_pad()` | Yes (cursor `→`) | No | Yes (center-keeping window) |
| `SelectListWidget` | 1-column + description | Yes (cursor) | No | Yes (wrapping) |
| `SwarmDashboardWidget` | Multi-column manual `str_pad()` | No | No | No |
| `MarkdownWidget::renderTable()` | Dynamic proportional shrink | No | No | No |
| `AnsiTableRenderer` | Box-drawing with fixed widths | No | No | No |
| Laravel `DataTablePrompt` | P90 percentile + proportional shrink | Yes | No | Yes (scroll window) |

### 3.3 Rendering Contract

From `AbstractWidget::render()`:
- Returns `string[]` — one element per terminal row
- Lines MAY contain ANSI escape sequences
- Lines MUST NOT exceed `$context->getColumns()` in visible width
- Lines MUST NOT contain newline characters
- Chrome (padding, border, background) is applied AFTER `render()` by `ChromeApplier`

### 3.4 Style System

```php
// Stylesheet entries resolve via cascade:
// * → FQCN → .class → :state → breakpoints → instance
// Elements use :: syntax:
TableWidget::class => Style::default()->withBorder(...),
TableWidget::class.'::header' => Style::default()->withBold(true),
TableWidget::class.'::row-selected' => Style::default()->withReverse(true),
```

## 4. Design

### 4.1 Value Objects

#### Column Definition

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Table;

/**
 * Defines a single column in a TableWidget.
 */
final class Column
{
    /**
     * @param string $key       Stable identifier used for sorting and data access.
     *                           Does not change with reorder. Analogous to Textual's column key.
     * @param string $label     Display text shown in the header row.
     * @param ColumnWidth $width Width constraint for this column.
     * @param TextAlign $align  Text alignment within the column (default: Left).
     * @param bool $sortable    Whether this column can be sorted (default: true).
     * @param callable(mixed): string|null $formatter Optional cell formatter. Receives the raw cell
     *                           value and returns a display string. If null, (string) cast is used.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ColumnWidth $width = new ColumnWidth\Flex(),
        public readonly \Symfony\Component\Tui\Style\TextAlign $align = \Symfony\Component\Tui\Style\TextAlign::Left,
        public readonly bool $sortable = true,
        public readonly ?callable $formatter = null,
    ) {}
}
```

#### Column Width Constraints

Inspired by Ratatui's `Constraint` enum, but simplified to the three patterns we actually need:

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Table\ColumnWidth;

/**
 * Column width strategies for TableWidget.
 *
 * Each variant is a final class under the ColumnWidth namespace.
 * Resolved during rendering by TableWidget::resolveColumnWidths().
 */
interface ColumnWidth
{
    /**
     * Given the available remaining width and the column's intrinsic (natural) width,
     * return the resolved pixel width for this column.
     */
    public function resolve(int $availableWidth, int $naturalWidth): int;
}

/**
 * Fixed-width column. Always renders at exactly N characters.
 * Use for: status icons (2ch), booleans (3ch), short codes (8ch).
 */
final class Fixed implements ColumnWidth
{
    public function __construct(
        public readonly int $chars,
    ) {}

    public function resolve(int $availableWidth, int $naturalWidth): int
    {
        return $this->chars;
    }
}

/**
 * Flex-grow column. After fixed columns are satisfied, flex columns
 * share the remaining space proportionally based on their weight.
 *
 * - flex(1) = equal share (default)
 * - flex(2) = twice as wide as flex(1)
 *
 * If the column's natural width exceeds its flex share, it uses the natural width
 * (flex is a minimum grow weight, not a maximum cap).
 */
final class Flex implements ColumnWidth
{
    public function __construct(
        public readonly int $weight = 1,
    ) {}

    public function resolve(int $availableWidth, int $naturalWidth): int
    {
        return max($naturalWidth, $availableWidth);
    }
}

/**
 * Percentage column. Takes the given percentage of the total available width.
 * Clamped to [naturalWidth, availableWidth].
 */
final class Percentage implements ColumnWidth
{
    public function __construct(
        public readonly int $percent, // 1–100
    ) {}

    public function resolve(int $availableWidth, int $naturalWidth): int
    {
        $resolved = (int) round($this->percent / 100 * $availableWidth);
        return max($naturalWidth, min($resolved, $availableWidth));
    }
}
```

#### Table Row

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Table;

/**
 * A single row in a TableWidget.
 *
 * Data is stored as an associative array keyed by column keys.
 * This decouples row data from column order — columns can be reordered
 * without touching rows.
 */
final class Row
{
    /**
     * @param array<string, mixed> $cells  Column key → cell value. Raw values; formatted
     *                                      by the Column's formatter during rendering.
     * @param string|null $id               Optional stable row identifier (for selection events,
     *                                      multi-select, etc.). If null, the row's index is used.
     * @param string[] $styleClasses        CSS-like class names for per-row styling.
     *                                      Resolved via KosmokratorStyleSheet.
     */
    public function __construct(
        public readonly array $cells,
        public readonly ?string $id = null,
        public readonly array $styleClasses = [],
    ) {}

    /**
     * Get the cell value for a given column key.
     */
    public function get(string $columnKey): mixed
    {
        return $this->cells[$columnKey] ?? null;
    }

    /**
     * Create a row from positional values, mapped to column keys.
     *
     * @param list<mixed> $values
     * @param list<string> $columnKeys
     */
    public static function fromValues(array $values, array $columnKeys, ?string $id = null): self
    {
        $cells = [];
        foreach ($values as $i => $value) {
            if (isset($columnKeys[$i])) {
                $cells[$columnKeys[$i]] = $value;
            }
        }
        return new self($cells, $id);
    }
}
```

#### Sort State

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget\Table;

/**
 * Describes the current sort state of the table.
 */
final class SortState
{
    public function __construct(
        public readonly string $columnKey,
        public readonly SortDirection $direction = SortDirection::Ascending,
    ) {}

    public function toggle(): self
    {
        return new self(
            $this->columnKey,
            $this->direction === SortDirection::Ascending
                ? SortDirection::Descending
                : SortDirection::Ascending,
        );
    }

    public function withColumn(string $columnKey): self
    {
        // If same column, toggle direction; otherwise start ascending
        if ($this->columnKey === $columnKey) {
            return $this->toggle();
        }
        return new self($columnKey, SortDirection::Ascending);
    }
}

enum SortDirection
{
    case Ascending;
    case Descending;
}
```

### 4.2 TableWidget

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\Table\Column;
use Kosmokrator\UI\Tui\Widget\Table\ColumnWidth;
use Kosmokrator\UI\Tui\Widget\Table\Row;
use Kosmokrator\UI\Tui\Widget\Table\SortState;
use Kosmokrator\UI\Tui\Widget\Table\SortDirection;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\TextAlign;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Interactive data table with column headers, scrollable rows, sorting,
 * keyboard navigation, and per-column/per-row styling via stylesheet.
 *
 * ## Layout
 *
 * ```
 *  ┌──────────────────────────────────────────────────────────┐
 *  │  Name ▲    Provider    Context    Cost     Speed         │  ← header
 *  │ ─────────────────────────────────────────────────────── │  ← separator
 *  │▶ claude-3.5  Anthropic   200k      $3/$15   ★★★★★       │  ← selected row
 *  │  gpt-4o      OpenAI      128k      $5/$15   ★★★★☆       │
 *  │  gemini-2    Google      1M        $1.25/$5 ★★★☆☆       │
 *  │  ...                                                     │
 *  │ ─────────────────────────────────────────────────────── │
 *  │ ↑↓ Navigate  Enter Select  S Sort  / Filter  Esc Back   │  ← hint
 *  └──────────────────────────────────────────────────────────┘
 * ```
 *
 * ## Styling (via KosmokratorStyleSheet)
 *
 * The widget uses `resolveElement()` / `applyElement()` for sub-element styling.
 * Pseudo-elements:
 *
 * - `header` — Column header cells
 * - `header-sorted` — The currently sorted column header (includes sort indicator)
 * - `row` — Base row cells
 * - `row-selected` — The highlighted/selected row (inverse, accent color, etc.)
 * - `row-even` / `row-odd` — Zebra striping (when enabled)
 * - `separator` — Horizontal line between header and body
 * - `hint` — Footer hint line
 * - `cursor` — Selection cursor (▶ or similar)
 *
 * ## Events
 *
 * - `SelectEvent` dispatched on Enter with the selected row's ID (or index as string).
 * - `CancelEvent` dispatched on Escape.
 * - `SelectionChangeEvent` dispatched when the highlighted row changes.
 *
 * ## Keyboard
 *
 * - ↑/↓: Move selection up/down
 * - Page Up/Page Down: Scroll by page
 * - Home/End: Jump to first/last row
 * - Enter: Select row (dispatches SelectEvent)
 * - S: Cycle sort column (header focus mode) / toggle sort direction
 * - /: Enter filter/search mode
 * - Escape: Cancel / exit filter mode
 */
final class TableWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    // ── Configuration ──────────────────────────────────────────────────

    /** @var list<Column> Column definitions in display order */
    private array $columns = [];

    /** @var list<Row> All rows (unsorted, unfiltered) */
    private array $rows = [];

    /** @var int Maximum visible rows (scroll window size) */
    private int $maxVisible = 10;

    /** @var int Gap between columns in characters */
    private int $columnSpacing = 2;

    /** @var bool Show header row */
    private bool $showHeader = true;

    /** @var bool Show separator between header and body */
    private bool $showSeparator = true;

    /** @var bool Show hint line at the bottom */
    private bool $showHint = true;

    /** @var bool Enable zebra striping */
    private bool $zebraStriping = false;

    /** @var string Symbol for the selected row cursor */
    private string $cursorSymbol = '▶ ';

    /** @var string Symbol for unselected rows */
    private string $cursorPlaceholder = '  ';

    /** @var callable(Row): bool|null Filter function for search */
    private $filter = null;

    // ── State ──────────────────────────────────────────────────────────

    /** @var int Index of the highlighted row (within filtered+sorted view) */
    private int $selectedIndex = 0;

    /** @var int Scroll offset (first visible row index in filtered+sorted view) */
    private int $scrollOffset = 0;

    /** @var SortState|null Current sort state, null = unsorted */
    private ?SortState $sortState = null;

    /** @var string|null Active search query */
    private ?string $searchQuery = null;

    /** @var bool Whether we're in search input mode */
    private bool $searchMode = false;

    // ── Cached computed data ──────────────────────────────────────────

    /** @var list<Row>|null Cached filtered + sorted rows */
    private ?array $viewRows = null;

    // ── Constructor ────────────────────────────────────────────────────

    /**
     * @param list<Column>|null $columns
     * @param list<Row>|null $rows
     */
    public function __construct(
        ?array $columns = null,
        ?array $rows = null,
        int $maxVisible = 10,
        ?Keybindings $keybindings = null,
    ) {
        if ($columns !== null) {
            $this->columns = $columns;
        }
        if ($rows !== null) {
            $this->rows = $rows;
        }
        $this->maxVisible = $maxVisible;
        if ($keybindings !== null) {
            $this->setKeybindings($keybindings);
        }
    }

    // ── Configuration Methods ──────────────────────────────────────────

    /**
     * Set column definitions.
     *
     * @param list<Column> $columns
     */
    public function setColumns(array $columns): static
    {
        $this->columns = $columns;
        $this->invalidateView();
        return $this;
    }

    /**
     * Set table rows.
     *
     * @param list<Row> $rows
     */
    public function setRows(array $rows): static
    {
        $this->rows = $rows;
        $this->invalidateView();
        return $this;
    }

    /**
     * Add a single row.
     */
    public function addRow(Row $row): static
    {
        $this->rows[] = $row;
        $this->invalidateView();
        return $this;
    }

    /**
     * Remove all rows.
     */
    public function clearRows(): static
    {
        $this->rows = [];
        $this->invalidateView();
        return $this;
    }

    public function setMaxVisible(int $maxVisible): static
    {
        $this->maxVisible = $maxVisible;
        $this->invalidate();
        return $this;
    }

    public function setColumnSpacing(int $spacing): static
    {
        $this->columnSpacing = $spacing;
        $this->invalidate();
        return $this;
    }

    public function setShowHeader(bool $show): static
    {
        $this->showHeader = $show;
        $this->invalidate();
        return $this;
    }

    public function setShowSeparator(bool $show): static
    {
        $this->showSeparator = $show;
        $this->invalidate();
        return $this;
    }

    public function setShowHint(bool $show): static
    {
        $this->showHint = $show;
        $this->invalidate();
        return $this;
    }

    public function setZebraStriping(bool $enabled): static
    {
        $this->zebraStriping = $enabled;
        $this->invalidate();
        return $this;
    }

    public function setCursorSymbol(string $symbol, string $placeholder = '  '): static
    {
        $this->cursorSymbol = $symbol;
        $this->cursorPlaceholder = $placeholder;
        $this->invalidate();
        return $this;
    }

    /**
     * Set a filter function for search mode.
     *
     * @param callable(Row, string $query): bool $filter
     */
    public function setFilter(callable $filter): static
    {
        $this->filter = $filter;
        return $this;
    }

    // ── State Accessors ────────────────────────────────────────────────

    /**
     * Get the currently selected row, or null if no rows.
     */
    public function getSelectedRow(): ?Row
    {
        $viewRows = $this->getViewRows();
        return $viewRows[$this->selectedIndex] ?? null;
    }

    /**
     * Get the selected row's ID, or null.
     */
    public function getSelectedRowId(): ?string
    {
        return $this->getSelectedRow()?->id;
    }

    /**
     * Get the current sort state.
     */
    public function getSortState(): ?SortState
    {
        return $this->sortState;
    }

    /**
     * Get all rows (unfiltered, unsorted).
     *
     * @return list<Row>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Get the filtered + sorted view rows.
     *
     * @return list<Row>
     */
    public function getViewRows(): array
    {
        if ($this->viewRows !== null) {
            return $this->viewRows;
        }

        // Start with all rows
        $rows = $this->rows;

        // Apply filter
        if ($this->searchQuery !== null && $this->searchQuery !== '') {
            if ($this->filter !== null) {
                $rows = array_filter($rows, fn(Row $r) => ($this->filter)($r, $this->searchQuery));
            } else {
                // Default filter: case-insensitive substring match across all cells
                $query = mb_strtolower($this->searchQuery);
                $rows = array_filter($rows, function (Row $r) use ($query): bool {
                    foreach ($r->cells as $value) {
                        if (str_contains(mb_strtolower((string) $value), $query)) {
                            return true;
                        }
                    }
                    return false;
                });
            }
            $rows = array_values($rows);
        }

        // Apply sort
        if ($this->sortState !== null) {
            $sortKey = $this->sortState->columnKey;
            $descending = $this->sortState->direction === SortDirection::Descending;
            usort($rows, function (Row $a, Row $b) use ($sortKey, $descending): int {
                $va = $a->get($sortKey);
                $vb = $b->get($sortKey);

                // Numeric comparison if both are numeric
                if (is_numeric($va) && is_numeric($vb)) {
                    return $descending ? $vb <=> $va : $va <=> $vb;
                }

                // String comparison
                $cmp = strcmp((string) $va, (string) $vb);
                return $descending ? -$cmp : $cmp;
            });
        }

        $this->viewRows = $rows;
        return $this->viewRows;
    }

    /**
     * Programmatically set the sort state.
     */
    public function setSortState(?SortState $state): static
    {
        $this->sortState = $state;
        $this->invalidateView();
        return $this;
    }

    /**
     * Set the selected index (0-based, within view rows).
     * Clamps to valid range.
     */
    public function setSelectedIndex(int $index): static
    {
        $total = count($this->getViewRows());
        $index = max(0, min($index, $total - 1));
        if ($index !== $this->selectedIndex) {
            $this->selectedIndex = $index;
            $this->adjustScrollOffset();
            $this->invalidate();
        }
        return $this;
    }

    // ── Event Callbacks ────────────────────────────────────────────────

    /**
     * @param callable(SelectEvent): void $callback
     */
    public function onSelect(callable $callback): static
    {
        return $this->on(SelectEvent::class, $callback);
    }

    /**
     * @param callable(CancelEvent): void $callback
     */
    public function onCancel(callable $callback): static
    {
        return $this->on(CancelEvent::class, $callback);
    }

    /**
     * @param callable(SelectionChangeEvent): void $callback
     */
    public function onSelectionChange(callable $callback): static
    {
        return $this->on(SelectionChangeEvent::class, $callback);
    }

    // ── Keybindings ────────────────────────────────────────────────────

    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => Key::UP,
            'down' => Key::DOWN,
            'page_up' => Key::PAGE_UP,
            'page_down' => Key::PAGE_DOWN,
            'home' => Key::HOME,
            'end' => Key::END,
            'confirm' => Key::ENTER,
            'cancel' => Key::ESCAPE,
        ];
    }

    // ── Input Handling ─────────────────────────────────────────────────

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }

        // Search mode: typing feeds the search query
        if ($this->searchMode) {
            $this->handleSearchInput($data);
            return;
        }

        $kb = $this->getKeybindings();
        $total = count($this->getViewRows());

        // Navigation
        if ($kb->matches($data, 'up')) {
            $this->moveSelection(-1);
            return;
        }

        if ($kb->matches($data, 'down')) {
            $this->moveSelection(1);
            return;
        }

        if ($kb->matches($data, 'page_up')) {
            $this->moveSelection(-$this->maxVisible);
            return;
        }

        if ($kb->matches($data, 'page_down')) {
            $this->moveSelection($this->maxVisible);
            return;
        }

        if ($kb->matches($data, 'home')) {
            $this->setSelectedIndex(0);
            $this->notifySelectionChange();
            return;
        }

        if ($kb->matches($data, 'end')) {
            $this->setSelectedIndex($total - 1);
            $this->notifySelectionChange();
            return;
        }

        // Confirm
        if ($kb->matches($data, 'confirm')) {
            $row = $this->getSelectedRow();
            if ($row !== null) {
                $this->dispatch(new SelectEvent($this, $row->id ?? (string) $this->selectedIndex));
            }
            return;
        }

        // Cancel
        if ($kb->matches($data, 'cancel')) {
            $this->dispatch(new CancelEvent($this));
            return;
        }

        // Sort toggle — press 's' to cycle sort through columns
        if ($data === 's' || $data === 'S') {
            $this->cycleSort();
            return;
        }

        // Sort by column shortcut — Shift+1 through Shift+9 sorts by column index
        if (strlen($data) === 1 && ctype_digit($data) && $data !== '0') {
            $colIndex = (int) $data - 1;
            if (isset($this->columns[$colIndex]) && $this->columns[$colIndex]->sortable) {
                $this->sortByColumn($this->columns[$colIndex]->key);
            }
            return;
        }

        // Enter search mode
        if ($data === '/') {
            $this->searchMode = true;
            $this->searchQuery = '';
            $this->invalidateView();
            return;
        }
    }

    // ── Rendering ──────────────────────────────────────────────────────

    /**
     * Render the table as ANSI-formatted lines.
     *
     * Output structure:
     *   1. Header row (optional)
     *   2. Separator line (optional)
     *   3. Body rows (visible window)
     *   4. Hint line (optional)
     *
     * If in search mode, replaces the hint line with a search input line.
     *
     * @param RenderContext $context Terminal dimensions
     * @return list<string> ANSI-formatted lines
     */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $viewRows = $this->getViewRows();
        $total = count($viewRows);

        if (empty($this->columns)) {
            return [];
        }

        // Resolve column widths
        $resolvedWidths = $this->resolveColumnWidths($columns, $viewRows);

        $lines = [];

        // 1. Header row
        if ($this->showHeader) {
            $lines[] = $this->renderHeader($resolvedWidths, $columns);
        }

        // 2. Separator
        if ($this->showSeparator) {
            $lines[] = $this->renderSeparator($resolvedWidths);
        }

        // 3. Body rows
        $visibleStart = $this->scrollOffset;
        $visibleEnd = min($visibleStart + $this->maxVisible, $total);

        for ($i = $visibleStart; $i < $visibleEnd; ++$i) {
            $row = $viewRows[$i];
            $isSelected = $i === $this->selectedIndex;
            $visualIndex = $i - $visibleStart; // for zebra striping

            $lines[] = $this->renderRow($row, $resolvedWidths, $isSelected, $visualIndex);
        }

        // Pad remaining visible rows with blanks
        $renderedRows = $visibleEnd - $visibleStart;
        for ($i = $renderedRows; $i < $this->maxVisible; ++$i) {
            $lines[] = '';
        }

        // 4. Hint / Search line
        if ($this->showHint || $this->searchMode) {
            $lines[] = $this->renderFooter($columns, $total);
        }

        // Truncate each line to terminal width
        return array_map(
            fn(string $line) => AnsiUtils::truncateToWidth($line, $columns),
            $lines,
        );
    }

    // ── Column Width Resolution ────────────────────────────────────────

    /**
     * Resolve column widths based on constraints, header labels, and content.
     *
     * Algorithm:
     * 1. Calculate natural width per column = max(header label width, max cell content width).
     *    For very large datasets, use P90 percentile instead of max (Laravel's approach).
     * 2. Calculate cursor width prefix.
     * 3. Calculate total spacing (column_spacing * (num_columns - 1)).
     * 4. Distribute width:
     *    a. Fixed columns get their exact width.
     *    b. Remaining width is divided among flex/percentage columns.
     * 5. If total exceeds available width, shrink flex columns proportionally.
     *
     * @param int $totalWidth Available terminal width
     * @param list<Row> $viewRows Filtered + sorted rows
     * @return list<int> Resolved width per column (in display order)
     */
    private function resolveColumnWidths(int $totalWidth, array $viewRows): array
    {
        $cursorWidth = max(
            AnsiUtils::visibleWidth($this->cursorSymbol),
            AnsiUtils::visibleWidth($this->cursorPlaceholder),
        );
        $spacing = $this->columnSpacing * max(0, count($this->columns) - 1);
        $availableWidth = $totalWidth - $cursorWidth - $spacing;

        // Step 1: Calculate natural widths
        $naturalWidths = [];
        foreach ($this->columns as $i => $column) {
            $naturalWidth = mb_strlen($column->label);

            // Sample up to 100 rows for natural width calculation (P90 approach)
            $sampleSize = min(count($viewRows), 100);
            $cellWidths = [];
            for ($r = 0; $r < $sampleSize; ++$r) {
                $rawValue = $viewRows[$r]->get($column->key);
                $formatted = $this->formatCell($column, $rawValue);
                $cellWidths[] = mb_strlen($formatted);
            }

            if (!empty($cellWidths)) {
                sort($cellWidths);
                $p90Index = (int) floor(0.9 * count($cellWidths));
                $p90Index = min($p90Index, count($cellWidths) - 1);
                $naturalWidth = max($naturalWidth, $cellWidths[$p90Index]);
            }

            $naturalWidths[$i] = $naturalWidth;
        }

        // Step 2: Satisfy fixed columns first
        $resolvedWidths = array_fill(0, count($this->columns), 0);
        $usedByFixed = 0;
        $flexColumns = [];
        $flexWeightTotal = 0;

        foreach ($this->columns as $i => $column) {
            if ($column->width instanceof ColumnWidth\Fixed) {
                $resolvedWidths[$i] = $column->width->chars;
                $usedByFixed += $column->width->chars;
            } else {
                $flexColumns[$i] = $column;
                $flexWeightTotal += ($column->width instanceof ColumnWidth\Flex)
                    ? $column->width->weight
                    : 1;
            }
        }

        // Step 3: Distribute remaining space to flex/percentage columns
        $remainingWidth = $availableWidth - $usedByFixed;

        if (!empty($flexColumns) && $remainingWidth > 0) {
            $allocated = 0;
            $lastFlexIndex = array_key_last($flexColumns);

            foreach ($flexColumns as $i => $column) {
                if ($i === $lastFlexIndex) {
                    // Last column gets the remainder to avoid rounding gaps
                    $resolvedWidths[$i] = max(1, $remainingWidth - $allocated);
                } elseif ($column->width instanceof ColumnWidth\Percentage) {
                    $share = (int) round($column->width->percent / 100 * $remainingWidth);
                    $share = max(1, min($share, $remainingWidth - $allocated));
                    $resolvedWidths[$i] = $share;
                    $allocated += $share;
                } else {
                    // Flex: proportional to weight
                    $weight = ($column->width instanceof ColumnWidth\Flex)
                        ? $column->width->weight
                        : 1;
                    $share = (int) round($remainingWidth * $weight / $flexWeightTotal);
                    $share = max($naturalWidths[$i], min($share, $remainingWidth - $allocated));
                    $resolvedWidths[$i] = $share;
                    $allocated += $share;
                }
            }
        } else {
            // No flex columns or no remaining space — use natural widths, shrunk proportionally
            $totalNatural = array_sum($naturalWidths);
            if ($totalNatural > 0 && $totalNatural > $availableWidth) {
                $shrinkRatio = $availableWidth / $totalNatural;
                foreach ($this->columns as $i => $column) {
                    $resolvedWidths[$i] = max(1, (int) floor($naturalWidths[$i] * $shrinkRatio));
                }
            } else {
                foreach ($this->columns as $i => $column) {
                    $resolvedWidths[$i] = $naturalWidths[$i];
                }
            }
        }

        return $resolvedWidths;
    }

    // ── Render Helpers ─────────────────────────────────────────────────

    /**
     * Render the header row with sort indicators.
     *
     * @param list<int> $widths
     * @return string
     */
    private function renderHeader(array $widths, int $totalColumns): string
    {
        $parts = [];

        foreach ($this->columns as $i => $column) {
            $label = $column->label;

            // Add sort indicator
            if ($this->sortState !== null && $this->sortState->columnKey === $column->key) {
                $indicator = $this->sortState->direction === SortDirection::Ascending ? ' ▲' : ' ▼';
                $label .= $indicator;
                $cell = $this->applyElement('header-sorted', $this->padAlign($label, $widths[$i], $column->align));
            } else {
                $cell = $this->applyElement('header', $this->padAlign($label, $widths[$i], $column->align));
            }

            $parts[] = $cell;
        }

        $header = implode(str_repeat(' ', $this->columnSpacing), $parts);

        // Strip ANSI for visible width measurement, then pad/truncate
        $result = $this->cursorPlaceholder . str_repeat(' ', $this->columnSpacing) . $header;

        return $result;
    }

    /**
     * Render the separator line between header and body.
     *
     * @param list<int> $widths
     */
    private function renderSeparator(array $widths): string
    {
        $parts = [];
        foreach ($widths as $i => $width) {
            $parts[] = str_repeat('─', $width);
        }

        $separator = implode(str_repeat(' ', $this->columnSpacing), $parts);
        $cursorW = max(
            AnsiUtils::visibleWidth($this->cursorSymbol),
            AnsiUtils::visibleWidth($this->cursorPlaceholder),
        );

        return $this->applyElement('separator', str_repeat(' ', $cursorW) . str_repeat(' ', $this->columnSpacing) . $separator);
    }

    /**
     * Render a single body row.
     *
     * @param list<int> $widths
     */
    private function renderRow(Row $row, array $widths, bool $isSelected, int $visualIndex): string
    {
        // Apply row-level style classes
        foreach ($row->styleClasses as $class) {
            $this->addStyleClass($class);
        }

        // Determine element name based on state
        if ($isSelected) {
            $cellElement = 'row-selected';
        } elseif ($this->zebraStriping && $visualIndex % 2 === 0) {
            $cellElement = 'row-even';
        } elseif ($this->zebraStriping) {
            $cellElement = 'row-odd';
        } else {
            $cellElement = 'row';
        }

        $cursor = $isSelected
            ? $this->applyElement('cursor', $this->cursorSymbol)
            : $this->cursorPlaceholder;

        $parts = [];
        foreach ($this->columns as $i => $column) {
            $rawValue = $row->get($column->key);
            $formatted = $this->formatCell($column, $rawValue);
            $padded = $this->padAlign($formatted, $widths[$i], $column->align);

            // Truncate if content exceeds column width
            if (AnsiUtils::visibleWidth($padded) > $widths[$i]) {
                $padded = AnsiUtils::truncateToWidth($padded, $widths[$i], '…');
            }

            $parts[] = $this->applyElement($cellElement, $padded);
        }

        $body = implode(str_repeat(' ', $this->columnSpacing), $parts);

        // Remove temporary style classes
        foreach ($row->styleClasses as $class) {
            $this->removeStyleClass($class);
        }

        return $cursor . str_repeat(' ', $this->columnSpacing) . $body;
    }

    /**
     * Render the footer hint or search input line.
     */
    private function renderFooter(int $totalWidth, int $totalRows): string
    {
        if ($this->searchMode) {
            $query = $this->searchQuery ?? '';
            $prompt = "/{$query}█";
            $count = " {$totalRows} results";
            $padding = max(1, $totalWidth - mb_strlen($prompt) - mb_strlen($count));
            return $this->applyElement('hint', $prompt . str_repeat(' ', $padding) . $count);
        }

        $hint = '↑↓ Navigate  Enter Select  S Sort  / Filter  Esc Back';
        if ($totalRows > $this->maxVisible) {
            $hint .= "  {$totalRows} rows";
        }
        return $this->applyElement('hint', $hint);
    }

    // ── Utility Methods ────────────────────────────────────────────────

    /**
     * Format a cell value using the column's formatter.
     */
    private function formatCell(Column $column, mixed $value): string
    {
        if ($column->formatter !== null) {
            return ($column->formatter)($value);
        }
        return (string) ($value ?? '');
    }

    /**
     * Pad and align a string to a given width.
     */
    private function padAlign(string $text, int $width, TextAlign $align): string
    {
        $visibleWidth = AnsiUtils::visibleWidth($text);

        if ($visibleWidth >= $width) {
            return $text;
        }

        $gap = $width - $visibleWidth;

        return match ($align) {
            TextAlign::Left => $text . str_repeat(' ', $gap),
            TextAlign::Right => str_repeat(' ', $gap) . $text,
            TextAlign::Center => str_repeat(' ', (int) floor($gap / 2)) . $text . str_repeat(' ', (int) ceil($gap / 2)),
        };
    }

    /**
     * Move selection by a delta, adjusting scroll offset.
     */
    private function moveSelection(int $delta): void
    {
        $total = count($this->getViewRows());
        if ($total === 0) {
            return;
        }

        $oldIndex = $this->selectedIndex;
        $this->selectedIndex = max(0, min($this->selectedIndex + $delta, $total - 1));

        if ($oldIndex !== $this->selectedIndex) {
            $this->adjustScrollOffset();
            $this->notifySelectionChange();
            $this->invalidate();
        }
    }

    /**
     * Adjust scroll offset to keep the selected row visible.
     */
    private function adjustScrollOffset(): void
    {
        // Ensure selected row is within the visible window
        if ($this->selectedIndex < $this->scrollOffset) {
            $this->scrollOffset = $this->selectedIndex;
        } elseif ($this->selectedIndex >= $this->scrollOffset + $this->maxVisible) {
            $this->scrollOffset = $this->selectedIndex - $this->maxVisible + 1;
        }

        // Clamp
        $total = count($this->getViewRows());
        $maxOffset = max(0, $total - $this->maxVisible);
        $this->scrollOffset = max(0, min($this->scrollOffset, $maxOffset));
    }

    /**
     * Cycle sort through sortable columns.
     *
     * If current sort is on column X, toggle direction. If toggled back to unsorted,
     * advance to the next sortable column.
     */
    private function cycleSort(): void
    {
        $sortableColumns = array_filter($this->columns, fn(Column $c) => $c->sortable);
        if (empty($sortableColumns)) {
            return;
        }

        if ($this->sortState === null) {
            // Start sorting by the first sortable column
            $first = reset($sortableColumns);
            $this->sortState = new SortState($first->key, SortDirection::Ascending);
        } else {
            // Find current column in sortable list
            $keys = array_map(fn(Column $c) => $c->key, $sortableColumns);
            $currentPos = array_search($this->sortState->columnKey, $keys, true);

            if ($currentPos === false) {
                // Current sort column was removed; start fresh
                $first = reset($sortableColumns);
                $this->sortState = new SortState($first->key, SortDirection::Ascending);
            } elseif ($this->sortState->direction === SortDirection::Ascending) {
                // Toggle to descending
                $this->sortState = new SortState($this->sortState->columnKey, SortDirection::Descending);
            } else {
                // Advance to next sortable column (or clear sort)
                $nextPos = $currentPos + 1;
                if (isset($keys[$nextPos])) {
                    $this->sortState = new SortState($keys[$nextPos], SortDirection::Ascending);
                } else {
                    // Wrapped around; back to first or clear
                    $this->sortState = null;
                }
            }
        }

        $this->invalidateView();
    }

    /**
     * Sort by a specific column key. Toggles direction if already sorted by this column.
     */
    private function sortByColumn(string $columnKey): void
    {
        if ($this->sortState !== null && $this->sortState->columnKey === $columnKey) {
            $this->sortState = $this->sortState->toggle();
        } else {
            $this->sortState = new SortState($columnKey, SortDirection::Ascending);
        }
        $this->invalidateView();
    }

    /**
     * Handle input during search mode.
     */
    private function handleSearchInput(string $data): void
    {
        // Escape exits search mode, keeping filter
        if ($data === Key::ESCAPE || $data === "\x1b") {
            $this->searchMode = false;
            $this->invalidate();
            return;
        }

        // Enter confirms search, exits mode
        if ($data === Key::ENTER || $data === "\n" || $data === "\r") {
            $this->searchMode = false;
            $this->selectedIndex = 0;
            $this->scrollOffset = 0;
            $this->invalidate();
            return;
        }

        // Backspace removes last character
        if ($data === Key::BACKSPACE || $data === "\x7f") {
            if ($this->searchQuery !== null && $this->searchQuery !== '') {
                $this->searchQuery = mb_substr($this->searchQuery, 0, -1);
                $this->invalidateView();
            }
            return;
        }

        // Ctrl+U clears the query
        if ($data === "\x15") {
            $this->searchQuery = '';
            $this->invalidateView();
            return;
        }

        // Printable character: append to query
        if (strlen($data) === 1 && ctype_print($data)) {
            $this->searchQuery .= $data;
            $this->invalidateView();
            return;
        }

        // During search, also allow navigation
        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'up')) {
            $this->moveSelection(-1);
        } elseif ($kb->matches($data, 'down')) {
            $this->moveSelection(1);
        }
    }

    /**
     * Invalidate the view cache and widget render cache.
     */
    private function invalidateView(): void
    {
        $this->viewRows = null;
        $this->selectedIndex = 0;
        $this->scrollOffset = 0;
        $this->invalidate();
    }

    /**
     * Notify listeners of selection change.
     */
    private function notifySelectionChange(): void
    {
        $this->dispatch(new SelectionChangeEvent($this));
    }
}
```

### 4.3 Stylesheet Integration

Add the following rules to `KosmokratorStyleSheet`:

```php
// TableWidget base
TableWidget::class => Style::default()
    ->withPadding(Style\Padding::symmetric(1, 0)),

// Header
TableWidget::class.'::header' => Style::default()
    ->withBold(true)
    ->withDim(false),

// Sorted column header
TableWidget::class.'::header-sorted' => Style::default()
    ->withBold(true)
    ->withUnderline(true),

// Base row
TableWidget::class.'::row' => Style::default(),

// Selected row
TableWidget::class.'::row-selected' => Style::default()
    ->withReverse(true),

// Zebra striping
TableWidget::class.'::row-even' => Style::default(),
TableWidget::class.'::row-odd' => Style::default()
    ->withDim(true),

// Separator
TableWidget::class.'::separator' => Style::default()
    ->withDim(true),

// Footer hint
TableWidget::class.'::hint' => Style::default()
    ->withDim(true),

// Cursor
TableWidget::class.'::cursor' => Style::default()
    ->withColor(Color::Cyan),
```

### 4.4 Rendering Algorithm — Walkthrough

```
Input:
  columns = [
    Column("name", "Name", Flex(1), Left, sortable),
    Column("provider", "Provider", Flex(1), Left, sortable),
    Column("ctx", "Context", Fixed(8), Right, sortable),
    Column("cost", "Cost", Fixed(10), Right, sortable),
  ]
  rows = [
    Row({"name": "claude-3.5", "provider": "Anthropic", "ctx": 200000, "cost": 3.0}),
    Row({"name": "gpt-4o", "provider": "OpenAI", "ctx": 128000, "cost": 5.0}),
    Row({"name": "gemini-2", "provider": "Google", "ctx": 1000000, "cost": 1.25}),
  ]
  sortState = SortState("name", Ascending)
  selectedIndex = 0
  maxVisible = 10
  columns_available = 80

Step 1: Resolve column widths
  cursorWidth = 2 (for "▶ ")
  spacing = 2 * 3 = 6
  availableWidth = 80 - 2 - 6 = 72

  Natural widths:
    "name": max(4, P90 of [10, 6, 8]) = 10
    "provider": max(8, P90 of [9, 6, 6]) = 9
    "ctx": Fixed(8)
    "cost": Fixed(10)

  Fixed used: 8 + 10 = 18
  Remaining for flex: 72 - 18 = 54
  Flex weight total: 1 + 1 = 2
  "name" flex share: 54 * 1/2 = 27  →  max(10, 27) = 27
  "provider" flex share: 54 * 1/2 = 27  →  max(9, 27) = 27

  Resolved: [27, 27, 8, 10]

Step 2: Render header
  "Name ▲" (sorted)  →  pad to 27 left:  "Name ▲                 "
  "Provider"          →  pad to 27 left:  "Provider               "
  "Context"           →  pad to 8 right:  "  Context"
  "Cost"              →  pad to 10 right:  "      Cost"

  Header line: "  Name ▲                   Provider                 Context  Cost"

Step 3: Render separator
  "───────────────────────────  ─────────────────────────  ────────  ──────────"

Step 4: Render body rows (sorted by name ascending)
  Row 0 (selected): "▶ claude-3.5               Anthropic                200,000      $3.00"
  Row 1:            "  gemini-2                  Google                 1,000,000      $1.25"
  Row 2:            "  gpt-4o                    OpenAI                   128,000      $5.00"

Step 5: Render hint
  "↑↓ Navigate  Enter Select  S Sort  / Filter  Esc Back"

Total output: 6 lines (header + separator + 3 rows + hint)
```

## 5. Use Cases

### 5.1 Model Picker

Replace the current `SelectListWidget`-based model picker with a `TableWidget`:

```php
$columns = [
    new Column('name', 'Model', new ColumnWidth\Flex(2)),
    new Column('provider', 'Provider', new ColumnWidth\Flex(1)),
    new Column('context', 'Context', new ColumnWidth\Fixed(10), TextAlign::Right,
        formatter: fn($v) => number_format($v)),
    new Column('costIn', '$ In', new ColumnWidth\Fixed(8), TextAlign::Right,
        formatter: fn($v) => '$' . number_format($v, 2)),
    new Column('speed', 'Speed', new ColumnWidth\Fixed(6), TextAlign::Center,
        sortable: false, formatter: fn($v) => str_repeat('★', $v) . str_repeat('☆', 5 - $v)),
];

$rows = [];
foreach ($models as $model) {
    $rows[] = new Row(
        cells: ['name' => $model->id, 'provider' => $model->provider,
                'context' => $model->contextWindow, 'costIn' => $model->costPer1MIn,
                'speed' => $model->speedRating],
        id: $model->id,
    );
}

$table = new TableWidget($columns, $rows, maxVisible: 8);
$table->setId('model-picker');
$table->onSelect(function (SelectEvent $event) use ($callback) {
    $callback($event->getValue()); // model ID
});
```

### 5.2 Settings Workspace

Replace the manual two-column layout in `SettingsWorkspaceWidget`:

```php
$columns = [
    new Column('label', 'Setting', new ColumnWidth\Flex(2), sortable: false),
    new Column('value', 'Value', new ColumnWidth\Flex(1), sortable: false),
];

$rows = [];
foreach ($settings as $setting) {
    $rows[] = new Row(
        cells: ['label' => $setting->label, 'value' => $setting->currentValue],
        id: $setting->id,
    );
}

$table = new TableWidget($columns, $rows);
$table->setShowHeader(false);
$table->setShowSeparator(false);
$table->setShowHint(false);
$table->onSelect(fn(SelectEvent $e) => $this->activateSetting($e->getValue()));
```

### 5.3 Session List

```php
$columns = [
    new Column('date', 'Date', new ColumnWidth\Fixed(16)),
    new Column('model', 'Model', new ColumnWidth\Flex(1)),
    new Column('tokens', 'Tokens', new ColumnWidth\Fixed(12), TextAlign::Right,
        formatter: fn($v) => Theme::formatTokenCount($v)),
    new Column('cost', 'Cost', new ColumnWidth\Fixed(8), TextAlign::Right,
        formatter: fn($v) => Theme::formatCost($v)),
    new Column('status', 'Status', new ColumnWidth\Fixed(10), TextAlign::Center),
];

$table = new TableWidget($columns, $sessionRows, maxVisible: 15);
$table->setSortState(new SortState('date', SortDirection::Descending));
$table->setId('session-list');
```

### 5.4 File Results (Grep/Glob Output)

```php
$columns = [
    new Column('file', 'File', new ColumnWidth\Flex(2)),
    new Column('line', 'Line', new ColumnWidth\Fixed(6), TextAlign::Right),
    new Column('match', 'Match', new ColumnWidth\Flex(3)),
];

$table = new TableWidget($columns, $fileResultRows, maxVisible: 20);
$table->setZebraStriping(true);
$table->setId('file-results');
$table->setSortState(new SortState('file', SortDirection::Ascending));
```

### 5.5 Swarm Dashboard Agent List

Replace the hand-coded agent rows in `SwarmDashboardWidget`:

```php
$columns = [
    new Column('status', '', new ColumnWidth\Fixed(2), sortable: false,
        formatter: fn($v) => match($v) { 'running' => '●', 'retrying' => '↻', default => '·' }),
    new Column('type', 'Type', new ColumnWidth\Fixed(8)),
    new Column('task', 'Task', new ColumnWidth\Flex(2)),
    new Column('progress', 'Progress', new ColumnWidth\Fixed(16), sortable: false,
        formatter: fn($v) => $this->renderProgressBar($v)),
    new Column('elapsed', 'Time', new ColumnWidth\Fixed(6), TextAlign::Right),
    new Column('tools', 'Tools', new ColumnWidth\Fixed(8), TextAlign::Right),
];

$table = new TableWidget($columns, $agentRows, maxVisible: 8);
$table->setShowHint(false);
$table->setId('swarm-agents');
$table->addStyleClass('swarm');
```

## 6. File Structure

```
src/UI/Tui/Widget/
├── TableWidget.php                           # Main widget class
└── Table/
    ├── Column.php                            # Column definition value object
    ├── ColumnWidth/
    │   ├── ColumnWidth.php                   # Interface
    │   ├── Fixed.php                         # Fixed-width constraint
    │   ├── Flex.php                          # Flex-grow constraint
    │   └── Percentage.php                    # Percentage constraint
    ├── Row.php                               # Row value object
    ├── SortState.php                         # Sort state value object
    └── SortDirection.php                     # Enum: Ascending, Descending

tests/Unit/UI/Tui/Widget/
├── TableWidgetTest.php                       # Rendering, input, events, sorting
└── Table/
    ├── ColumnTest.php                        # Column construction
    ├── ColumnWidth/
    │   └── ColumnWidthTest.php               # resolve() for each variant
    ├── RowTest.php                           # get(), fromValues()
    └── SortStateTest.php                     # toggle(), withColumn()
```

## 7. Rendering Algorithm — Column Width Resolution

The column width algorithm is the most complex part of the widget. Here is the full decision tree:

```
resolveColumnWidths(totalWidth, viewRows)
│
├─ Calculate cursor width (max of cursorSymbol, cursorPlaceholder visible widths)
├─ Calculate total spacing = columnSpacing * (numColumns - 1)
├─ Calculate availableWidth = totalWidth - cursorWidth - totalSpacing
│
├─ For each column:
│   ├─ Natural width = max(label length, P90 of cell content lengths)
│   │  (P90 calculated from up to 100 sample rows)
│   └─ Store naturalWidth[i]
│
├─ Phase 1: Satisfy fixed columns
│   └─ For each column with Fixed width:
│       resolvedWidths[i] = Fixed.chars
│       accumulate usedByFixed
│
├─ Phase 2: Distribute remaining space
│   ├─ remainingWidth = availableWidth - usedByFixed
│   │
│   ├─ If remainingWidth > 0 AND flex columns exist:
│   │   ├─ For each flex/percentage column:
│   │   │   ├─ Percentage: share = round(percent/100 * remainingWidth)
│   │   │   │              clamped to [1, remainingWidth - allocated]
│   │   │   └─ Flex:       share = round(remainingWidth * weight / totalWeight)
│   │   │                  clamped to [naturalWidth, remainingWidth - allocated]
│   │   └─ Last flex column gets remainder (avoids rounding gaps)
│   │
│   └─ Else (no flex or no remaining space):
│       ├─ Shrink all proportionally if totalNatural > availableWidth
│       │   shrinkRatio = availableWidth / totalNatural
│       │   resolvedWidths[i] = max(1, floor(naturalWidths[i] * shrinkRatio))
│       └─ Use natural widths if they fit
│
└─ Return resolvedWidths[]
```

**Edge cases:**

| Scenario | Behavior |
|----------|----------|
| No columns | `render()` returns `[]` |
| No rows | Header + separator + hint (empty body) |
| Single column | Full width, no spacing |
| All fixed columns exceed width | Shrink proportionally to fit |
| Very long cell content | Truncated with `…` ellipsis |
| CJK/wide characters | `AnsiUtils::visibleWidth()` handles double-width |
| ANSI-formatted cell values | `visibleWidth()` strips ANSI codes for measurement |

## 8. Test Plan

### 8.1 `TableWidgetTest`

| Test | Input | Expected |
|------|-------|----------|
| Empty columns | `new TableWidget([], [])` | `render()` returns `[]` |
| Empty rows | Columns defined, no rows | Header + separator + hint only |
| Header rendering | 3 columns with labels | Header line contains all 3 labels |
| Sort indicator | Sort on column 0 | Header shows `▲` or `▼` after sorted column label |
| Row rendering | 3 rows of data | 3 body lines with correctly aligned cells |
| Selected row styling | `selectedIndex = 1` | Second row uses `row-selected` element style |
| Cursor symbol | Selected vs unselected row | Selected row starts with `▶ `, others with `  ` |
| Column spacing | `columnSpacing = 2` | Visible gap of 2 spaces between columns |
| Fixed width column | `Fixed(10)` | Column renders at exactly 10 chars |
| Flex width column | `Flex(1)` and `Flex(2)` | Second column is ~2× wider than first |
| Percentage column | `Percentage(30)` | Column is ~30% of available width |
| Text alignment | Right-aligned column | Cell content right-padded |
| Cell formatter | Column with custom formatter | Formatter output displayed |
| P90 width calculation | 100 rows with outlier widths | Column width based on P90, not max |
| Scrolling down | 20 rows, maxVisible=5, press down 6× | scrollOffset adjusts to keep selection visible |
| Scrolling up | At scroll offset 5, press up 6× | scrollOffset adjusts upward |
| Page Up | 30 rows, page_up | Selection moves up by maxVisible |
| Page Down | 30 rows, page_down | Selection moves down by maxVisible |
| Home/End | Any position | Jump to first/last row |
| Select event | Press Enter | `SelectEvent` dispatched with row ID |
| Cancel event | Press Escape | `CancelEvent` dispatched |
| Selection change event | Press down | `SelectionChangeEvent` dispatched |
| Sort by 's' key | Press 's' | Sort cycles to next sortable column |
| Sort toggle direction | Press 's' twice on same column | Direction toggles Asc→Desc→next column |
| Sort by number | Press '1' | Sort by first sortable column |
| Sort numeric | Column with numeric values | Numeric comparison (not string) |
| Search mode enter | Press '/' | searchMode = true, query = '' |
| Search mode type | Type 'claude' | Rows filtered to match, query updated |
| Search mode backspace | Type 'abc', backspace | Query becomes 'ab' |
| Search mode escape | In search, press Esc | searchMode = false, filter kept |
| Search mode enter | In search, press Enter | searchMode = false, filter kept |
| Custom filter | Set filter closure | Filter closure called with (Row, query) |
| Zebra striping | 4 rows, zebra enabled | Even/odd rows use different elements |
| Row style classes | Row with `styleClasses: ['error']` | Class applied during row rendering |
| No header | `setShowHeader(false)` | No header line in output |
| No separator | `setShowSeparator(false)` | No separator line in output |
| No hint | `setShowHint(false)` | No hint line (unless search mode) |
| Width truncation | 80-col terminal, wide content | All lines ≤ 80 visible width |
| Programmatic setRows | Call `setRows()` after construction | view cache invalidated, new rows rendered |

### 8.2 `ColumnTest`

| Test | Input | Expected |
|------|-------|----------|
| Default construction | `new Column('id', 'Label')` | Flex(1), Left, sortable=true, formatter=null |
| Fixed width | `new Column('x', 'X', new Fixed(10))` | Width is Fixed(10) |
| Custom align | `new Column('x', 'X', align: TextAlign::Right)` | Align is Right |

### 8.3 `ColumnWidthTest`

| Test | Input | Expected |
|------|-------|----------|
| Fixed resolve | `Fixed(10)->resolve(50, 15)` | 10 |
| Flex resolve | `Flex(1)->resolve(30, 5)` | 30 |
| Percentage resolve | `Percentage(50)->resolve(80, 5)` | 40 |
| Percentage clamped low | `Percentage(1)->resolve(80, 50)` | 50 (naturalWidth floor) |

### 8.4 `RowTest`

| Test | Input | Expected |
|------|-------|----------|
| Get existing key | `new Row(['a' => 1])->get('a')` | 1 |
| Get missing key | `new Row(['a' => 1])->get('z')` | null |
| From values | `Row::fromValues(['x', 'y'], ['a', 'b'])` | `cells: ['a' => 'x', 'b' => 'y']` |

### 8.5 `SortStateTest`

| Test | Input | Expected |
|------|-------|----------|
| Toggle direction | `new SortState('x', Asc)->toggle()` | SortState('x', Desc) |
| With same column | `new SortState('x', Asc)->withColumn('x')` | SortState('x', Desc) |
| With different column | `new SortState('x', Asc)->withColumn('y')` | SortState('y', Asc) |

## 9. Accessibility Considerations

- **Keyboard-only navigation**: All rows reachable via ↑/↓, Page Up/Down, Home/End. Sort via S or 1-9. Search via `/`.
- **Visible selection**: `cursor` element (▶) and `row-selected` element (reverse video) provide dual visual indication.
- **Color contrast**: Sort indicators (▲/▼) and cursor symbol work without color. `row-selected` uses reverse which works on any terminal background.
- **Screen reader**: Events carry full context — `SelectEvent` includes the row ID, `SelectionChangeEvent` fires on every navigation change.

## 10. Future Enhancements (out of scope for initial implementation)

1. **Column reordering** — Drag or keyboard shortcut to reorder columns. Store column order in state.
2. **Resizable columns** — Interactive column width adjustment via keyboard (Ctrl+←/→ on header).
3. **Multi-select** — Shift+↑/↓ to select range, Ctrl+Space to toggle individual rows. Returns list of row IDs.
4. **Cell-level editing** — Double-click or Enter on a cell to enter inline edit mode. Dispatches `CellEditEvent`.
5. **Frozen/pinned columns** — Left-most N columns stay fixed during horizontal scroll.
6. **Horizontal scrolling** — When columns exceed terminal width, scroll horizontally with ←/→ in header focus mode.
7. **Column hiding** — Toggle column visibility via a column picker UI.
8. **Row expand/collapse** — Click or Enter on a row to reveal detail rows below it.
9. **Virtual scrolling** — For datasets with >10,000 rows, only render visible rows without holding all formatted strings in memory.
10. **CSV/clipboard copy** — Keybinding to copy selected rows or entire table to clipboard as TSV.
11. **Column aggregation** — Footer row with SUM, AVG, COUNT for numeric columns.
12. **Lazy row loading** — Callable that fetches rows on demand as user scrolls down.
