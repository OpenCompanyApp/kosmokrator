# Widget Compaction & Eviction

> **Module**: `13-architecture`  
> **Depends on**: `03-virtual-scrolling` (for scroll-range virtualization)  
> **Status**: Plan

---

## Problem

Every conversation turn adds widgets to `ContainerWidget::children[]`. Each widget holds its full source content (markdown text, tool output, command strings) indefinitely. In long sessions:

- A `BashCommandWidget` stores the full `$command` + full `$output` (often 10–200 KB of tool output).
- A `MarkdownWidget` stores the full response text (2–20 KB per response), plus has an internal `MarkdownParser` and `Highlighter` instance.
- A `CollapsibleWidget` stores the full `$content` string (file diffs, file reads — 5–100 KB).
- A `DiscoveryBatchWidget` stores an array of items with full detail strings.

After 50+ turns with tool use, the conversation container can hold 200+ widgets retaining 20–100 MB of raw content in PHP memory — all of it immutable (the data is never modified after being added).

The widget tree is also walked on every render frame. Even with `AbstractWidget::renderCacheLines`, the PHP objects themselves consume RAM just existing.

## Design

### Widget Lifecycle Stages

```
 Active  ──►  Settled  ──►  Compacted  ──►  Evicted
  │           │              │               │
  │           │              │               └─ metadata only (type, summary, height estimate)
  │           │              └─ rendered string[] cached, original content freed
  │           └─ full content, no longer changing
  └─ full content, still streaming / interactive
```

#### Active

Widget is still receiving updates (streaming response, running bash command). Full content, interactive (expand/collapse). **Must stay in the widget tree.**

Applies to:
- `$activeResponse` (`MarkdownWidget`/`AnsiArtWidget` during streaming)
- `BashCommandWidget` with `$output === null` (still running)
- Any widget the user has explicitly expanded

#### Settled

Widget content is complete and will not change. Full content retained, but no longer needs to be interactive unless the user explicitly expands it.

This is the default state for 90%+ of conversation widgets after the turn completes. The widget remains in the tree and renders normally. The key distinction from Active is that **compaction is allowed**.

#### Compacted

The widget's rendered output has been captured as `string[]` (the return value of `render()`). The original content-holding properties are freed:

| Widget | Freed properties | Savings |
|--------|-----------------|---------|
| `MarkdownWidget` | `$text` (full markdown source) | 2–20 KB |
| `BashCommandWidget` | `$command`, `$output` | 10–200 KB |
| `CollapsibleWidget` | `$content` (full expanded text) | 5–100 KB |
| `DiscoveryBatchWidget` | `$items[].detail` | 1–50 KB |
| `TextWidget` | `$text` | 0.1–2 KB |
| `AnsiArtWidget` | `$text` | 1–10 KB |

A compacted widget replaces `render()` with a static return of the cached lines. Toggle/expand is disabled (or deferred to reconstitution).

**Implementation**: Each widget gets a `compact(): void` method that:
1. Calls `$this->render($lastContext)` to cache output.
2. Nulls out content properties.
3. Sets a `bool $isCompacted = true` flag.
4. Short-circuits future `render()` calls to return cached lines.

#### Evicted

Only metadata is retained — the widget is removed from the conversation container entirely. Metadata includes:

```php
final class EvictedWidgetEntry
{
    public function __construct(
        public readonly string $type,          // 'markdown', 'bash', 'collapsible', etc.
        public readonly string $summary,       // First line or summary for placeholder rendering
        public readonly int $estimatedHeight,  // Line count for scroll height calculation
        public readonly int $messageIndex,     // Index into session messages for reconstitution
        public readonly ?string $widgetId,     // Original widget ID if any
    ) {}
}
```

Evicted slots are rendered as dim placeholder lines: `  ⊛ 42 lines of bash output (scroll to load)`. They contribute to the scroll height but have near-zero RAM cost (~200 bytes each).

### New Classes

