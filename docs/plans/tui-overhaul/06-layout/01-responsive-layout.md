# 06.1 — Responsive Layout

> Make KosmoKrator's TUI layout fully responsive, adapting to any terminal width from 60 columns to unlimited.

## 1. Problem Statement

The current TUI layout contains **numerous hardcoded widths** that break or look poor on terminals narrower or wider than ~120 columns.

### 1.1 Catalogue of Hardcoded Widths

| Location | Hardcoded Value | Impact |
|----------|----------------|--------|
| `TuiToolRenderer.php:168` | `$maxToolCallWidth = 120` | Tool call labels wrap/collapse at fixed 120 regardless of terminal |
| `TuiConversationRenderer.php:203` | `$maxWidth = 120` | Same issue in history replay |
| `KosmokratorStyleSheet.php:206` | `maxColumns: 100` | Markdown response widget capped at 100 cols — wastes space on wide terminals |
| `SubagentDisplayManager.php:319` | `new CollapsibleWidget(..., 120)` | Subagent batch results capped at 120 |
| `SubagentDisplayManager.php:355` | `new CollapsibleWidget(..., 120)` | Full output collapsible capped at 120 |
| `TuiToolRenderer.php:348` | `mb_substr($last, 0, 100)` | Tool executing preview truncates at 100 chars |
| `TuiToolRenderer.php:308` | `$loader->setSpinner('cosmos', 120)` | Spinner frame interval hardcoded |
| `TuiToolRenderer.php:345` | `mb_strlen($command) > 90` | Discovery bash label truncation at 90 |
| `TuiCoreRenderer.php:375` | `$cols = $this->tui->getTerminal()->getColumns()` | Only used for user message padding — good pattern, needs extension |
| `Widget/SettingsWorkspaceWidget.php:387` | `max(90, $context->getColumns())` | Minimum 90 cols — fails on narrow terminals |

### 1.2 Patterns Already Done Right

Several widgets correctly use `$context->getColumns()` for responsive sizing:
- `CollapsibleWidget::render()` — reads `$cols` from `RenderContext`
- `BashCommandWidget::render()` — adapts header width via `max(20, $cols - 3)`
- `DiscoveryBatchWidget::render()` — truncates to `$cols`
- `PlanApprovalWidget::render()` — reads `$context->getColumns()`
- `SwarmDashboardWidget::render()` — reads `$context->getColumns()`

**The problem is not architectural — it's inconsistent.** Some code paths know the terminal width and adapt; others use magic numbers. The fix is to propagate terminal width to every rendering decision.

---

## 2. Design Principles

Adapt CSS responsive design principles to the terminal grid:

1. **Content-first sizing** — widths derived from available space, not fixed numbers
2. **Progressive enhancement** — base layout works at 60 cols; extra space adds detail
3. **Graceful degradation** — narrow terminals show compact views, never break
4. **Single source of truth** — terminal dimensions read once per render, propagated to widgets
5. **Breakpoint-driven adaptation** — layout and widget behavior shift at defined column thresholds

---

## 3. Breakpoint System

### 3.1 Breakpoint Definitions

| Name | Columns | Character |
|------|---------|-----------|
| **Tiny** | < 60 | ⚠ Minimum supported — show warning |
| **Narrow** | 60–79 | Compact single-column, abbreviated labels |
| **Medium** | 80–119 | Current default layout |
| **Wide** | 120–159 | Expanded views, more detail |
| **Ultra-wide** | ≥ 160 | Side panels, multi-column layouts |

### 3.2 TerminalDimension Helper

Introduce a value object that encapsulates breakpoint logic:

