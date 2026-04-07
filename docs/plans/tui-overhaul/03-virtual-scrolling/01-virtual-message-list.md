# 03-01: VirtualMessageList — Virtual Scrolling for the Conversation Container

## 1. Problem Statement

The conversation `ContainerWidget` grows unboundedly. Every user message, assistant response, tool result, and status widget is appended and never removed. In long sessions this accumulates hundreds to thousands of child widgets, all of which are rendered on every frame — even when scrolled far out of view.

**Current architecture** (`src/UI/Tui/TuiCoreRenderer.php`):

- Line 47: `$this->conversation` is a plain `ContainerWidget` that holds all messages.
- Line 108: `$this->scrollOffset` tracks scroll distance from bottom.
- Line 821: `Tui::setScrollOffset()` delegates to `ScreenWriter::setScrollOffset()`, which shifts the diff-rendering window up from the bottom by N lines. The full content is still rendered — just clipped at the terminal edge.
- Line 693: Every widget is added via `$this->conversation->add($widget)` and never removed.

**Symptoms in long sessions**:
- Render time grows linearly with message count.
- Memory usage increases monotonically.
- Streaming updates re-render the full widget tree even when only the last message changed.

---

## 2. Design Goals

| Goal | Metric |
|------|--------|
| **Constant-time renders** | Render cost bounded by viewport height, not message count |
| **Smooth scrolling** | PgUp/PgDn/G/Home/End respond in <16ms |
| **Zero visual regressions** | Existing scroll UX preserved identically |
| **Incremental migration** | Can ship behind a feature flag, coexists with current code |

---

## 3. How Claude Code Does It

Claude Code's terminal UI implements virtual scrolling through several cooperating concepts:

### 3.1 `VirtualMessageList` + `useVirtualScroll` Hook

A React-like hook that:
1. Maintains an ordered list of message descriptors (not DOM nodes).
2. Computes a **height map**: `Map<messageId, renderedHeight>`.
3. On each render, sums heights from the bottom up to find which messages intersect the viewport.
4. Only mounts/renders messages in the **visible window + headroom**.

### 3.2 Headroom (`HEADROOM = 3`)

When computing the visible window, Claude Code includes 3 extra message rows above the current viewport. This prevents flicker when the user scrolls up — the messages are already rendered and measured.

### 3.3 `OffscreenFreeze`

A wrapper component that:
- Receives a `frozen: boolean` prop.
- When `frozen = true`, the subtree does **not** re-render — its last rendered output is cached.
- Used for messages above or below the visible window.
- Critical during streaming: only the last (active) message re-renders; all prior messages are frozen.

### 3.4 Height Cache

- Keyed by **message content hash** (or message ID).
- Stores the pixel (row) height after the last render.
- **Invalidated on width change**: if the terminal width changes, all cached heights are stale because line-wrapping changes. The cache is cleared and rebuilt lazily.
- Prevents layout thrashing: scroll positions are computed from cached heights without re-rendering.

### 3.5 Hardware Scroll (`DECSTBM`)

When only the scroll position changes (no content mutation), Claude Code uses the terminal's native scroll region:
- `CSI r` (DECSTBM) sets top/bottom scroll margins.
- `CSI S` / `CSI T` scroll up/down by N lines.
- Avoids a full re-render — the terminal hardware handles the visual scroll.

---

## 4. Architecture

### 4.1 Component Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     TuiCoreRenderer                         │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐    │
│  │              VirtualMessageList                      │    │
│  │                                                      │    │
│  │  ┌──────────────────────────────────────────────┐   │    │
│  │  │  MessageWindow (renders visible + headroom)  │   │    │
│  │  │                                               │   │    │
│  │  │  [frozen] msg 0 (offscreen placeholder)      │   │    │
│  │  │  [frozen] msg 1 (offscreen placeholder)      │   │    │
│  │  │  ...                                          │   │    │
│  │  │  ── headroom ──                               │   │    │
│  │  │  [live]   msg N-3                             │   │    │
│  │  │  [live]   msg N-2                             │   │    │
│  │  │  [live]   msg N-1                             │   │    │
│  │  │  [live]   msg N   ← viewport top             │   │    │
│  │  │  [live]   msg N+1                             │   │    │
│  │  │  ...                                          │   │    │
│  │  │  [live]   msg N+V   ← viewport bottom        │   │    │
│  │  │  ── headroom ──                               │   │    │
│  │  │  [frozen] msg N+V+1 (offscreen placeholder)  │   │    │
│  │  │  ...                                          │   │    │
│  │  └──────────────────────────────────────────────┘   │    │
│  │                                                      │    │
│  │  HeightCache ── MessageRegistry ── ScrollState      │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                             │
│  ScrollbarWidget ◄── bound to ScrollState signals           │
│  HistoryStatusWidget ◄── bound to ScrollState signals       │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 New Classes