```
src/UI/Tui/Compaction/
├── WidgetCompactor.php              # Orchestrates compaction/eviction
├── EvictedWidgetEntry.php           # Metadata record for evicted widgets  
├── EvictedPlaceholderWidget.php     # Renders placeholder line(s) for evicted slots
└── CompactionStrategy.php           # Configurable thresholds and policy
```

### `CompactionStrategy` — Thresholds & Policy

```php
final class CompactionStrategy
{
    public function __construct(
        public readonly int $compactAfterNthWidget = 50,    // Start compacting after N widgets
        public readonly int $evictAfterNthWidget = 100,     // Start evicting after N widgets
        public readonly int $memoryThresholdBytes = 50 * 1024 * 1024, // 50 MB
        public readonly int $keepActiveCount = 20,           // Always keep last N widgets active
        public readonly int $keepSettledCount = 30,          // Keep N widgets in settled state
    ) {}
}
```

### `WidgetCompactor` — Orchestrator

```php
final class WidgetCompactor
{
    private array $evictedEntries = [];  // EvictedWidgetEntry[]
    private int $totalEstimatedHeight = 0;
    
    public function __construct(
        private readonly ContainerWidget $conversation,
        private readonly CompactionStrategy $strategy,
    ) {}

    /**
     * Called after each turn completes (or periodically via EventLoop::defer).
     * Walks widgets from oldest to newest, transitioning states.
     */
    public function compact(): void;

    /**
     * Estimate memory usage of all conversation widgets.
     * Uses strlen on content properties as approximation.
     */
    public function estimateMemoryUsage(): int;

    /**
     * When the user scrolls into an evicted region, reconstitute
     * the widgets from the session message history.
     *
     * @return int Number of widgets reconstituted
     */
    public function reconstituteRange(int $startLine, int $endLine): int;

    /**
     * Return total estimated scroll height (including evicted placeholders).
     */
    public function getTotalEstimatedHeight(): int;
}
```

### Widget Changes

Each content-heavy widget needs a `compact()` method and a `CompactedWidgetTrait`:

```php
trait CompactedWidgetTrait
{
    private bool $isCompacted = false;
    
    /** @var string[]|null Cached rendered lines */
    private ?array $compactedLines = null;
    
    public function isCompacted(): bool
    {
        return $this->isCompacted;
    }
    
    public function compact(RenderContext $context): void
    {
        if ($this->isCompacted) {
            return;
        }
        $this->compactedLines = $this->render($context);
        $this->isCompacted = true;
    }
    
    /**
     * To be called at the top of render() in each widget:
     * if ($this->isCompacted && $this->compactedLines !== null) {
     *     return $this->compactedLines;
     * }
     */
}
```

Each widget also needs:

```php
/** Returns a one-line summary for the evicted placeholder. */
public function getSummaryLine(): string;

/** Returns the estimated rendered height in lines. */
public function getEstimatedHeight(): int;
```

### Trigger Mechanism

Compaction is triggered in two ways:

1. **Widget count threshold**: After `addConversationWidget()`, check `count($conversation->all())`. If it exceeds `$strategy->compactAfterNthWidget`, schedule a compaction pass.

2. **Memory threshold**: Periodically (every N renders or every 30 seconds via `EventLoop::repeat()`), check `memory_get_usage()`. If it exceeds `$strategy->memoryThresholdBytes`, trigger compaction.

Both use `EventLoop::defer()` to avoid blocking the render loop:

```php
// In TuiCoreRenderer::addConversationWidget()
if (count($this->conversation->all()) > $this->compactor->shouldCompactAfter()) {
    EventLoop::defer(fn () => $this->compactor->compact());
}
```

### Compaction Pass Algorithm

```
compact():
  1. Get all widgets from conversation container
  2. Calculate keepZone = last keepActiveCount + keepSettledCount widgets
  3. For each widget outside keepZone, oldest first:
     a. If Active → skip (still updating)
     b. If Settled and compactAfterNthWidget exceeded:
        - Call widget.compact(lastRenderContext)
        - Mark as Compacted
     c. If Compacted and evictAfterNthWidget exceeded:
        - Create EvictedWidgetEntry from widget metadata
        - Remove widget from conversation container
        - Add EvictedPlaceholderWidget in its place
        - Append to evictedEntries list
  4. Update totalEstimatedHeight
```

