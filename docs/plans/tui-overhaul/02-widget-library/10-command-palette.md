# 10 — CommandPaletteWidget

> **Module**: `src/UI/Tui/Widget/CommandPaletteWidget.php`
> **Dependencies**: `AbstractWidget`, `FocusableInterface`, `KeybindingsTrait`, existing `SelectListWidget` (reference only)
> **Blocks**: TUI overhaul input-system plan (09), power-user experience

## 1. Background: Command Palette Patterns

### VS Code (Ctrl+Shift+P)
- **Modal overlay** in the top-center of the screen, ~60% width.
- Text input at top, filtered results below.
- Shows `>` prefix for commands; recent items float to top.
- Categories shown as bold group headers (e.g., **File**, **Edit**, **View**).
- Each item: label + optional shortcut on the right.
- Fuzzy matching ranks by consecutive characters, word-boundary matches, and recency.
- **Key insight**: The palette is a *mode* — all keystrokes go to search until dismissed.

### Sublime Text (Ctrl+Shift+P)
- Similar overlay, but simpler ranking (substring + fuzzy).
- Shows file paths alongside commands.
- Folders/categories via prefix (`View:`, `File:`, `Edit:`).
- **Key insight**: Prefix filtering by colon-delimited category is intuitive.

### Helix (`:`)
- Inline at the bottom of the screen (status bar area).
- Typing `:` opens a command mode with fuzzy completion.
- Shows a small dropdown of matches above the input.
- **Key insight**: Minimal footprint — doesn't obscure the main content area much.

### fzf
- Full-screen TUI with a split: search input at bottom, results above.
- **Fuzzy matching algorithm**: each character must appear in order, but may have gaps. Scored by:
  1. Exact substring match (highest)
  2. Consecutive characters
  3. Word-boundary matches (`-`, `_`, space, camelCase transitions)
  4. Proximity (closer = better)
- Highlights matched characters in the result.
- Preview pane on the right (optional).
- **Key insight**: Scoring is the core UX differentiator — good fuzzy search feels magical.

## 2. Current State: Slash/Power/Dollar Commands

### Source: `TuiInputHandler.php`

Three command registries are hardcoded as constants:

| Registry | Prefix | Count | Examples |
|----------|--------|-------|---------|
| `SLASH_COMMANDS` | `/` | 21 | `/edit`, `/plan`, `/ask`, `/compact`, `/new`, `/quit`, `/settings`, `/memories` |
| `POWER_COMMANDS` | `:` | 20 | `:unleash`, `:trace`, `:autopilot`, `:deslop`, `:deepinit`, `:team`, `:review` |
| `DOLLAR_COMMANDS` | `$` | 5 + dynamic skills | `$list`, `$create`, `$show`, `$edit`, `$delete` |

**Current UX flow:**
1. User types `/`, `:`, or `$` in the prompt `EditorWidget`.
2. `handleChange()` detects the prefix and calls `showCommandCompletion()`.
3. A `SelectListWidget` is added to the `overlay` container with prefix-filtered items.
4. User navigates with up/down, selects with Enter/Tab, dismisses with Esc.
5. Selected command replaces the input text and is resumed via the prompt suspension.

**Limitations of current approach:**
- Prefix-only matching (no fuzzy search).
- Three separate dropdowns with no unified entry point.
- No keyboard shortcut trigger (must type the prefix character).
- No category grouping.
- No description shown inline.
- No recency/frequency ranking.

### Source: `src/Command/Slash/`

Actual command implementations (22 files):

```
AgentsCommand.php      — /agents     (show swarm dashboard)
ArgusCommand.php       — /argus      (switch to Argus mode)
ClearCommand.php       — /clear      (clear screen)
CompactCommand.php     — /compact    (compact context)
FeedbackCommand.php    — /feedback   (submit feedback)
ForgetCommand.php      — /forget     (delete a memory)
GuardianCommand.php    — /guardian   (switch to Guardian mode)
HelpCommand.php        — /help       (show help)
MemoriesCommand.php    — /memories   (show memories)
ModeCommand.php        — /edit, /plan, /ask  (switch mode)
NewCommand.php         — /new        (new session)
PrometheusCommand.php  — /prometheus (switch to Prometheus mode)
QuitCommand.php        — /quit       (exit)
RenameCommand.php      — /rename     (rename session)
ResumeCommand.php      — /resume     (resume session)
SessionsCommand.php    — /sessions   (list sessions)
SeedCommand.php        — /seed       (mock demo)
SettingsCommand.php    — /settings   (open settings)
TasksClearCommand.php  — /tasks-clear (clear task list)
TheogonyCommand.php    — /theogony   (origin spectacle)
UpdateCommand.php      — /update     (check for updates)
```