```php
// src/UI/Tui/Layout/TerminalDimension.php
namespace Kosmokrator\UI\Tui\Layout;

enum Breakpoint: string
{
    case Tiny = 'tiny';       // < 60 cols
    case Narrow = 'narrow';   // 60-79
    case Medium = 'medium';   // 80-119
    case Wide = 'wide';       // 120-159
    case UltraWide = 'ultra'; // ≥ 160
}

final readonly class TerminalDimension
{
    public function __construct(
        public int $columns,
        public int $rows,
    ) {}

    public function breakpoint(): Breakpoint
    {
        return match (true) {
            $this->columns < 60 => Breakpoint::Tiny,
            $this->columns < 80 => Breakpoint::Narrow,
            $this->columns < 120 => Breakpoint::Medium,
            $this->columns < 160 => Breakpoint::Wide,
            default => Breakpoint::UltraWide,
        };
    }

    public function isTiny(): bool    { return $this->columns < 60; }
    public function isNarrow(): bool  { return $this->columns >= 60 && $this->columns < 80; }
    public function isMedium(): bool  { return $this->columns >= 80 && $this->columns < 120; }
    public function isWide(): bool    { return $this->columns >= 120 && $this->columns < 160; }
    public function isUltraWide(): bool { return $this->columns >= 160; }

    /** Content width after subtracting padding (2 per side) */
    public function contentWidth(): int
    {
        return max(40, $this->columns - 4);
    }

    /** Max width for tool call labels */
    public function toolCallWidth(): int
    {
        return min($this->contentWidth(), match ($this->breakpoint()) {
            Breakpoint::Tiny, Breakpoint::Narrow => $this->contentWidth(),
            Breakpoint::Medium => 120,
            Breakpoint::Wide => 140,
            Breakpoint::UltraWide => 160,
        });
    }

    /** Max columns for markdown rendering */
    public function markdownColumns(): int
    {
        return min($this->contentWidth(), match ($this->breakpoint()) {
            Breakpoint::Tiny, Breakpoint::Narrow => $this->contentWidth(),
            Breakpoint::Medium => 100,
            Breakpoint::Wide => 120,
            Breakpoint::UltraWide => 140,
        });
    }

    /** Preview truncation length for tool executing indicator */
    public function previewLength(): int
    {
        return match ($this->breakpoint()) {
            Breakpoint::Tiny => 40,
            Breakpoint::Narrow => 60,
            Breakpoint::Medium => 100,
            Breakpoint::Wide => 120,
            Breakpoint::UltraWide => 140,
        };
    }

    /** Minimum terminal size check */
    public function isSupported(): bool
    {
        return $this->columns >= 60 && $this->rows >= 20;
    }

    /** Warning message for tiny terminals */
    public function sizeWarning(): ?string
    {
        if ($this->columns < 60) {
            return "Terminal too narrow ({$this->columns} cols). Minimum: 60 columns.";
        }
        if ($this->rows < 20) {
            return "Terminal too short ({$this->rows} rows). Minimum: 20 rows.";
        }
        return null;
    }
}
```

### 3.3 Integration Point

Add terminal dimension resolution to the render pipeline:

```php
// In TuiCoreRenderer — make dimension available to all sub-renderers
public function getDimension(): TerminalDimension
{
    return new TerminalDimension(
        $this->tui->getTerminal()->getColumns(),
        $this->tui->getTerminal()->getRows(),
    );
}
```

All sub-renderers (`TuiToolRenderer`, `TuiConversationRenderer`, `SubagentDisplayManager`) already receive `TuiCoreRenderer` — they can call `$this->core->getDimension()` instead of hardcoding widths.

For widgets, `RenderContext::getColumns()` and `RenderContext::getRows()` already provide this. The `TerminalDimension` helper adds breakpoint semantics on top.

---

## 4. Widget-Level Adaptation Rules

### 4.1 CollapsibleWidget

| Breakpoint | Behavior |
|-----------|----------|
| Tiny/Narrow | Preview width = `contentWidth() - 4`, no side borders |
| Medium | Preview width = 120, current behavior |
| Wide | Preview width = 140 |
| Ultra | Preview width = 160 |

**Change**: Replace `$previewWidth` constructor parameter with a callable `?(RenderContext $ctx): int` or pass `TerminalDimension` to `render()`.

### 4.2 MarkdownWidget (response area)

| Breakpoint | `maxColumns` |
|-----------|-------------|
| Tiny/Narrow | `contentWidth()` (full width) |
| Medium | 100 (current) |
| Wide | 120 |
| Ultra | 140 |

**Change**: Replace static `maxColumns: 100` in `KosmokratorStyleSheet` with a dynamic style. Two approaches:

- **Option A (preferred)**: Remove `maxColumns` from stylesheet. Set it at widget creation time:
  ```php
  $widget = new MarkdownWidget($text);
  $widget->setMaxColumns($this->core->getDimension()->markdownColumns());
  ```
