# Memory Profiling & Optimization

> **Module**: `13-architecture`  
> **Depends on**: `02-widget-compaction` (for eviction/compaction lifecycle)  
> **Status**: Plan

---

## Problem

The TUI accumulates widgets in the `ContainerWidget::children[]` array for the entire session. Each widget holds its full source content (markdown, tool output, ANSI strings) indefinitely. Combined with ANSI color duplication, streaming string concatenation, and closure-heavy architecture, memory grows unbounded as the conversation continues.

**Observed trajectory** (estimated from code analysis):

| Session Phase | Widget Count | Estimated RAM |
|---------------|-------------|---------------|
| Start (intro) | 3–5 | ~8 MB |
| After 1st turn | 10–20 | ~12 MB |
| After 10 turns | 80–150 | ~25–40 MB |
| After 30 turns | 250–400 | ~60–120 MB |
| Long session (50+) | 500+ | ~100–200+ MB |

Target: **< 50 MB RAM for a typical 30-minute session** (≈15–25 turns with tool use).

---

## 1. Memory Hotspot Analysis

### 1.1 Conversation Widget Accumulation (CRITICAL)

**Source**: `TuiCoreRenderer::addConversationWidget()` → `ContainerWidget::add()`

Every tool call, response, status message, and user message creates a widget that is appended to `$this->conversation->children[]` and **never removed** during a session (only on explicit `/new` or `/compact`).

```php
// TuiCoreRenderer.php:572
public function addConversationWidget(AbstractWidget $widget): void
{
    $this->conversation->add($widget); // appends to children[], never pruned
}
```

**Growth rate**: ~5–15 widgets per turn × session length. Each widget is an object with properties holding the full content.

**Contributors by content size**:

| Widget Type | Instances/Turn | Content/Instance | Total/Turn |
|------------|---------------|-----------------|------------|
| `MarkdownWidget` | 1 (response) | 2–20 KB | 2–20 KB |
| `BashCommandWidget` | 1–5 | 5–100 KB (output) | 5–500 KB |
| `CollapsibleWidget` | 2–8 | 1–100 KB | 2–800 KB |
| `DiscoveryBatchWidget` | 0–2 | 5–50 KB (items[].detail) | 0–100 KB |
| `TextWidget` | 3–10 | 0.1–2 KB | 0.3–20 KB |
| `CancellableLoaderWidget` | 0–2 | ~0.5 KB | ~1 KB |

**Worst case**: A single "edit 5 files" turn can add ~1 MB of widget content.

### 1.2 String Concatenation During Streaming (HIGH)

**Source**: `TuiCoreRenderer::streamChunk()` at line 340

```php
$current = $this->activeResponse->getText();
$this->activeResponse->setText($current . $text);
```

This creates a new string on every chunk. For a typical LLM response:
- ~200–500 chunks per response
- Each chunk: `strlen($current) + strlen($text)` bytes allocated
- Final response ~5 KB → ~2.5 MB of intermediate string allocations

PHP's garbage collector eventually reclaims old strings, but peak memory during streaming equals the sum of all intermediate strings (triangular allocation pattern: `O(n²/2)` bytes allocated for a response of size `n`).

**Same pattern in**: `TuiAnimationManager::startBreathingAnimation()` where `Theme::rgb()` generates a new 20-byte ANSI string on every tick (~30fps).

### 1.3 ANSI Escape Sequence Duplication (MEDIUM)

**Source**: `Theme` class — every call generates a fresh string

```php
// Theme.php — each call returns a NEW string
public static function rgb(int $r, int $g, int $b): string {
    return "\033[38;2;{$r};{$g};{$b}m"; // 20 bytes, fresh allocation
}
public static function reset(): string {
    return "\033[0m"; // 4 bytes, fresh allocation
}
public static function dim(): string {
    return self::ESC."[38;5;240m"; // ~12 bytes, fresh allocation
}
```

Every widget render, every status bar update, every animation frame calls `Theme::reset()`, `Theme::dim()`, `Theme::accent()`, etc. A single `flushRender()` triggers rendering of all visible widgets, each calling Theme methods 5–20 times.