All classes live under `src/UI/Tui/VirtualScroll/`.

#### 4.2.1 `MessageDescriptor`

Value object representing a single message entry in the virtual list.

```php
namespace KosmoKrator\UI\Tui\VirtualScroll;

/**
 * Describes a single message in the virtual list.
 *
 * The descriptor is lightweight — it does NOT hold the widget instance
 * (widgets are created on demand). Instead it stores enough information
 * to reconstruct or freeze the widget.
 */
final class MessageDescriptor
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,           // 'user', 'assistant', 'tool', 'status', 'system'
        public readonly int $contentHash,       // hash of content for cache invalidation
        public readonly array $widgetParams,    // params to (re)create the widget
        public readonly bool $isStreaming = false,
        public readonly ?\Closure $widgetFactory = null,
    ) {}

    public function withContentHash(int $hash): self
    {
        return new self(
            $this->id,
            $this->type,
            $hash,
            $this->widgetParams,
            $this->isStreaming,
            $this->widgetFactory,
        );
    }
}
```

#### 4.2.2 `HeightCache`

Stores the rendered row-height of each message, invalidated when terminal width changes.

```php
namespace KosmoKrator\UI\Tui\VirtualScroll;

/**
 * Caches the rendered row-height of each message.
 *
 * Invalidation strategy:
 * - On terminal width change: clear all entries (line-wrapping changes).
 * - On message content change (hash mismatch): clear that entry.
 * - On message removal: remove that entry.
 */
final class HeightCache
{
    /** @var array<string, int> messageId → rendered row count */
    private array $heights = [];

    /** Width at which the cache was last fully validated */
    private int $cachedWidth = 0;

    public function get(string $messageId): ?int
    {
        return $this->heights[$messageId] ?? null;
    }

    public function set(string $messageId, int $height): void
    {
        $this->heights[$messageId] = $height;
    }

    public function remove(string $messageId): void
    {
        unset($this->heights[$messageId]);
    }

    /**
     * Invalidate all entries if the terminal width has changed.
     */
    public function validateWidth(int $currentWidth): void
    {
        if ($this->cachedWidth !== $currentWidth) {
            $this->heights = [];
            $this->cachedWidth = $currentWidth;
        }
    }

    /**
     * Invalidate a single entry if its content hash changed.
     */
    public function validateEntry(string $messageId, int $contentHash): void
    {
        // The cache key includes both ID and hash.
        // If hash differs, the entry is already absent (different key).
        // This is handled by the caller checking cache before measurement.
    }

    /**
     * Sum cached heights for a range of message IDs.
     *
     * @param string[] $messageIds
     */
    public function sumHeights(array $messageIds): int
    {
        $sum = 0;
        foreach ($messageIds as $id) {
            $sum += $this->heights[$id] ?? 0;
        }
        return $sum;
    }

    public function clear(): void
    {
        $this->heights = [];
    }

    public function getCachedWidth(): int
    {
        return $this->cachedWidth;
    }
}
```

#### 4.2.3 `MessageRegistry`

The central ordered list of all messages. Replaces direct `$conversation->add()` calls.

```php
namespace KosmoKrator\UI\Tui\VirtualScroll;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Maintains the ordered list of all message descriptors and their
 * associated widget instances.
 *
 * This is the "source of truth" for what messages exist. The virtual
 * list queries this to determine what to render.
 */
final class MessageRegistry
{
    /** @var list<MessageDescriptor> */
    private array $descriptors = [];

    /** @var array<string, AbstractWidget> messageId → widget (lazy) */
    private array $widgets = [];

    /** @var array<string, bool> messageId → whether widget is "live" */
    private array $frozen = [];

    private int $version = 0;

    /** Append a new message. */
    public function append(MessageDescriptor $descriptor, AbstractWidget $widget): void
    {
        $this->descriptors[] = $descriptor;
        $this->widgets[$descriptor->id] = $widget;
        $this->frozen[$descriptor->id] = false;
        $this->version++;
    }

    /** Update an existing message (e.g., streaming content update). */
    public function update(string $messageId, MessageDescriptor $descriptor): void
    {
        foreach ($this->descriptors as $i => $d) {
            if ($d->id === $messageId) {
                $this->descriptors[$i] = $descriptor;
                $this->version++;
                return;
            }
        }
    }

    /** Remove a message by ID. */
    public function remove(string $messageId): void
    {
        foreach ($this->descriptors as $i => $d) {
            if ($d->id === $messageId) {
                array_splice($this->descriptors, $i, 1);
                unset($this->widgets[$messageId], $this->frozen[$messageId]);
                $this->version++;
                return;
            }
        }
    }

    /** Get all descriptors in order. */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    /** Get descriptor by ID. */
    public function getDescriptor(string $messageId): ?MessageDescriptor
    {
        foreach ($this->descriptors as $d) {
            if ($d->id === $messageId) {
                return $d;
            }
        }
        return null;
    }

    /** Get or create the widget for a message. */
    public function getWidget(string $messageId): ?AbstractWidget
    {
        return $this->widgets[$messageId] ?? null;
    }

    /** Register a widget for a message (used after lazy creation). */
    public function setWidget(string $messageId, AbstractWidget $widget): void
    {
        $this->widgets[$messageId] = $widget;
    }

    /** Mark a message as frozen (offscreen) or live. */
    public function setFrozen(string $messageId, bool $frozen): void
    {
        $this->frozen[$messageId] = $frozen;
    }

    public function isFrozen(string $messageId): bool
    {
        return $this->frozen[$messageId] ?? true;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function count(): int
    {
        return count($this->descriptors);
    }

    public function clear(): void
    {
        $this->descriptors = [];
        $this->widgets = [];
        $this->frozen = [];
        $this->version++;
    }
}
```