- **Option B**: Use Symfony TUI's `StyleSheet::addBreakpoint()` to override `maxColumns` per breakpoint:
  ```php
  $sheet->addBreakpoint(80, MarkdownWidget::class, new Style(maxColumns: 100));
  $sheet->addBreakpoint(120, MarkdownWidget::class, new Style(maxColumns: 120));
  $sheet->addBreakpoint(160, MarkdownWidget::class, new Style(maxColumns: 140));
  ```

### 4.3 BashCommandWidget

Already responsive via `$context->getColumns()`. No changes needed.

### 4.4 DiscoveryBatchWidget

Already responsive via `$context->getColumns()`. No changes needed.

### 4.5 Tool Call Display (TuiToolRenderer)

**Current**: `$maxToolCallWidth = 120` (line 168)

**Change**: 
```php
$dimension = $this->core->getDimension();
$maxToolCallWidth = $dimension->toolCallWidth();
```

### 4.6 Tool Executing Preview (TuiToolRenderer)

**Current**: `mb_strlen($last) > 100 ? mb_substr($last, 0, 100).'…'` (line 348)

**Change**:
```php
$previewLen = $this->core->getDimension()->previewLength();
$this->toolExecutingPreview = mb_strlen($last) > $previewLen
    ? mb_substr($last, 0, $previewLen).'…'
    : $last;
```

### 4.7 Discovery Bash Label (TuiToolRenderer)

**Current**: `mb_strlen($command) > 90` (line 345)

**Change**: Use `$dimension->toolCallWidth() - 30` (reserve space for prefix/decoration).

### 4.8 Subagent Display (SubagentDisplayManager)

**Current**: `new CollapsibleWidget(..., 120)` (lines 319, 355)

**Change**: 
```php
$width = $this->core->getDimension()->toolCallWidth();
$widget = new CollapsibleWidget($header, $content, $lineCount, $width);
```

### 4.9 History Replay (TuiConversationRenderer)

**Current**: `$maxWidth = 120` (line 203)

**Change**: Same as tool call width — `$this->core->getDimension()->toolCallWidth()`.

### 4.10 Status Bar (TuiCoreRenderer)

**Current**: `$this->statusBar->setBarWidth(20)` — the progress bar segment width is hardcoded.

**Change**: Scale to terminal:
```php
$barWidth = match ($this->getDimension()->breakpoint()) {
    Breakpoint::Tiny, Breakpoint::Narrow => 10,
    Breakpoint::Medium => 20,
    Breakpoint::Wide => 30,
    Breakpoint::UltraWide => 40,
};
$this->statusBar->setBarWidth($barWidth);
```

### 4.11 Settings Workspace (SettingsWorkspaceWidget)

**Current**: `max(90, $context->getColumns())` — enforces 90 minimum, breaks at 60-89 cols.

**Change**: `max(60, $context->getColumns())` — lower minimum, adapt layout below 90:
- Below 90 cols: hide description text, use compact single-column layout
- Below 70 cols: hide hints too, show only label + value

### 4.12 User Message Padding (TuiCoreRenderer)

**Current**: Already uses `$this->tui->getTerminal()->getColumns()` — good. No changes needed.

---

## 5. Layout-Level Adaptation

### 5.1 Session Container Structure

Current layout is purely vertical:
```
┌─ session ─────────────────────────┐
│ conversation (scrollable)         │
│ history-status (conditional)      │
│ overlay (modals, floating)        │
│ task-bar                          │
│ thinking-bar                      │
│ input (editor)                    │
│ status-bar                        │
└───────────────────────────────────┘
```

### 5.2 Wide Terminal Layout (≥ 160 cols)

For ultra-wide terminals, introduce an optional side panel:

```
┌─ session ─────────────────────────────────────────┐
│ ┌─ main ───────────────┐ ┌─ sidebar ────────────┐ │
│ │ conversation         │ │ task-tree            │ │
│ │ (scrollable)         │ │ agent-progress       │ │
│ │                      │ │ context-usage        │ │
│ ├──────────────────────┤ └──────────────────────┘ │
│ │ input + status       │                          │
│ └──────────────────────┘                          │
└───────────────────────────────────────────────────┘
```