**Measured in breathing animation alone** (`TuiAnimationManager::startBreathingAnimation`):
- 30 fps × (`Theme::rgb()` + `Theme::reset()` + `Theme::dim()`) = ~90 new strings/second
- 30-second thinking period = ~2,700 Theme string allocations just for the loader

**Conversation widgets** (each render call):
- A `CollapsibleWidget::render()` calls `Theme::reset()`, `Theme::dim()`, `Theme::borderTask()` = 3 fresh strings
- 200 widgets × 3 Theme calls = 600 strings per render frame
- 30 fps rendering = 18,000 Theme string allocations/second

### 1.4 BashCommandWidget Full Output Storage (MEDIUM-HIGH)

**Source**: `BashCommandWidget::setResult()` at line 68

```php
public function setResult(string $output, bool $success): void {
    $this->output = self::normalizeOutput($output); // stores FULL output
    // ...
}
```

Bash commands can produce 10–200 KB of output. The widget stores:
1. The original `$command` string
2. The normalized `$output` (full, only non-SGR control chars stripped)

A typical codebase exploration session runs 20–50 bash commands. If each averages 20 KB output: **400 KB–1 MB** of bash output alone.

### 1.5 Subagent Result Accumulation (MEDIUM)

**Source**: `SubagentDisplayManager::showBatch()`

```php
// SubagentDisplayManager.php:213 — stores full result per agent
$details = implode("\n---\n", array_map(fn ($e) => $e['result'], $entries));
$expand = new CollapsibleWidget("{$dim}Full output{$r}", $details, 1, 120);
```

Each subagent result (5–50 KB) is stored in a `CollapsibleWidget`. For 5 subagents with 20 KB average results: **100 KB** per batch. These persist in the conversation container forever.

### 1.6 DiscoveryBatchWidget Item Detail Storage (MEDIUM)

**Source**: `TuiToolRenderer::appendDiscoveryToolCall()` / `buildDiscoveryItem()`

```php
// DiscoveryBatchWidget items include 'detail' — full file content or search output
'detail' => $name === 'file_read'
    ? $this->highlightFileOutput($output, (string) ($args['path'] ?? ''))
    : $output,
```

A discovery batch reading 10 files of ~5 KB each stores **~50 KB** in `items[].detail`. After the batch is finalized, the widget persists with all details in the conversation.

### 1.7 ScreenWriter Differential Buffer (LOW)

**Source**: `ScreenWriter` — stores `previousLines[]` and `previousRawLines[]`

```php
private array $previousLines = [];
private array $previousRawLines = [];
```

These arrays hold the full screen content from the previous frame for differential rendering. At 80×24 terminal: ~2 KB. Even at 200×60: ~12 KB. **Negligible** relative to widget content.

### 1.8 Closures (LOW)

**Source**: `TuiCoreRenderer` constructor and `bindInputHandlers()`

The following closures are created and stored:

| Component | Closures | Context Captured |
|-----------|---------|-----------------|
| `SubagentDisplayManager` constructor | 4 | `$this` (via methods) |
| `TuiAnimationManager` constructor | 8 | `$this` (via methods) |
| `TuiModalManager` constructor | 2 | `$this` (via methods) |
| `TuiInputHandler` constructor | 12 | `$this->queueMessage`, `$this->messageQueue`, etc. |
| `EventLoop::repeat()` callbacks | 3 | `$dim`, `$r`, `$this` |

Each closure in PHP occupies ~200–600 bytes (zval + opcode + bound variables). **20+ closures ≈ 4–12 KB total**. This is negligible.

---

## 2. Unbounded Growth Patterns

### 2.1 Primary: `ContainerWidget::children[]`

The `conversation` ContainerWidget is the dominant growth vector. Its `children[]` array grows by ~5–15 entries per turn and is only cleared by `clearConversation()` (explicit `/new` or `/compact`).

```
Turn 1:  [header, orrery, tutorial, user, response]                         → 5 widgets
Turn 2:  [... + user, tool-call, tool-result, tool-call, tool-result, response] → +5 = 10
Turn 3:  [... + user, bash, bash, collapsible, response]                    → +5 = 15
...
Turn 30: [... + N widgets]                                                   → ~200-400 widgets
```