#### 4.2.4 `ScrollState`

Value object (and future signal holder) for scroll position.

```php
namespace KosmoKrator\UI\Tui\VirtualScroll;

/**
 * Immutable snapshot of the current scroll state.
 *
 * In phase 1, this is a plain value object computed on each render.
 * In phase 2 (signal integration), it becomes a computed signal.
 */
final class ScrollState
{
    public function __construct(
        public readonly int $totalHeight,      // sum of all message heights (rows)
        public readonly int $viewportHeight,    // visible rows in the conversation area
        public readonly int $scrollOffset,      // rows scrolled up from bottom
        public readonly int $messageCount,      // total messages
        public readonly int $firstVisibleIndex, // index of first visible message
        public readonly int $lastVisibleIndex,  // index of last visible message
    ) {}

    public function isScrolled(): bool
    {
        return $this->scrollOffset > 0;
    }

    public function maxScrollOffset(): int
    {
        return max(0, $this->totalHeight - $this->viewportHeight);
    }

    public function scrollFraction(): float
    {
        $max = $this->maxScrollOffset();
        return $max > 0 ? $this->scrollOffset / $max : 0.0;
    }
}
```

#### 4.2.5 `VirtualMessageList`

The main orchestrator. Replaces the plain `ContainerWidget` for the conversation area.

