<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Unified, searchable command palette triggered by Ctrl+K.
 *
 * Aggregates slash commands, power commands, dollar commands, mode switches,
 * and actions into a single fuzzy-searchable overlay. Type to filter, up/down
 * to navigate, Enter to execute, Esc to dismiss.
 *
 * @phpstan-type PaletteItem array{label: string, description: string, category: string, action: string}
 */
final class CommandPaletteWidget extends AbstractWidget
{
    private const MAX_VISIBLE = 10;

    /** @var PaletteItem[] */
    private array $items = [];

    /** @var PaletteItem[] */
    private array $filteredItems = [];

    private string $query = '';

    private int $selectedIndex = 0;

    private bool $visible = false;

    /** @var \Closure(string): void|null */
    private ?\Closure $onExecute = null;

    /**
     * Register all available commands.
     *
     * @param PaletteItem[] $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
        $this->applyFilter();
    }

    /**
     * Set the callback invoked when the user confirms a command selection.
     *
     * @param \Closure(string): void $callback  Receives the action string
     */
    public function onExecute(\Closure $callback): void
    {
        $this->onExecute = $callback;
    }

    public function show(): void
    {
        $this->visible = true;
        $this->query = '';
        $this->selectedIndex = 0;
        $this->applyFilter();
        $this->invalidate();
    }

