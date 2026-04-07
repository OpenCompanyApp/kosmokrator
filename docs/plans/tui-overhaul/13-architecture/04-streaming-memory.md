# 04 — Streaming Memory Optimization

> **Module**: `13-architecture`
> **Depends on**: `02-widget-compaction` (for settled/compacted lifecycle), `03-string-interning` (for `StringBuilder`), `11-ai-chat-patterns/01-streaming-optimization` (for `StreamingMarkdownBuffer`)
> **Status**: Plan

---

## 1. Problem Analysis

### 1.1 Memory Growth During Streaming

During an active LLM response, KosmoKrator's streaming pipeline holds multiple growing data structures in memory simultaneously:

```
streamChunk("foo")
  → $activeResponse->setText($current . $text)     // growing string
  → MarkdownWidget::render()
    → MarkdownParser::parse($fullText)              // full AST in memory
    → renderDocument()                              // string[] of all rendered lines
    → TextWrapper::wrapTextWithAnsi()               // wrapped line copies
  → AbstractWidget::setRenderCache($lines)          // cached rendered lines
  → ScreenWriter::writeLines()                      // previous frame + new frame
```

**At any given moment during streaming, the following copies of the response exist:**

| Copy | Location | Size (for 8 KB response) | Lifetime |
|------|----------|--------------------------|----------|
| Raw markdown source | `MarkdownWidget::$text` | 8 KB | Until compacted |
| Previous raw source | `$current` in `streamChunk()` (before concat) | 8 KB | Per-chunk (GC) |
| CommonMark AST | `MarkdownParser::parse()` return value | ~40 KB (nodes, literals, spans) | Per-chunk (GC) |
| Rendered ANSI lines | `MarkdownWidget::render()` return array | ~20 KB (ANSI inflation ~2.5×) | Per-chunk (GC) |
| Wrapped ANSI lines | `TextWrapper::wrapTextWithAnsi()` outputs | ~20 KB | Per-chunk (GC) |
| Cached render output | `AbstractWidget::$renderCacheLines` | ~20 KB | Until next invalidate() |
| Previous screen state | `ScreenWriter` internal buffer | ~20 KB | Persistent |
| New screen state | `ScreenWriter::writeLines()` new buffer | ~20 KB | Per-chunk (GC) |
| **Peak total** | | **~156 KB** | |

For a typical 2000-token response (~8 KB raw), **peak memory is ~156 KB**. For a verbose 8000-token response (~32 KB raw), peak is **~624 KB** — entirely for a single widget's transient data.

### 1.2 The Concatenation Problem

`TuiCoreRenderer::streamChunk()` at line 483–484:

```php
$current = $this->activeResponse->getText();
$this->activeResponse->setText($current . $text);
```

This creates **three** full copies of the accumulated text per chunk:
1. `$current` — extracted from widget (copy-on-write usually shares, but then…)
2. `$current . $text` — PHP allocates a new string of length `len(current) + len(chunk)` and copies both
3. `StringUtils::sanitizeUtf8($text)` inside `setText()` may create another copy

For an 8 KB response arriving in 50 chunks, the concatenation chain creates strings of sizes: 160 B, 320 B, 480 B, … 8 KB, 8.16 KB. The cumulative allocation is roughly `n × avg_size = 50 × 4 KB = 200 KB` of **throwaway intermediate strings**.

### 1.3 Markdown Parse Tree Overhead

`MarkdownWidget::renderMarkdown()` at line 141:

```php
$document = $this->parser->parse($this->text);
```

league/commonmark creates a full AST on every `render()` call. Per-node memory cost in PHP:

| Node type | Approx. memory | Example count (8 KB response) |
|-----------|---------------|-------------------------------|
| `Document` | ~200 B | 1 |
| `Paragraph` | ~300 B | ~15 |
| `Text` | ~250 B + literal length | ~40 |
| `Code` | ~250 B + literal | ~5 |
| `Strong` / `Emphasis` | ~250 B | ~10 |
| `FencedCode` | ~300 B + content | ~2 |
| `Heading` | ~300 B | ~3 |
| **Total** | | **~30 KB per parse** |

This AST is created and destroyed on every chunk — 50 AST constructions for a single response.

### 1.4 Double Buffering

There is no explicit double buffering, but two implicit buffers exist:

1. **`AbstractWidget::$renderCacheLines`** — holds the last rendered `string[]`. When `invalidate()` is called (every `setText()`), this is set to `null`. The new render output is then cached. Between `null`-ing and re-caching, there's no duplication — the old lines are released before new ones are created.