## 3. Design

### 3.1 Triggering

| Trigger | Context | Behavior |
|---------|---------|----------|
| `Ctrl+P` | Any time prompt is focused | Opens palette, clears any current input |
| `/` | Empty prompt | Opens palette filtered to slash commands |
| `:` | Empty prompt | Opens palette filtered to power commands |
| `$` | Empty prompt | Opens palette filtered to skill commands |

The `Ctrl+P` trigger shows **all** commands regardless of prefix. The `/`, `:`, `$` triggers pre-filter to the relevant category, but the user can backspace the prefix and see everything.

### 3.2 Visual Layout

```
┌─────────────────────────────────────────────────────────┐
│                                                         │
│                   ┌───────────────────────┐             │
│                   │ 🔍 type a command...  │             │
│                   ├───────────────────────┤             │
│                   │                       │             │
│                   │  Mode                 │             │
│                   │  ▸ /edit    Ctrl+E    │             │
│                   │    /plan              │             │
│                   │    /ask               │             │
│                   │                       │             │
│                   │  Navigation           │             │
│                   │    /new               │             │
│                   │    /resume            │             │
│                   │    /sessions          │             │
│                   │    /quit     Ctrl+Q   │             │
│                   │                       │             │
│                   │  Workflow             │             │
│                   │    :unleash           │             │
│                   │    :autopilot         │             │
│                   │    :team              │             │
│                   │    :review            │             │
│                   │                       │             │
│                   └───────────────────────┘             │
│                                                         │
│ ─── ┌──────────────────────────────────────────────┐ ───│
│     │ >                                              │  │
│     └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

- Overlay centered horizontally, positioned in the upper third of the screen.
- Width: 50–60% of terminal width (min 40 cols, max 80 cols).
- Height: dynamic, up to 60% of terminal rows, min 8 rows.
- Semi-transparent/dimmed background behind the overlay (if terminal supports it).
- Search input at top, scrollable results below.
- Group headers are non-selectable, rendered in bold/muted color.
- Selected item highlighted with accent color.
- Keyboard shortcut (if any) right-aligned on each row.

### 3.3 Categories

Commands are grouped into categories for display:

| Category | Commands | Color hint |
|----------|----------|------------|
| **Mode** | `/edit`, `/plan`, `/ask`, `/guardian`, `/argus`, `/prometheus` | Green/amber/red |
| **Navigation** | `/new`, `/resume`, `/sessions`, `/quit`, `/rename` | Blue |
| **Context** | `/compact`, `/clear`, `/memories`, `/forget`, `/tasks-clear` | Cyan |
| **Workflow** | `:unleash`, `:trace`, `:autopilot`, `:deslop`, `:deepinit`, `:ralph`, `:team`, `:ultraqa`, `:interview`, `:doctor`, `:learner`, `:cancel`, `:replay`, `:review`, `:research`, `:deepdive`, `:babysit`, `:release`, `:docs`, `:consensus` | Magenta |
| **Skills** | `$list`, `$create`, `$show`, `$edit`, `$delete`, + dynamic | Yellow |
| **Tools** | `/agents`, `/settings`, `/update`, `/feedback`, `/seed`, `/theogony` | Gray |

### 3.4 Fuzzy Matching Algorithm

Implement a lightweight fzf-style scorer in PHP:

```
score(query, candidate):
  1. If query is empty → return base_score (recency rank)
  2. For each char in query, find next occurrence in candidate (case-insensitive)
  3. If not all chars found → reject (score = -1)
  4. Score based on:
     a. +10 per exact word-boundary match (start of string, after space/_/-/camelCase)
     b. +5 per consecutive char match
     c. -1 per gap between matched chars
     d. Bonus for match at start of string
     e. Bonus for exact prefix match of any word
  5. Return total score