### Virtual Scrolling Integration

The compaction system integrates with virtual scrolling (`03-virtual-scrolling`) to:

1. **Report total height**: `WidgetCompactor::getTotalEstimatedHeight()` returns the full scroll range including evicted placeholders.

2. **Map scroll position → visible widgets**: Only widgets in the visible viewport are in the `ContainerWidget::children[]` array. Evicted placeholders above/below the viewport are lightweight entries in the compactor's bookkeeping.

3. **Detect scroll into evicted region**: When the scroll position moves into a region backed by evicted entries, call `reconstituteRange()` to load widgets from session DB before the user sees them.

4. **Re-evict after scroll-away**: Once the user scrolls away from a reconstituted region, those widgets can be evicted again after a cooldown.

### Reconstitution from Session DB

When the user scrolls to evicted content:

```php
reconstituteRange(int $startLine, int $endLine): int
{
    // 1. Find which EvictedWidgetEntries fall in the line range
    $entries = $this->findEntriesInRange($startLine, $endLine);
    
    // 2. Load the corresponding messages from SessionRepository
    //    (messages table already stores role + content per turn)
    $messages = $this->sessionRepo->loadMessageRange(
        sessionId: $this->sessionId,
        startIndex: min(...$entries->messageIndices),
        endIndex: max(...$entries->messageIndices),
    );
    
    // 3. Re-run TuiConversationRenderer::replayHistory() for just
    //    those messages, but with a bounded widget factory that only
    //    creates the widgets for the target range.
    //    Alternative: store serialized widget snapshots in the DB
    //    (simpler but uses more disk).
    
    // 4. Replace EvictedPlaceholderWidgets with real widgets
    // 5. Return count of reconstituted widgets
}
```

**Simpler alternative (recommended for v1)**: Instead of re-running replay logic, store a serialized snapshot of the widget's render output in the session DB at compaction time. Reconstitution is then just a lookup and insertion.

```php
// At eviction time:
$renderedLines = $widget->render($lastContext);
$this->sessionDb->storeWidgetSnapshot($sessionId, $widgetIndex, $renderedLines);

// At reconstitution time:
$lines = $this->sessionDb->loadWidgetSnapshot($sessionId, $widgetIndex);
$widget = new StaticLinesWidget($lines);  // Simple widget that returns fixed lines
```

### `EvictedPlaceholderWidget`

```php
final class EvictedPlaceholderWidget extends AbstractWidget
{
    public function __construct(
        private readonly string $summary,
        private readonly int $estimatedHeight,
    ) {}

    public function render(RenderContext $context): array
    {
        $dim = Theme::dim();
        $r = Theme::reset();
        $lines = ["  {$dim}⊛ {$this->summary} ({$this->estimatedHeight} lines){$r}"];
        // Pad to estimated height so scroll calculations stay correct
        for ($i = 1; $i < $this->estimatedHeight; $i++) {
            $lines[] = '';
        }
        return $lines;
    }
}
```

### `StaticLinesWidget` (for reconstitution)

```php
final class StaticLinesWidget extends AbstractWidget
{
    public function __construct(
        private readonly array $lines,  // string[]
    ) {}

    public function render(RenderContext $context): array
    {
        return $this->lines;
    }
}
```

## Memory Savings Estimates

### Per-Widget Costs (Before Compaction)

| Widget Type | Content Size | PHP Object Overhead | Total |
|-------------|-------------|---------------------|-------|
| `MarkdownWidget` | 2–20 KB | ~4 KB (parser, highlighter, AST cache) | 6–24 KB |
| `BashCommandWidget` | 10–200 KB (output) | ~2 KB | 12–202 KB |
| `CollapsibleWidget` (file read) | 5–100 KB | ~1 KB | 6–101 KB |
| `CollapsibleWidget` (file edit diff) | 2–50 KB | ~1 KB | 3–51 KB |
| `DiscoveryBatchWidget` | 1–50 KB | ~1 KB | 2–51 KB |
| `TextWidget` | 0.1–2 KB | ~0.5 KB | 0.6–2.5 KB |
| `AnsiArtWidget` | 1–10 KB | ~0.5 KB | 1.5–10.5 KB |