2. **`ScreenWriter` internal state** — holds the previous frame's screen buffer. After `writeLines()`, the new frame becomes the old frame. There is a brief overlap where both exist during the diff computation.

**Conclusion**: No unnecessary double buffering, but both buffers scale with total rendered content size.

### 1.5 Post-Streaming State

`streamComplete()` at line 489–495:

```php
public function streamComplete(): void
{
    $this->activeResponse = null;
    $this->activeResponseIsAnsi = false;
    $this->finalizeDiscoveryBatch();
    $this->flushRender();
}
```

After streaming completes:
- `$this->activeResponse` is set to `null` — the reference is dropped
- But the `MarkdownWidget` (or `AnsiArtWidget`) remains in `$this->conversation` container
- The widget retains its full `$text` property (8–32 KB)
- The widget's `$renderCacheLines` holds the final rendered output (~20–80 KB)
- The CommonMark `MarkdownParser` and `Highlighter` instances live inside the widget indefinitely

**Memory retained after streaming**: `text + cached lines + parser object + highlighter object` ≈ **30–120 KB per completed response**. This is addressed by `02-widget-compaction.md`'s compaction strategy.

---

## 2. Design: Five Optimizations

### 2.1 ChunkedStringBuilder — Rope-Like Append

**Goal**: Eliminate O(n) string concatenation during streaming by accumulating chunks in an array and only materializing the full string when needed.

**New class**: `src/UI/Tui/Buffer/ChunkedStringBuilder.php`

```php
namespace Kosmokrator\UI\Tui\Buffer;

/**
 * Efficient string builder that avoids reallocation by collecting chunks
 * in an array. Materializes the full string only on demand.
 *
 * Memory cost: O(chunks) pointers + original chunk strings.
 * Append cost: O(1) amortized (array push).
 * toString cost: O(total length) — but called only when needed.
 */
final class ChunkedStringBuilder
{
    /** @var list<string> */
    private array $chunks = [];
    private int $length = 0;

    /**
     * Append a chunk. O(1) — just pushes to the array.
     * The chunk string is stored by reference (no copy).
     */
    public function append(string $chunk): self
    {
        if ($chunk !== '') {
            $this->chunks[] = $chunk;
            $this->length += \strlen($chunk);
        }
        return $this;
    }

    /**
     * Materialize the full string. O(n) but only called when needed
     * (e.g., for setText(), getText(), or final render).
     */
    public function toString(): string
    {
        if ($this->chunks === []) {
            return '';
        }
        if (\count($this->chunks) === 1) {
            return $this->chunks[0];
        }
        return implode('', $this->chunks);
    }

    /**
     * Get the total byte length without materializing.
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * Get the number of chunks.
     */
    public function chunkCount(): int
    {
        return \count($this->chunks);
    }

    /**
     * Get the last N characters without materializing the full string.
     * Used for the "streaming window" (§2.2).
     */
    public function tail(int $bytes): string
    {
        if ($this->length <= $bytes) {
            return $this->toString();
        }

        $result = '';
        $remaining = $bytes;
        for ($i = \count($this->chunks) - 1; $i >= 0 && $remaining > 0; $i--) {
            $chunk = $this->chunks[$i];
            if (\strlen($chunk) <= $remaining) {
                $result = $chunk . $result;
                $remaining -= \strlen($chunk);
            } else {
                $result = substr($chunk, -$remaining) . $result;
                $remaining = 0;
            }
        }
        return $result;
    }

    /**
     * Clear and optionally reuse the internal array.
     */
    public function clear(): void
    {
        $this->chunks = [];
        $this->length = 0;
    }

    /**
     * Compact adjacent small chunks into a single chunk.
     * Call this periodically to prevent unbounded chunk array growth.
     *
     * @param int $threshold Only compact if chunk count exceeds this
     */
    public function compact(int $threshold = 64): void
    {
        if (\count($this->chunks) < $threshold) {
            return;
        }
        $this->chunks = [$this->toString()];
    }
}
```

**Integration with `streamChunk()`**:

```php
// TuiCoreRenderer — new property
private ChunkedStringBuilder $streamBuffer;

// In constructor:
$this->streamBuffer = new ChunkedStringBuilder();

// Modified streamChunk
public function streamChunk(string $text): void
{
    // ... existing setup logic (flushPendingQuestionRecap, etc.) ...

    $this->streamBuffer->append($text);

    // Compact if too many chunks accumulated (prevents array bloat)
    $this->streamBuffer->compact();

    // Only materialize when MarkdownWidget actually needs the full text
    $this->activeResponse->setText($this->streamBuffer->toString());

    // ... rest of existing logic ...
}

public function streamComplete(): void
{
    // Materialize final text, then release buffer
    $this->streamBuffer->clear();
    $this->activeResponse = null;
    // ... rest of existing logic ...
}
```

**Savings**: Eliminates the per-chunk concatenation chain. For a 50-chunk response:
- Before: ~200 KB of intermediate string allocations
- After: ~50 array pushes + 50 `implode()` calls on growing data → ~50 KB of intermediates
- **Net reduction: ~75% fewer bytes allocated during streaming**

---

### 2.2 Streaming Window — Settled/Active Split

**Goal**: Avoid holding and re-rendering the full accumulated text during streaming. Split content into a "settled" prefix that never changes and an "active" tail that re-renders each chunk.

This builds on the `StreamingMarkdownBuffer` concept from `11-ai-chat-patterns/01-streaming-optimization.md` but adds a **memory dimension**: the settled prefix is stored as pre-rendered lines (compact), while only the active tail holds a MarkdownParser AST.

**Design**: Two-tier storage in `StreamingMarkdownBuffer`:

```
┌──────────────────────────────────────────────────────────────┐
│                    StreamingMarkdownBuffer                     │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ Settled Region (frozen)                                  │ │
│  │                                                          │ │
│  │ settledLines: string[]    ← Pre-rendered ANSI lines      │ │
│  │ settledBytes: int         ← Raw byte count of settled     │ │
│  │                                                          │ │
│  │ Memory: ~2.5× raw bytes (ANSI-inflated rendered lines)   │ │
│  │ Cost to render: O(1) — just return the array             │ │
│  └─────────────────────────────────────────────────────────┘ │
│                                                               │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ Active Region (live)                                     │ │
│  │                                                          │ │
│  │ activeChunks: ChunkedStringBuilder  ← Recent raw text    │ │
│  │ activeLines: string[]               ← Rendered lines     │ │
│  │                                                          │ │
│  │ Window size: last 4–8 KB of raw text, or ~20 lines       │ │
│  │ Memory: raw text + AST + rendered lines                  │ │
│  │ Cost to render: O(active text only)                       │ │
│  └─────────────────────────────────────────────────────────┘ │
│                                                               │
├──────────────────────────────────────────────────────────────┤
│ liveWindowBytes: int = 4096     // Active region byte budget │
│ liveWindowLines: int = 20       // Minimum active line count │
│ settleThresholdBytes: int = 8192 // Min settled bytes before │
│                                  // next settle pass          │
└──────────────────────────────────────────────────────────────┘
```

**Algorithm**:

```php
public function append(string $text): array
{
    $this->activeChunks->append($text);

    // Try to settle completed blocks
    $this->trySettle();

    // Re-render only the active region
    $activeText = $this->activeChunks->toString();
    $this->activeLines = $this->renderMarkdown($activeText);

    return [...$this->settledLines, ...$this->activeLines];
}

private function trySettle(): void
{
    $activeText = $this->activeChunks->toString();

    // Don't settle if active region is still small
    if (strlen($activeText) < $this->settleThresholdBytes) {
        return;
    }

    // Find the last block boundary before the live window
    $boundary = $this->findSettleBoundary($activeText);
    if ($boundary === null) {
        return;
    }

    // Split: settled prefix + remaining active tail
    $settleText = substr($activeText, 0, $boundary);
    $remainText = substr($activeText, $boundary);

    // Render and freeze the settled prefix
    $newSettledLines = $this->renderMarkdown($settleText);
    array_push($this->settledLines, ...$newSettledLines);
    $this->settledBytes += strlen($settleText);

    // Reset active region to just the remaining tail
    $this->activeChunks->clear();
    $this->activeChunks->append($remainText);
}

private function findSettleBoundary(string $text): ?int
{
    // Look for the last double-newline that leaves at least
    // liveWindowBytes in the active tail
    $minActiveStart = max(0, strlen($text) - $this->liveWindowBytes);

    // Search backwards from minActiveStart for a block boundary
    $pos = $minActiveStart;
    while ($pos > 0) {
        // Check for double newline
        if ($pos >= 2 && $text[$pos - 2] === "\n" && $text[$pos - 1] === "\n") {
            return $pos;
        }
        $pos--;
    }

    return null; // No suitable boundary found
}
```