**Root cause**: No eviction, no compaction, no limit. Every widget added since session start is retained.

### 2.2 Secondary: `lastToolArgsByName[]`

```php
// TuiToolRenderer.php:33
private array $lastToolArgsByName = [];
```

This accumulates one entry per unique tool name. For a typical session with 10 unique tool names: ~1–5 KB. Not a significant concern, but grows unbounded for sessions that use many different tool names.

### 2.3 Tertiary: Streaming Intermediates

During streaming, `streamChunk()` builds the full response text via repeated concatenation. PHP's copy-on-write semantics help with multiple references, but the intermediate strings from `$current . $text` are allocated fresh each time. The GC cleans them up, but **peak memory during streaming** can be ~3–5× the final response size.

### 2.4 Quaternary: `pendingQuestionRecap[]`

```php
// TuiCoreRenderer.php:108
private array $pendingQuestionRecap = [];
```

Grows with each queued question. Typically 0–5 entries. Flushes on the next user message or tool call. **Negligible**.

---

## 3. String Deduplication Opportunities

### 3.1 Theme Constants (HIGH IMPACT, LOW EFFORT)

The most frequently allocated strings are ANSI escape sequences. Every call to `Theme::reset()`, `Theme::dim()`, `Theme::accent()`, etc. creates a fresh string.

**Recommendation**: Cache all Theme results in static properties.

```php
// Before: fresh allocation every call
public static function reset(): string {
    return "\033[0m";
}

// After: cached singleton
private static ?string $reset = null;
public static function reset(): string {
    return self::$reset ??= "\033[0m";
}
```

**Estimated savings**: ~18,000 fewer string allocations/second during active rendering. Each allocation avoids a 4–20 byte string + zval overhead (16 bytes) = ~300 KB/s less GC pressure.

**Scope**: All 30+ Theme methods that return static strings. For `rgb()` with dynamic parameters, cache the 20–30 most common colors used in the breathing animation (the sine wave produces only ~100 distinct RGB values per cycle).

### 3.2 ANSI Escape Sequence Interming (MEDIUM IMPACT)

Widget content strings interleave ANSI escape codes with text:

```
"\033[38;2;255;200;80m✓\033[0m \033[38;2;80;220;100m●\033[0m"
```

Each status bar refresh, each tool result header, each tree node rendering generates these patterns anew. A "string pool" or `InternPool` for the 50 most common ANSI sequences would eliminate duplication across widgets.

**Recommendation**: Implement `AnsiStringPool` as a WeakMap or simple array cache:

```php
final class AnsiStringPool
{
    /** @var array<string, string> */
    private static array $pool = [];
    
    public static function get(string $sequence): string
    {
        return self::$pool[$sequence] ??= $sequence;
    }
}
```

Use it in `Theme` methods and in widget `render()` methods for repeated patterns like status icons (`✓`, `✗`, `●`).

### 3.3 Streaming Buffer (MEDIUM IMPACT, MEDIUM EFFORT)

Replace repeated concatenation in `streamChunk()` with a string builder pattern:

```php
// Before: O(n²) allocation
$current = $this->activeResponse->getText();
$this->activeResponse->setText($current . $text);

// After: append-only buffer
$this->streamBuffer .= $text;
// Commit to widget only on render frames (throttled)
```

This reduces peak allocation from `O(n²)` to `O(n)` for a response of size `n`.

---

## 4. Widget Content Storage Strategy

### 4.1 Current State: Eager Full-Content Storage

Every widget stores its full content from creation to session end:

| Widget | Stored Content | Lifetime |
|--------|---------------|----------|
| `MarkdownWidget` | Full markdown text (2–20 KB) | Session |
| `BashCommandWidget` | `$command` + `$output` (5–200 KB) | Session |
| `CollapsibleWidget` | `$header` + `$content` (1–100 KB) | Session |
| `DiscoveryBatchWidget` | `$items[]` with full details (5–50 KB) | Session |
| `TextWidget` | `$text` (0.1–2 KB) | Session |
| `AnsiArtWidget` | `$text` (1–10 KB) | Session |