### Per-Widget Costs (After Compaction)

| Widget Type | Cached Lines (rendered) | Object Overhead | Total |
|-------------|------------------------|-----------------|-------|
| Any compacted widget | 1–20 KB (rendered string[]) | ~0.5 KB | 1.5–20.5 KB |
| Any evicted entry | 0 bytes (not in tree) | ~0.2 KB | 0.2 KB |

### Scenario Estimates

**Typical 50-turn session** (each turn: 1 user msg + 1 response + 3 tool calls + 3 tool results):

- Total widgets: ~250 (50 × 5 non-trivial + 50 user messages + 50 headers)
- Average widget content: ~15 KB
- **Before compaction**: 250 × 15 KB = **~3.75 MB** (conservative)
- **After compaction** (200 compacted, 50 active): 200 × 5 KB + 50 × 15 KB = **~1.75 MB** (53% reduction)
- **After eviction** (180 evicted, 20 compacted, 50 active): 180 × 0.2 KB + 20 × 5 KB + 50 × 15 KB = **~0.9 MB** (76% reduction)

**Heavy tool-use session** (100 turns with bash, file_read, grep, large diffs):

- Total widgets: ~600
- Average widget content: ~40 KB (large bash outputs, file reads)
- **Before compaction**: 600 × 40 KB = **~24 MB**
- **After compaction** (500 compacted, 100 active): 500 × 8 KB + 100 × 40 KB = **~8 MB** (67% reduction)
- **After eviction** (450 evicted, 50 compacted, 100 active): 450 × 0.2 KB + 50 × 8 KB + 100 × 40 KB = **~4.5 MB** (81% reduction)

**Edge case** — agent running for hours with thousands of bash outputs:

- Total widgets: 2000+
- **Before compaction**: **>100 MB** (likely to hit PHP memory limits)
- **After eviction**: **<10 MB** (stable, bounded by keepActiveCount + keepSettledCount)

## Implementation Steps

### Phase 1: CompactedWidgetTrait & Widget Changes

1. Add `CompactedWidgetTrait` with `compact()`, `isCompacted()`, `$compactedLines` to the trait file.
2. Add `getSummaryLine(): string` and `getEstimatedHeight(): int` to each content widget.
3. Integrate the trait into: `MarkdownWidget` (subclass or wrapper), `BashCommandWidget`, `CollapsibleWidget`, `DiscoveryBatchWidget`, `TextWidget`, `AnsiArtWidget`.
4. Modify each widget's `render()` to short-circuit when compacted.

### Phase 2: EvictedWidgetEntry & Placeholder

1. Create `EvictedWidgetEntry` value object.
2. Create `EvictedPlaceholderWidget` with summary rendering.
3. Create `StaticLinesWidget` for reconstituted content.

### Phase 3: WidgetCompactor

1. Create `CompactionStrategy` with configurable thresholds.
2. Create `WidgetCompactor` with `compact()`, `estimateMemoryUsage()`, `reconstituteRange()`.
3. Implement the compaction pass algorithm (walk from oldest, respect keep zones).

### Phase 4: TuiCoreRenderer Integration

1. Inject `WidgetCompactor` into `TuiCoreRenderer`.
2. Schedule compaction after `addConversationWidget()` when count exceeds threshold.
3. Add periodic memory check via `EventLoop::repeat('30', fn() => ...)`.
4. Store a `RenderContext` reference for compaction (or reconstruct from terminal dimensions).

### Phase 5: Session DB Snapshots (Reconstitution)

1. Add `widget_snapshots` table to the session database:
   ```sql
   CREATE TABLE widget_snapshots (
       id INTEGER PRIMARY KEY AUTOINCREMENT,
       session_id TEXT NOT NULL,
       widget_index INTEGER NOT NULL,
       type TEXT NOT NULL,
       summary TEXT NOT NULL,
       estimated_height INTEGER NOT NULL,
       rendered_lines TEXT NOT NULL,  -- JSON-encoded string[]
       created_at TEXT NOT NULL,
       FOREIGN KEY (session_id) REFERENCES sessions(id)
   );
   ```