**Memory impact** for a 32 KB response being streamed:

| Region | Before Optimization | After Streaming Window |
|--------|--------------------|-----------------------|
| Raw text held | 32 KB (full) | ~4 KB (active tail only) |
| AST in memory | ~120 KB (full doc) | ~15 KB (active blocks only) |
| Rendered lines (cached) | ~80 KB (full) | ~80 KB (settled) + ~10 KB (active) |
| **Total during streaming** | **~232 KB** | **~109 KB** |
| **Reduction** | | **53%** |

---

### 2.3 Markdown Lazy Parse — Streaming Fast-Path

**Goal**: Avoid full CommonMark parsing during streaming. Use a lightweight formatting pass for live content and defer full markdown parsing to stream completion.

**Design**: Three rendering modes in `StreamingMarkdownBuffer`:

```php
enum StreamingRenderMode: string
{
    case Plain = 'plain';        // No markdown detected — raw text with wrapping
    case Light = 'light';        // Basic formatting only (bold, italic, code, links)
    case Full = 'full';          // Full CommonMark + GFM (tables, fenced code, etc.)
}
```

**Transition logic**:

```php
private StreamingRenderMode $renderMode = StreamingRenderMode::Plain;

private function detectRenderMode(string $text): StreamingRenderMode
{
    // Fast single-pass detection
    $hasBasicMd = preg_match('/[*_`#\[\]]/', $text) === 1;
    $hasAdvancedMd = preg_match('/^\s*```/m|^\s*\|.*\|/m|^\s*>\s/m|^\s*[-*+]\s/m|^\s*\d+\.\s/m', $text) === 1;

    if ($hasAdvancedMd) {
        return StreamingRenderMode::Full;
    }
    if ($hasBasicMd) {
        return StreamingRenderMode::Light;
    }
    return StreamingRenderMode::Plain;
}
```

**Light renderer** — handles inline formatting without CommonMark AST:

```php
private function renderLight(string $text): array
{
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        // Apply inline formatting via regex (no AST)
        $styled = $this->applyInlineStyles($line);
        array_push($lines, ...TextWrapper::wrapTextWithAnsi($styled, $this->columns));
    }
    return $lines;
}

private function applyInlineStyles(string $line): string
{
    // `code` → styled inline code
    $line = preg_replace(
        '/`([^`]+)`/',
        $this->resolveElement('code')->apply('$1') . $this->restoreContext,
        $line
    );

    // **bold** → styled bold
    $line = preg_replace(
        '/\*\*([^*]+)\*\*/',
        $this->resolveElement('bold')->apply('$1') . $this->restoreContext,
        $line
    );

    // *italic* → styled italic
    $line = preg_replace(
        '/\*([^*]+)\*/',
        $this->resolveElement('italic')->apply('$1') . $this->restoreContext,
        $line
    );

    return $line;
}
```

**Performance comparison** for a 4 KB chunk:

| Mode | Parse cost | Render cost | Memory | Quality |
|------|-----------|-------------|--------|---------|
| `Plain` | 0 | `explode` + wrap (~0.1ms) | ~2 KB | No formatting |
| `Light` | 3 regex passes (~0.3ms) | style + wrap (~0.2ms) | ~4 KB | Bold, italic, code |
| `Full` | CommonMark AST (~2ms) | AST walk + wrap (~1ms) | ~30 KB | Full markdown |

**When to upgrade**:

| Current Mode | Trigger | New Mode |
|-------------|---------|----------|
| Plain | First `*`, `` ` ``, `#`, `[` in text | Light |
| Light | Fenced code ```` ``` ````, table `|`, blockquote `>`, list prefix | Full |
| Full | — (stays Full) | Full |

**On `streamComplete()`**: Always re-render with `Full` mode for the final display. This ensures correctness — the light/plain modes may have edge cases that differ from CommonMark's output.

```php
public function finalize(): void
{
    // Re-render everything with full CommonMark for correctness
    $fullText = $this->settledRawText . $this->activeChunks->toString();
    $this->settledLines = $this->renderFull($fullText);
    $this->activeChunks->clear();
    $this->activeLines = [];
}
```

---

### 2.4 Stream Buffer Recycling

**Goal**: Reuse the streaming buffer between responses to avoid repeated allocation/deallocation cycles.

**Design**: The `ChunkedStringBuilder` instance lives on `TuiCoreRenderer` and is cleared (not destroyed) between responses:

```php
// TuiCoreRenderer
private ChunkedStringBuilder $streamBuffer;
private StreamingMarkdownBuffer $markdownBuffer;

