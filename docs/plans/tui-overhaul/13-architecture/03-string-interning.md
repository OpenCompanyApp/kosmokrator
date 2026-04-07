# 03 — String Interning & Memory Reduction

> Plan: Reduce per-frame string allocation in KosmoKrator's TUI by interning
> ANSI sequences, caching Theme results, reusing render buffers, and
> deduplicating widget content.

---

## 1. Problem Analysis

### 1.1 Scale of the problem

| Metric | Value |
|---|---|
| Total `Theme::` static calls in `src/UI/` | **1,462** |
| Total `Theme::` calls in `src/UI/Tui/` only | **224** |
| Unique `Theme::` methods called | **~30** |
| `Theme::reset()` calls across UI | **218** |
| `Theme::rgb()` calls across UI | **449** (367 unique RGB triplets) |
| `Theme::moveTo()` calls across UI | **349** (all dynamic) |
| `Theme::clearScreen()` calls | **86** |
| Widgets with `render()` methods | **12** |
| String concatenation / implode sites in TUI | **81** |
| `SettingsWorkspaceWidget` alone (Theme calls / LOC) | **36 / 1,966** |

### 1.2 How strings are built today

Every `Theme` method is **pure but uncached** — it builds a fresh PHP string on
every call:

```php
// Theme.php — every invocation allocates a new string
public static function rgb(int $r, int $g, int $b): string
{
    return self::ESC."[38;2;{$r};{$g};{$b}m";
}
```

A typical `render()` method fetches 3–6 Theme colors per frame:

```php
// DiscoveryBatchWidget::render() — called every redraw
$r     = Theme::reset();      // "\033[0m"
$gold  = Theme::accent();     // "\033[38;2;255;200;80m"
$dim   = Theme::dim();        // "\033[38;5;240m"
$text  = Theme::text();       // "\033[38;2;180;180;190m"
```

These 4 calls produce 4 identical strings every frame. With ~12 widgets and
multiple sub-renders, a single redraw generates **~60–80 identical ANSI
strings** that are duplicates of strings created in the previous frame.

### 1.3 String proliferation vectors

#### A. ANSI escape sequences (Theme methods)

The same ~30 named colors and control sequences are re-created on every
`render()` call. With a 30fps breathing animation running, that's:
- 30 sequences × ~4 calls/widget × 12 widgets = **~1,440 identical strings/second**
- Each ANSI string is ~20 bytes → **~28 KB/s of throwaway allocations**

#### B. Render buffer arrays

Every `render()` returns `array<string>` (a new PHP array each frame). The
breathing animation triggers redraws at ~30 Hz. Even if only the status bar
and task bar change, the entire widget tree is re-rendered:
- `refreshTaskBar()` → builds 1–3 lines
- `refreshStatusBar()` → builds 1 line
- `refreshHistoryStatus()` → builds 1–3 lines
- Breathing animation → rebuilds animation overlay

Each frame allocates a fresh `array` + its string elements.

#### C. Markdown rendering

`MarkdownToAnsi::render()` walks a CommonMark AST and builds output via
string concatenation (`$output .= ...`, `$inlineBuffer .= ...`). For a
typical agent response of ~50 paragraphs:
- **~200–400 intermediate string concatenations** (headings, code blocks,
  inline formatting, list items)
- `wrapAnsiText()` splits into arrays of wrapped lines

#### D. Widget content deduplication

Multiple widgets may display the same content (e.g., tool labels, file paths,
token counts). Currently each widget builds its own copy.

### 1.4 Impact estimate

During active agent operation with streaming + breathing animation:

| Source | Est. allocations/frame | Est. bytes/frame | At 30fps |
|---|---|---|---|
| Theme color strings (duplicates) | ~60 | ~1,200 B | 36 KB/s |
| Render buffers (arrays) | ~12 arrays × ~20 lines | ~5,000 B | 150 KB/s |
| Markdown intermediate strings | ~100 (during streaming) | ~5,000 B | burst |
| Concatenation overhead (temp copies) | ~80 | ~3,000 B | 90 KB/s |
| **Total** | | | **~280 KB/s** |

Over a 60-second agent run, that's **~16 MB** of short-lived string garbage.
PHP's GC handles this, but the allocation pressure contributes to occasional
frame stalls, especially on constrained systems.