```

Highlight matched characters using ANSI bold/reverse in the rendered output.

### 3.5 Keyboard Navigation

| Key | Action |
|-----|--------|
| `Ctrl+P` | Open palette / close palette |
| `/` `:` `$` | Open palette pre-filtered (when input is empty) |
| `↑` / `Ctrl+P` (in palette) | Move selection up (skip group headers) |
| `↓` / `Ctrl+N` | Move selection down (skip group headers) |
| `Enter` | Confirm selection, close palette, execute command |
| `Tab` | Confirm selection, keep palette open (for chaining) |
| `Esc` / `Ctrl+C` | Cancel, close palette, restore previous input |
| `Backspace` (empty query) | Close palette |
| `PgUp` / `PgDn` | Scroll results by page |
| `Home` / `End` | Jump to first/last visible item |

### 3.6 Item Data Structure

Each palette item:

```php
[
    'id'          => string,     // Unique identifier (e.g., '/edit', ':unleash')
    'label'       => string,     // Display label (e.g., 'Edit Mode')
    'command'     => string,     // Actual command string to execute
    'category'    => string,     // Category key ('mode', 'navigation', 'context', 'workflow', 'skills', 'tools')
    'shortcut'    => ?string,    // Keyboard shortcut hint (e.g., 'Ctrl+E')
    'description' => string,     // One-line description
    'prefix'      => string,     // '/', ':', or '$'
    'frequency'   => int,        // Usage count for ranking (persisted)
    'lastUsed'    => ?int,       // Timestamp of last use
]
```

### 3.7 Integration with TuiInputHandler

The palette intercepts input via `handleInput()`:
1. `Ctrl+P` or `/`/`:`/`$` on empty input creates a `CommandPaletteWidget` and adds it to the `overlay`.
2. All further keystrokes are routed to the palette's `handleInput()` method.
3. On confirm: the command string is sent through the same suspension/resume flow as current slash commands.
4. On cancel: the overlay is removed, input focus returns to the `EditorWidget`.

The palette **replaces** the current `SelectListWidget`-based completion for `/`, `:`, `$` triggers. The inline completion is removed in favor of the richer palette.

## 4. PHP Class Sketch

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Modal command palette overlay — a unified, searchable launcher for all KosmoKrator commands.
 *
 * Triggered by Ctrl+P or by typing /, :, $ in an empty prompt.
 * Shows grouped, fuzzy-filtered command list with keyboard navigation.
 * Returns the selected command string via the onConfirm callback, or null on cancel.
 */
final class CommandPaletteWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** Category display order and labels */
    private const CATEGORIES = [
        'mode'       => 'Mode',
        'navigation' => 'Navigation',
        'context'    => 'Context',
        'workflow'   => 'Workflow',
        'skills'     => 'Skills',
        'tools'      => 'Tools',
    ];

    /** Category → color (ANSI 256 or RGB) */
    private const CATEGORY_COLORS = [
        'mode'       => 'rgb(80,200,120)',
        'navigation' => 'rgb(100,160,255)',
        'context'    => 'rgb(80,220,220)',
        'workflow'   => 'rgb(200,120,255)',
        'skills'     => 'rgb(255,200,60)',
        'tools'      => 'rgb(160,160,160)',
    ];

    /** @var list<array{id: string, label: string, command: string, category: string, shortcut: ?string, description: string, prefix: string, frequency: int, lastUsed: ?int}> */
    private array $allItems;

    /** Current search/query string */
    private string $query = '';

    /** Pre-filter prefix ('', '/', ':', '$') — set when opened via prefix trigger */
    private string $initialPrefix = '';

    /** @var list<array{type: 'header', category: string}|array{type: 'item', index: int}> */
    private array $visibleRows = [];

    /** Index into $visibleRows for the currently selected row */
    private int $cursorIndex = 0;

    /** Vertical scroll offset (first visible row index) */
    private int $scrollOffset = 0;

    /** Max number of result rows to render (computed from terminal height) */
    private int $maxVisibleRows = 15;

    /** @var callable(string): void|null Callback invoked with the selected command string. */
    private $onConfirmCallback = null;

    /** @var callable(): void|null Callback invoked when the user dismisses the palette. */
    private $onCancelCallback = null;

    /**
     * @param list<array{id: string, label: string, command: string, category: string, shortcut?: ?string, description: string, prefix: string, frequency?: int, lastUsed?: ?int}> $items
     * @param string $initialPrefix Pre-filter to a specific prefix ('/', ':', '$', or '' for all)
     */
    public function __construct(array $items, string $initialPrefix = '')
    {
        // Normalize items with defaults
        $this->allItems = array_map(fn (array $item) => array_merge([
            'shortcut'  => null,
            'frequency' => 0,
            'lastUsed'  => null,
        ], $item), $items);

        $this->initialPrefix = $initialPrefix;

        if ($initialPrefix !== '') {
            $this->query = $initialPrefix;
        }

        $this->rebuildRows();
    }

    // ── Callbacks ─────────────────────────────────────────────────────────

    /** Register callback for when the user confirms a selection. */
    public function onConfirm(callable $callback): static
    {
        $this->onConfirmCallback = $callback;
        return $this;
    }

    /** Register callback for when the user cancels/dismisses the palette. */
    public function onCancel(callable $callback): static
    {
        $this->onCancelCallback = $callback;
        return $this;
    }

    // ── Public API ────────────────────────────────────────────────────────

    /** Get the current query string. */
    public function getQuery(): string
    {
        return $this->query;
    }

    /** Check whether the palette is showing results. */
    public function hasVisibleItems(): bool
    {
        foreach ($this->visibleRows as $row) {
            if ($row['type'] === 'item') {
                return true;
            }
        }
        return false;
    }

    // ── Input Handling ────────────────────────────────────────────────────

    public function handleInput(string $data): bool
    {
        $kb = $this->getKeybindings();

        // Escape / Ctrl+C → cancel
        if ($data === "\x1b" || $data === "\x03") {
            $this->dismiss();
            return true;
        }

        // Enter → confirm selection
        if ($kb->matches($data, 'submit')) {
            $this->confirmSelection();
            return true;
        }

        // Up arrow / Ctrl+P → move cursor up
        if ($kb->matches($data, 'cursor_up') || $data === "\x10") {
            $this->moveCursor(-1);
            return true;
        }

        // Down arrow / Ctrl+N → move cursor down
        if ($kb->matches($data, 'cursor_down') || $data === "\x0E") {
            $this->moveCursor(1);
            return true;
        }

        // Page Up
        if ($data === "\x1b[5~") {
            $this->moveCursor(-$this->maxVisibleRows);
            return true;
        }

        // Page Down
        if ($data === "\x1b[6~") {
            $this->moveCursor($this->maxVisibleRows);
            return true;
        }

        // Backspace → delete last char from query, or dismiss if empty
        if ($data === "\x7f" || $data === "\x08") {
            if ($this->query !== '' && $this->query !== $this->initialPrefix) {
                $this->query = substr($this->query, 0, -1);
                $this->rebuildRows();
            } else {
                $this->dismiss();
            }
            return true;
        }

        // Printable character → append to query
        if (mb_strlen($data) === 1 && ord($data) >= 32) {
            $this->query .= $data;
            $this->rebuildRows();
            return true;
        }

        return false;
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    public function render(RenderContext $ctx): void
    {
        $terminalWidth = $ctx->getWidth();
        $terminalHeight = $ctx->getHeight();

        // Palette dimensions
        $width = min(70, max(40, (int) ($terminalWidth * 0.55)));
        $this->maxVisibleRows = min(15, max(5, (int) ($terminalHeight * 0.45)));
        $height = 2 + count($this->visibleRows) + 1; // border + input + rows + border
        $height = min($height, $this->maxVisibleRows + 3);

        // Center horizontally, upper third vertically
        $startX = (int) (($terminalWidth - $width) / 2);
        $startY = max(1, (int) ($terminalHeight * 0.15));

        $x = $startX;
        $y = $startY;

        // ── Top border with search input ──
        $prompt = ' › ' . $this->query;
        $prompt .= "\x1b[5m▏\x1b[0m"; // blinking cursor
        $prompt = str_pad($prompt, $width - 2);
        $ctx->text($x, $y, '┌' . str_repeat('─', $width - 2) . '┐');
        $y++;
        $ctx->text($x, $y, '│' . Theme::bold($prompt) . '│');
        $y++;
        $ctx->text($x, $y, '├' . str_repeat('─', $width - 2) . '┤');
        $y++;

        // ── Result rows ──
        $visibleSlice = array_slice($this->visibleRows, $this->scrollOffset, $this->maxVisibleRows);

        foreach ($visibleSlice as $rowIdx => $row) {
            if ($row['type'] === 'header') {
                $category = $row['category'];
                $label = self::CATEGORIES[$category] ?? $category;
                $color = self::CATEGORY_COLORS[$category] ?? '';
                $headerText = "  {$label}";
                $headerText = str_pad($headerText, $width - 2);
                if ($color !== '') {
                    $headerText = Theme::color($color) . Theme::bold($headerText) . Theme::reset();
                }
                $ctx->text($x, $y, '│' . $headerText . '│');
            } else {
                $item = $this->allItems[$row['index']];
                $isSelected = ($this->scrollOffset + $rowIdx) === $this->cursorIndex;

                // Build row content
                $commandPart = $item['command'];
                $descPart = $item['description'];
                $shortcutPart = $item['shortcut'] ?? '';

                // Fuzzy-highlight matched characters in the command
                $highlighted = $this->highlightMatches($commandPart);

                $left = "  {$highlighted}";
                if ($shortcutPart !== '') {
                    $right = Theme::dim($shortcutPart);
                } else {
                    $right = Theme::dim($descPart);
                }

                // Truncate/pad to fit
                $maxLeft = $width - 4;
                $left = mb_substr($left, 0, $maxLeft);
                $availableRight = $width - 4 - mb_strlen($left);
                if ($availableRight > 5 && $right !== '') {
                    $right = str_pad($right, $availableRight, ' ', STR_PAD_LEFT);
                    $line = $left . $right;
                } else {
                    $line = str_pad($left, $width - 4);
                }
                $line = str_pad($line, $width - 4);

                if ($isSelected) {
                    $line = Theme::reverse() . Theme::cyan($line) . Theme::reset();
                }

                $ctx->text($x, $y, '│' . $line . '│');
            }
            $y++;
        }

        // Pad remaining rows
        while ($y < $startY + $height - 1) {
            $ctx->text($x, $y, '│' . str_repeat(' ', $width - 2) . '│');
            $y++;
        }

        // ── Bottom border ──
        $ctx->text($x, $y, '└' . str_repeat('─', $width - 2) . '┘');
    }

    // ── Fuzzy Matching ────────────────────────────────────────────────────

    /**
     * Fuzzy-match score for a query against a candidate string.
     * Returns -1 if no match, or a positive score (higher = better).
     */
    private function fuzzyScore(string $query, string $candidate): int
    {
        if ($query === '') {
            return 0;
        }

        $query = mb_strtolower($query);
        $candidate = mb_strtolower($candidate);

        // Strip prefix chars for matching
        $candidate = ltrim($candidate, '/:$');

        $score = 0;
        $candLen = mb_strlen($candidate);
        $queryLen = mb_strlen($query);
        $candPos = 0;
        $lastMatchPos = -2;

        for ($qi = 0; $qi < $queryLen; $qi++) {
            $qChar = $query[$qi];
            $found = false;

            for ($ci = $candPos; $ci < $candLen; $ci++) {
                if ($candidate[$ci] === $qChar) {
                    $found = true;
                    $candPos = $ci + 1;

                    // Score: word boundary
                    if ($ci === 0 || in_array($candidate[$ci - 1], [' ', '-', '_', '.', '/'], true)) {
                        $score += 10;
                    }
                    // Score: consecutive match
                    if ($ci === $lastMatchPos + 1) {
                        $score += 5;
                    }
                    // Score: start-of-string bonus
                    if ($ci === 0) {
                        $score += 8;
                    }
                    // Penalty: gap
                    if ($lastMatchPos >= 0 && ($ci - $lastMatchPos) > 1) {
                        $score -= ($ci - $lastMatchPos - 1);
                    }

                    $lastMatchPos = $ci;
                    break;
                }
            }

            if (!$found) {
                return -1; // No match
            }
        }

        return max(0, $score);
    }

    /**
     * Get the positions of matched characters for highlighting.
     *
     * @return list<int>
     */
    private function getMatchPositions(string $query, string $candidate): array
    {
        if ($query === '') {
            return [];
        }

        $query = mb_strtolower($query);
        $candidate = mb_strtolower($candidate);

        $positions = [];
        $candPos = 0;
        $queryLen = mb_strlen($query);
        $candLen = mb_strlen($candidate);

        for ($qi = 0; $qi < $queryLen; $qi++) {
            for ($ci = $candPos; $ci < $candLen; $ci++) {
                if ($candidate[$ci] === $query[$qi]) {
                    $positions[] = $ci;
                    $candPos = $ci + 1;
                    break;
                }
            }
        }

        return $positions;
    }

    /**
     * Return the command string with matched characters highlighted (bold).
     */
    private function highlightMatches(string $command): string
    {
        $queryForMatch = $this->query;
        // Strip prefix for matching
        if (in_array($queryForMatch[0] ?? '', ['/', ':', '$'], true)) {
            $queryForMatch = substr($queryForMatch, 1);
        }

        if ($queryForMatch === '') {
            return Theme::bold($command);
        }

        $positions = $this->getMatchPositions($queryForMatch, $command);
        if ($positions === []) {
            return $command;
        }

        $result = '';
        $chars = mb_str_split($command);
        $posSet = array_flip($positions);

        foreach ($chars as $i => $char) {
            if (isset($posSet[$i])) {
                $result .= Theme::bold(Theme::cyan($char));
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    // ── Internal ──────────────────────────────────────────────────────────

    /**
     * Rebuild the visible rows list based on current query, sorted by score.
     */
    private function rebuildRows(): void
    {
        // Filter and score items
        $scored = [];
        foreach ($this->allItems as $idx => $item) {
            // Pre-filter by initial prefix
            if ($this->initialPrefix !== '' && !str_starts_with($item['prefix'], $this->initialPrefix[0])) {
                continue;
            }

            // Determine the search query (strip prefix for matching)
            $searchQuery = $this->query;
            if ($this->initialPrefix !== '' && str_starts_with($searchQuery, $this->initialPrefix)) {
                $searchQuery = substr($searchQuery, strlen($this->initialPrefix));
            }

            // Match against command, label, and description
            $cmdScore = $this->fuzzyScore($searchQuery, $item['command']);
            $labelScore = $this->fuzzyScore($searchQuery, $item['label']);
            $descScore = $this->fuzzyScore($searchQuery, $item['description']);

            $bestScore = max($cmdScore, $labelScore, $descScore);
            if ($bestScore < 0 && $searchQuery !== '') {
                continue;
            }

            // Boost by frequency and recency
            $bestScore += $item['frequency'] * 2;
            if ($item['lastUsed'] !== null) {
                $recencyHours = (time() - $item['lastUsed']) / 3600;
                $bestScore += max(0, 50 - $recencyHours); // Decays over 50 hours
            }

            $scored[] = ['index' => $idx, 'score' => $bestScore, 'category' => $item['category']];
        }

        // Sort by score descending, then by category order
        $categoryOrder = array_flip(array_keys(self::CATEGORIES));
        usort($scored, function (array $a, array $b) use ($categoryOrder): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score']; // Higher score first
            }
            return ($categoryOrder[$a['category']] ?? 99) <=> ($categoryOrder[$b['category']] ?? 99);
        });

        // Build visible rows with group headers
        $this->visibleRows = [];
        $lastCategory = '';

        foreach ($scored as $entry) {
            if ($entry['category'] !== $lastCategory) {
                $this->visibleRows[] = ['type' => 'header', 'category' => $entry['category']];
                $lastCategory = $entry['category'];
            }
            $this->visibleRows[] = ['type' => 'item', 'index' => $entry['index']];
        }

        // Reset cursor
        $this->cursorIndex = 0;
        $this->scrollOffset = 0;
        $this->moveCursorToFirstItem();
    }

    /**
     * Move the cursor to the first selectable (item) row.
     */
    private function moveCursorToFirstItem(): void
    {
        foreach ($this->visibleRows as $idx => $row) {
            if ($row['type'] === 'item') {
                $this->cursorIndex = $idx;
                $this->ensureCursorVisible();
                return;
            }
        }
    }

    /**
     * Move the cursor by a delta, skipping header rows.
     */
    private function moveCursor(int $delta): void
    {
        $direction = $delta > 0 ? 1 : -1;
        $target = $this->cursorIndex;

        for ($i = 0; $i < abs($delta); $i++) {
            $next = $target + $direction;
            // Skip headers
            while ($next >= 0 && $next < count($this->visibleRows) && $this->visibleRows[$next]['type'] === 'header') {
                $next += $direction;
            }
            if ($next >= 0 && $next < count($this->visibleRows)) {
                $target = $next;
            }
        }

        $this->cursorIndex = $target;
        $this->ensureCursorVisible();
    }

    /**
     * Adjust scroll offset so cursor is within the visible window.
     */
    private function ensureCursorVisible(): void
    {
        if ($this->cursorIndex < $this->scrollOffset) {
            $this->scrollOffset = $this->cursorIndex;
        } elseif ($this->cursorIndex >= $this->scrollOffset + $this->maxVisibleRows) {
            $this->scrollOffset = $this->cursorIndex - $this->maxVisibleRows + 1;
        }
    }

    /**
     * Confirm the currently selected item and invoke the callback.
     */
    private function confirmSelection(): void
    {
        if (!isset($this->visibleRows[$this->cursorIndex]) || $this->visibleRows[$this->cursorIndex]['type'] !== 'item') {
            return;
        }

        $itemIndex = $this->visibleRows[$this->cursorIndex]['index'];
        $command = $this->allItems[$itemIndex]['command'];

        if ($this->onConfirmCallback !== null) {
            ($this->onConfirmCallback)($command);
        }
    }

    /**
     * Dismiss the palette without selecting anything.
     */
    private function dismiss(): void
    {
        if ($this->onCancelCallback !== null) {
            ($this->onCancelCallback)();
        }
    }
}
```