public function __construct(/* ... */)
{
    // ...
    $this->streamBuffer = new ChunkedStringBuilder();
    $this->markdownBuffer = new StreamingMarkdownBuffer(
        liveWindowBytes: 4096,
        liveWindowLines: 20,
    );
}

public function streamChunk(string $text): void
{
    // ... setup logic ...

    if ($this->activeResponse === null) {
        // First chunk of a new response — reset buffers
        $this->streamBuffer->clear();
        $this->markdownBuffer->reset();
        // ... create widget ...
    }

    $this->streamBuffer->append($text);

    // ... render using markdownBuffer ...
}

public function streamComplete(): void
{
    // Finalize: re-render with full markdown, then compact
    $this->markdownBuffer->finalize();
    $this->streamBuffer->clear();  // Reuse on next response

    $this->activeResponse = null;
    // ...
}
```

**Why this matters**: In a typical agent session, the LLM responds 20–50 times. Without recycling:
- 20–50 `ChunkedStringBuilder` allocations
- 20–50 internal chunk arrays allocated, filled, discarded
- PHP's allocator handles this well, but recycling avoids the GC pressure entirely

The `StreamingMarkdownBuffer` also maintains its `MarkdownParser` and `Highlighter` instances across responses — these are expensive to construct (~2 KB each, with regex pattern compilation).

---

### 2.5 Memory Budget — Hard Cap with Spillover

**Goal**: Prevent unbounded memory growth during exceptionally long streaming responses (e.g., agent generating a 20 KB code file). Cap the streaming buffer at ~100 KB and spill excess to temporary storage.

**Design**:

```php
final class StreamingMemoryBudget
{
    /**
     * Maximum bytes to keep in memory for the active streaming region.
     * Settled/frozen lines are already accounted for in the render cache.
     */
    public const ACTIVE_BUDGET_BYTES = 100 * 1024; // 100 KB

    /**
     * Maximum rendered lines to keep in memory before spilling.
     */
    public const MAX_IN_MEMORY_LINES = 2000;

