# UX Audit: Scrolling Experience

> **Research Question**: How good is the scrolling experience in KosmoKrator's TUI?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `TuiCoreRenderer.php:108,237-244,786-845`, `TuiInputHandler.php:85-228`, `HistoryStatusWidget.php`, `ScreenWriter.php:75-114`, `StdinBuffer.php:247-272`

---

## Executive Summary

KosmoKrator's scrolling is **functionally adequate but UX-poor**. The implementation supports page scrolling via `Page Up`/`Page Down`, a jump-to-bottom via `End`, and a minimal history status bar — but it lacks a scrollbar, line scrolling, mouse wheel support, any visual indication of scroll position, and any "new content" notification when scrolled up during streaming. The scroll step is dynamically calculated but opaque to the user. Compared to Claude Code, Lazygit, Vim, and `less`, KosmoKrator's scrolling feels like a bare-minimum implementation rather than a designed experience.

**Severity**: High. Scrolling is one of the most fundamental interactions in a long-running conversation tool. Users who can't comfortably review history will lose trust in the tool and miss important outputs.

---

## 1. Current Implementation Analysis

### 1.1 Architecture

Scrolling is implemented as a **viewport offset** mechanism in the `ScreenWriter` (`vendor/symfony/tui/.../ScreenWriter.php:80-114`). When `scrollOffset > 0`, the rendered lines are sliced from a position shifted upward from the bottom:

```
totalLines - rows - effectiveOffset
```

The offset is managed by `TuiCoreRenderer.php:108` (`private int $scrollOffset = 0`) and passed to the TUI engine via `$this->tui->setScrollOffset($this->scrollOffset)` at `TuiCoreRenderer.php:821`.

### 1.2 Keybindings

From `TuiCoreRenderer.php:241-243`:

| Key | Action | Code Location |
|-----|--------|---------------|
| `Page Up` | `scrollHistoryUp()` — increase offset | `TuiInputHandler.php:212-216` |
| `Page Down` | `scrollHistoryDown()` — decrease offset | `TuiInputHandler.php:218-222` |
| `End` | `jumpToLiveOutput()` — reset offset to 0 | `TuiInputHandler.php:224-228` |

### 1.3 Scroll Step Calculation

`TuiCoreRenderer.php:842-845`:

```php
private function historyScrollStep(): int
{
    return max(6, $this->tui->getTerminal()->getRows() - 10);
}
```

This means on a standard 24-line terminal: step = `max(6, 14)` = **14 lines** (nearly a full page). On a 50-line terminal: step = **40 lines**. The `-10` reserves space for the status bar, prompt, and borders — a reasonable heuristic, but the user has no way to know how far each press will scroll.

### 1.4 Hidden Activity Tracking

`TuiCoreRenderer.php:786-794`:

```php
private function markHiddenConversationActivity(): void
{
    if (! $this->isBrowsingHistory()) {
        return;
    }
    $this->hasHiddenActivityBelow = true;
    $this->refreshHistoryStatus();
}
```

This flag is set whenever `addConversationWidget()` is called while the user is scrolled up. It's displayed by `HistoryStatusWidget` as `"new activity below ↓"`.

### 1.5 HistoryStatusWidget

`HistoryStatusWidget.php:48-71` renders a single-line status bar:

- **Left side**: `"Browsing history"` (dim)
- **Right side**: Either `"new activity below ↓"` (accent color) OR `"PgUp/PgDn scroll  End latest"` (dim)
- Bounded by `│` border characters

This bar only appears when `scrollOffset > 0`.

---

## 2. Audit Findings

### 2.1 Scroll Position Visibility — ❌ No Scrollbar

**Current state**: There is no scrollbar, no percentage indicator, no "line X of Y" display. The only scroll position feedback is the binary `HistoryStatusWidget` which shows *"Browsing history"* when `scrollOffset > 0` — nothing when at the bottom.

**Impact**: Users cannot tell:
- Where they are in the conversation
- How much content is above or below the viewport
- Whether they're at the top, middle, or bottom of history

**Comparison**:
| Tool | Scroll Position Indicator |
|------|--------------------------|
| **Claude Code** | Thin scrollbar on right edge; "new messages" pill with count |
| **Lazygit** | Scroll position percentage in status bar |
| **Vim** | Scroll percentage in ruler (`:set ruler`) |
| **Less** | Position percentage in bottom-left (`30%`) |
| **KosmoKrator** | Binary "Browsing history" / nothing |