## 5. Integration: TuiInputHandler Changes

The following changes to `TuiInputHandler.php` wire in the command palette:

```php
// In TuiInputHandler::handleInput():

// Add Ctrl+P handler (before other handlers)
if ($data === "\x10") { // Ctrl+P
    $this->openCommandPalette('');
    return true;
}

// New method:
private function openCommandPalette(string $prefix): void
{
    $items = $this->buildPaletteItems();
    $this->commandPalette = new CommandPaletteWidget($items, $prefix);
    $this->commandPalette->onConfirm(function (string $command): void {
        $this->closeCommandPalette();
        $suspension = ($this->getPromptSuspension)();
        if ($suspension !== null) {
            ($this->clearPromptSuspension)(null);
            $suspension->resume($command);
        }
    });
    $this->commandPalette->onCancel(function (): void {
        $this->closeCommandPalette();
    });
    $this->overlay->add($this->commandPalette);
    ($this->flushRender)();
}

private function closeCommandPalette(): void
{
    if ($this->commandPalette !== null) {
        $this->overlay->remove($this->commandPalette);
        $this->commandPalette = null;
        ($this->flushRender)();
    }
}

/** @return list<array{...}> */
private function buildPaletteItems(): array
{
    $items = [];

    foreach (self::SLASH_COMMANDS as $cmd) {
        $items[] = [
            'id'          => $cmd['value'],
            'label'       => $cmd['label'],
            'command'     => $cmd['value'],
            'category'    => $this->categorizeSlashCommand($cmd['value']),
            'description' => $cmd['description'],
            'prefix'      => '/',
        ];
    }

    foreach (self::POWER_COMMANDS as $cmd) {
        $items[] = [
            'id'          => $cmd['value'],
            'label'       => $cmd['label'],
            'command'     => $cmd['value'],
            'category'    => 'workflow',
            'description' => $cmd['description'],
            'prefix'      => ':',
        ];
    }

    foreach (self::DOLLAR_COMMANDS as $cmd) {
        $items[] = [
            'id'          => $cmd['value'],
            'label'       => $cmd['label'],
            'command'     => $cmd['value'],
            'category'    => 'skills',
            'description' => $cmd['description'],
            'prefix'      => '$',
        ];
    }

    // Append dynamic skill completions
    foreach ($this->skillCompletions as $skill) {
        $items[] = [
            'id'          => $skill['value'],
            'label'       => $skill['label'],
            'command'     => $skill['value'],
            'category'    => 'skills',
            'description' => $skill['description'] ?? '',
            'prefix'      => '$',
        ];
    }

    return $items;
}

private function categorizeSlashCommand(string $command): string
{
    return match (true) {
        in_array($command, ['/edit', '/plan', '/ask', '/guardian', '/argus', '/prometheus'], true) => 'mode',
        in_array($command, ['/new', '/resume', '/sessions', '/quit', '/rename'], true) => 'navigation',
        in_array($command, ['/compact', '/clear', '/memories', '/forget', '/tasks-clear'], true) => 'context',
        default => 'tools',
    };
}
```