    public function hide(): void
    {
        $this->visible = false;
        $this->query = '';
        $this->selectedIndex = 0;
        $this->invalidate();
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    /**
     * Handle raw terminal input. Returns true if the input was consumed.
     */
    public function handleInput(string $data): bool
    {
        if (!$this->visible) {
            return false;
        }

        // Escape → dismiss
        if ($data === "\x1b" || $data === "\x03") {
            $this->hide();

            return true;
        }

        // Enter → execute selected item
        if ($data === "\n" || $data === "\r") {
            $this->executeSelected();

            return true;
        }

        // Up arrow
        if ($data === "\x1b[A") {
            $this->moveSelection(-1);

            return true;
        }

        // Down arrow
        if ($data === "\x1b[B") {
            $this->moveSelection(1);

            return true;
        }

        // Backspace → shorten query
        if ($data === "\x7f" || $data === "\x08") {
            if ($this->query !== '') {
                $this->query = mb_substr($this->query, 0, -1);
                $this->applyFilter();
                $this->invalidate();
            }

            return true;
        }

        // Ctrl+U → clear query
        if ($data === "\x15") {
            $this->query = '';
            $this->applyFilter();
            $this->invalidate();

            return true;
        }

        // Printable character → extend query
        $ord = ord($data[0] ?? "\x00");
        if ($ord >= 32 && !str_starts_with($data, "\x1b") && mb_strlen($data) === 1) {
            $this->query .= $data;
            $this->applyFilter();
            $this->invalidate();

            return true;
        }

        // Swallow all other input while visible
        return true;
    }

    /**
     * @return string The current search query
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @return int The currently selected index
     */
    public function getSelectedIndex(): int
    {
        return $this->selectedIndex;
    }

    /**
     * @return PaletteItem[]
     */
    public function getFilteredItems(): array
    {
        return $this->filteredItems;
    }

    public function render(RenderContext $context): array
    {
        if (!$this->visible) {
            return [];
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $accent = Theme::accent();
        $bold = Theme::bold();
        $border = Theme::borderAccent();
        $text = Theme::text();
        $white = Theme::white();
        $cols = $context->getColumns();

        $lines = [];

        // Search input line
        $prompt = "{$accent}{$bold}>{$r} ";
        $queryDisplay = $this->query !== '' ? $white.$this->query.$r : $dim.'type to search...'.$r;
        $cursor = "\x1b[5 q"; // blink bar cursor
        $lines[] = $prompt.$queryDisplay.$cursor;

        // Empty state
        if ($this->filteredItems === []) {
            $lines[] = '';
            $lines[] = "{$dim}  No matching commands{$r}";
        } else {
            // Calculate visible range with scrolling
            $startIndex = max(
                0,
                min(
                    $this->selectedIndex - (int) floor(self::MAX_VISIBLE / 2),
                    count($this->filteredItems) - self::MAX_VISIBLE,
                ),
            );
            $endIndex = min($startIndex + self::MAX_VISIBLE, count($this->filteredItems));

            // Group by category for display
            $lastCategory = '';
            for ($i = $startIndex; $i < $endIndex; ++$i) {
                $item = $this->filteredItems[$i];

                // Category header
                if ($item['category'] !== $lastCategory) {
                    if ($lastCategory !== '') {
                        $lines[] = '';
                    }
                    $lines[] = "{$dim}{$item['category']}{$r}";
                    $lastCategory = $item['category'];
                }

                $isSelected = $i === $this->selectedIndex;
                $lines[] = $this->renderItem($item, $isSelected, $cols);
            }

            // Scroll indicator
            if ($startIndex > 0 || $endIndex < count($this->filteredItems)) {
                $scrollText = sprintf('  (%d/%d)', $this->selectedIndex + 1, count($this->filteredItems));
                $lines[] = "{$dim}" . AnsiUtils::truncateToWidth($scrollText, $cols - 4, '') . "{$r}";
            }
        }

        // Help line
        $lines[] = "{$dim}  ↑↓ navigate · ↵ select · Esc close{$r}";

        // Wrap in bordered box
        $contentWidth = 0;
        foreach ($lines as $line) {
            $w = AnsiUtils::visibleWidth($line);
            if ($w > $contentWidth) {
                $contentWidth = $w;
            }
        }
        $contentWidth = min($contentWidth + 4, $cols - 4);

        $result = [];
        $result[] = "{$border}┌" . str_repeat('─', $contentWidth) . "┐{$r}";
        foreach ($lines as $line) {
            $visW = AnsiUtils::visibleWidth($line);
            $pad = max(0, $contentWidth - $visW);
            $result[] = "{$border}│{$r} {$line}" . str_repeat(' ', $pad) . " {$border}│{$r}";
        }
        $result[] = "{$border}└" . str_repeat('─', $contentWidth) . "┘{$r}";

        return $result;
    }

    // ── Fuzzy matching ──────────────────────────────────────────────────

    /**
     * Compute a fuzzy match score for a query against a label.
     *
     * Scoring:
     *  - Each matched character: +1
     *  - Consecutive matches: +2 bonus per consecutive char (after the first)
     *  - First-character-of-word bonus: +3 when the query char matches the
     *    start of a word (after space, /, :, $, _)
     *  - Returns 0 if any query character cannot be found
     */
    public static function fuzzyScore(string $query, string $label): int
    {
        if ($query === '') {
            return 1; // Empty query matches everything with minimal score
        }

        $query = mb_strtolower($query);
        $label = mb_strtolower($label);
        $queryLen = mb_strlen($query);
        $labelLen = mb_strlen($label);

        $score = 0;
        $labelPos = 0;
        $consecutive = 0;

        for ($qi = 0; $qi < $queryLen; ++$qi) {
            $qChar = $query[$qi];
            $found = false;

            while ($labelPos < $labelLen) {
                $lChar = $label[$labelPos];

                if ($lChar === $qChar) {
                    $score += 1;
                    ++$consecutive;

                    // Consecutive match bonus
                    if ($consecutive > 1) {
                        $score += 2;
                    }

                    // First-char-of-word bonus
                    if ($labelPos === 0 || self::isWordBoundary($label[$labelPos - 1] ?? '')) {
                        $score += 3;
                    }

                    ++$labelPos;
                    $found = true;
                    break;
                }

                // Reset consecutive counter on mismatch
                $consecutive = 0;
                ++$labelPos;
            }

            if (!$found) {
                return 0; // Query char not found → no match
            }
        }

        return $score;
    }

    /**
     * Check if a character is a word boundary for fuzzy matching purposes.
     */
    private static function isWordBoundary(string $char): bool
    {
        return $char === ' ' || $char === '/' || $char === ':' || $char === '$' || $char === '_' || $char === '-';
    }

    // ── Internal helpers ────────────────────────────────────────────────

    /**
     * Apply the current query to filter and sort items.
     */
    private function applyFilter(): void
    {
        if ($this->query === '') {
            $this->filteredItems = $this->items;
        } else {
            $scored = [];
            foreach ($this->items as $item) {
                $score = self::fuzzyScore($this->query, $item['label'] . ' ' . $item['description']);
                if ($score > 0) {
                    $scored[] = ['score' => $score, 'item' => $item];
                }
            }

            // Sort by score descending, then alphabetically by label
            usort($scored, function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) {
                    return $b['score'] <=> $a['score'];
                }
                return strcmp($a['item']['label'], $b['item']['label']);
            });

            $this->filteredItems = array_map(fn(array $s): array => $s['item'], $scored);
        }

        $this->selectedIndex = min($this->selectedIndex, max(0, count($this->filteredItems) - 1));
    }