---

## 2. Design: Five Optimizations

### 2.1 AnsiStringPool — Intern ANSI escape sequences

**Goal:** Replace per-frame string creation with shared references.

```php
namespace Kosmokrator\UI\Tui\Buffer;

final class AnsiStringPool
{
    /** @var array<string, string> keyed by raw ANSI bytes */
    private static array $pool = [];

    /**
     * Intern an ANSI string. Returns the same reference for identical input.
     */
    public static function intern(string $ansi): string
    {
        return self::$pool[$ansi] ??= $ansi;
    }

    /**
     * Intern a 24-bit foreground color.
     */
    public static function rgb(int $r, int $g, int $b): string
    {
        return self::intern("\033[38;2;{$r};{$g};{$b}m");
    }

    /**
     * Intern a 24-bit background color.
     */
    public static function bgRgb(int $r, int $g, int $b): string
    {
        return self::intern("\033[48;2;{$r};{$g};{$b}m");
    }

    /**
     * Intern a 256-color foreground.
     */
    public static function color256(int $code): string
    {
        return self::intern("\033[38;5;{$code}m");
    }

    /**
     * Clear the pool (called on theme change or shutdown).
     */
    public static function clear(): void
    {
        self::$pool = [];
    }
}
```

**Integration:** Modify `Theme` methods to use the pool internally:

```php
// Theme.php — add pooling (backward-compatible)
public static function rgb(int $r, int $g, int $b): string
{
    return AnsiStringPool::rgb($r, $g, $b);
}

public static function reset(): string
{
    return AnsiStringPool::intern("\033[0m");
}
```

**Estimated savings:** ~1,440 fewer allocations/second → **~36 KB/s** saved.
The pool holds ~30–50 unique strings (~1 KB total) that are reused forever.

**Complexity:** Low. One new class, modify ~8 Theme methods to route through pool.

---

### 2.2 Theme Cache — Memoize Theme method results

**Goal:** Cache all stateless Theme methods so repeated calls return the same
string reference.

```php
namespace Kosmokrator\UI;

class Theme
{
    /** @var array<string, string> Method name → cached result */
    private static array $cache = [];

    private static function cached(string $key, callable $factory): string
    {
        return self::$cache[$key] ??= $factory();
    }

    public static function accent(): string
    {
        return self::cached('accent', fn() => self::rgb(255, 200, 80));
    }

    public static function reset(): string
    {
        return self::cached('reset', fn() => self::ESC.'[0m');
    }

    public static function contextColor(float $ratio): string
    {
        // Quantize to 0.01 steps — only ~100 cached entries max
        $key = 'context/' . round($ratio, 2);
        return self::cached($key, fn() => self::computeContextColor($ratio));
    }

    // ... same pattern for all 30 named methods
}
```

**Why both AnsiStringPool AND Theme cache?**
- `AnsiStringPool` operates at the raw ANSI bytes level — catches duplicates
  from *any* source (Theme, hardcoded, AnsiArt, animations).
- `Theme cache` catches the higher-level named methods and avoids even
  entering the pool lookup for repeated calls.
- Together they're complementary: Theme cache prevents function call overhead;
  AnsiStringPool deduplicates the underlying bytes.

**Note:** `Theme::moveTo(int $row, int $col)` is dynamic and called 349 times
across the codebase (0 in Tui/ — only in Ansi animations). It should NOT be
cached since row/col changes every call. Same for `contextBar()` which produces
variable-length output.

**Estimated savings:** Eliminates ~100 redundant method calls per frame in the
TUI path. Marginal additional savings over AnsiStringPool, but simplifies all
widget code that currently stores `$r = Theme::reset()` in a local variable as
a manual optimization.

**Complexity:** Low. Add `$cache` array + `cached()` helper. Wrap ~30 methods.

---

### 2.3 StringBuilder — Efficient string building

**Goal:** Reduce temporary string copies from concatenation.

PHP strings are immutable. Each `$a .= $b` creates a new string. For the
Markdown renderer which does hundreds of concatenations, this creates a chain
of increasingly-large temporary strings.