Implementation strategy:
1. **Phase 1**: Single-column responsive (this plan) — fix all hardcoded widths
2. **Phase 2**: Wide layout with optional sidebar — separate plan (`02-wide-layout.md`)

### 5.3 Narrow Terminal Adaptation (< 80 cols)

At narrow widths, apply these transformations:

| Element | Normal | Narrow |
|---------|--------|--------|
| Padding | 2 left/right | 1 left/right |
| Tool call format | `⟡ file_read  path/to/file:10` | `⟡ read path…:10` |
| Status bar | `Edit · Guardian · 12k/200k · model` | `Edit · 12k/200k` |
| Collapsible preview | 3 lines | 1 line |
| Discovery batch | Full summary | Count only |
| Task tree | Full tree | Flat list |
| Mode labels | Full names | Abbreviations: `E`/`P`/`A` |
| Permission labels | `Guardian ◈` | `G` |
| Welcome tutorial | Full reference | Compact reference |

---

## 6. Stylesheet Breakpoint Integration

### 6.1 Symfony TUI StyleSheet Breakpoints

Symfony TUI supports `StyleSheet::addBreakpoint(width, selector, style)`:

```php
// In KosmokratorStyleSheet::create()
$sheet = new StyleSheet([/* base styles */]);

// Override padding for narrow terminals
$sheet->addBreakpoint(80, '.session', new Style(
    padding: new Padding(0, 1, 0, 1),
));

// Expand markdown width for wide terminals  
$sheet->addBreakpoint(120, MarkdownWidget::class, new Style(
    maxColumns: 120,
));

$sheet->addBreakpoint(160, MarkdownWidget::class, new Style(
    maxColumns: 140,
));

// Compact tool calls on narrow
$sheet->addBreakpoint(80, '.tool-call', new Style(
    padding: new Padding(0, 1, 0, 1),
));

// Compact response padding
$sheet->addBreakpoint(80, '.response', new Style(
    padding: new Padding(0, 1, 0, 1),
));

return $sheet;
```

### 6.2 Dynamic Style Updates