    /**
     * Check if the active region exceeds the budget.
     */
    public function isOverBudget(int $activeBytes, int $renderedLines): bool
    {
        return $activeBytes > self::ACTIVE_BUDGET_BYTES
            || $renderedLines > self::MAX_IN_MEMORY_LINES;
    }
}
```

**Spillover mechanism**: When the budget is exceeded, settled lines are written to a temporary file:

```php
private function spillToDisk(): void
{
    if ($this->spillFile === null) {
        $this->spillFile = tmpfile();
        $this->spillLineOffsets = [];
        $this->spilledLineCount = 0;
    }

    // Write settled lines to the temp file
    foreach ($this->settledLines as $line) {
        $offset = ftell($this->spillFile);
        $this->spillLineOffsets[] = $offset;
        fwrite($this->spillFile, $line . "\n");
        $this->spilledLineCount++;
    }

    // Keep only last N lines in memory for the active viewport
    $keepInMemory = max(0, count($this->settledLines) - self::MAX_IN_MEMORY_LINES);
    $this->settledLines = array_slice($this->settledLines, -$keepInMemory);
}
```

**Reading spilled lines** (on scroll-up or final render):

```php
private function readSpilledLines(int $start, int $count): array
{
    if ($this->spillFile === null) {
        return [];
    }

    $lines = [];
    for ($i = $start; $i < $start + $count && $i < $this->spilledLineCount; $i++) {
        fseek($this->spillFile, $this->spillLineOffsets[$i]);
        $lines[] = rtrim(fgets($this->spillFile), "\n");
    }
    return $lines;
}
```

**When to trigger**: The budget check runs inside `trySettle()`:

```php
private function trySettle(): void
{
    // ... existing boundary detection ...

    // After settling, check budget
    $totalSettledMemory = $this->estimateSettledMemory();
    if ($this->budget->isOverBudget($totalSettledMemory, count($this->settledLines))) {
        $this->spillToDisk();
    }
}
```

**On `streamComplete()`**: Read all spilled lines back, concatenate with in-memory lines, and store the final result in the widget. Then close and delete the temp file:

```php
public function finalize(): array
{
    $allLines = [];

    // Read spilled lines from disk
    if ($this->spillFile !== null) {
        rewind($this->spillFile);
        while (($line = fgets($this->spillFile)) !== false) {
            $allLines[] = rtrim($line, "\n");
        }
        fclose($this->spillFile);
        $this->spillFile = null;
    }

    // Add in-memory settled lines
    array_push($allLines, ...$this->settledLines);

    // Re-render active region with full markdown
    $activeText = $this->activeChunks->toString();
    $activeLines = $this->renderFull($activeText);
    array_push($allLines, ...$activeLines);

    // Reset state
    $this->settledLines = [];
    $this->activeChunks->clear();
    $this->activeLines = [];

    return $allLines;
}
```

**Budget target**: For a normal response (8–32 KB raw), the streaming window + active region stays well under 100 KB. The spillover mechanism only activates for:
- Very long code generation responses (>40 KB raw)
- Agents that produce multi-file outputs in a single response
- Edge cases where the LLM generates extremely verbose explanations

---

## 3. Combined Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                       TuiCoreRenderer                             │
│                                                                   │
│  streamChunk($text)                                               │
│    │                                                              │
│    ├─► ChunkedStringBuilder::append($text)     ← §2.1 O(1) push  │
│    │                                                              │
│    ├─► StreamingMarkdownBuffer::append($text)  ← §2.2 settle     │
│    │     │                                                        │
│    │     ├─ trySettle()                                           │
│    │     │   ├─ findSettleBoundary()                              │
│    │     │   ├─ renderSettled() → settledLines[]                  │
│    │     │   └─ StreamingMemoryBudget::check() ← §2.5 cap        │
│    │     │       └─ spillToDisk() if over budget                  │
│    │     │                                                        │
│    │     └─ renderActive()                                        │
│    │         └─ StreamingRenderMode ← §2.3 lazy parse            │
│    │             ├─ Plain → explode + wrap                        │
│    │             ├─ Light → regex inline styles                   │
│    │             └─ Full  → CommonMark parse                      │
│    │                                                              │
│    ├─► activeResponse->setText(fullText)                           │
│    │                                                              │
│    └─► flushRender()                                              │
│                                                                   │
│  streamComplete()                                                 │
│    │                                                              │
│    ├─► markdownBuffer::finalize()  ← Full re-render for quality  │
│    ├─► streamBuffer::clear()       ← §2.4 recycle                │
│    └─► activeResponse = null                                     │
│                                                                   │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │ Persistent (recycled across responses)                      │  │
│  │                                                            │  │
│  │ ChunkedStringBuilder  $streamBuffer                        │  │
│  │ StreamingMarkdownBuffer $markdownBuffer                    │  │
│  │   ├─ MarkdownParser (shared, ~2 KB)                       │  │
│  │   ├─ Highlighter (shared, ~2 KB)                          │  │
│  │   ├─ settledLines: string[] (cleared each response)       │  │
│  │   └─ spillFile: resource|null (created on demand)         │  │
│  └────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
```

---

## 4. Memory Savings Estimates

### 4.1 Per-Response Estimates

| Response Size | Before (Peak) | After (Peak) | Reduction |
|---------------|--------------|-------------|-----------|
| 2 KB (short answer) | ~40 KB | ~12 KB | 70% |
| 8 KB (typical response) | ~156 KB | ~55 KB | 65% |
| 32 KB (verbose/code) | ~624 KB | ~150 KB | 76% |
| 128 KB (extreme) | ~2.5 MB | ~250 KB (with spillover) | 90% |

### 4.2 Session-Level Estimates

**50-turn session** (mix of short and typical responses):

| Metric | Before | After |
|--------|--------|-------|
| Streaming allocations (total) | ~3 MB | ~0.8 MB |
| Active streaming memory (peak) | ~156 KB | ~55 KB |
| GC collections during streaming | ~15–20 | ~5–8 |
| Post-streaming retained (before compaction) | Addressed by `02-widget-compaction` | Same |

**Heavy session** (100 turns with code generation, 10+ responses >32 KB):

| Metric | Before | After |
|--------|--------|-------|
| Streaming allocations (total) | ~25 MB | ~4 MB |
| Peak with spillover | ~2.5 MB (single response) | ~250 KB |
| Spillover activations | N/A | ~10 responses |

---

## 5. Implementation Steps

### Phase 1: ChunkedStringBuilder + Buffer Recycling (2 days)

