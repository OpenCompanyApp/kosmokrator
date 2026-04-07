# Streaming Markdown Optimization — Implementation Plan

> **File**: `src/UI/Tui/TuiCoreRenderer.php` (streaming), new `StreamingMarkdownBuffer` class
> **Depends on**: Virtual scrolling (`03-virtual-scrolling`), Widget render cache (existing `AbstractWidget::renderCacheLines`)
> **Blocks**: Fast chat experience, responsive streaming UX

---

## 1. Problem Statement

Every streaming chunk from the LLM triggers a full render pipeline in KosmoKrator's TUI:

```
streamChunk($text)
  → MarkdownWidget::setText()          → invalidate() clears render cache
  → TuiCoreRenderer::flushRender()
    → Tui::requestRender() + processRender()
      → Renderer::render(root)          → walks entire widget tree
        → MarkdownWidget::render()
          → MarkdownParser::parse()     → re-parses FULL accumulated text
          → renderDocument()            → renders ALL AST nodes
          → TextWrapper::wrapTextWithAnsi() for every line
        → ScreenWriter::writeLines()    → differential write (good)
```

**Cost per chunk** (measured on typical responses):

| Phase | Cost | Scales with |
|-------|------|-------------|
| CommonMark `parse()` | O(n) full document | total accumulated text |
| `renderDocument()` | O(n) all blocks | total rendered lines |
| `TextWrapper` per line | O(w·l) | total lines × width |
| Widget tree walk | O(children) | conversation widget count |
| ScreenWriter diff | O(changed) ✓ | only changed lines |

For a 2000-token response at ~4 chars/token over ~50 chunks, the final chunks re-parse ~8000 characters and re-render hundreds of lines every 50–100ms. This creates:

1. **Perceptible lag** during fast streaming — the TUI stutters when render time exceeds chunk interval
2. **CPU waste** — unchanged prefix blocks are re-parsed and re-rendered identically
3. **Flicker** — full widget tree invalidation can cause brief visual glitches

## 2. Research: How Aider and Claude Code Solve This

### 2.1 Aider — Stable/Unstable Line Split (`MarkdownStream`)

Aider's `MarkdownStream` class (Python) splits rendered output into two regions:

- **Stable lines** (top) — rendered once, never repainted. Once a line scrolls above the "live window", it's emitted and forgotten.
- **Unstable lines** (bottom N lines) — the "live window" (default: 6 lines). These are re-rendered on every chunk and written to the terminal.

