<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget;

use KosmoKrator\UI\Tui\Widget\Table\Column;
use KosmoKrator\UI\Tui\Widget\Table\ColumnWidth;
use KosmoKrator\UI\Tui\Widget\Table\Row;
use KosmoKrator\UI\Tui\Widget\Table\SortDirection;
use KosmoKrator\UI\Tui\Widget\Table\SortState;
use KosmoKrator\UI\Tui\Widget\Table\TableSelectEvent;
use KosmoKrator\UI\Tui\Widget\Table\TableSelectionChangeEvent;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
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
 * - `TableSelectEvent` dispatched on Enter with the selected row.
 * - `CancelEvent` dispatched on Escape.
 * - `TableSelectionChangeEvent` dispatched when the highlighted row changes.
 *
 * ## Keyboard
 *
 * - ↑/↓: Move selection up/down
 * - Page Up/Page Down: Scroll by page
 * - Home/End: Jump to first/last row
 * - Enter: Select row (dispatches TableSelectEvent)
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

    /** @var (callable(Row, string): bool)|null Filter function for search */
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
     * @param callable(Row, string): bool $filter
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
                $rows = array_filter($rows, fn (Row $r): bool => ($this->filter)($r, $this->searchQuery));
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
        if ($total === 0) {
            $this->selectedIndex = 0;

            return $this;
        }
        $index = max(0, min($index, $total - 1));
        if ($index !== $this->selectedIndex) {
            $this->selectedIndex = $index;
            $this->adjustScrollOffset();
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Get the current selected index.
     */
    public function getSelectedIndex(): int
    {
        return $this->selectedIndex;
    }

    /**
     * Get the current scroll offset.
     */
    public function getScrollOffset(): int
    {
        return $this->scrollOffset;
    }

    /**
     * Check if search mode is active.
     */
    public function isSearchMode(): bool
    {
        return $this->searchMode;
    }

    /**
     * Get the current search query.
     */
    public function getSearchQuery(): ?string
    {
        return $this->searchQuery;
    }

    // ── Event Callbacks ────────────────────────────────────────────────

    /**
     * @param callable(TableSelectEvent): void $callback
     */
    public function onSelect(callable $callback): static
    {
        return $this->on(TableSelectEvent::class, $callback);
    }

    /**
     * @param callable(CancelEvent): void $callback
     */
    public function onCancel(callable $callback): static
    {
        return $this->on(CancelEvent::class, $callback);
    }

    /**
     * @param callable(TableSelectionChangeEvent): void $callback
     */
    public function onSelectionChange(callable $callback): static
    {
        return $this->on(TableSelectionChangeEvent::class, $callback);
    }

    // ── Keybindings ────────────────────────────────────────────────────

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP],
            'down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'home' => [Key::HOME],
            'end' => [Key::END],
            'confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE],
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
                $this->dispatch(new TableSelectEvent(
                    $this,
                    $row->id ?? (string) $this->selectedIndex,
                    $row,
                ));
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

        // Sort by column shortcut — 1 through 9 sorts by column index
        if (\strlen($data) === 1 && ctype_digit($data) && $data !== '0') {
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
     *
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
            $lines[] = $this->renderHeader($resolvedWidths);
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
            fn (string $line): string => AnsiUtils::truncateToWidth($line, $columns),
            $lines,
        );
    }

    // ── Column Width Resolution ────────────────────────────────────────

    /**
     * Resolve column widths based on constraints, header labels, and content.
     *
     * Algorithm:
     * 1. Calculate natural width per column = max(header label width, P90 cell content width).
     * 2. Calculate cursor width prefix.
     * 3. Calculate total spacing (column_spacing * (num_columns - 1)).
     * 4. Distribute width:
     *    a. Fixed columns get their exact width.
     *    b. Remaining width is divided among flex/percentage columns.
     * 5. If total exceeds available width, shrink proportionally.
     *
     * @param int $totalWidth Available terminal width
     * @param list<Row> $viewRows Filtered + sorted rows
     *
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
     */
    private function renderHeader(array $widths): string
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

        return $this->cursorPlaceholder . str_repeat(' ', $this->columnSpacing) . $header;
    }

    /**
     * Render the separator line between header and body.
     *
     * @param list<int> $widths
     */
    private function renderSeparator(array $widths): string
    {
        $parts = [];
        foreach ($widths as $width) {
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
        // Apply row-level style classes temporarily
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
        $sortableColumns = array_filter($this->columns, fn (Column $c): bool => $c->sortable);
        if (empty($sortableColumns)) {
            return;
        }

        if ($this->sortState === null) {
            // Start sorting by the first sortable column
            $first = reset($sortableColumns);
            $this->sortState = new SortState($first->key, SortDirection::Ascending);
        } else {
            // Find current column in sortable list
            $keys = array_map(fn (Column $c): string => $c->key, $sortableColumns);
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
                    // Wrapped around; clear sort
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
        if (\strlen($data) === 1 && ctype_print($data)) {
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
        $row = $this->getSelectedRow();
        $this->dispatch(new TableSelectionChangeEvent(
            $this,
            $row?->id ?? (string) $this->selectedIndex,
            $row,
        ));
    }
}