```php
namespace KosmoKrator\UI\Tui\VirtualScroll;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Virtual scrolling container for the conversation message list.
 *
 * Instead of rendering all messages, this computes which messages
 * are visible (or near-visible) and only renders those.
 *
 * Rendering strategy:
 *   1. Validate HeightCache against current terminal width.
 *   2. Compute visible window from scrollOffset + viewportHeight.
 *   3. Expand window by HEADROOM messages above and below.
 *   4. Freeze all messages outside the window.
 *   5. Render only live messages into the output ContainerWidget.
 *   6. After render, measure actual heights and update cache.
 *
 * Integration with Symfony TUI:
 *   - The visible messages are placed in a ContainerWidget that the
 *     Symfony TUI renderer processes normally.
 *   - ScreenWriter::setScrollOffset() is still used for the actual
 *     viewport windowing — but now the underlying content is much
 *     smaller (only visible messages).
 */
final class VirtualMessageList
{
    public const HEADROOM = 3;  // Extra messages above/below viewport

    private readonly MessageRegistry $registry;
    private readonly HeightCache $heightCache;

    /** Current scroll offset in rows (from bottom). */
    private int $scrollOffset = 0;

    /** Viewport height in rows (set externally from terminal dimensions). */
    private int $viewportHeight = 0;

    /** Terminal width at last render. */
    private int $terminalWidth = 0;

    /** Whether new content arrived while user is scrolled up. */
    private bool $hasUnseenContent = false;

    /** The container that holds only the visible widgets. */
    private readonly ContainerWidget $viewport;

    /** @var list<string> IDs of messages currently in the viewport container */
    private array $renderedMessageIds = [];

    public function __construct(
        private readonly int $headroom = self::HEADROOM,
    ) {
        $this->registry = new MessageRegistry();
        $this->heightCache = new HeightCache();
        $this->viewport = new ContainerWidget();
        $this->viewport->setId('virtual-viewport');
        $this->viewport->expandVertically(true);
    }

    // ── Public API ────────────────────────────────────────────────

    /**
     * Append a new message widget.
     *
     * Returns the assigned message ID for future reference.
     */
    public function appendMessage(
        string $type,
        AbstractWidget $widget,
        int $contentHash = 0,
        bool $isStreaming = false,
    ): string {
        $id = $this->generateId();
        $descriptor = new MessageDescriptor(
            id: $id,
            type: $type,
            contentHash: $contentHash,
            widgetParams: [],
            isStreaming: $isStreaming,
        );
        $this->registry->append($descriptor, $widget);

        // If user is scrolled up, mark unseen content.
        if ($this->scrollOffset > 0) {
            $this->hasUnseenContent = true;
        }

        return $id;
    }

    /**
     * Update content hash for a streaming message (triggers re-measurement).
     */
    public function updateContentHash(string $messageId, int $newHash): void
    {
        $descriptor = $this->registry->getDescriptor($messageId);
        if ($descriptor !== null) {
            $this->registry->update(
                $messageId,
                $descriptor->withContentHash($newHash),
            );
        }
    }

    /**
     * Remove a message by ID.
     */
    public function removeMessage(string $messageId): void
    {
        $this->registry->remove($messageId);
        $this->heightCache->remove($messageId);
    }

    /**
     * Scroll up by N rows.
     */
    public function scrollUp(int $rows): void
    {
        $this->scrollOffset = min(
            $this->scrollOffset + $rows,
            $this->computeMaxScrollOffset(),
        );
    }

    /**
     * Scroll down by N rows.
     */
    public function scrollDown(int $rows): void
    {
        $this->scrollOffset = max(0, $this->scrollOffset - $rows);
        if ($this->scrollOffset === 0) {
            $this->hasUnseenContent = false;
        }
    }

    /**
     * Jump to the bottom (live output).
     */
    public function jumpToBottom(): void
    {
        $this->scrollOffset = 0;
        $this->hasUnseenContent = false;
    }

    /**
     * Get the ContainerWidget to embed in the layout.
     *
     * This container only holds the currently visible messages.
     * Its contents are rebuilt on each reconcile() call.
     */
    public function getViewportContainer(): ContainerWidget
    {
        return $this->viewport;
    }

    /**
     * Get the full registry (for querying message state).
     */
    public function getRegistry(): MessageRegistry
    {
        return $this->registry;
    }

    /**
     * Get the height cache (for measurement updates).
     */
    public function getHeightCache(): HeightCache
    {
        return $this->heightCache;
    }

    /**
     * Get the current scroll state snapshot.
     */
    public function getScrollState(): ScrollState
    {
        $this->heightCache->validateWidth($this->terminalWidth);

        $totalHeight = 0;
        $descriptors = $this->registry->getDescriptors();
        foreach ($descriptors as $d) {
            $totalHeight += $this->heightCache->get($d->id) ?? $this->estimateHeight($d);
        }

        [$firstIdx, $lastIdx] = $this->computeVisibleRange($descriptors, $totalHeight);

        return new ScrollState(
            totalHeight: $totalHeight,
            viewportHeight: $this->viewportHeight,
            scrollOffset: $this->scrollOffset,
            messageCount: count($descriptors),
            firstVisibleIndex: $firstIdx,
            lastVisibleIndex: $lastIdx,
        );
    }

    public function isScrolled(): bool
    {
        return $this->scrollOffset > 0;
    }

    public function hasUnseenContent(): bool
    {
        return $this->hasUnseenContent;
    }

    public function setViewportDimensions(int $width, int $height): void
    {
        $this->terminalWidth = $width;
        $this->viewportHeight = $height;
    }

    public function clear(): void
    {
        $this->registry->clear();
        $this->heightCache->clear();
        $this->scrollOffset = 0;
        $this->hasUnseenContent = false;
        $this->renderedMessageIds = [];
        $this->viewport->clear();
    }

    // ── Reconciliation (called each render cycle) ────────────────

    /**
     * Rebuild the viewport container to contain only visible messages.
     *
     * This is the core of virtual scrolling. Called before each render.
     *
     * @return string[] IDs of messages that were newly rendered (need measurement)
     */
    public function reconcile(): array
    {
        $this->heightCache->validateWidth($this->terminalWidth);

        $descriptors = $this->registry->getDescriptors();
        if ($descriptors === []) {
            $this->viewport->clear();
            $this->renderedMessageIds = [];
            return [];
        }

        // Compute which messages should be visible.
        $totalHeight = $this->computeTotalHeight($descriptors);
        [$firstIdx, $lastIdx] = $this->computeVisibleRange($descriptors, $totalHeight);

        // Expand by headroom.
        $firstIdx = max(0, $firstIdx - $this->headroom);
        $lastIdx = min(count($descriptors) - 1, $lastIdx + $this->headroom);

        // Determine which messages should be live.
        $desiredIds = [];
        $needsMeasurement = [];
        for ($i = $firstIdx; $i <= $lastIdx; $i++) {
            $d = $descriptors[$i];
            $desiredIds[] = $d->id;
            if ($this->heightCache->get($d->id) === null) {
                $needsMeasurement[] = $d->id;
            }
        }

        // Freeze messages that scrolled out.
        foreach ($this->renderedMessageIds as $oldId) {
            if (!in_array($oldId, $desiredIds, true)) {
                $this->registry->setFrozen($oldId, true);
            }
        }

        // Rebuild viewport: remove old, add new.
        $this->viewport->clear();
        $this->renderedMessageIds = [];

        foreach ($desiredIds as $id) {
            $widget = $this->registry->getWidget($id);
            if ($widget !== null) {
                $this->viewport->add($widget);
                $this->renderedMessageIds[] = $id;
                $this->registry->setFrozen($id, false);
            }
        }

        return $needsMeasurement;
    }

    /**
     * After rendering, update the height cache with measured heights.
     *
     * @param array<string, int> $measuredHeights messageId → row count
     */
    public function updateMeasuredHeights(array $measuredHeights): void
    {
        foreach ($measuredHeights as $id => $height) {
            $this->heightCache->set($id, $height);
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function generateId(): string
    {
        return 'msg_' . bin2hex(random_bytes(8));
    }

    /**
     * Compute the total height of all messages using cached or estimated heights.
     */
    private function computeTotalHeight(array $descriptors): int
    {
        $total = 0;
        foreach ($descriptors as $d) {
            $total += $this->heightCache->get($d->id) ?? $this->estimateHeight($d);
        }
        return $total;
    }

    /**
     * Compute the [first, last] index of messages that intersect the viewport.
     *
     * Strategy: walk from the bottom, accumulating heights, to find which
     * messages span the visible window defined by scrollOffset + viewportHeight.
     */
    private function computeVisibleRange(array $descriptors, int $totalHeight): array
    {
        $count = count($descriptors);
        if ($count === 0) {
            return [0, 0];
        }

        // The viewport shows a window from (totalHeight - viewportHeight - scrollOffset)
        // to (totalHeight - scrollOffset).
        $viewBottom = $totalHeight - $this->scrollOffset;
        $viewTop = $viewBottom - $this->viewportHeight;

        $firstIdx = 0;
        $lastIdx = $count - 1;

        // Walk from bottom to find lastIdx.
        $accumulated = 0;
        for ($i = $count - 1; $i >= 0; $i--) {
            $h = $this->heightCache->get($descriptors[$i]->id) ?? $this->estimateHeight($descriptors[$i]);
            $accumulated += $h;
            if ($accumulated >= $viewBottom) {
                // This message spans or is below the viewport bottom.
                // Actually, we want to find where the viewport bottom falls.
                $lastIdx = $i + (int)(($accumulated - $viewBottom) > 0 ? 0 : 0);
                $lastIdx = min($count - 1, $i);
                break;
            }
        }

        // Walk from bottom accumulating to find firstIdx.
        $accumulated = 0;
        for ($i = $count - 1; $i >= 0; $i--) {
            $h = $this->heightCache->get($descriptors[$i]->id) ?? $this->estimateHeight($descriptors[$i]);
            $accumulated += $h;
            if ($accumulated >= $viewTop) {
                $firstIdx = $i;
                break;
            }
        }

        return [max(0, $firstIdx), max(0, min($count - 1, $lastIdx))];
    }

    /**
     * Estimate the height of a message when not yet measured.
     */
    private function estimateHeight(MessageDescriptor $descriptor): int
    {
        return match ($descriptor->type) {
            'user' => 3,
            'system' => 2,
            'status' => 1,
            default => 5,  // assistant, tool — conservative estimate
        };
    }

    private function computeMaxScrollOffset(): int
    {
        $descriptors = $this->registry->getDescriptors();
        $totalHeight = $this->computeTotalHeight($descriptors);
        return max(0, $totalHeight - $this->viewportHeight);
    }
}
```