### 4.2 Proposed: Three-Tier Storage

**(Depends on `02-widget-compaction.md` for the compaction/eviction lifecycle)**

```
 Tier 1: Active (full content, interactive)
   ↓ (after content finalized + 2 render frames)
 Tier 2: Compacted (cached rendered lines only)
   ↓ (after scrolled past viewport + N older widgets exist)
 Tier 3: Evicted (metadata only → placeholder widget)
```

#### Tier 1 → Tier 2 Compaction

When a widget's content is finalized (response complete, bash finished), capture its `render()` output and free the content properties:

```php
// In CollapsibleWidget
public function compact(?RenderContext $lastContext): void
{
    if ($this->isCompacted || $lastContext === null) return;
    $this->cachedLines = $this->render($lastContext);
    $this->content = ''; // free the full content
    $this->isCompacted = true;
}
```

**Savings per widget**: 90–99% of content memory (keeps only the rendered lines for collapsed display).

#### Tier 2 → Tier 3 Eviction

When a widget has been scrolled past the viewport and `N` newer widgets exist, replace it with a lightweight placeholder:

```php
final class EvictedPlaceholder extends AbstractWidget
{
    public function __construct(
        private readonly string $summary,   // first 80 chars
        private readonly int $estimatedHeight, // for scroll calculations
    ) {}
    
    public function render(RenderContext $context): array
    {
        return ["  {$this->summary}..."]; // single summary line
    }
}
```

**Savings**: 100% of content memory for the evicted widget.

### 4.3 Lazy Rendering for Collapsed Widgets

**Current**: `CollapsibleWidget` always stores full content and renders preview lines on every frame.

**Proposed**: When collapsed, render once and cache the preview. Only re-render on toggle:

```php
public function render(RenderContext $context): array
{
    if ($this->collapsedCache !== null && !$this->expanded) {
        return $this->collapsedCache; // skip re-rendering
    }
    // ... existing render logic
}
```

**Savings**: Eliminates repeated `explode()`, `array_slice()`, and ANSI width calculations for collapsed widgets on every frame.

---

## 5. Conversation History Eviction

### 5.1 Current Eviction Points

Conversation widgets are only cleared in `clearConversationState()`:

```php
// TuiCoreRenderer.php:566
public function clearConversationState(): void
{
    $this->conversation->clear(); // removes ALL widgets
    // ...
}
```

This is triggered by:
- `/new` command
- `/compact` command

There is **no partial eviction** — it's all or nothing.

### 5.2 Proposed: LRU Window Eviction

Maintain a sliding window of "live" widgets. Older widgets outside the window are compacted or evicted.

**Strategy**:

```
[Evicted placeholders...] [Compacted widgets] [Active widgets + viewport]
      ↑ oldest                      ↑ middle              ↑ newest
```

**Parameters**:
- `COMPACT_THRESHOLD = 20` — widgets older than 20th from bottom are compacted
- `EVICT_THRESHOLD = 60` — widgets older than 60th from bottom are evicted
- **Viewport protection**: Never compact/evict widgets currently visible on screen

**Implementation**: Run eviction check after each `addConversationWidget()` call:

```php
public function addConversationWidget(AbstractWidget $widget): void
{
    $this->conversation->add($widget);
    $this->maybeCompactAndEvict();
}

private function maybeCompactAndEvict(): void
{
    $children = $this->conversation->all();
    $count = count($children);
    
    if ($count < self::COMPACT_THRESHOLD) return;
    
    foreach ($children as $i => $child) {
        $age = $count - $i; // distance from newest
        
        if ($age > self::EVICT_THRESHOLD && $child instanceof CompactableInterface) {
            $this->evictWidget($child, $i);
        } elseif ($age > self::COMPACT_THRESHOLD && $child instanceof CompactableInterface) {
            $child->compact($this->lastRenderContext);
        }
    }
}
```

### 5.3 Scroll Interaction with Eviction

When the user scrolls up into history, evicted placeholders should render as dim summary lines. If the user expands a placeholder (ctrl+o), it could be reconstituted from the conversation history stored by the agent loop (not the TUI).