### 2.2 Scrolling During Streaming — ⚠️ Partial

**Current state**: When the user is scrolled up (`scrollOffset > 0`) and new content arrives via `addConversationWidget()`, the system correctly:
1. Calls `markHiddenConversationActivity()` (`TuiCoreRenderer.php:694`)
2. Sets `hasHiddenActivityBelow = true`
3. Updates `HistoryStatusWidget` to show `"new activity below ↓"`

**However**: The indicator shows only *"new activity below"* — no count, no preview, no animation. The user has no idea whether the agent typed one line or completed an entire complex task. There is no audible or visual alert beyond this single static line.

**Critical gap**: If the user is NOT scrolled up (at the bottom), the streaming experience is fine — the viewport auto-follows. But there's no way to intentionally "pin" a position. The moment you press `Page Down` or `End`, you're back at the bottom and can't hold a scroll position while watching new content arrive.

### 2.3 "New Content Below" Visibility — ⚠️ Minimal

**Current state**: The `HistoryStatusWidget.php:61-63` shows:

```
new activity below ↓
```

This is a **single-line, static, easy-to-miss** indicator in the conversation area. Problems:
- **No content count**: "new activity" could be 1 line or 500 lines
- **No type indicator**: Is it a tool call? An error? A final response?
- **No animation**: No pulsing, color change, or badge to draw attention
- **Competes with content**: It's rendered as a conversation line, easily lost among tool output
- **Positioned at the top**: The user's eyes are focused wherever they scrolled to, not at the top of the viewport

**Comparison**: Claude Code's "new messages" pill is a floating overlay with a count badge, positioned at the exact point where new content begins. It's clickable/pressable to jump to the new content. KosmoKrator's equivalent is a dim text line at the top.

### 2.4 Jump-to-Bottom Discoverability — ❌ Poor

**Current state**: `End` jumps to live output (`TuiInputHandler.php:224-228`), but only works **when already browsing history** (guarded by `$this->isBrowsingHistory()`). This means:
- `End` does nothing if you're at the bottom (acceptable)
- The keybinding is only shown inside the `HistoryStatusWidget` hint text: `"PgUp/PgDn scroll  End latest"` — which only appears when you're already scrolled up
- There is **no other discoverability mechanism** for the `End` key

**Missing alternatives**: Power users expect multiple ways to jump to bottom:
- `G` (Vim convention)
- `Shift+G` (Vim — but reversed; `G` = bottom in Vim)
- `Ctrl+End` (Windows convention)
- Click on prompt area

None of these are implemented. Only `End` works.

### 2.5 Page Scrolling vs Line Scrolling — ❌ Page Only

**Current state**: Only page-level scrolling exists. The step is `max(6, rows - 10)`, which means:
- On a 24-row terminal: 14 lines per press (58% of screen)
- On a 50-row terminal: 40 lines per press (80% of screen)