```php
namespace Kosmokrator\UI\Tui\Buffer;

final class StringBuilder
{
    /** @var list<string> */
    private array $parts = [];
    private int $length = 0;

    public function append(string $str): self
    {
        if ($str !== '') {
            $this->parts[] = $str;
            $this->length += strlen($str);
        }
        return $this;
    }

    public function appendLine(string $str = ''): self
    {
        return $this->append($str . "\n");
    }

    public function length(): int
    {
        return $this->length;
    }

    public function toString(): string
    {
        return implode('', $this->parts);
    }

    public function clear(): void
    {
        $this->parts = [];
        $this->length = 0;
    }
}
```

**Primary target:** `MarkdownToAnsi::render()` which currently does:

```php
private string $output = '';
private string $inlineBuffer = '';

private function appendInline(string $text): void
{
    $this->inlineBuffer .= $text;  // Creates new string each time
}

private function flushParagraph(): void
{
    // ... wraps and appends to $output
    $this->output .= ...;
}
```

Refactored:

```php
private StringBuilder $output;
private StringBuilder $inlineBuffer;

private function appendInline(string $text): void
{
    $this->inlineBuffer->append($text);  // Just pushes to array
}
```

**Secondary targets:**
- Widget `render()` methods that build `$lines[] = ...` arrays — replace the
  concatenation inside each line with StringBuilder
- `CollapsibleWidget` — builds long formatted strings
- `SettingsWorkspaceWidget` — 1,966 lines with 36 Theme calls

**Estimated savings:** ~50% reduction in temporary string copies during
Markdown rendering. For a typical agent response: ~200 fewer intermediate
strings. During streaming (multiple responses), cumulative savings of **~5 KB
per response**.

**Complexity:** Medium. New class is trivial; refactoring MarkdownToAnsi to
use it requires touching ~20 methods.

---

### 2.4 Render Buffer Reuse — Pool string arrays between frames

**Goal:** Stop allocating new `array<string>` on every `render()` call.

Currently every widget's `render()` creates a fresh `array`:

```php
public function render(RenderContext $context): array
{
    $lines = [];           // New array every frame
    $lines[] = "...";      // New string elements
    $lines[] = "...";
    return $lines;         // Returned, then discarded by caller
}
```

The breathing animation refreshes at ~30 Hz, meaning 30 array allocations per
second per active widget.

**Design: Buffer pool per widget**

```php
namespace Kosmokrator\UI\Tui\Buffer;

final class RenderBuffer
{
    /** @var list<string> */
    private array $lines = [];
    private int $count = 0;

    /**
     * Reset the buffer for a new frame (reuses the underlying array).
     */
    public function reset(): void
    {
        $this->count = 0;
    }

    /**
     * Add a line to the buffer. Overwrites previous frame's data at same index.
     */
    public function addLine(string $line): void
    {
        $this->lines[$this->count] = $line;
        $this->count++;
    }

    /**
     * Extract the rendered lines as a plain array.
     */
    public function toArray(): array
    {
        return array_slice($this->lines, 0, $this->count);
    }

    /**
     * Number of lines in the current frame.
     */
    public function count(): int
    {
        return $this->count;
    }
}
```

**Integration:** Widgets that render frequently (task bar, status bar, history
status, animation overlay) receive a `RenderBuffer` instead of building arrays:

```php
// Before
public function render(RenderContext $context): array
{
    $lines = [];
    $lines[] = Theme::accent() . 'Status: ...' . Theme::reset();
    return $lines;
}

// After
public function render(RenderContext $context, RenderBuffer $buffer): array
{
    $buffer->reset();
    $buffer->addLine(Theme::accent() . 'Status: ...' . Theme::reset());
    return $buffer->toArray();
}
```

For frequently-rendered widgets (task bar at 30fps), the buffer's internal
array stabilizes at its maximum line count and stops growing. Array indices
are overwritten in place rather than appended to a new array.

**Estimated savings:** For 3 hot-path widgets × 30 fps:
- ~90 fewer array allocations/second
- ~90 × ~20 elements × ~100 bytes = **~180 KB/s** less GC pressure
- PHP arrays don't shrink when elements are overwritten — the reused array
  avoids repeated `zval` allocation/deallocation cycles