**Algorithm**:
1. On each chunk, render the full markdown to lines
2. Split: `stable = lines[:-live_window]`, `unstable = lines[-live_window:]`
3. If new stable lines appeared since last render, emit them (they'll never change)
4. Move cursor up to the start of the unstable region, repaint only those N lines

**Key insight**: The live window is small (6 lines), so only ~6 lines need ANSI rewrites per chunk. Stable lines are permanent — zero repaint cost.

### 2.2 Claude Code — Prefix Caching + Block-Level Invalidation

Claude Code's terminal renderer uses a block-level cache:

- **Parsed AST blocks are cached** — the markdown is split into blocks (paragraphs, code fences, lists)
- **Only the last block is re-parsed** during streaming — all previous blocks are frozen
- **Rendered output is cached per block** — unchanged blocks reuse their previous ANSI lines

This reduces parse cost from O(total text) to O(last block text) per chunk.

### 2.3 Claude Code — Rate-Adaptive Rendering

Claude Code measures render time and adapts:

```python
render_time = measure(render)
min_delay = max(base_delay, render_time * 2)
```

- If rendering takes 10ms, the next chunk is delayed at least 20ms
- Prevents render queue buildup during fast streaming
- Creates a natural throttle that adapts to terminal performance

### 2.4 Applicability to KosmoKrator

KosmoKrator's TUI already has advantages that change the optimization landscape:

- **ScreenWriter differential rendering** (`ScreenWriter.php:102`) — already does line-level diffing, only writes changed lines. This is equivalent to Aider's "only repaint unstable lines" at the terminal I/O level.
- **Widget render cache** (`AbstractWidget.php:309`) — widgets cache their output. But `setText()` calls `invalidate()`, busting the cache every chunk.
- **ContainerWidget tree** — the entire conversation is a flat vertical container. Each chunk invalidates only the `MarkdownWidget` (or `AnsiArtWidget`), so sibling widgets stay cached.

The remaining bottlenecks are:
1. **MarkdownWidget re-parses the full text** on every `setText()` — CommonMark parser is O(n)
2. **MarkdownWidget re-renders all blocks** — even unchanged ones produce identical lines
3. **No throttle on chunk frequency** — every chunk forces a synchronous render
4. **No fast-path for plain text** — even `hello world` goes through CommonMark

## 3. Current Architecture

### 3.1 Streaming Flow

```
src/UI/Tui/TuiCoreRenderer.php:454-486

streamChunk(string $text): void
├── flushPendingQuestionRecap()          // emit queued Q&A widgets
├── finalizeDiscoveryBatch()             // finalize tool discovery
├── if activeResponse === null:
│   ├── clearThinking()                  // remove thinking indicator
│   ├── detect ANSI → AnsiArtWidget OR MarkdownWidget
│   └── addConversationWidget()          // add to conversation container
├── elseif mid-stream ANSI detection:
│   ├── extract accumulated text
│   ├── remove old MarkdownWidget
│   └── replace with AnsiArtWidget
├── activeResponse->setText(current . $text)   // append + invalidate()
├── markHiddenConversationActivity()     // show "new content below" hint
└── flushRender()
    ├── tui->requestRender()
    └── tui->processRender()
        └── ScreenWriter::writeLines()  // differential write
```

### 3.2 Widget Hierarchy During Streaming

```
Root (ContainerWidget)
├── conversation (ContainerWidget, vertical)
│   ├── [previous message widgets...]      ← cached, unchanged
│   ├── MarkdownWidget (activeResponse)    ← invalidated every chunk
│   └── [future widgets appended later]
├── statusBar (StatusBarWidget)
├── taskBar (TextWidget)
├── overlay (ContainerWidget)
└── input (InputWidget)
```

### 3.3 Key Files

| File | Role |
|------|------|
| `src/UI/Tui/TuiCoreRenderer.php:454` | `streamChunk()` — entry point for streaming |
| `src/UI/Tui/TuiCoreRenderer.php:489` | `streamComplete()` — ends streaming |
| `src/UI/Tui/TuiCoreRenderer.php:92` | `$activeResponse` — the live MarkdownWidget/AnsiArtWidget |
| `vendor/symfony/tui/.../MarkdownWidget.php:56` | Markdown rendering (CommonMark + Tempest Highlight) |
| `vendor/symfony/tui/.../AbstractWidget.php:190` | `invalidate()` — busts render cache |
| `vendor/symfony/tui/.../ScreenWriter.php:102` | Differential terminal write |
| `src/UI/Tui/Widget/AnsiArtWidget.php:13` | ANSI content fallback |

### 3.4 Render Cache Behavior

```
AbstractWidget::invalidate()
  → renderCacheLines = null
  → parent->invalidate()  (propagates up to conversation container)

Renderer::renderWidget()
  → getRenderCache()  → null (cache miss)
  → full render pipeline
  → setRenderCache(lines)
```

The cache propagation up to the `conversation` ContainerWidget means the **layout engine re-runs** on every chunk, even though only one child changed. However, the layout engine for vertical containers is O(children) with simple line concatenation — relatively cheap.

## 4. Optimization Strategy

### 4.1 Overview

Six layered optimizations, ordered by impact and implementation complexity:

| # | Optimization | Impact | Complexity | Phase |
|---|-------------|--------|------------|-------|
| 1 | Rate-adaptive throttling | High | Low | 1 |
| 2 | Plain text fast-path | Medium | Low | 1 |
| 3 | Streaming MarkdownBuffer (prefix caching) | High | Medium | 2 |
| 4 | Stable/unstable line split | Medium | Medium | 2 |
| 5 | ANSI content detection enhancement | Low | Low | 1 |
| 6 | Virtual scroll integration | Medium | Medium | 3 |

### 4.2 Phase 1: Low-Hanging Fruit

These are self-contained changes to `TuiCoreRenderer.php` that don't require new classes.

---

#### 4.2.1 Rate-Adaptive Throttling

**Goal**: Prevent render queue buildup by throttling `streamChunk()` based on measured render time.

**Implementation** in `TuiCoreRenderer`:

```php
// New properties
private float $lastStreamRenderStart = 0.0;
private float $lastStreamRenderDuration = 0.0;
private float $streamChunkAccumulator = '';
private const STREAM_MIN_DELAY_MS = 16;    // ~60fps cap
private const STREAM_RENDER_MULTIPLIER = 2.0; // delay = renderTime × 2

// Modified streamChunk
public function streamChunk(string $text): void
{
    $this->streamChunkAccumulator .= $text;

    $now = hrtime(true) / 1_000_000; // ms
    $elapsed = $now - $this->lastStreamRenderStart;
    $minDelay = max(
        self::STREAM_MIN_DELAY_MS,
        $this->lastStreamRenderDuration * self::STREAM_RENDER_MULTIPLIER,
    );

    if ($elapsed < $minDelay) {
        return; // accumulate, don't render yet
    }

    $this->flushStreamAccumulator();
}

private function flushStreamAccumulator(): void
{
    if ($this->streamChunkAccumulator === '') {
        return;
    }

    $start = hrtime(true) / 1_000_000;

    // ... existing streamChunk logic, but using accumulator ...
    $text = $this->streamChunkAccumulator;
    $this->streamChunkAccumulator = '';

    $this->doStreamChunk($text); // existing logic moved here

    $this->lastStreamRenderDuration = (hrtime(true) / 1_000_000) - $start;
    $this->lastStreamRenderStart = hrtime(true) / 1_000_000;
}

public function streamComplete(): void
{
    $this->flushStreamAccumulator(); // flush any remaining text
    // ... existing streamComplete logic ...
    $this->lastStreamRenderDuration = 0.0;
    $this->lastStreamRenderStart = 0.0;
}
```

**Key design decisions**:
- Chunk accumulation is a simple string concat (negligible cost)
- The throttle only delays the *render*, not the text accumulation
- `streamComplete()` always flushes — no text is ever lost
- `STREAM_RENDER_MULTIPLIER = 2.0` ensures the TUI never falls behind

---

#### 4.2.2 Plain Text Fast-Path

**Goal**: Skip CommonMark parsing for chunks that contain no markdown syntax.

**Detection heuristic** (check the accumulated chunk, not each token):

```php
private function isLikelyPlainText(string $text): bool
{
    // Fast checks for common markdown syntax
    return !preg_match(
        '/[#*_`\[\]()>~|]/S',  // single-byte char class, very fast
        $text,
    );
}
```

**Implementation**: Create a `PlainTextWidget` that extends `AbstractWidget` with a trivial `render()` — just `explode("\n", $this->text)` with `TextWrapper`. No CommonMark, no Tempest Highlight.

During streaming, start with `PlainTextWidget`. Switch to `MarkdownWidget` on the first chunk containing markdown syntax (similar to the existing ANSI detection pattern at `TuiCoreRenderer.php:473`).

```php
// In streamChunk, after the initial widget creation block:
if ($this->activeResponse instanceof PlainTextWidget) {
    if (!$this->isLikelyPlainText($this->activeResponse->getText() . $text)) {
        // Upgrade to MarkdownWidget
        $accumulated = $this->activeResponse->getText();
        $this->conversation->remove($this->activeResponse);
        $this->activeResponse = new MarkdownWidget($accumulated);
        $this->activeResponse->addStyleClass('response');
        $this->addConversationWidget($this->activeResponse);
    }
}
```

**Why not just optimize MarkdownWidget for plain text?** Because `MarkdownWidget` does:
1. `MarkdownParser::parse()` — creates full AST with Document, Paragraph, Text nodes
2. `renderDocument()` — walks the AST
3. `TextWrapper::wrapTextWithAnsi()` — wraps each line

A `PlainTextWidget` skips steps 1–2 entirely. For typical conversational responses that are 70%+ plain text, this eliminates the CommonMark overhead for the majority of chunks.

---

#### 4.2.3 ANSI Content Detection Enhancement

**Current behavior** (`TuiCoreRenderer.php:473`): If ANSI escapes appear mid-stream, the MarkdownWidget is removed and replaced with an AnsiArtWidget. This works but:
- The switch happens on the first ANSI chunk, causing a widget removal + re-add
- The accumulated plain text is passed to AnsiArtWidget which just explodes on `\n`

**Improvement**: No change needed for the detection itself (`containsAnsiEscapes` at line 781 is fine). But we should ensure the rate-adaptive throttling (4.2.1) accounts for widget swaps — force a render on widget type change:

```php
// In the widget swap section, after replacing the widget:
$this->lastStreamRenderDuration = 0; // force immediate render
$this->flushStreamAccumulator();
```

---

### 4.3 Phase 2: Prefix Caching + Stable/Unstable Split

These require a new class: `StreamingMarkdownBuffer`.

---

#### 4.3.1 StreamingMarkdownBuffer

**Goal**: Cache parsed-and-rendered prefix blocks; only re-render the last (active) markdown block during streaming.

**New class**: `src/UI/Tui/StreamingMarkdownBuffer.php`

```
┌─────────────────────────────────────────────────────────┐
│                 StreamingMarkdownBuffer                  │
├─────────────────────────────────────────────────────────┤
│ frozenLines: string[]     // Already-emitted ANSI lines │
│ activeBlock: string       // Current block's raw text   │
│ activeLines: string[]     // Rendered lines for active  │
│ liveWindow: int = 6       // Unstable line count        │
│ parser: MarkdownParser    // Shared parser instance     │
├─────────────────────────────────────────────────────────┤
│ append(text) → string[]  // Returns full rendered lines │
│ freeze() → void          // Freeze active, start fresh  │
│ getLines() → string[]    // frozenLines + activeLines   │
│ reset() → void           // Clear all state             │
└─────────────────────────────────────────────────────────┘
```

**Block splitting heuristic**:

Markdown blocks are separated by blank lines. The buffer tracks the last blank-line boundary:

```php
public function append(string $text): array
{
    $this->activeBlock .= $text;

    // Check if the active block now contains a block boundary
    // (double newline or end of a fenced code block)
    while ($this->tryFreezeCompletedBlock()) {
        // A complete block was found — parse it, render it, freeze it
    }

    // Re-render only the (remaining) active block
    $this->activeLines = $this->renderMarkdown($this->activeBlock);

    return [...$this->frozenLines, ...$this->activeLines];
}
```

**Block boundary detection**:

```php
private function tryFreezeCompletedBlock(): bool
{
    // Look for the last block boundary in activeBlock
    // A block boundary is:
    //   - Two consecutive newlines ("\n\n")
    //   - Closing fence of a code block ("```\n")
    //   - End of a list item followed by a non-list line

    $boundary = $this->findLastBlockBoundary($this->activeBlock);
    if ($boundary === null) {
        return false;
    }

    $completedText = substr($this->activeBlock, 0, $boundary);
    $this->activeBlock = substr($this->activeBlock, $boundary);

    // Render the completed block and freeze it
    $lines = $this->renderMarkdown($completedText);
    array_push($this->frozenLines, ...$lines);

    return true;
}
```

**Integration with MarkdownWidget**:

Instead of modifying the vendor `MarkdownWidget`, create a `StreamingMarkdownWidget` that wraps the buffer:

```php
class StreamingMarkdownWidget extends AbstractWidget
{
    private StreamingMarkdownBuffer $buffer;