## 6. Style Sheet Additions

In `KosmokratorStyleSheet.php`:

```php
CommandPaletteWidget::class => new Style(
    borderRadius: 4,
    border: Border::rounded(BorderColor::cyan()),
    shadow: true,
    zIndex: 100,
),
CommandPaletteWidget::class . '::header' => new Style(
    fontWeight: FontWeight::Bold,
    color: 'category-specific', // handled at render time
),
CommandPaletteWidget::class . '::selected' => new Style(
    backgroundColor: 'rgb(40,60,80)',
    color: 'rgb(255,255,255)',
),
```

## 7. Usage Frequency Tracking

To support recency/frequency ranking, a small persistence layer is needed:

- **Storage**: `~/.kosmokrator/command_usage.json` (or in the existing config).
- **Format**: `{"/edit": {"count": 42, "lastUsed": 1712457600}, ...}`
- **Update**: Increment count and set `lastUsed` each time a command is confirmed from the palette.
- **Pruning**: Cap at 200 entries, evict lowest-count entries when full.

## 8. Future Enhancements

1. **Recent commands section** — Show last 5 used commands at the top (above categories).
2. **Command arguments** — After selecting `:unleash`, show a secondary prompt for the task description.
3. **Custom aliases** — Let users define aliases like `/e` → `/edit`.
4. **Tool-specific commands** — When viewing a file in a tool result, show file-related commands (copy path, open in editor).
5. **Multi-key shortcuts** — `g g` to go to top, `G` to go to bottom (vim-style).
6. **Preview pane** — Show command description in a right-hand panel for the selected item.