#### 4.2.6 `OffscreenFreeze`

A widget wrapper that caches its last rendered output and skips re-rendering when frozen.

```php
namespace KosmoKrator\UI\Tui\VirtualScroll;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Wraps a widget to prevent re-rendering when offscreen.
 *
 * When frozen, the widget's render() returns the cached output
 * from the last live render — avoiding any computation inside
 * the wrapped widget.
 *
 * This is critical for streaming performance: only the active
 * (last) message re-renders; all prior messages are frozen.
 */
final class OffscreenFreeze extends AbstractWidget
{
    private bool $frozen = false;

    /** @var string[]|null Cached rendered lines */
    private ?array $cachedLines = null;

    private int $cachedWidth = 0;

    public function __construct(
        private readonly AbstractWidget $inner,
    ) {
        $this->inner->setParent($this);
    }

    public function setFrozen(bool $frozen): void
    {
        $this->frozen = $frozen;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function getInner(): AbstractWidget
    {
        return $this->inner;
    }

    public function render(RenderContext $context): array
    {
        $currentWidth = $context->width();

        // If frozen AND width hasn't changed, return cached output.
        if ($this->frozen && $this->cachedLines !== null && $this->cachedWidth === $currentWidth) {
            return $this->cachedLines;
        }

        // Render the inner widget.
        $lines = $this->inner->render($context);

        // Cache the result.
        $this->cachedLines = $lines;
        $this->cachedWidth = $currentWidth;

        return $lines;
    }
}
```