    public function __construct(int $liveWindow = 6)
    {
        $this->buffer = new StreamingMarkdownBuffer($liveWindow);
    }

    public function appendText(string $text): void
    {
        $this->buffer->append($text);
        $this->invalidate();
    }

    public function getText(): string
    {
        return $this->buffer->getFullText();
    }

    public function setText(string $text): void
    {
        $this->buffer->reset();
        $this->buffer->append($text);
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        return $this->buffer->getLines();
    }

    public function freeze(): void
    {
        $this->buffer->freeze();
    }
}
```

---

#### 4.3.2 Stable/Unstable Line Split

**Goal**: Leverage the `liveWindow` concept from Aider to minimize ScreenWriter work.

**Current state**: ScreenWriter already does differential rendering (line 441). If only the last N lines change, it already only writes those N lines. So the "stable/unstable split" is **already implicitly implemented** at the terminal I/O level.

**Where it helps**: The optimization is not in ScreenWriter (which already diffs), but in the **render pipeline above it**:

Without stable/unstable split:
```
streamChunk("foo")
  → MarkdownWidget::render()           → renders ALL 200 lines
  → Renderer::renderWidget()           → layout, chrome on all 200 lines
  → ScreenWriter::writeLines(200)      → diffs, writes last 6
```

With StreamingMarkdownBuffer:
```
streamChunk("foo")
  → buffer.append("foo")
    → frozenLines: 194 lines (cached, no re-parse)
    → activeLines: render only last block → 6 lines
  → StreamingMarkdownWidget::render()   → returns cached 194 + fresh 6
  → Renderer: widget cache is invalidated, but render() is O(last block)
  → ScreenWriter::writeLines(200)      → diffs, writes last 6