## 9. Implementation Phases

| Phase | Scope | Effort |
|-------|-------|--------|
| **P1** | `CommandPaletteWidget` with fuzzy search, categories, keyboard nav | 2–3 days |
| **P2** | Integration into `TuiInputHandler`, replace `SelectListWidget` completion | 1 day |
| **P3** | Usage frequency tracking and persistence | 0.5 day |
| **P4** | Recent commands section, preview pane | 1 day |
| **P5** | Custom aliases, argument prompts | 1 day |

## 10. Testing Strategy

1. **Unit tests** for `fuzzyScore()`:
   - Exact match scores highest.
   - Word-boundary matches score higher than mid-word matches.
   - Non-matching queries return -1.
   - Empty query returns 0 (show all).

2. **Unit tests** for `rebuildRows()`:
   - Verify category headers appear between groups.
   - Verify items are sorted by score within each category.
   - Verify prefix filtering (`/` shows slash commands only).

3. **Unit tests** for cursor movement:
   - Skip header rows.
   - Clamp to bounds.
   - Page up/down scrolls correctly.

4. **Integration test** with mock `TuiInputHandler`:
   - Ctrl+P opens palette.
   - Typing filters results.
   - Enter confirms and invokes suspension resume.
   - Escape closes without action.

5. **Visual snapshot test**:
   - Render palette at 80×24 terminal.
   - Verify layout, borders, highlighting.