#### 4.2.7 `HardwareScrollHint` (Phase 2)

A utility for optimizing scroll-only changes using terminal escape sequences.

```php
namespace KosmoKrator\UI\Tui\VirtualScroll;

/**
 * Provides hardware scroll hints for the terminal.
 *
 * When only the scroll position changes (no content mutation),
 * we can use terminal escape sequences to scroll the existing
 * content instead of re-rendering:
 *
 * - DECSTBM (CSI Pt ; Pb r): Set scroll region (top/bottom margins).
 * - SU     (CSI Pn S):       Scroll up by Pn lines.
 * - SD     (CSI Pn T):       Scroll down by Pn lines.
 *
 * Phase 2 optimization — not required for initial implementation.
 */
final class HardwareScrollHint
{
    /**
     * Determine whether a scroll change can be handled by hardware scroll.
     *
     * Conditions:
     * - No messages were added or removed since last render.
     * - No message content changed (no streaming updates).
     * - Only scrollOffset changed.
     * - The terminal supports the required escape sequences.
     */
    public function canHardwareScroll(ScrollState $previous, ScrollState $current): bool
    {
        // Content changes require a full re-render.
        if ($previous->messageCount !== $current->messageCount) {
            return false;
        }

        if ($previous->totalHeight !== $current->totalHeight) {
            return false;
        }

        return $previous->scrollOffset !== $current->scrollOffset;
    }

    /**
     * Generate the escape sequence for a hardware scroll.
     *
     * @return string|null ANSI escape sequence, or null if not applicable.
     */
    public function getScrollSequence(
        ScrollState $previous,
        ScrollState $current,
    ): ?string {
        $delta = $current->scrollOffset - $previous->scrollOffset;

        if ($delta === 0) {
            return null;
        }

        if ($delta > 0) {
            // Scrolled up — content moves up, need to scroll down (SD).
            return "\x1b[{$delta}T";
        }

        // Scrolled down — content moves down, need to scroll up (SU).
        $amount = abs($delta);
        return "\x1b[{$amount}S";
    }
}
```

---

## 5. Integration with Symfony TUI

### 5.1 Current Flow

```
TuiCoreRenderer::flushRender()
  → Tui::requestRender() + processRender()
    → ScreenWriter::write() (diff-based)
      → Uses scrollOffset to shift the viewport window
      → Renders ALL lines from ALL conversation widgets
```

### 5.2 New Flow

```
TuiCoreRenderer::flushRender()
  → VirtualMessageList::reconcile()
    → Computes visible range
    → Rebuilds viewport ContainerWidget with only visible messages
    → Wraps offscreen messages with OffscreenFreeze
  → Tui::requestRender() + processRender()
    → ScreenWriter::write()
      → scrollOffset is now LOCAL to the viewport
      → Only renders lines from visible messages
```

### 5.3 Scroll Offset Translation

The Symfony TUI `ScreenWriter::setScrollOffset()` shifts the rendering window up from the bottom. After virtual scrolling, the scroll offset needs to be translated:

```php
// In TuiCoreRenderer:

private function applyScrollOffset(): void
{
    if ($this->virtualScrollingEnabled) {
        // The VirtualMessageList handles its own window.
        // We only need to tell ScreenWriter about any intra-message scroll.
        $intraOffset = $this->virtualList->getIntraMessageOffset();
        $this->tui->setScrollOffset($intraOffset);
    } else {
        $this->tui->setScrollOffset($this->scrollOffset);
    }

    $this->refreshHistoryStatus();
    $this->flushRender();
}
```

### 5.4 Measurement Integration

After each render, we need to measure how many rows each rendered widget produced. The Symfony TUI `ScreenWriter` tracks lines per widget indirectly. We need a measurement hook:

```php
// After rendering, measure heights from the output:
$needsMeasurement = $this->virtualList->reconcile();
$this->flushRender();

// Post-render measurement:
foreach ($needsMeasurement as $messageId) {
    $widget = $this->virtualList->getRegistry()->getWidget($messageId);
    // Measure from the rendered buffer (Symfony TUI provides line counts).
    $height = $this->measureWidgetHeight($widget);
    $measured[$messageId] = $height;
}
$this->virtualList->updateMeasuredHeights($measured);
```

The `measureWidgetHeight()` method calls `$widget->render($context)` and counts the returned lines — a lightweight operation.

---

## 6. Integration with Signal-Based State (Phase 01)

The virtual scroll system will integrate with the reactive state layer from `01-reactive-state`:

```php
// Future: Signal-based integration (Phase 2, after signal primitives land)

use KosmoKrator\UI\Tui\Signal\Signal;
use KosmoKrator\UI\Tui\Signal\Computed;
use KosmoKrator\UI\Tui\Signal\Effect;

// In TuiCoreRenderer initialization:
$this->scrollOffsetSignal = new Signal(0);
$this->viewportHeightSignal = new Signal(0);
$this->terminalWidthSignal = new Signal(0);

// Computed scroll state — automatically recalculates when inputs change.
$this->scrollStateSignal = new Computed(function () {
    return $this->virtualList->computeScrollState(
        $this->scrollOffsetSignal->get(),
        $this->viewportHeightSignal->get(),
        $this->terminalWidthSignal->get(),
    );
});

// Effect: update scrollbar when scroll state changes.
new Effect(function () use ($scrollStateSignal, $scrollbar) {
    $state = $scrollStateSignal->get();
    $scrollbar->setState(new ScrollbarState(
        contentLength: $state->totalHeight,
        viewportLength: $state->viewportHeight,
        position: max(0, $state->totalHeight - $state->viewportHeight - $state->scrollOffset),
    ));
});

// Effect: update history status indicator.
new Effect(function () use ($scrollStateSignal, $historyStatus) {
    if ($scrollStateSignal->get()->isScrolled()) {
        $historyStatus->show($this->virtualList->hasUnseenContent());
    } else {
        $historyStatus->hide();
    }
});
```

---

## 7. "New Messages Below" Indicator

When the user scrolls up and new content arrives at the bottom:

```
┌──────────────────────────────────┐
│  [User message]                  │
│  [Assistant response]            │
│         ↕ scrolled               │
│  [Older user message]            │
│                                  │
│  ┄┄┄ 3 new messages below ┄┄┄   │  ← indicator
│  ┌─────────────────────────┐     │
│  │ > Type your message...  │     │  ← input
│  └─────────────────────────┘     │
└──────────────────────────────────┘
```

Implementation via `HistoryStatusWidget` (already exists at `src/UI/Tui/Widget/HistoryStatusWidget.php`):

```php
// In VirtualMessageList:
public function hasUnseenContent(): bool
{
    return $this->hasUnseenContent;
}

// In TuiCoreRenderer::refreshHistoryStatus():
private function refreshHistoryStatus(): void
{
    if ($this->virtualList->isScrolled()) {
        $this->historyStatus->show($this->virtualList->hasUnseenContent());
    } else {
        $this->historyStatus->hide();
    }
}
```

Jump-to-bottom trigger: pressing `End` or `G` calls `VirtualMessageList::jumpToBottom()`, which resets scroll offset and clears the unseen flag.

---

## 8. File Structure

```
src/UI/Tui/VirtualScroll/
├── VirtualMessageList.php       # Main orchestrator
├── MessageDescriptor.php        # Value object per message
├── MessageRegistry.php          # Ordered message store
├── HeightCache.php              # messageId → row count cache
├── ScrollState.php              # Immutable scroll snapshot
├── OffscreenFreeze.php          # Widget wrapper for frozen rendering
└── HardwareScrollHint.php       # DECSTBM optimization (Phase 2)
```

Tests:

```
tests/UI/Tui/VirtualScroll/
├── VirtualMessageListTest.php
├── HeightCacheTest.php
├── MessageRegistryTest.php
├── OffscreenFreezeTest.php
└── ScrollStateTest.php
```

---

## 9. Changes to Existing Files

### `src/UI/Tui/TuiCoreRenderer.php`

| Line(s) | Current | New |
|---------|---------|-----|
| 47 | `private ContainerWidget $conversation;` | `private ContainerWidget\|VirtualMessageList $conversation;` |
| 108 | `private int $scrollOffset = 0;` | Removed (moved into VirtualMessageList) |
| 110 | `private bool $hasHiddenActivityBelow = false;` | Removed (moved into VirtualMessageList) |
| 190–192 | Direct `ContainerWidget` creation | Create `VirtualMessageList` when feature flag enabled |
| 693 | `$this->conversation->add($widget)` | `$this->virtualList->appendMessage($type, $widget)` |
| 725 | `$this->conversation->clear()` | `$this->virtualList->clear()` |
| 796–845 | Manual scroll offset management | Delegated to `VirtualMessageList` |
| 873–879 | Input handler scroll callbacks | Updated to call `VirtualMessageList` methods |

### `src/UI/Tui/TuiInputHandler.php`

No structural changes — the scroll closures are already injected via constructor (lines 115–116). Only the closure bodies change to call `VirtualMessageList` methods instead of direct state manipulation.

### `src/UI/Tui/Widget/HistoryStatusWidget.php`

Already supports the "new activity below" indicator. Minor update to accept `VirtualMessageList` state directly.

---

## 10. Migration Plan