**Important**: The agent loop already has the full conversation history in memory for LLM context. Eviction in the TUI is purely a **display-layer** optimization — the data still exists in the agent's conversation store. This means eviction is safe: the data is not lost, just not duplicated in the widget layer.

---

## 6. Streaming Intermediate Strings

### 6.1 Current Pattern

```php
// TuiCoreRenderer.php:340
public function streamChunk(string $text): void
{
    // ...
    $current = $this->activeResponse->getText();  // reads full accumulated text
    $this->activeResponse->setText($current . $text); // allocates new string = old + chunk
    // ...
}
```

For a 5 KB response streamed in 300 chunks:
- Chunk 1: allocates ~17 bytes (first chunk)
- Chunk 150: allocates ~2,500 bytes (half the response + new chunk)
- Chunk 300: allocates ~5,000 bytes (full response)
- **Total allocations**: ~750 KB for a 5 KB response (150× overhead)

### 6.2 Proposed: Buffer-Then-Commit

```php
private string $streamBuffer = '';

public function streamChunk(string $text): void
{
    $this->streamBuffer .= $text; // append-only, PHP optimizes this
    
    // Throttle widget updates to render frame rate (~30fps)
    if ($this->shouldFlushStream()) {
        $this->activeResponse->setText($this->streamBuffer);
    }
}

public function streamComplete(): void
{
    if ($this->streamBuffer !== '') {
        $this->activeResponse->setText($this->streamBuffer);
        $this->streamBuffer = '';
    }
    // ...
}
```

PHP optimizes `.=` (concatenation-assignment) by extending the buffer in-place when the refcount is 1, making it `O(1)` amortized per append instead of `O(n)`.

### 6.3 MarkdownWidget Internal Duplication

`MarkdownWidget` stores `$text` AND parses it on every `render()` call via `$this->parser->parse($this->text)`. During streaming, this means:
- `getText()` returns the full accumulated text
- A new `setText()` replaces it
- The parser creates a new AST on every render frame

**Proposed**: Cache the parsed AST and invalidate only when text changes:

```php
private ?Document $parsedDocument = null;
private int $lastParsedLength = -1;

public function render(RenderContext $context): array
{
    if ($this->lastParsedLength !== strlen($this->text)) {
        $this->parsedDocument = $this->parser->parse($this->text);
        $this->lastParsedLength = strlen($this->text);
    }
    return $this->renderDocument($this->parsedDocument, $context->getColumns());
}
```

**Note**: This is a Symfony TUI upstream change. For KosmoKrator, we can subclass `MarkdownWidget` with this optimization.

---

## 7. Memory Profiling Strategy

### 7.1 Built-In Memory Reporter

Add a memory reporter accessible via:
1. **Signal-based**: `SIGUSR1` (kill -USR1 <pid>) dumps memory report to stderr
2. **Status bar**: Show `mem:XXm` in the status bar when a debug flag is set
3. **Command**: `/mem` command in the TUI input

**Implementation**:

```php
// New class: src/UI/Tui/TuiMemoryProfiler.php
final class TuiMemoryProfiler
{
    private array $snapshots = [];
    private array $componentSnapshots = [];
    
    public function snapshot(string $label): void
    {
        $this->snapshots[] = [
            'label' => $label,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_usage(false),
            'widget_count' => $this->countWidgets(),
        ];
    }
    
    public function profileComponent(string $name, object $component): array
    {
        $reflection = new ReflectionObject($component);
        $size = 0;
        foreach ($reflection->getProperties() as $prop) {
            $prop->setAccessible(true);
            $value = $prop->getValue($component);
            $size += $this->estimateSize($value);
        }
        return ['name' => $name, 'estimated_bytes' => $size];
    }
    
    public function generateReport(): string
    {
        // Format: timestamp, label, usage, peak, widget_count, delta
    }
}
```

### 7.2 Memory Snapshots at Key Lifecycle Points

Take snapshots at these moments:

| Lifecycle Point | Method | Label |
|----------------|--------|-------|
| After initialization | `TuiCoreRenderer::initialize()` | `init` |
| After intro render | `TuiCoreRenderer::renderIntro()` | `intro` |
| Before prompt | `TuiCoreRenderer::prompt()` | `pre-prompt-N` |
| After user message | `TuiCoreRenderer::showUserMessage()` | `user-msg-N` |
| After stream complete | `TuiCoreRenderer::streamComplete()` | `response-N` |
| After tool result | `TuiToolRenderer::showToolResult()` | `tool-N` |
| After compaction | `TuiCoreRenderer::maybeCompactAndEvict()` | `compact-N` |
| On teardown | `TuiCoreRenderer::teardown()` | `teardown` |

Enabled via environment variable: `KOSMOKRATOR_MEM_PROFILE=1`

### 7.3 Growth Rate Tracking Per Component

Track memory attributed to each component:

```php
// In TuiMemoryProfiler
public function trackGrowth(): array
{
    return [
        'conversation_widgets' => $this->estimateContainerSize($this->core->getConversation()),
        'subagent_display' => $this->profileComponent('SubagentDisplayManager', $this->core->getSubagentDisplay()),
        'animation_manager' => $this->profileComponent('TuiAnimationManager', $this->core->getAnimationManager()),
        'tool_renderer' => $this->profileComponent('TuiToolRenderer', $this->tool),
        'screen_writer' => $this->estimateScreenWriterBuffer(),
        'theme_strings' => $this->countThemeAllocations(),
    ];
}
```

**Display format** (in status bar or `/mem` command):

```
Memory Profile (turn 12, 4m32s elapsed)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Total: 28.3 MB (peak: 35.1 MB)
                                  
Conversation widgets:  19.2 MB (142 widgets)
  ├ MarkdownWidget:      8.4 MB (12 × avg 700 KB)
  ├ BashCommandWidget:   5.1 MB (23 × avg 222 KB)
  ├ CollapsibleWidget:   4.2 MB (38 × avg 110 KB)
  ├ DiscoveryBatchWidget:0.8 MB (4 × avg 200 KB)
  └ TextWidget:          0.7 MB (65 × avg 11 KB)
                        
Subagent display:       1.2 MB
Animation manager:      0.4 MB
Tool renderer state:    0.3 MB
Screen writer buffer:   0.1 MB
Theme string pool:      0.0 MB
                        
Growth rate: +1.8 MB/turn (+0.6 MB/min)
Projected at 30 turns:  54 MB ⚠ (over target)
```

### 7.4 Non-Invasive Measurement via `memory_get_usage()`

PHP's `memory_get_usage(true)` reports actual allocated memory from the allocator (real memory), while `memory_get_usage(false)` reports memory in use (excluding freed blocks).

**Approach**: Wrap key methods with before/after measurements:

```php
private function measure(callable $fn, string $label): mixed
{
    $before = memory_get_usage(false);
    $result = $fn();
    $after = memory_get_usage(false);
    $delta = $after - $before;
    
    if ($delta > 1024) { // only log significant allocations
        $this->profiler?->recordAllocation($label, $delta);
    }
    
    return $result;
}
```

**Key methods to wrap**:
- `addConversationWidget()` — measure each widget's contribution
- `streamChunk()` — measure streaming accumulation
- `showToolResult()` — measure tool output storage
- `SubagentDisplayManager::showBatch()` — measure subagent result storage
- `TuiConversationRenderer::replayHistory()` — measure replay memory impact

### 7.5 Signal Handler for Live Profiling

Register a SIGUSR1 handler to dump a full memory report:

```php
// In TuiCoreRenderer::initialize()
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, function () {
        $report = $this->memoryProfiler->generateReport();
        file_put_contents('/tmp/kosmokrator-mem-' . getmypid() . '.txt', $report);
        // Also append to stderr if possible
    });
}
```

---

## 8. Specific Optimization Recommendations

### 8.1 Quick Wins (< 1 day each)