There is **no line-by-line scrolling**. Missing keybindings that users expect:
- `↑`/`↓` arrow keys (but these are consumed by the editor prompt)
- `j`/`k` (Vim convention — not applicable since there's no normal mode)
- `Ctrl+U`/`Ctrl+D` (Vim half-page)
- `Ctrl+E`/`Ctrl+Y` (Vim single-line scroll — but `Ctrl+E` conflicts with editor)

**The overlap problem**: Arrow keys and many single-key bindings are consumed by the prompt editor. This is a fundamental architecture constraint — there is no "scroll mode" vs "input mode" distinction. Everything shares the same input stream.

**Comparison**:
| Tool | Line Scroll | Page Scroll | Half-Page | Jump |
|------|-------------|-------------|-----------|------|
| **Vim** | `j`/`k`, `↑`/`↓` | `Ctrl+F`/`Ctrl+B` | `Ctrl+U`/`Ctrl+D` | `gg`/`G` |
| **Less** | `j`/`k`, `↑`/`↓` | `Space`/`b` | `d`/`u` | `g`/`G` |
| **Lazygit** | `↑`/`k`/`↓`/`j` | `PgUp`/`PgDn` | — | Home/End |
| **Claude Code** | Mouse wheel, `↑`/`↓` (when not in input) | `PgUp`/`PgDn` | — | `End`, click pill |
| **KosmoKrator** | ❌ None | `PgUp`/`PgDn` | ❌ None | `End` only |

### 2.6 Mouse Scroll Wheel Support — ❌ None

**Current state**: There is no mouse scroll wheel support. While the underlying `StdinBuffer.php:247-272` can parse both old-style (`ESC[M`) and SGR-style (`ESC[<`) mouse sequences, no code in the KosmoKrator layer enables mouse reporting or handles mouse scroll events.

The `StdinBuffer` parses mouse sequences purely as part of CSI sequence extraction — it does not interpret them. There is no mouse event dispatch system in the TUI framework layer that KosmoKrator could hook into.

**Impact**: Modern terminal users expect mouse wheel scrolling. Every major TUI tool (Lazygit, Helix, Claude Code, Midnight Commander, htop) supports it. Its absence is immediately noticeable.

### 2.7 Large Conversation Performance — ⚠️ Unknown Risk

**Current state**: The scroll mechanism works by slicing the full rendered output array (`ScreenWriter.php:106-114`):

```php
if ($this->scrollOffset > 0) {
    $totalLines = count($lines);
    if ($totalLines > $rows) {
        $maxOffset = $totalLines - $rows;
        $effectiveOffset = min($this->scrollOffset, $maxOffset);
        $startLine = $totalLines - $rows - $effectiveOffset;
        $lines = array_slice($lines, $startLine, $rows);
    }
}
```

This means:
1. **Every render cycle** produces the full conversation as an array of ANSI-formatted lines
2. The `array_slice` extracts a viewport window
3. The differential renderer compares against the previous frame

For large conversations (hundreds of messages with tool output), this could become a bottleneck:
- Memory: all rendered lines are held in memory
- CPU: full re-render on every update, even when scrolled to a static position
- No virtualization: there's no "only render visible widgets" optimization

**However**: The differential renderer (`ScreenWriter::writeLines`) only writes changed lines to the terminal, mitigating the I/O cost. The rendering cost (widget → ANSI) may still be significant for very long conversations.

**No evidence of real-world issues**: This assessment is based on code analysis. No performance testing was found.

---

## 3. Comparative Analysis

### 3.1 Claude Code — Gold Standard

Claude Code implements **virtual scrolling** with:
- A thin scrollbar on the right edge of the conversation area
- A "N new messages" floating pill that appears when scrolled up during streaming
- The pill shows the count of new messages and can be clicked to jump to bottom
- Mouse wheel scrolling with smooth acceleration
- Arrow key scrolling when the input is empty
- Auto-follow with graceful re-entry (scrolling up pauses auto-follow, scrolling to bottom resumes it)

**Key takeaway**: The "new messages" pill is the single most important UX innovation. It solves the "am I missing something?" anxiety during streaming.

### 3.2 Lazygit — Pragmatic Excellence

Lazygit provides:
- A scroll percentage indicator (`30%`) in the panel footer
- `j`/`k` and arrow key line scrolling
- `PgUp`/`PgDn` page scrolling
- Mouse wheel support
- `g`/`G` for jump-to-top/bottom (Vim convention)
- Smooth scrolling that feels responsive

**Key takeaway**: Lazygit proves you don't need virtual scrolling — just good feedback (percentage indicator) and multiple input methods (keyboard + mouse).

### 3.3 Vim — The Reference Standard

Vim's scrolling conventions are deeply ingrained in terminal users:
- **Multi-resolution**: line (`j`/`k`), half-page (`Ctrl+U`/`Ctrl+D`), full-page (`Ctrl+F`/`Ctrl+B`), jump (`gg`/`G`)
- **Relative jumps**: `5j`, `10Ctrl+D` — precise control
- **Position feedback**: percentage in status line, `:set scrolloff` for context lines
- **Scroll anchoring**: `z<CR>`, `z.`, `z-` to reposition the cursor

**Key takeaway**: The multi-resolution model (line / half-page / page / jump) is the gold standard. Users should be able to choose their scroll granularity.

### 3.4 Less Pager — Simple & Predictable

`less` proves that scrolling doesn't need to be complex:
- **Single-line scrolling**: `j`/`k`, `↑`/`↓`, `Enter` (forward one line)
- **Page scrolling**: `Space`/`b` (forward/back one screen), `PgUp`/`PgDn`
- **Position feedback**: `30%` always visible in bottom-left
- **Search-highlighted scrolling**: `n`/`N` to jump between search matches
- **Jump**: `g`/`G` for top/bottom, `<number>g` for line number

**Key takeaway**: Predictable position feedback (always-visible percentage) eliminates user disorientation.

---

## 4. Specific UX Problems

### Problem 1: No Position Awareness — Severity: High

Users have zero information about where they are in the conversation. A 1000-line conversation scrolled to the middle looks identical to a 50-line conversation at the top. This causes:
- Anxiety about missing content ("did I miss something above?")
- Inefficient navigation (hitting PgUp repeatedly with no sense of progress)
- Reluctance to scroll at all ("I'll just stay at the bottom")

### Problem 2: Binary "New Activity" Indicator — Severity: High

The current `"new activity below ↓"` tells you *something* happened but not *what* or *how much*. In practice:
- During a long agent task, the user scrolls up to review earlier context
- The agent generates 200 lines of tool output while scrolled up
- The user sees only "new activity below" — no urgency signal, no content preview
- They must jump to the bottom blind, losing their reading position

### Problem 3: No Line Scrolling — Severity: Medium

Page-only scrolling is coarse-grained. When reviewing code output or tool results, users need to scroll by 1-3 lines to keep context. The current step size (`rows - 10`) is too aggressive for this. Users end up:
- Overshooting the content they want to read
- PgUp/PgDn hunting back and forth
- Giving up and staying at the bottom

### Problem 4: No Mouse Wheel — Severity: High

In 2026, mouse wheel scrolling is expected in any terminal application. Its absence is not just a missing feature — it's an active frustration. Users will instinctively try to scroll with the mouse wheel, and nothing happens. This breaks the "it just works" expectation.

### Problem 5: Jump-to-Bottom Friction — Severity: Medium

`End` works but has discoverability issues (only shown when already scrolled up) and lacks alternatives. More importantly, there's no "jump to bottom AND show me what I missed" behavior. The transition from "browsing history" to "live output" is abrupt — the user loses all context about where they were.

### Problem 6: No Scroll-to-Top — Severity: Low

There is no way to jump to the top of the conversation. Users who want to review the beginning must press `Page Up` repeatedly. Vim's `gg` and `less`'s `g` are standard conventions that are missing.

### Problem 7: Streaming + Scrolling Conflict — Severity: Medium

When the user scrolls up during streaming, the viewport correctly stays at the scroll position. But when they press `End` to return, the viewport jumps to the current bottom — potentially far from where the agent was when they started scrolling. There's no smooth transition, no "this is where you left off" marker.

---

## 5. Recommendations

### 5.1 Add a Minimal Scrollbar (Priority: P0)

Implement a thin vertical scrollbar on the right edge of the conversation area:

```
User: Fix the bug              │
Agent: I'll analyze the code...│█
  → Reading src/Bug.php        │▓
  → Found the issue on line 42 │▒
The bug is a null check...      │ 
                                 │░
Agent: I've also noticed...    │░
```

- Use Unicode block characters: `█` (full), `▓` (dark shade), `▒` (medium shade), `░` (light shade)
- Show position: the filled portion represents the viewport's position relative to total content
- One column wide, always visible when content overflows
- Alternative: a simpler `▲`/`▼` indicator at the top/bottom of the right gutter when more content exists in that direction

### 5.2 Implement "New Content" Pill/Badge (Priority: P0)

Replace the static "new activity below" text with a floating pill:

```
┌─────────────────────────────────────────────┐
│ User: What files are in src/?               │
│ Agent: Let me check...                      │
│  → Reading directory...                     │
│  → Found 15 PHP files                       │
│                                             │
│         ┌─ 47 new lines ──────────┐         │
│         └─────────────────────────┘         │
│                                             │
│ Agent: Here are the files I found in        │
│ src/: ...                                   │
│                                             │
│ > Type your message...                      │
└─────────────────────────────────────────────┘
```

- Show **count** of new lines/messages: "47 new lines" or "3 new messages"
- **Clickable**: pressing `End` or clicking the pill jumps to the new content
- **Animated**: subtle pulse or color shift to draw attention
- **Auto-dismiss**: when user scrolls to bottom

### 5.3 Add Line Scrolling (Priority: P1)

Add `Ctrl+↑`/`Ctrl+↓` for single-line scrolling (to avoid conflict with the editor's arrow keys):

- `Ctrl+↑`: scroll up 1 line
- `Ctrl+↓`: scroll down 1 line
- Keep `PgUp`/`PgDn` for page scrolling (current behavior)
- Consider `Ctrl+U`/`Ctrl+D` for half-page (Vim convention) — but `Ctrl+U` may conflict with editor's "delete to line start"

### 5.4 Enable Mouse Wheel Scrolling (Priority: P1)

The `StdinBuffer` already parses mouse sequences. What's needed:

1. **Enable mouse reporting** on terminal startup: `echo -e "\e[?1000h"` (basic) or `"\e[?1002h"` (button tracking) or `"\e[?1006h"` (SGR mode)
2. **Disable on exit**: `"\e[?1000l"` (must be in a `finally` block to prevent terminal corruption)
3. **Parse scroll events**: SGR mouse wheel sends `ESC[<64;X;YM` (scroll up) and `ESC[<65;X;YM` (scroll down)
4. **Route to scroll handlers**: Dispatch scroll-up to `scrollHistoryUp()` with step=3, scroll-down to `scrollHistoryDown()` with step=3
5. **Only in conversation area**: Check if the mouse X coordinate is within the conversation panel's column range

### 5.5 Add Scroll Position Percentage (Priority: P1)

Show position percentage in the `HistoryStatusWidget` or status bar:

```
 │ Browsing history (45%)          PgUp/PgDn scroll  End latest │
```

This requires tracking total content height. The `ScreenWriter` already has access to `$totalLines`. Expose this to the renderer for percentage calculation.

### 5.6 Add Jump-to-Top Keybinding (Priority: P2)

Add `Home` key to jump to the very top of conversation history (mirror of `End` which jumps to bottom).

- `Home`: set `scrollOffset` to `maxOffset` (maximum possible offset)
- Show in `HistoryStatusWidget` hint: `"PgUp/PgDn scroll  Home top  End latest"`

### 5.7 Virtual Scrolling for Large Conversations (Priority: P2)

For conversations exceeding a threshold (e.g., 5000 lines), implement virtual rendering:

1. Only render widgets that are within the viewport ± buffer zone
2. Track each widget's estimated line height
3. Use placeholder "phantom" height for off-screen widgets
4. This would require significant refactoring of the rendering pipeline

This is a performance optimization that becomes a UX concern at scale. Lower priority until real-world performance issues are observed.

### 5.8 Smooth Scroll Transition (Priority: P3)

When jumping to bottom (`End`) or top (`Home`), add a brief animated transition rather than an instant teleport. This helps users maintain spatial awareness:

- Show 2-3 intermediate frames during the transition
- Duration: ~150ms total
- Alternative: just add a brief flash/highlight on the target area

---

## 6. Implementation Priority Matrix

| # | Recommendation | Impact | Effort | Priority |
|---|---------------|--------|--------|----------|
| 1 | Scrollbar | High | Medium | P0 |
| 2 | "New content" pill with count | High | Medium | P0 |
| 3 | Line scrolling (Ctrl+↑/↓) | Medium | Low | P1 |
| 4 | Mouse wheel support | High | Medium | P1 |
| 5 | Position percentage | Medium | Low | P1 |
| 6 | Jump-to-top (Home) | Low | Low | P2 |
| 7 | Virtual scrolling | Medium | High | P2 |
| 8 | Smooth scroll transition | Low | Medium | P3 |

---

## 7. Key Code References

| Concern | File | Lines |
|---------|------|-------|
| Scroll offset state | `TuiCoreRenderer.php` | `108` |
| Keybinding definitions | `TuiCoreRenderer.php` | `241-243` |
| Scroll up/down handlers | `TuiCoreRenderer.php` | `796-817` |
| Scroll step calculation | `TuiCoreRenderer.php` | `842-845` |
| Hidden activity tracking | `TuiCoreRenderer.php` | `786-794` |
| History status widget | `HistoryStatusWidget.php` | `48-71` |
| Input handler dispatch | `TuiInputHandler.php` | `212-228` |
| Viewport line slicing | `ScreenWriter.php` | `102-114` |
| Mouse sequence parsing | `StdinBuffer.php` | `247-272` |

---

## 8. Summary Verdict

KosmoKrator's scrolling is **functionally complete for basic use** (you can scroll up, scroll down, and jump to bottom) but **fails the "designed experience" bar** set by modern TUI tools. The three most critical gaps are:

1. **No position feedback** — users are disoriented the moment they scroll
2. **No mouse wheel** — breaks the "it just works" expectation
3. **Inadequate "new content" indicator** — the "new activity below" text is easy to miss and tells you nothing about what you're missing

Fixing items #1 and #3 alone would elevate the scrolling experience from "barely acceptable" to "good." Adding mouse wheel (#2) would make it "great."