**Complexity:** Medium. Requires changing `render()` signatures across 12
widgets and their callers. The `RenderBuffer` is passed in by the TUI framework
layer (`TuiCoreRenderer`).

---

### 2.5 Widget Content Deduplication

**Goal:** Share identical strings across widgets when content overlaps.

**Where duplication occurs:**
- Multiple widgets display the same tool names (`file_read`, `bash`, etc.)
- Token counts and cost strings are built independently by status bar + task bar
- File paths from `Theme::relativePath()` are computed repeatedly

**Design: ContentCache**

```php
namespace Kosmokrator\UI\Tui\Buffer;

final class ContentCache
{
    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * Get or compute a named content string.
     */
    public static function get(string $key, callable $factory): string
    {
        return self::$cache[$key] ??= $factory();
    }

    /**
     * Format and cache a token count string.
     */
    public static function formatTokenCount(int $tokens): string
    {
        return self::get("tokens/{$tokens}", fn() => Theme::formatTokenCount($tokens));
    }

    /**
     * Format and cache a cost string.
     */
    public static function formatCost(float $cost): string
    {
        $key = 'cost/' . number_format($cost, 6, '.', '');
        return self::get($key, fn() => Theme::formatCost($cost));
    }

    /**
     * Get or compute a relative path.
     */
    public static function relativePath(string $path): string
    {
        return self::get("path/{$path}", fn() => Theme::relativePath($path));
    }

    public static function clear(): void
    {
        self::$cache = [];
    }
}
```

**Integration points:**
- `TuiCoreRenderer::refreshStatusBar()` — token counts + cost
- `TuiCoreRenderer::refreshTaskBar()` — tool labels, file paths
- `DiscoveryBatchWidget` — tool icons + labels (already uses Theme methods,
  just add caching)
- `HistoryStatusWidget` — token counts