1. Create `src/UI/Tui/Buffer/ChunkedStringBuilder.php`
2. Add `$streamBuffer` property to `TuiCoreRenderer`
3. Modify `streamChunk()` to use `ChunkedStringBuilder::append()` instead of string concat
4. Modify `streamComplete()` to call `streamBuffer->clear()`
5. Unit tests for `ChunkedStringBuilder`: append, toString, tail, compact, clear

**Files**:
| File | Change |
|------|--------|
| `src/UI/Tui/Buffer/ChunkedStringBuilder.php` | **New** |
| `src/UI/Tui/TuiCoreRenderer.php:454–495` | **Modify** — use buffer |

### Phase 2: Streaming Window (3 days)

1. Extend `StreamingMarkdownBuffer` (from `11-ai-chat-patterns/01-streaming-optimization`) with settled/active split
2. Add `ChunkedStringBuilder` as the active region storage
3. Implement `trySettle()` with block boundary detection
4. Integrate `StreamingMarkdownBuffer` into `TuiCoreRenderer::streamChunk()`
5. Tests for settle boundary detection with various markdown structures

**Files**:
| File | Change |
|------|--------|
| `src/UI/Tui/StreamingMarkdownBuffer.php` | **New / Extend** |
| `src/UI/Tui/TuiCoreRenderer.php:454–495` | **Modify** — use buffer |

### Phase 3: Lazy Parse (2 days)

1. Create `StreamingRenderMode` enum
2. Add `renderLight()` and `renderPlain()` methods to `StreamingMarkdownBuffer`
3. Add mode transition logic with regex detection
4. Ensure `finalize()` always re-renders with `Full` mode
5. Visual regression tests: compare Light render output vs. Full render for typical responses

**Files**:
| File | Change |
|------|--------|
| `src/UI/Tui/StreamingRenderMode.php` | **New** |
| `src/UI/Tui/StreamingMarkdownBuffer.php` | **Modify** — add modes |

### Phase 4: Memory Budget + Spillover (2 days)

1. Create `StreamingMemoryBudget` value object
2. Add spillover temp file management to `StreamingMarkdownBuffer`
3. Integrate budget check into `trySettle()`
4. Test with artificially large responses (>100 KB)

**Files**:
| File | Change |
|------|--------|
| `src/UI/Tui/StreamingMemoryBudget.php` | **New** |
| `src/UI/Tui/StreamingMarkdownBuffer.php` | **Modify** — add spillover |

---

## 6. Benchmark Targets

### 6.1 Measurement Infrastructure

Add profiling hooks to `StreamingMarkdownBuffer`:

```php
final class StreamingMemoryMetrics
{
    public int $chunkCount = 0;
    public int $totalBytesAppended = 0;
    public int $peakActiveBytes = 0;
    public int $peakSettledLines = 0;
    public int $settlePasses = 0;
    public int $spillCount = 0;
    public float $totalRenderTimeMs = 0.0;
    public float $peakRenderTimeMs = 0.0;

    public function recordChunk(int $bytes, float $renderMs): void
    {
        $this->chunkCount++;
        $this->totalBytesAppended += $bytes;
        $this->totalRenderTimeMs += $renderMs;
        $this->peakRenderTimeMs = max($this->peakRenderTimeMs, $renderMs);
    }
}
```

### 6.2 Target Metrics

| Metric | Baseline | Phase 1 | Phase 2 | Phase 3 | Phase 4 |
|--------|----------|---------|---------|---------|---------|
| **Allocations per chunk** (8 KB response) | ~8 | ~4 | ~3 | ~2 | ~2 |
| **Peak memory during streaming** (8 KB) | 156 KB | 100 KB | 55 KB | 30 KB | 30 KB |
| **Peak memory during streaming** (128 KB) | 2.5 MB | 1.5 MB | 600 KB | 300 KB | 250 KB |
| **Render time per chunk** (last chunk, 8 KB total) | 8 ms | 8 ms | 3 ms | 1.5 ms | 1.5 ms |
| **GC collections per 50-turn session** | 15–20 | 10–15 | 5–8 | 3–5 | 3–5 |
| **Spillover activations** (50-turn session) | N/A | N/A | N/A | N/A | 0–2 |

### 6.3 Benchmark Scenarios

| Scenario | Input | Measurement |
|----------|-------|-------------|
| **Short response** | 50 chunks × ~40 B = 2 KB total | Peak memory, total allocations |
| **Typical response** | 80 chunks × ~100 B = 8 KB total | Render time (first/middle/last chunk) |
| **Code generation** | 200 chunks × ~160 B = 32 KB total | Settle pass count, active region size |
| **Massive response** | 500 chunks × ~260 B = 128 KB total | Spillover activation, disk I/O time |
| **Rapid-fire session** | 50 responses × 8 KB each | Cumulative GC impact, buffer reuse |