### Phase 0: Feature Flag & Infrastructure (Week 1)

1. Add `VirtualMessageList`, `HeightCache`, `MessageRegistry`, `ScrollState` classes with full test coverage.
2. Add feature flag: `TUI_VIRTUAL_SCROLL=1` environment variable.
3. Add `OffscreenFreeze` wrapper class with tests.
4. No integration yet — all classes are standalone.

**Acceptance criteria**: All new classes pass tests. Existing behavior unchanged.

### Phase 1: Dual-Mode TuiCoreRenderer (Week 2)

1. Add `VirtualMessageList` as optional layer between `TuiCoreRenderer` and the `conversation` container.
2. When feature flag is OFF: existing behavior (no changes).
3. When feature flag is ON: messages go through `VirtualMessageList`.
4. Implement measurement: after each render, measure widget heights and update cache.
5. Wire up scroll controls (PgUp/PgDn/Home/End) to `VirtualMessageList`.
6. Wire `HistoryStatusWidget` to `VirtualMessageList::hasUnseenContent()`.

**Acceptance criteria**: With flag ON, long sessions show measurable render-time improvement. With flag OFF, zero behavioral change.

### Phase 2: Optimization & Hardware Scroll (Week 3)

1. Implement `HardwareScrollHint` for DECSTBM-based scroll-only updates.
2. Optimize `reconcile()` to diff the previous and current visible set (avoid clear+re-add when only one message scrolled in/out).
3. Height cache estimation refinement: use exponential moving average of actual measured heights per message type.
4. Benchmark: establish render-time budget per frame (<16ms for 60Hz, <33ms for 30Hz).

**Acceptance criteria**: Scroll-only operations trigger no full re-render. Benchmark results documented.

### Phase 3: Signal Integration (After 01-reactive-state lands)

1. Replace manual scroll state computation with `Computed` signals.
2. Bind `ScrollbarWidget` to `VirtualMessageList`'s signal outputs.
3. Bind `HistoryStatusWidget` visibility to scroll state signal via `Effect`.
4. Remove manual `refreshHistoryStatus()` / `refreshScrollbar()` calls.

**Acceptance criteria**: All scroll-related UI updates are signal-driven. No manual state plumbing remains.

### Phase 4: Feature Flag Removal (After Validation)

1. Remove the feature flag — virtual scrolling is always on.
2. Remove dead code: old `$scrollOffset`, old `$hasHiddenActivityBelow`.
3. Simplify `TuiCoreRenderer` — conversation container is always virtual.
4. Update documentation.

**Acceptance criteria**: Clean codebase, no feature flag checks, all tests pass.

---

## 11. Performance Model

### Before (current)

```
Render cost = O(total_messages)
  - Every message widget renders on every frame.
  - ScreenWriter diffs all lines.
  - Memory grows linearly.
```

### After (virtual scroll)

```
Render cost = O(visible_messages + headroom)
  - Only ~20–30 messages render per frame.
  - OffscreenFreeze prevents re-rendering of scrolled-out messages.
  - HeightCache avoids re-measurement.
  - Memory still grows (all widgets retained), but CPU cost is bounded.

Typical numbers (100-message session, 30-row viewport):
  Before: 100 widgets × ~5 rows = 500 lines per frame
  After:  24 widgets × ~5 rows = 120 lines per frame (4× improvement)

Typical numbers (1000-message session):
  Before: 1000 widgets × ~5 rows = 5000 lines per frame
  After:  24 widgets × ~5 rows = 120 lines per frame (42× improvement)
```

### Future optimization (Phase 5+): Widget pruning

For truly unbounded sessions, add a pruning layer:
- Messages beyond `2 × viewport` from the visible range have their widgets destroyed.
- A placeholder row (height from cache) is left in their place.
- On scroll-back, widgets are recreated from `MessageDescriptor::widgetParams`.
- This bounds memory as well as CPU.

---

## 12. Risk Analysis

| Risk | Mitigation |
|------|-----------|
| **Height estimation errors** cause visual jumps | Conservative estimates + immediate measurement on first render. Cache is always preferred over estimates. |
| **Width change during scroll** causes cache invalidation | All heights invalidated on width change; re-measurement happens lazily on next reconcile. Scroll position preserved as fraction, not absolute offset. |
| **Streaming message height changes** cause flicker | Streaming message is never frozen; always re-measured. Headroom absorbs small height changes. |
| **Widget identity loss** when pruning/recreating | MessageDescriptor stores factory params. Widget identity tracked by message ID, not PHP object identity. |
| **Symfony TUI integration** with viewport container | The viewport is a standard ContainerWidget — Symfony TUI renders it normally. The virtual layer only controls *which* widgets are children. |
| **OffscreenFreeze cache staleness** | Cache is invalidated on width change (via `cachedWidth` check). Content changes always unfreeze the message. |