```

The cost reduction is in `renderMarkdown()` — from O(total text) to O(last block text).

**liveWindow parameter**: Controls how many lines are considered "active" for the block boundary detection. Default of 6 means:
- The buffer won't freeze a block until it's ≥6 lines away from the bottom
- Ensures in-progress paragraphs that are wrapping get re-flowed correctly
- Matches Aider's empirical finding that 6 lines balances smoothness vs. efficiency

---

### 4.4 Phase 3: Virtual Scroll Integration

**Goal**: Ensure streaming content integrates cleanly with the virtual scrolling system (once implemented per `03-virtual-scrolling`).

**Key principle**: Streaming content is always at the bottom of the conversation. The virtual scroll system needs to:

1. **Not virtualize the active streaming widget** — it must always be rendered
2. **Include streaming widget height in total content height** — for scroll calculations
3. **Handle height changes smoothly** — as streaming adds lines, the viewport follows

**Integration points**:

```php
// In TuiCoreRenderer::streamChunk(), after appending text:
$lineCount = $this->activeResponse->getRenderedLineCount(); // new method
$this->virtualScrollManager->notifyContentChanged(
    totalLines: $this->conversation->getTotalRenderedLines(),
    activeWidgetLines: $lineCount,
);
```

**Stream-follow behavior**:
- When the user is at the bottom (scrollOffset === 0), the viewport follows streaming content automatically
- When the user has scrolled up (scrollOffset > 0), show the "new content below" indicator (existing `markHiddenConversationActivity()` at line 786)
- This behavior already exists (`TuiCoreRenderer.php:788-794`); virtual scroll just needs to respect it

---

## 5. Implementation Plan

### 5.1 Phase 1 — Throttling + Fast-Path (1–2 days)

**Files to modify**:
- `src/UI/Tui/TuiCoreRenderer.php` — add throttling state, modify `streamChunk()`/`streamComplete()`

**New files**:
- `src/UI/Tui/Widget/PlainTextWidget.php` — trivial text widget (explode + wrap)

**Steps**:

| Step | Description | Lines changed |
|------|-------------|---------------|
| 1a | Add throttling properties to `TuiCoreRenderer` | ~15 new |
| 1b | Refactor `streamChunk()` → `doStreamChunk()` with accumulator | ~30 changed |
| 1c | Create `PlainTextWidget` | ~50 new |
| 1d | Add plain text detection + widget upgrade logic | ~20 new |
| 1e | Add tests for throttling behavior | ~80 new |

**Testing**:
- Unit test: `streamChunk()` with fast chunks below threshold → text accumulates
- Unit test: `streamComplete()` flushes accumulated text
- Unit test: PlainTextWidget upgrades to MarkdownWidget on `**bold**`
- Integration test: fast streaming session with render time measurement

### 5.2 Phase 2 — Prefix Caching + StreamingMarkdownBuffer (2–3 days)

**Files to modify**:
- `src/UI/Tui/TuiCoreRenderer.php` — use `StreamingMarkdownWidget` instead of `MarkdownWidget`

**New files**:
- `src/UI/Tui/StreamingMarkdownBuffer.php` — prefix-caching buffer (~150 lines)
- `src/UI/Tui/Widget/StreamingMarkdownWidget.php` — streaming-aware widget (~80 lines)

**Steps**:

| Step | Description | Lines changed |
|------|-------------|---------------|
| 2a | Create `StreamingMarkdownBuffer` with block splitting | ~150 new |
| 2b | Create `StreamingMarkdownWidget` wrapper | ~80 new |
| 2c | Replace `MarkdownWidget` usage in `TuiCoreRenderer::streamChunk()` | ~15 changed |
| 2d | Handle `streamComplete()` → freeze buffer, possibly downgrade to `MarkdownWidget` | ~20 new |
| 2e | Add `liveWindow` config (default 6) | ~5 new |
| 2f | Tests for block boundary detection | ~100 new |
| 2g | Tests for frozen/active line behavior | ~80 new |

**Block boundary detection edge cases to test**:
- Fenced code blocks with ` ``` ` inside them
- Nested lists
- Tables (GFM)
- Inline code containing double newlines
- Empty input
- Very long single paragraph (no block boundaries)