When the terminal is resized (SIGWINCH), Symfony TUI already re-renders. We need to ensure:
1. Breakpoint styles are re-evaluated (handled by Symfony TUI's stylesheet)
2. Widget content that was generated with hardcoded widths is re-rendered

For point 2, introduce a resize listener that invalidates cached widths:

```php
// In TuiCoreRenderer::initialize()
$this->tui->onResize(function (int $cols, int $rows) {
    // Invalidate cached dimension
    $this->cachedDimension = null;
    // Re-render status bar with new bar width
    $this->refreshStatusBar();
});
```

---

## 7. Implementation Phases

### Phase 1: TerminalDimension + Hardcoded Width Elimination

**Files to modify:**
1. **New**: `src/UI/Tui/Layout/TerminalDimension.php` — value object with breakpoint logic
2. **Modify**: `TuiCoreRenderer.php` — add `getDimension()`, resize handler
3. **Modify**: `TuiToolRenderer.php` — replace 4 hardcoded widths
4. **Modify**: `TuiConversationRenderer.php` — replace `$maxWidth = 120`
5. **Modify**: `SubagentDisplayManager.php` — replace 2 `CollapsibleWidget(..., 120)`
6. **Modify**: `KosmokratorStyleSheet.php` — add breakpoints, remove static `maxColumns: 100`

**Estimated effort**: ~2 hours
**Risk**: Low — purely replacing constants with dynamic values

### Phase 2: Narrow Terminal Adaptation

**Files to modify:**
1. **Modify**: `CollapsibleWidget.php` — reduce preview lines at narrow breakpoint
2. **Modify**: `DiscoveryBatchWidget.php` — compact summary at narrow breakpoint
3. **Modify**: `BashCommandWidget.php` — shorter headers at narrow breakpoint
4. **Modify**: `SettingsWorkspaceWidget.php` — lower minimum, compact layout below 90
5. **Modify**: `TuiCoreRenderer.php` — compact status bar at narrow breakpoint
6. **New**: Tiny terminal warning banner widget

**Estimated effort**: ~3 hours
**Risk**: Medium — visual testing needed for each breakpoint

### Phase 3: Wide Terminal Enhancement

**Files to modify:**
1. **Modify**: All widgets — expanded detail views at wide breakpoint
2. **Modify**: `KosmokratorStyleSheet.php` — wider maxColumns at 120/160 breakpoints
3. **Optional**: Sidebar layout for ultra-wide (separate plan)

**Estimated effort**: ~2 hours (without sidebar), ~8 hours (with sidebar)
**Risk**: Low (without sidebar), Medium (with sidebar)

---

## 8. Minimum Size Warning

When the terminal is smaller than 60×20, show a persistent warning:

```
⚠ Terminal too small (54×18). Minimum: 60×20. Some UI may be clipped.
```

Implementation:
```php
// In TuiCoreRenderer::initialize(), after tui->start():
$dimension = $this->getDimension();
if (! $dimension->isSupported()) {
    $warning = $dimension->sizeWarning();
    $widget = new TextWidget(Theme::warning() . "⚠ {$warning}" . Theme::reset());
    $widget->addStyleClass('tool-error');
    $this->addConversationWidget($widget);
}
```

Also hook into resize events to show/remove the warning dynamically.

---

## 9. Testing Strategy

### 9.1 Snapshot Tests at Each Breakpoint

Create render snapshots at key widths:
- 54 cols (tiny — warning expected)
- 60 cols (narrow minimum)
- 79 cols (narrow maximum)
- 80 cols (medium minimum)
- 119 cols (medium maximum)
- 120 cols (wide minimum)
- 160 cols (ultra minimum)
- 200 cols (ultra)

### 9.2 Widget Unit Tests

Each widget gets test cases at multiple breakpoints:
```
testRendersAtNarrowWidth()   // 70 cols
testRendersAtMediumWidth()   // 100 cols
testRendersAtWideWidth()     // 140 cols
```

### 9.3 Resize Simulation

Test that a resize event mid-session:
1. Re-evaluates breakpoint styles
2. Re-renders tool call labels to new width
3. Doesn't crash on extreme sizes (1×1, 999×999)

---

## 10. Summary of All Changes

### New Files
| File | Purpose |
|------|---------|
| `src/UI/Tui/Layout/TerminalDimension.php` | Breakpoint value object |

### Modified Files
| File | Change |
|------|--------|
| `TuiCoreRenderer.php` | Add `getDimension()`, resize handler, dynamic bar width |
| `TuiToolRenderer.php:168` | `$maxToolCallWidth = 120` → `$dimension->toolCallWidth()` |
| `TuiToolRenderer.php:308` | Spinner interval (cosmetic, leave as-is) |
| `TuiToolRenderer.php:345` | `mb_strlen > 90` → dynamic based on dimension |
| `TuiToolRenderer.php:348` | `mb_substr($last, 0, 100)` → `$dimension->previewLength()` |
| `TuiConversationRenderer.php:203` | `$maxWidth = 120` → `$dimension->toolCallWidth()` |
| `SubagentDisplayManager.php:319` | `new CollapsibleWidget(..., 120)` → dynamic width |
| `SubagentDisplayManager.php:355` | `new CollapsibleWidget(..., 120)` → dynamic width |
| `KosmokratorStyleSheet.php:206` | `maxColumns: 100` → dynamic via breakpoints |
| `SettingsWorkspaceWidget.php:387` | `max(90, ...)` → `max(60, ...)` + compact layout |
| `CollapsibleWidget.php` | Dynamic preview width from `RenderContext` |
| `TuiCoreRenderer.php` | Compact status bar labels at narrow breakpoint |

### Unchanged Files (Already Responsive)
| File | Notes |
|------|-------|
| `BashCommandWidget.php` | Uses `$context->getColumns()` throughout |
| `DiscoveryBatchWidget.php` | Uses `$context->getColumns()` throughout |
| `PlanApprovalWidget.php` | Uses `$context->getColumns()` |
| `SwarmDashboardWidget.php` | Uses `$context->getColumns()` |
| `HistoryStatusWidget.php` | Uses `$context->getColumns()` |
| `PermissionPromptWidget.php` | Uses `$context->getColumns()` |
| `QuestionWidget.php` | Uses `$context->getColumns()` |
| `AnsweredQuestionsWidget.php` | Uses `$context->getColumns()` |
| `AnsiArtWidget.php` | Uses `$context->getColumns()` |
| `BorderFooterWidget.php` | Uses `$context->getColumns()` |