**Important caveat:** This cache must be invalidated when:
- CWD changes (relative paths become stale)
- Token counts update (the cache key includes the raw value, so old entries
  just accumulate — acceptable since they're small)

**Estimated savings:** Low per-frame impact but reduces allocation count.
~20–30 fewer string computations per refresh cycle. Negligible byte savings
(~2 KB/s) but reduces CPU work for repeated path computations.

**Complexity:** Low. One new class, route ~10 call sites through it.

---

## 3. Implementation Order

| Phase | Component | Effort | Impact | Risk |
|---|---|---|---|---|
| **1** | AnsiStringPool | 2h | High (36 KB/s) | None — drop-in for Theme internals |
| **2** | Theme cache | 2h | Medium (redundancy elimination) | None — pure optimization |
| **3** | StringBuilder for MarkdownToAnsi | 4h | Medium (5 KB/response) | Low — careful refactoring of ~20 methods |
| **4** | RenderBuffer for hot widgets | 4h | High (180 KB/s GC pressure) | Medium — render() signature changes |
| **5** | ContentCache | 1h | Low (2 KB/s) | None — additive |
| | **Total** | **~13h** | | |

Phase 1–2 can ship together as a single PR. Phase 3–5 are independent and
can be parallelized.

---

## 4. Measurement Plan

### 4.1 Before/after profiling

Add a temporary profiling mode to `TuiCoreRenderer`:

```php
// In TuiCoreRenderer — conditional profiling
private function profileRender(Closure $render): array
{
    if (! ($this->config['profile'] ?? false)) {
        return $render();
    }

    $memBefore = memory_get_usage();
    $allocBefore = gc_status()['collected'] ?? 0;
    $start = hrtime(true);

    $result = $render();

    $elapsed = (hrtime(true) - $start) / 1_000; // microseconds
    $memAfter = memory_get_usage();
    $allocDelta = $memAfter - $memBefore;

    $this->profileLog[] = [
        'time_us' => $elapsed,
        'mem_delta' => $allocDelta,
        'lines' => count($result),
    ];

    return $result;
}
```

### 4.2 Benchmarks

| Benchmark | Measurement |
|---|---|
| **Theme::reset() × 10,000** | Before: 10,000 allocations. After: 1 allocation + 9,999 lookups |
| **Full render cycle** (all 12 widgets) | Memory delta per frame before vs. after |
| **30-second breathing animation** | Total memory allocated (gc_status comparison) |
| **MarkdownToAnsi::render() on 50-para input** | Peak memory and allocation count |
| **Streaming 10 agent responses** | Cumulative allocation over time |

### 4.3 Success criteria

| Metric | Target |
|---|---|
| Per-frame allocation delta | **< 50% of baseline** |
| Theme string pool hit rate | **> 95%** (measured as pool lookups vs. new inserts) |
| Render buffer reuse rate | **> 80%** (buffer count unchanged between frames) |
| GC collections per 30s animation run | **< 50% of baseline** |
| No regressions | All existing tests pass; no visible rendering differences |

---

## 5. Architecture Diagram

```
┌─────────────────────────────────────────────────────┐
│                   TuiCoreRenderer                    │
│                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────┐  │
│  │ Widget A  │  │ Widget B  │  │ MarkdownToAnsi   │  │
│  │           │  │           │  │                  │  │
│  │ render()  │  │ render()  │  │  render()        │  │
│  │    ↓      │  │    ↓      │  │    ↓             │  │
│  │ RenderBuf │  │ RenderBuf │  │  StringBuilder   │  │
│  └────┬──────┘  └────┬──────┘  └────────┬─────────┘  │
│       │              │                   │            │
│       └──────────────┴───────────────────┘            │
│                      ↓                                │
│            ┌─────────────────┐                        │
│            │   Theme (cached) │ ← All 30 named        │
│            │                  │   methods memoized     │
│            └────────┬────────┘                        │
│                     ↓                                  │
│            ┌─────────────────┐                        │
│            │ AnsiStringPool   │ ← Deduplicates        │
│            │                  │   raw ANSI bytes      │
│            └─────────────────┘                        │
│                                                      │
│            ┌─────────────────┐                        │
│            │ ContentCache     │ ← Shared content      │
│            │                  │   (paths, tokens)     │
│            └─────────────────┘                        │
└─────────────────────────────────────────────────────┘
```

---

## 6. File Changes Summary

| File | Change |
|---|---|
| `src/UI/Tui/Buffer/AnsiStringPool.php` | **New** — ANSI string interning pool |
| `src/UI/Tui/Buffer/StringBuilder.php` | **New** — Efficient string builder |
| `src/UI/Tui/Buffer/RenderBuffer.php` | **New** — Reusable render line buffer |
| `src/UI/Tui/Buffer/ContentCache.php` | **New** — Shared content cache |
| `src/UI/Theme.php` | **Modify** — Route through AnsiStringPool + add `$cache` |
| `src/UI/Ansi/MarkdownToAnsi.php` | **Modify** — Use StringBuilder for output/inline |
| `src/UI/Tui/Widget/*.php` (12 files) | **Modify** — Accept RenderBuffer in render() |
| `src/UI/Tui/TuiCoreRenderer.php` | **Modify** — Allocate RenderBuffers per widget |
| `tests/UI/...` | **New** — Unit tests for pool, builder, buffer, cache |

---

## 7. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Theme cache grows unbounded | Static methods return fixed set of strings; `contextColor` quantized to ~100 entries; `clear()` available for resets |
| RenderBuffer holds stale data if widget size changes | `reset()` clears count; array auto-grows but never shrinks — acceptable |
| StringBuilder `implode()` at the end creates a copy | Only called once per render; intermediate savings far exceed final copy |
| Rendering order changes cause visible flicker | No functional behavior changes; all optimizations are transparent |
| PHP's copy-on-write means string "interning" may not actually share memory | AnsiStringPool stores strings in a static array; PHP will share the same `zval` reference for identical strings from the pool |
| Thread safety (amp concurrency) | PHP is single-threaded under amp; no concurrent access issues |

---

## 8. Out of Scope

- **ANSI animation classes** (`AnsiIntro`, `AnsiTheogony`, etc.) — these run
  once during startup and their per-character `Theme::rgb()` calls with random
  colors cannot be effectively cached. They're also not in the TUI hot path.
- **Symfony TUI framework internals** — we don't control `TextWidget`,
  `MarkdownWidget`, etc. Their internal string handling is out of scope.
- **Opcode-level optimization** — OPcache already avoids recompilation. The
  issue here is runtime string allocation, not parsing overhead.