| # | Optimization | Impact | Effort | File(s) |
|---|-------------|--------|--------|---------|
| 1 | Cache Theme static strings in class properties | ~300 KB/s less GC pressure | 2h | `Theme.php` |
| 2 | Stream buffer pattern in `streamChunk()` | 5–10× peak reduction during streaming | 3h | `TuiCoreRenderer.php` |
| 3 | Evict `CancellableLoaderWidget` after stop | Prevents loader accumulation | 1h | `TuiToolRenderer.php` |
| 4 | Limit `BashCommandWidget` output to last 500 lines | Caps bash output at ~20 KB | 2h | `BashCommandWidget.php` |
| 5 | Clear `lastToolArgsByName` on turn boundary | Prevents slow growth | 1h | `TuiToolRenderer.php` |

### 8.2 Medium-Term (1–3 days each)

| # | Optimization | Impact | Effort | File(s) |
|---|-------------|--------|--------|---------|
| 6 | Widget compaction (Tier 2) | 90% reduction in settled widget memory | 3d | All widget classes |
| 7 | Widget eviction with placeholders (Tier 3) | Enables unbounded session length | 3d | `TuiCoreRenderer.php`, new `EvictedPlaceholder` |
| 8 | DiscoveryBatchWidget lazy detail loading | Stores summaries, loads detail on expand | 2d | `DiscoveryBatchWidget.php` |
| 9 | MarkdownWidget parsed AST caching | Avoids re-parsing on every render frame | 2d | Subclass `MarkdownWidget` |

### 8.3 Structural (requires architecture coordination)

| # | Optimization | Impact | Effort | File(s) |
|---|-------------|--------|--------|---------|
| 10 | Virtual scrolling with bounded widget window | O(1) memory regardless of session length | 5d | `TuiCoreRenderer.php`, `Renderer` |
| 11 | Conversation history source-of-truth in agent loop | Eliminates TUI-side content duplication | 5d | `TuiConversationRenderer.php` |
| 12 | String interning pool for ANSI sequences | Eliminates cross-widget ANSI duplication | 2d | New `AnsiStringPool`, `Theme.php` |

---

## 9. Implementation Priority

### Phase 1: Measurement (Day 1–2)

1. Implement `TuiMemoryProfiler` with snapshot and reporting
2. Add `KOSMOKRATOR_MEM_PROFILE=1` env var support
3. Add `/mem` command to TUI input handler
4. Add lifecycle snapshots at all key points
5. Run a typical 30-minute session and capture the profile

**Deliverable**: Baseline memory profile report showing actual growth curve.

### Phase 2: Quick Wins (Day 3–5)

1. Theme string caching (#1)
2. Stream buffer pattern (#2)
3. Bash output truncation (#4)
4. Loader eviction (#3)
5. Args cleanup (#5)

**Target**: Reduce growth rate by 40–60%.

### Phase 3: Compaction (Day 6–12)

1. Add `CompactableInterface` with `compact()` method
2. Implement on all widget classes
3. Add compaction trigger in `addConversationWidget()`
4. Add eviction trigger for old widgets
5. Implement `EvictedPlaceholder` widget

**Target**: < 50 MB for 30-minute session.

### Phase 4: Advanced (Day 13–20)

1. Virtual scrolling window
2. ANSI string interning
3. MarkdownWidget AST caching
4. DiscoveryBatchWidget lazy loading

**Target**: < 30 MB for 30-minute session, < 50 MB for 60-minute session.

---

## 10. Success Metrics

| Metric | Current (est.) | After Phase 2 | After Phase 3 | After Phase 4 |
|--------|---------------|---------------|---------------|---------------|
| RAM at 10 turns | ~25 MB | ~15 MB | ~12 MB | ~10 MB |
| RAM at 30 turns | ~80 MB | ~50 MB | ~35 MB | ~25 MB |
| RAM at 60 turns | ~200 MB | ~120 MB | ~45 MB | ~30 MB |
| Peak during streaming | ~3× response size | ~1.2× response size | ~1.2× | ~1.1× |
| Theme allocations/frame | ~600 | ~0 (cached) | ~0 | ~0 |
| Widget content retained | 100% | ~80% | ~20% | ~5% |

**Primary target**: < 50 MB RAM for a typical 30-minute session (Phase 3).
**Stretch target**: < 30 MB RAM for a 60-minute session (Phase 4).