### 6.4 Success Criteria

| Criterion | Threshold |
|-----------|-----------|
| Peak streaming memory (8 KB response) | < 60 KB (62% reduction) |
| Peak streaming memory (128 KB response) | < 300 KB (88% reduction) |
| Render time per chunk (late-stage) | < 3 ms (63% reduction) |
| No text loss on `streamComplete()` | 100% — all bytes accounted for |
| Visual output identical after `finalize()` | Bit-exact match with current `MarkdownWidget::render()` |
| Spillover temp file cleaned up | 0 open file handles after `streamComplete()` |

---

## 7. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Settled/active split at wrong boundary | Corrupted markdown (e.g., splitting mid-code-fence) | Boundary detection checks for fenced code blocks, tables. Fallback: if no clean boundary found, don't settle (keep growing active region). |
| Light-mode regex formatting differs from CommonMark | Visual inconsistency during streaming | Always `finalize()` with Full mode. Light mode is transient — only visible during streaming, replaced on completion. |
| Spillover temp file leaks | File descriptor exhaustion | Register cleanup in `EventLoop::onClose()`. Use `register_shutdown_function()` as safety net. Track file handles in metrics. |
| `ChunkedStringBuilder::compact()` called too early | Forces premature string materialization | `compact()` threshold of 64 chunks means it fires only when the chunk array would be costly to iterate. After compact, it's one string — acceptable. |
| Tail extraction is O(chunks) | Slow `tail()` for many small chunks | `compact()` prevents unbounded chunk growth. With threshold 64, worst case is 64 iterations. |
| `finalize()` re-render causes visible flash | Brief content change on stream end | `finalize()` output should be identical to settled+active lines. Use `ScreenWriter` diff — if lines match, no terminal write. |
| Block boundary detection misses edge cases | Content appears twice or missing | Comprehensive test suite with edge cases: nested fences, tables with `|`, blockquotes, definition lists. Assertion mode in development. |

---

## 8. Interaction with Existing Plans

| Plan | Relationship |
|------|-------------|
| `02-widget-compaction` | Streaming optimizations reduce memory *during* streaming. Compaction reduces memory *after* streaming. They are complementary. |
| `03-string-interning` | The `StringBuilder` in §2.1 of `03` is superseded by `ChunkedStringBuilder` (which is better for streaming). For non-streaming code, `StringBuilder` still applies. |
| `11-ai-chat-patterns/01-streaming-optimization` | That plan focuses on **render performance** (prefix caching, throttling). This plan focuses on **memory** during streaming. Both modify `StreamingMarkdownBuffer` — coordinate to use a single class. |
| `03-virtual-scrolling` | Streaming content is always at the bottom. The virtual scroll manager must treat the streaming widget as "always rendered." |

**Recommended implementation order**: `01-streaming-optimization` Phase 1 (throttling) → this plan Phase 1 (ChunkedStringBuilder) → `01-streaming-optimization` Phase 2 (StreamingMarkdownBuffer) → this plan Phase 2–4 (window, lazy parse, budget).

---

## 9. File Changes Summary

| File | Change | Phase |
|------|--------|-------|
| `src/UI/Tui/Buffer/ChunkedStringBuilder.php` | **New** — rope-like string builder | 1 |
| `src/UI/Tui/StreamingMarkdownBuffer.php` | **New / Extend** — settled/active split, lazy parse, spillover | 2–4 |
| `src/UI/Tui/StreamingRenderMode.php` | **New** — render mode enum | 3 |
| `src/UI/Tui/StreamingMemoryBudget.php` | **New** — budget constants + checker | 4 |
| `src/UI/Tui/StreamingMemoryMetrics.php` | **New** — profiling data class | 4 |
| `src/UI/Tui/TuiCoreRenderer.php` | **Modify** — use ChunkedStringBuilder + StreamingMarkdownBuffer | 1–2 |
| `tests/UI/Tui/Buffer/ChunkedStringBuilderTest.php` | **New** | 1 |
| `tests/UI/Tui/StreamingMarkdownBufferTest.php` | **New** | 2 |
| `tests/UI/Tui/StreamingMemoryIntegrationTest.php` | **New** | 4 |