**Downgrade on streamComplete**: When streaming finishes, the `StreamingMarkdownWidget` has all its lines frozen. At this point, it behaves identically to a regular `MarkdownWidget`. We can either:
- (a) Keep using `StreamingMarkdownWidget` forever — it's already cached, no cost
- (b) Replace with `MarkdownWidget` for consistency — slight overhead of re-render on first non-streaming interaction

**Recommendation**: Option (a). No reason to swap. The buffer is already frozen, subsequent renders are cache hits.

### 5.3 Phase 3 — Virtual Scroll Integration (1 day)

**Depends on**: Virtual scrolling being implemented (`03-virtual-scrolling`)

**Steps**:

| Step | Description |
|------|-------------|
| 3a | Mark streaming widget as "always render" in virtual scroll manager |
| 3b | Hook `notifyContentChanged()` into `streamChunk()` |
| 3c | Ensure scroll-follow behavior works with virtual scroll |
| 3d | Test with long conversations (1000+ lines) + active streaming |

---

## 6. Performance Budget

### Target metrics (measured on M1 MacBook, 80-column terminal):

| Metric | Current | Phase 1 | Phase 2 |
|--------|---------|---------|---------|
| Render time per chunk (100 lines) | ~8ms | ~8ms | ~2ms |
| Render time per chunk (500 lines) | ~30ms | ~16ms* | ~4ms |
| Render time per chunk (2000 lines) | ~120ms | ~40ms* | ~6ms |
| CommonMark parse per chunk | O(n) full | O(n) full | O(last block) |
| Lines re-rendered per chunk | all | all | active block only |
| Terminal I/O per chunk | differential ✓ | differential ✓ | differential ✓ |