    /**
     * Move the selection by the given delta (wrapping).
     */
    private function moveSelection(int $delta): void
    {
        $count = count($this->filteredItems);
        if ($count === 0) {
            return;
        }

        $this->selectedIndex = ($this->selectedIndex + $delta + $count) % $count;
        $this->invalidate();
    }

    /**
     * Execute the currently selected item.
     */
    private function executeSelected(): void
    {
        $item = $this->filteredItems[$this->selectedIndex] ?? null;
        if ($item === null) {
            $this->hide();
            return;
        }

        $action = $item['action'];
        $this->hide();

        if ($this->onExecute !== null) {
            ($this->onExecute)($action);
        }
    }

    /**
     * Render a single palette item.
     *
     * @param PaletteItem $item
     */
    private function renderItem(array $item, bool $isSelected, int $cols): string
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $text = Theme::text();

        $label = $item['label'];
        $description = $item['description'] ?? '';

        if ($isSelected) {
            $selectedStyle = $this->resolveElement('selected');
            $prefix = '→ ';
            $maxLabelWidth = 20;
            $truncatedLabel = AnsiUtils::truncateToWidth($label, $maxLabelWidth, '');
            $spacing = str_repeat(' ', max(1, $maxLabelWidth - AnsiUtils::visibleWidth($truncatedLabel) + 2));

            if ($description !== '' && $cols > 50) {
                $descStart = strlen($prefix) + AnsiUtils::visibleWidth($truncatedLabel) + strlen($spacing);
                $remaining = $cols - $descStart - 4;
                if ($remaining > 10) {
                    $truncatedDesc = AnsiUtils::truncateToWidth($description, $remaining, '');
                    return $selectedStyle->apply("{$prefix}{$truncatedLabel}{$spacing}{$truncatedDesc}");
                }
            }

            $maxColumns = $cols - strlen($prefix) - 2;
            return $selectedStyle->apply($prefix . AnsiUtils::truncateToWidth($label, $maxColumns, ''));
        }

        // Non-selected item
        $prefix = '  ';
        $maxLabelWidth = 20;
        $truncatedLabel = AnsiUtils::truncateToWidth($label, $maxLabelWidth, '');
        $spacing = str_repeat(' ', max(1, $maxLabelWidth - AnsiUtils::visibleWidth($truncatedLabel) + 2));

        if ($description !== '' && $cols > 50) {
            $descStart = strlen($prefix) + AnsiUtils::visibleWidth($truncatedLabel) + strlen($spacing);
            $remaining = $cols - $descStart - 4;
            if ($remaining > 10) {
                $truncatedDesc = AnsiUtils::truncateToWidth($description, $remaining, '');
                $labelText = $this->applyElement('label', $truncatedLabel);
                $descText = $dim . $truncatedDesc . $r;
                return $prefix . $labelText . $spacing . $descText;
            }
        }

        $maxColumns = $cols - strlen($prefix) - 2;
        return $prefix . $text . AnsiUtils::truncateToWidth($label, $maxColumns, '') . $r;
    }
}