2. Write snapshot at eviction time.
3. Load snapshot on reconstitution.

### Phase 6: Virtual Scrolling Integration

1. Wire `WidgetCompactor::getTotalEstimatedHeight()` into the scroll range calculation.
2. Detect scroll into evicted regions and trigger reconstitution.
3. Re-evict widgets that scroll out of view after a cooldown.

### Phase 7: /compact Command

1. Expose manual compaction via `/compact` slash command.
2. Show compaction stats: "Compacted 150 widgets, evicted 80 (~15 MB freed)".
3. Allow configuration of thresholds in `/settings`.

## Edge Cases & Considerations

### Active Widget Detection

A widget is "Active" if:
- It is `$core->activeResponse` (currently streaming)
- It is a `BashCommandWidget` with `null` output
- It has been expanded by the user in the last N seconds (track via `lastToggleTime`)

The compactor must never compact/evict active widgets. The `keepActiveCount` strategy parameter provides a safety margin.

### Render Context Availability

Compaction calls `render($context)` to cache output. The `RenderContext` depends on terminal width. If the terminal is resized after compaction, compacted lines may be wrong width.

**Solution**: Recompact affected widgets on resize. Listen for terminal resize events and schedule recompaction of compacted widgets in the keep zone. Evicted widgets are re-rendered on reconstitution with the current width.

### Toggle on Compacted Widgets

A compacted widget cannot be toggled (expanded/collapsed) because the content is freed. Options:
1. **Disable toggle** — simplest. The preview lines in the compacted output are always visible.
2. **Reconstitute on toggle** — if the user tries to expand, load from session DB snapshot. Better UX but adds complexity.

**Recommendation**: v1 disables toggle on compacted widgets. The compacted output already shows the preview lines from the last render. Full reconstitution on toggle is a v2 enhancement.

### ContainerWidget::all() Ordering

Widgets are stored in insertion order in `ContainerWidget::$children[]`. The compactor walks this array from index 0 (oldest) upward. When evicting, `array_splice` is used (via `ContainerWidget::remove()`), which reindexes the array. The compactor must account for index shifts.

**Solution**: Collect widgets to evict first, then remove from newest to oldest (reverse order) to avoid index shift issues. Or use `ContainerWidget::remove($widget)` by reference.

### Concurrency

Compaction happens in `EventLoop::defer()` — single-threaded, no true concurrency concerns. But compaction must not run during an active render. Use a flag:

```php
private bool $isCompacting = false;

public function compact(): void
{
    if ($this->isCompacting) return;
    $this->isCompacting = true;
    try { /* ... */ } 
    finally { $this->isCompacting = false; }
}
```

### replayHistory() Interaction

`TuiConversationRenderer::replayHistory()` clears and rebuilds the conversation. The compactor must:
1. Clear all evicted entries on `clearConversationState()`.
2. Reset the compaction state when history is replayed (session resume).
3. Not attempt to compact during replay.

## Open Questions

1. **Should MarkdownWidget be subclassed or wrapped?** The Symfony `MarkdownWidget` is a vendor class. We can't add `CompactedWidgetTrait` to it directly. Options:
   - Create `CompactableMarkdownWidget extends MarkdownWidget` (simple, but coupled to vendor).
   - Wrap in a decorator that intercepts `render()` (cleaner, more indirection).
   - **Recommended**: Subclass — minimal code, the trait only adds a `render()` guard and a `compact()` method.

2. **Reconstitution data source**: Should we store rendered snapshots (simple, more disk) or re-run replay logic (complex, less disk)?  
   - **Recommended for v1**: Rendered snapshots. Disk is cheap; replay re-execution is fragile.

3. **Memory estimation accuracy**: `memory_get_usage()` includes all PHP memory, not just widgets. Should we use `strlen()` on content properties as a proxy?  
   - **Recommended**: Use `strlen()` sum on known content properties as the primary metric, with `memory_get_usage()` as a secondary safety net.