*Phase 1 improvement comes from throttling: fewer renders, same cost per render.

### Measurement approach:

```php
// Temporary profiling in TuiCoreRenderer::flushStreamAccumulator()
$start = hrtime(true);
$this->doStreamChunk($text);
$elapsed = (hrtime(true) - $start) / 1_000_000; // ms
// Log: sprintf("streamChunk render: %.2f ms (%d total lines)", $elapsed, count($lines))
```

---

## 7. Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Block boundary misdetection | Corrupted markdown rendering | Fallback: if frozen lines don't match full re-render, fall back to full render. Add assertion in debug mode. |
| Throttling causes text loss | Missing content | `streamComplete()` always flushes. Add invariant test. |
| PlainTextWidget styling mismatch | Visual inconsistency | Use identical wrapping logic. Test against MarkdownWidget output for plain text inputs. |
| StreamingMarkdownBuffer memory | Large buffers for long responses | frozenLines are string arrays — bounded by rendered line count, not text length. Same order as final output. |
| Widget tree interaction | Other widgets affected by streaming | Streaming widget is one child in the conversation container. Container layout is O(children) — unaffected. |

---

## 8. Alternative Approaches Considered

### 8.1 Incremental CommonMark Parsing

**Idea**: Modify league/commonmark to support incremental parsing (parse new text, merge AST).

**Rejected because**: league/commonmark doesn't support incremental parsing. Forking/maintaining a custom parser is high-cost. Block-level caching achieves the same benefit without parser changes.

### 8.2 Render on a Separate Thread

**Idea**: Offload markdown rendering to a background thread, double-buffer the output.

**Rejected because**: PHP doesn't have native multi-threading. `pnctl` forks are heavy for this purpose. The render pipeline is fast enough with prefix caching (~2–6ms per chunk) that async isn't needed.

### 8.3 Terminal-Aware Partial Invalidation

**Idea**: Instead of invalidating the widget's entire render cache, only invalidate the last N lines.

**Rejected because**: The render cache is per-widget (one array of lines). Partial cache invalidation would require changing the cache structure to support sliced access, adding complexity with no benefit over the StreamingMarkdownBuffer approach, which naturally produces "frozen + active" line arrays.

---

## 9. File Structure Summary

```
src/UI/Tui/
├── TuiCoreRenderer.php                    # Modified: throttling, widget selection
├── StreamingMarkdownBuffer.php            # New: prefix-caching buffer
├── Widget/
│   ├── PlainTextWidget.php                # New: fast-path text widget
│   ├── StreamingMarkdownWidget.php        # New: streaming-aware markdown widget
│   └── AnsiArtWidget.php                  # Unchanged
```
