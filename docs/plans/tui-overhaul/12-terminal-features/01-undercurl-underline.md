# Advanced Text Decorations: Undercurl, Styled Underlines & Overline

> **Module**: `src/UI/Theme.php`, `src/UI/TerminalCapabilities.php` (new), `vendor/symfony/tui/` (CellBuffer, AnsiCodeTracker, ScreenBuffer patches)
> **Dependencies**: Semantic theming plan (`04-theming/01-semantic-theming.md`), Symfony TUI attribute system
> **Blocks**: Error highlighting UX, diff decoration, interactive element affordances, search-match emphasis

## 1. Problem Statement

### 1.1 Current State

KosmoKrator's `Theme.php` (`src/UI/Theme.php:13`) provides basic ANSI styling: bold (`\x1b[1m`), italic (`\x1b[3m`), strikethrough (`\x1b[9m`), and a single underline (`\x1b[4m`). Symfony TUI's attribute system is similarly limited:

- **`CellBuffer`** (`vendor/.../Render/CellBuffer.php:38-44`) defines 7 bitmask attributes: `BOLD`, `DIM`, `ITALIC`, `UNDERLINE`, `BLINK`, `REVERSE`, `STRIKETHROUGH`.
- **`AnsiCodeTracker`** (`vendor/.../Ansi/AnsiCodeTracker.php:28-29`) tracks only `underline` (SGR 4) and `doubleUnderline` (SGR 21) — no undercurl, dotted, or dashed variants.
- **`ScreenBuffer`** (`vendor/.../Terminal/ScreenBuffer.php:742-755`) already supports underline *color* (`58;5;N` / `58;2;R;G;B`) but does not support styled underline types.
- **`Style`** (`vendor/.../Style/Style.php:99`) exposes only a boolean `underline` — no style or color parameters.

All decoration beyond "underline on/off" is invisible to the rendering pipeline.

### 1.2 What We're Missing

Modern terminals (Kitty, WezTerm, Ghostty, iTerm2, Windows Terminal, foot, Alacritty ≥ 0.13) support the **styled underline** extension (originally Kitty protocol, now widely adopted):

| SGR Sequence | Effect | Visual |
|---|---|---|
| `\x1b[4m` | Standard underline | `━━━` |
| `\x1b[4:0m` | No underline (explicit off) | |
| `\x1b[4:1m` | Standard underline (explicit on) | `━━━` |
| `\x1b[4:2m` | Double underline | `═══` |
| `\x1b[4:3m` | Undercurl (wavy) | `~~~` |
| `\x1b[4:4m` | Dotted underline | `•••` |
| `\x1b[4:5m` | Dashed underline | `---` |
| `\x1b[58;2;R;G;Bm` | Underline color (true-color) | colored version of above |
| `\x1b[58;5;Nm` | Underline color (256-color) | colored version of above |
| `\x1b[59m` | Reset underline color to default | |
| `\x1b[53m` | Overline | overline above text |
| `\x1b[55m` | Reset overline | |

### 1.3 Why It Matters

KosmoKrator is a **code-centric AI agent**. Its most important visual tasks are:

1. **Showing errors** — PHP parse errors, type errors, test failures. Undercurl makes these instantly recognizable (every IDE uses wavy red underlines).
2. **Showing diffs** — word-level change highlighting currently uses background color only. Colored underlines add a second visual channel without obscuring syntax highlighting backgrounds.
3. **Marking search matches** — double underline is distinctive and doesn't interfere with the code's own underlines.
4. **Affording interaction** — dotted underlines on clickable/hoverable elements (like a web browser's link styling).
5. **Section dividers** — overline provides a lighter-weight visual separator than drawing a full box-drawing character line.

Without these, every decoration looks identical — a single solid underline — and users lose visual information density.

---

## 2. Terminal Support Matrix

### 2.1 Styled Underline Support (SGR 4:N)

| Terminal | Undercurl | Double | Dotted | Dashed | Color | Detection |
|---|---|---|---|---|---|---|
| **Kitty ≥ 0.20** | ✅ | ✅ | ✅ | ✅ | ✅ | `$TERM_PROGRAM = "kitty"` or Kitty keyboard protocol DA1 response |
| **WezTerm ≥ 2022** | ✅ | ✅ | ✅ | ✅ | ✅ | `$TERM_PROGRAM = "WezTerm"` |
| **Ghostty ≥ 1.0** | ✅ | ✅ | ✅ | ✅ | ✅ | `$TERM_PROGRAM = "ghostty"` |
| **iTerm2 ≥ 3.5** | ✅ | ✅ | ✅ | ✅ | ✅ | `$TERM_PROGRAM = "iTerm.app"` |
| **Windows Terminal** | ✅ | ✅ | ✅ | ✅ | ✅ | `$WT_SESSION` set |
| **foot ≥ 1.13** | ✅ | ✅ | ✅ | ✅ | ✅ | `$TERM = "foot"` or `$TERM = "foot-direct"` |
| **Alacritty ≥ 0.13** | ✅ | ✅ | ✅ | ✅ | ✅ | `$TERM_PROGRAM` or version check |
| **Konsole ≥ 22.12** | ✅ | ✅ | ✅ | ✅ | ✅ | `$KONSOLE_VERSION` |
| **tmux ≥ 3.4** | ✅ (pass-through) | ✅ | ✅ | ✅ | ✅ | `$TMUX` set, check `tmux -V` |
| **screen** | ❌ | ❌ | ❌ | ❌ | ❌ | Fallback to plain `\x1b[4m` |
| **xterm** | ❌ | ❌ | ❌ | ❌ | ❌ | Fallback |
| **Linux console** | ❌ | ❌ | ❌ | ❌ | ❌ | Fallback |

### 2.2 Overline Support (SGR 53)

| Terminal | Overline | Notes |
|---|---|---|
| **Kitty** | ✅ | Full support |
| **WezTerm** | ✅ | Full support |
| **Ghostty** | ✅ | Full support |
| **iTerm2** | ❌ | Not supported as of 3.5 |
| **Windows Terminal** | ❌ | Not supported |
| **foot** | ✅ | Full support |
| **Alacritty** | ❌ | Not supported |
| **Konsole** | ✅ | Full support |

### 2.3 Key Insight

The intersection of **styled underline + underline color** is well-supported across all modern GPU-accelerated terminals. The key is detecting `$TERM_PROGRAM` and `$TERM` environment variables for a fast path, with a DA1-based feature query as the authoritative fallback.

---

## 3. Architecture

### 3.1 New Files

```
src/UI/TerminalCapabilities.php     — Capability detection singleton
```

### 3.2 Modified Files

```
src/UI/Theme.php                    — Add decoration helper methods
vendor/.../Render/CellBuffer.php    — Extend attribute bitmask for underline styles
vendor/.../Ansi/AnsiCodeTracker.php — Track underline style + color
vendor/.../Terminal/ScreenBuffer.php — Emit styled underline sequences
vendor/.../Style/Style.php          — Add underlineStyle, underlineColor params
```

---

## 4. Implementation Plan

### 4.1 Phase 1: Terminal Capability Detection

**File**: `src/UI/TerminalCapabilities.php` (new)

```php
<?php
declare(strict_types=1);

namespace Kosmokrator\UI;

/**
 * Detects terminal support for advanced text decorations.
 *
 * Uses environment variables for fast detection, with an optional
 * DA1 (Device Attributes) query for authoritative results.
 */
final class TerminalCapabilities
{
    private static ?self $instance = null;

    private bool $supportsStyledUnderline;
    private bool $supportsUnderlineColor;
    private bool $supportsOverline;

    private function __construct()
    {
        $this->detect();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // Reset singleton (for testing or after terminal change)
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function supportsStyledUnderline(): bool
    {
        return $this->supportsStyledUnderline;
    }

    public function supportsUnderlineColor(): bool
    {
        return $this->supportsUnderlineColor;
    }

    public function supportsOverline(): bool
    {
        return $this->supportsOverline;
    }

    private function detect(): void
    {
        $program = getenv('TERM_PROGRAM') ?: '';
        $term = getenv('TERM') ?: '';

        // Terminals with full styled underline support
        $styledTerminals = [
            'kitty'      => true,
            'WezTerm'    => true,
            'ghostty'    => true,
            'iTerm.app'  => true,
        ];

        // Terminals with overline support
        $overlineTerminals = [
            'kitty'      => true,
            'WezTerm'    => true,
            'ghostty'    => true,
            'foot'       => true,  // via $TERM
        ];

        $this->supportsStyledUnderline
            = isset($styledTerminals[$program])
            || getenv('WT_SESSION') !== false  // Windows Terminal
            || str_starts_with($term, 'foot')  // foot terminal
            || $this->isKonsole()
            || $this->isTmuxWithPassThrough()
            || $this->isAlacritty();

        $this->supportsUnderlineColor = $this->supportsStyledUnderline;

        $this->supportsOverline
            = isset($overlineTerminals[$program])
            || str_starts_with($term, 'foot')
            || $this->isKonsole();
    }

    private function isKonsole(): bool
    {
        return getenv('KONSOLE_VERSION') !== false;
    }

    private function isTmuxWithPassThrough(): bool
    {
        if (getenv('TMUX') === false) {
            return false;
        }
        $version = trim((string) shell_exec('tmux -V 2>/dev/null'));
        // tmux 3.4+ passes through styled underlines
        return preg_match('/(\d+)\.(\d+)/', $version, $m) === 1
            && ((int) $m[1] > 3 || ((int) $m[1] === 3 && (int) $m[2] >= 4));
    }

    private function isAlacritty(): bool
    {
        // Alacritty doesn't set TERM_PROGRAM reliably; check terminfo
        $term = getenv('TERM') ?: '';
        return str_contains($term, 'alacritty');
    }
}
```

**Key decisions**:
- Singleton pattern — capabilities don't change during a session.
- Environment-variable fast path — no I/O overhead at startup.
- Future: add `queryDa1()` method for terminals that support XTVERSION/DA1 queries.

### 4.2 Phase 2: Theme Decoration Helpers

**File**: `src/UI/Theme.php` — add after `strikethrough()` (line 186)

```php
// --- Advanced text decorations (with fallback) ---

/**
 * Standard underline (SGR 4). Universal fallback for all styled variants.
 */
public static function underline(): string
{
    return self::ESC.'[4m';
}

/**
 * Undercurl / wavy underline (SGR 4:3). Falls back to standard underline.
 * Ideal for errors and warnings.
 */
public static function undercurl(): string
{
    if (!TerminalCapabilities::getInstance()->supportsStyledUnderline()) {
        return self::underline();
    }
    return self::ESC.'[4:3m';
}

/**
 * Double underline (SGR 4:2). Falls back to standard underline.
 * Ideal for search matches and emphasis.
 */
public static function doubleUnderline(): string
{
    if (!TerminalCapabilities::getInstance()->supportsStyledUnderline()) {
        return self::underline();
    }
    return self::ESC.'[4:2m';
}

/**
 * Dotted underline (SGR 4:4). Falls back to standard underline.
 * Ideal for interactive/clickable elements.
 */
public static function dottedUnderline(): string
{
    if (!TerminalCapabilities::getInstance()->supportsStyledUnderline()) {
        return self::underline();
    }
    return self::ESC.'[4:4m';
}

/**
 * Dashed underline (SGR 4:5). Falls back to standard underline.
 * Ideal for de-emphasized links and annotations.
 */
public static function dashedUnderline(): string
{
    if (!TerminalCapabilities::getInstance()->supportsStyledUnderline()) {
        return self::underline();
    }
    return self::ESC.'[4:5m';
}

/**
 * Set underline color (true-color). Falls back to no-op.
 *
 * @param int $r Red (0-255)
 * @param int $g Green (0-255)
 * @param int $b Blue (0-255)
 */
public static function underlineColor(int $r, int $g, int $b): string
{
    if (!TerminalCapabilities::getInstance()->supportsUnderlineColor()) {
        return '';
    }
    return self::ESC."[58;2;{$r};{$g};{$b}m";
}

/**
 * Reset underline color to default (SGR 59).
 */
public static function underlineColorReset(): string
{
    if (!TerminalCapabilities::getInstance()->supportsUnderlineColor()) {
        return '';
    }
    return self::ESC.'[59m';
}

/**
 * Overline (SGR 53). Falls back to no-op.
 */
public static function overline(): string
{
    if (!TerminalCapabilities::getInstance()->supportsOverline()) {
        return '';
    }
    return self::ESC.'[53m';
}

/**
 * Reset overline (SGR 55).
 */
public static function overlineReset(): string
{
    if (!TerminalCapabilities::getInstance()->supportsOverline()) {
        return '';
    }
    return self::ESC.'[55m';
}

/**
 * Reset underline and its style (SGR 24). Works on all terminals.
 */
public static function underlineReset(): string
{
    return self::ESC.'[24m';
}
```

**Design principle**: Every method degrades gracefully. No broken escape sequences on unsupported terminals. The semantic meaning is preserved (e.g., undercurl → plain underline → the user still sees *something* under the error).

### 4.3 Phase 3: Symfony TUI CellBuffer Extension

**File**: `vendor/.../Render/CellBuffer.php` — extend attribute bitmask

Current bitmask (7 bits, values 1–64):
```php
public const ATTR_BOLD          = 1;    // bit 0
public const ATTR_DIM           = 2;    // bit 1
public const ATTR_ITALIC        = 4;    // bit 2
public const ATTR_UNDERLINE     = 8;    // bit 3
public const ATTR_BLINK         = 16;   // bit 4
public const ATTR_REVERSE       = 32;   // bit 5
public const ATTR_STRIKETHROUGH = 64;   // bit 6
```

Proposed additions (bits 7–11):
```php
public const ATTR_DOUBLE_UNDERLINE = 128;   // bit 7  — SGR 4:2
public const ATTR_UNDERCURL        = 256;   // bit 8  — SGR 4:3
public const ATTR_DOTTED_UNDERLINE = 512;   // bit 9  — SGR 4:4
public const ATTR_DASHED_UNDERLINE = 1024;  // bit 10 — SGR 4:5
public const ATTR_OVERLINE         = 2048;  // bit 11 — SGR 53
```

Add a separate underline color storage (not in the bitmask — it's a color value like fg/bg):
```php
/** @var string[] Underline color code (e.g., "58;2;255;0;0") or "" for default */
private array $underlineColor;
```

**Modified `sgrForState()`** (`CellBuffer.php:430-467`):
```php
private function sgrForState(string $fg, string $bg, int $attrs, string $ulColor = ''): string
{
    // Fast path: reset to default
    if ('' === $fg && '' === $bg && 0 === $attrs && '' === $ulColor) {
        return "\x1b[0m";
    }

    $sgr = "\x1b[0";

    if ($attrs & self::ATTR_BOLD) {
        $sgr .= ';1';
    }
    if ($attrs & self::ATTR_DIM) {
        $sgr .= ';2';
    }
    if ($attrs & self::ATTR_ITALIC) {
        $sgr .= ';3';
    }

    // Underline variants — only one style is active at a time
    if ($attrs & self::ATTR_UNDERCURL) {
        $sgr .= ';4:3';
    } elseif ($attrs & self::ATTR_DOUBLE_UNDERLINE) {
        $sgr .= ';4:2';
    } elseif ($attrs & self::ATTR_DOTTED_UNDERLINE) {
        $sgr .= ';4:4';
    } elseif ($attrs & self::ATTR_DASHED_UNDERLINE) {
        $sgr .= ';4:5';
    } elseif ($attrs & self::ATTR_UNDERLINE) {
        $sgr .= ';4';
    }

    if ($attrs & self::ATTR_BLINK) {
        $sgr .= ';5';
    }
    if ($attrs & self::ATTR_REVERSE) {
        $sgr .= ';7';
    }
    if ($attrs & self::ATTR_STRIKETHROUGH) {
        $sgr .= ';9';
    }
    if ($attrs & self::ATTR_OVERLINE) {
        $sgr .= ';53';
    }
    if ('' !== $fg) {
        $sgr .= ';'.$fg;
    }
    if ('' !== $bg) {
        $sgr .= ';'.$bg;
    }
    if ('' !== $ulColor) {
        $sgr .= ';'.$ulColor;
    }

    return $sgr.'m';
}
```

**Modified `parseSgrInline()`** (`CellBuffer.php:476+`): Add parsing for `4:N` sub-parameters and SGR 53/55:

```php
// In the code parsing loop, handle compound SGR 4:N
if (4 === $c) {
    // Check if next "code" is actually a sub-parameter (4:2, 4:3, etc.)
    // Sub-parameters appear as separate semicolon-delimited values in the
    // same SGR sequence, e.g., \x1b[4:3m becomes params "4:3"
    // BUT in practice, terminals send \x1b[4:3m and the colon is within
    // a single parameter. We need to handle both forms.
    $attrs |= self::ATTR_UNDERLINE;
}
// Handle colon-sub-parameter form in the raw param string
// (requires looking at the raw string before integer conversion)
```

**Note**: The colon sub-parameter syntax (`4:3`) is not standard semicolon-delimited SGR. Terminals may emit `\x1b[4:3m` where `4:3` is a single parameter with a colon sub-separator. The parser needs to detect colons in the raw parameter string. This requires modifying `parseSgrInline()` to check for `:` within parameter boundaries before converting to integer.

### 4.4 Phase 4: AnsiCodeTracker Extension

**File**: `vendor/.../Ansi/AnsiCodeTracker.php`

Add new state fields:
```php
private bool $undercurl = false;
private bool $dottedUnderline = false;
private bool $dashedUnderline = false;
private bool $overline = false;
private ?string $underlineColor = null;
```

Modify `process()` to handle `4:N` sequences:
```php
// When code is 4, peek for sub-parameter
4 => $this->underline = true,  // existing
// New: when the raw param contains ':', parse sub-style
// e.g., "4:3" → undercurl
```

Modify `getActiveCodes()` to emit the correct variant:
```php
// Only one underline style active at a time — priority: undercurl > double > dotted > dashed > plain
if ($this->undercurl) {
    $codes[] = '4:3';
} elseif ($this->dottedUnderline) {
    $codes[] = '4:4';
} elseif ($this->dashedUnderline) {
    $codes[] = '4:5';
} elseif ($this->underline) {
    $codes[] = '4';
}
if ($this->doubleUnderline) {
    $codes[] = '21';
}
```

Add `getLineEndReset()` to include overline:
```php
public function getLineEndReset(): string
{
    $resets = '';
    if ($this->underline || $this->doubleUnderline || $this->undercurl
        || $this->dottedUnderline || $this->dashedUnderline) {
        $resets .= "\x1b[24m";
    }
    if ($this->overline) {
        $resets .= "\x1b[55m";
    }
    return $resets;
}
```

### 4.5 Phase 5: Style Class Extension

**File**: `vendor/.../Style/Style.php`

Add underline style and color to the constructor and wither methods:

```php
public enum UnderlineStyle: string
{
    case None = 'none';
    case Single = 'single';       // SGR 4
    case Double = 'double';       // SGR 4:2
    case Curly = 'curly';         // SGR 4:3 (undercurl)
    case Dotted = 'dotted';       // SGR 4:4
    case Dashed = 'dashed';       // SGR 4:5
}

// New constructor parameters:
private ?UnderlineStyle $underlineStyle = null,   // replaces bool $underline
private ?Color $underlineColor = null,             // underline color
private ?bool $overline = null,                    // overline
```

Withers:
```php
public function withUnderlineStyle(UnderlineStyle $style): self;
public function withUnderlineColor(Color|string|int|null $color): self;
public function withOverline(bool $overline = true): self;
```

Backward compatibility: `withUnderline(true)` maps to `UnderlineStyle::Single`. `getUnderline()` returns `true` when any underline style is set.

---

## 5. Usage in KosmoKrator — Semantic Decoration Mapping

### 5.1 Undercurl — Errors & Warnings

**Use case**: PHP errors, type errors, lint warnings in code blocks and tool output.

Before (current):
```
  Theme::error() . "Parse error: syntax error, unexpected ';'" . Theme::reset()
  → Red text: Parse error: syntax error, unexpected ';'
```

After (with undercurl):
```
  Theme::error() . Theme::undercurl()
    . "Parse error: syntax error, unexpected ';'"
    . Theme::underlineReset() . Theme::reset()
  → Red text with wavy red underline: Parse error: syntax error, unexpected ';'
    ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
```

For warnings:
```
  Theme::warning() . Theme::undercurl()
    . Theme::underlineColor(255, 200, 80)
    . "Deprecated: Optional parameter declared before required"
    . Theme::underlineColorReset()
    . Theme::underlineReset()
    . Theme::reset()
  → Yellow text with amber wavy underline
```

**Implementation sites**:
- `src/UI/Ansi/CodeBlockRenderer.php` — error annotation rendering
- `src/UI/Ansi/BashOutputRenderer.php` — stderr line highlighting
- `src/UI/Tui/Widget/BashCommandWidget.php` — error output in collapsible tool calls

### 5.2 Colored Underline — Diffs

**Use case**: Word-level diff highlighting that doesn't conflict with syntax highlighting backgrounds.

Before (current):
```
  Theme::diffAddBgStrong() . "changed_word" . Theme::diffAddBg()
  → Green background highlight on the changed word
```

After (with colored underline):
```
  Theme::diffAdd() . Theme::underlineColor(80, 220, 100) . Theme::underline()
    . "changed_word"
    . Theme::underlineColorReset() . Theme::underlineReset() . Theme::reset()
  → Green text with bright green underline: changed_word
                                             ═════════════
```

For removals:
```
  Theme::diffRemove() . Theme::underlineColor(255, 80, 60) . Theme::undercurl()
    . "removed_word"
    . Theme::underlineColorReset() . Theme::underlineReset() . Theme::reset()
  → Red text with red wavy underline (shows destructive nature): removed_word
                                                                    ~~~~~~~~~~~~
```

**Advantage over background-color approach**: Syntax highlighting backgrounds are preserved. The underline layer is independent — the user sees both the syntax color AND the diff indication simultaneously.

**Implementation sites**:
- `src/UI/Ansi/DiffRenderer.php` — word-level diff spans
- `src/UI/Tui/Widget/ApplyPatchWidget.php` — patch preview

### 5.3 Double Underline — Search Matches

**Use case**: Highlighting matched text in code search results (`grep` output, search-in-conversation).

Before (current):
```
  Theme::accent() . "matched_term" . Theme::reset()
  → Gold text
```

After:
```
  Theme::accent() . Theme::doubleUnderline()
    . "matched_term"
    . Theme::underlineReset() . Theme::reset()
  → Gold text with double underline: matched_term
                                      ════════════
```

The double underline is visually distinct from single underlines that may already exist in the code (e.g., links in markdown). It's bold and unmistakable as a "search hit" indicator.

**Implementation sites**:
- `src/UI/Ansi/GrepRenderer.php` — matched pattern highlighting
- Future: search-in-conversation feature

### 5.4 Dotted Underline — Interactive Elements

**Use case**: Clickable file paths, expandable sections, keyboard shortcuts — any element that responds to interaction.

Before (current):
```
  Theme::link() . "src/UI/Theme.php" . Theme::reset()
  → Blue text
```

After:
```
  Theme::link() . Theme::dottedUnderline()
    . "src/UI/Theme.php"
    . Theme::underlineReset() . Theme::reset()
  → Blue text with dotted underline: src/UI/Theme.php
                                      ··················
```

This mirrors web browser link styling (dotted underline for "this is clickable"), providing immediate affordance without needing explicit instructions like "(click to open)".

**Implementation sites**:
- `src/UI/Tui/Widget/CollapsibleWidget.php` — expand/collapse affordance
- `src/UI/Ansi/FilePathRenderer.php` — file path links in tool output
- `src/UI/Tui/Widget/PlanApprovalWidget.php` — action buttons

### 5.5 Overline — Section Dividers

**Use case**: Lightweight visual separators between conversation turns, tool call sections, or status areas.

Before (current):
```
  Theme::dim() . "───────────────────────────" . Theme::reset()
  → A full line of dim box-drawing characters
```

After:
```
  Theme::dim() . Theme::overline()
    . "                           "
    . Theme::overlineReset() . Theme::reset()
  → A single thin line rendered directly above the whitespace
```

**Advantage**: Overline is rendered by the terminal at sub-character precision — thinner and more elegant than a box-drawing character. It doesn't consume a full line of vertical space; it decorates the existing line.

**Implementation sites**:
- `src/UI/Ansi/ConversationRenderer.php` — turn separators
- `src/UI/Tui/Widget/StatusBarWidget.php` — status bar top border
- `src/UI/Ansi/ToolCallRenderer.php` — tool result section dividers

---

## 6. Visual Examples

### 6.1 Error Highlighting

```
┌─ Bash ──────────────────────────────────────────────────────┐
│ ⚡ php -l src/UI/Theme.php                                   │
│                                                              │
│ Parse error: syntax error, unexpected ';'                    │
│              ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~                │
│ in src/UI/Theme.php on line 42                               │
│                    ~~~~~~~~                                   │
└──────────────────────────────────────────────────────────────┘

Before: Red text only — easy to miss in a wall of output
After:  Red text + red undercurl — immediately draws the eye,
        consistent with every IDE's error display convention
```

### 6.2 Diff With Colored Underline

```
  // Old line (removed):
-     return self::ESC."[4m";
                       ~~~ ← red undercurl indicating removal
  // New line (added):
+     return self::ESC."[4:3m";
                       ~~~~ ← green underline indicating addition

Before: Background color changes (obscures syntax highlighting)
After:  Underline color + style — syntax colors fully visible,
        diff indication in a separate visual channel
```

### 6.3 Search Results

```
  grep -rn "CellBuffer" src/
  src/Render/CellBuffer.php:38:    public const ATTR_BOLD = 1;
                         ═════════                        ← gold double underline
  src/Render/CellBuffer.php:41:    public const ATTR_UNDERLINE = 8;
                         ═════════

Before: Gold foreground only — could be confused with highlighted keywords
After:  Double underline — unmistakably "this is a search match"
```

### 6.4 Interactive Elements

```
  Files modified:
    ·src/UI/Theme.php·        ← dotted blue underline (clickable)
    ·src/UI/TerminalCapabilities.php·  ← dotted blue underline (clickable)

  Press Enter to open, Esc to dismiss

Before: Blue text — doesn't look clickable
After:  Dotted blue underline — immediately recognizable as interactive
```

### 6.5 Section Dividers With Overline

```
  ───── Previous conversation ─────     ← overline + text + overline
  (continues below with overline as the top border)

Before: Full line of ─────── characters (takes up a full content line)
After:  Overline on a thin spacer (subtle, doesn't waste vertical space)
```

---

## 7. Fallback Strategy

### 7.1 Decision Tree

```
TerminalCapabilities::supportsStyledUnderline()?
├── YES → Use SGR 4:N (styled underline)
│   └── TerminalCapabilities::supportsUnderlineColor()?
│       ├── YES → Use SGR 58;2;R;G;Bm (colored underline)
│       └── NO  → Use default-colored styled underline only
└── NO  → Fallback to SGR 4 (plain underline)
    └── Use foreground color to hint at semantic meaning

TerminalCapabilities::supportsOverline()?
├── YES → Use SGR 53m (overline)
└── NO  → Fallback to dim ─── box-drawing characters
```

### 7.2 Fallback Rendering Examples

| Feature | Supported | Unsupported |
|---|---|---|
| Error | Red undercurl (`4:3`) | Red plain underline (`4`) |
| Warning | Amber undercurl (`4:3` + color) | Amber plain underline (`4`) |
| Diff add | Green underline (`4` + green color) | Green bold text |
| Diff remove | Red undercurl (`4:3` + red color) | Red strikethrough text |
| Search match | Gold double underline (`4:2`) | Gold bold underline (`1;4`) |
| Interactive | Blue dotted underline (`4:4`) | Blue plain underline (`4`) |
| Overline | SGR 53 | Dim `─` characters |

### 7.3 Testing Matrix

A `\KosmoKrator\Tests\UI\TerminalDecorationTest` should verify:

1. Each decoration method emits correct sequences when capability is on.
2. Each decoration method falls back correctly when capability is off.
3. Underline styles are mutually exclusive (setting undercurl clears double).
4. Underline color is independent of underline style.
5. Reset sequences (`24m`, `55m`, `59m`) are always safe (no broken state).
6. Capability detection returns correct results for known `$TERM_PROGRAM` values.

---

## 8. Performance Considerations

### 8.1 Singleton Overhead

`TerminalCapabilities::getInstance()` is called once per `Theme::*()` call. PHP's static singleton is O(1) after first construction. The first call does ~5 `getenv()` calls and optionally one `shell_exec('tmux -V')` — all under 1ms.

### 8.2 CellBuffer Array Growth

Adding `underlineColor[]` parallel array: `width × height × ~24 bytes` for string storage. At 200×50 = 10,000 cells, that's ~240KB — negligible.

### 8.3 Escape Sequence Length

A fully-decorated cell (`\x1b[0;1;3;4:3;53;38;2;R;G;B;48;2;R;G;B;58;2;R;G;Bm`) is ~45 bytes. Current max is ~25 bytes. This only affects the diff/serialization step, not per-frame rendering.

---

## 9. Implementation Order

| Step | File | Change | Effort |
|---|---|---|---|
| **1** | `src/UI/TerminalCapabilities.php` | New file — capability detection | Small |
| **2** | `src/UI/Theme.php` | Add decoration helpers (undercurl, double, dotted, dashed, overline, underlineColor) | Small |
| **3** | `vendor/.../Render/CellBuffer.php` | Extend bitmask, add underlineColor array, update `sgrForState()` and `parseSgrInline()` | Medium |
| **4** | `vendor/.../Ansi/AnsiCodeTracker.php` | Track new underline styles, underline color, overline | Medium |
| **5** | `vendor/.../Terminal/ScreenBuffer.php` | Emit styled underline + overline in output | Medium |
| **6** | `vendor/.../Style/Style.php` | Add `UnderlineStyle` enum, wither methods, overline | Medium |
| **7** | `src/UI/Ansi/DiffRenderer.php` | Apply colored underlines to word-level diffs | Small |
| **8** | `src/UI/Ansi/BashOutputRenderer.php` | Apply undercurl to stderr/error lines | Small |
| **9** | `src/UI/Tui/Widget/CollapsibleWidget.php` | Dotted underline on expand triggers | Small |
| **10** | Tests | `TerminalDecorationTest`, `CellBufferDecorationTest` | Medium |

**Steps 1–2** can ship immediately — pure addition, zero risk to existing rendering.
**Steps 3–6** require Symfony TUI patches and should be a coordinated upstream PR.
**Steps 7–9** are integration work that depends on steps 1–2 (ANSI renderer) or 3–6 (TUI renderer).

---

## 10. Future Extensions

- **Blinking underline** — some terminals support `5:2` (rapid blink) or `5:3` (slow blink). Could be used for "currently processing" indicators.
- **Colored overline** — no standard yet, but Kitty has proposed `54;2;R;G;Bm`. Monitor and add when adopted.
- **DECSTR soft reset** — ensure all new attributes are properly reset on terminal soft-reset (`\x1b[!p`).
- **HTML renderer** — `ScreenBufferHtmlRenderer.php` already maps underline to CSS `text-decoration: underline`. Extend to map `4:3` → `wavy`, `4:2` → `double`, `4:4` → `dotted`, `4:5` → `dashed` for the web preview fallback.
