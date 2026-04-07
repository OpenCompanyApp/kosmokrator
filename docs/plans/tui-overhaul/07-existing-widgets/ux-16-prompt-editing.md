# UX Audit: Multi-Line Prompt Editing

> **Research Question**: How good is the multi-line prompt editing experience?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `EditorWidget.php`, `EditorDocument.php`, `EditorRenderer.php`, `EditorViewport.php`, `BracketedPasteTrait.php`, `KillRing.php`, `Keybindings.php`, `TuiCoreRenderer.php` (lines 191–252), `TuiInputHandler.php`

---

## Executive Summary

The multi-line prompt editing experience is **architecturally capable but UX-impaired**. The `EditorWidget` provides a genuine multi-line text editor with undo/redo (100-deep stack), an Emacs-style kill ring, word-level navigation, character-jump mode, bracketed paste handling, and viewport scrolling with word-wrap. This is a rich foundation — roughly equivalent to what prompt_toolkit gives Aider.

The problem is that **KosmoKrator constrains this engine to a 1–2 line visual box** (`setMinVisibleLines(1)`, `setMaxVisibleLines(2)`) with no auto-expansion, no input history recall, no auto-completion beyond slash/power/skill commands, and no visual indicators that multi-line editing is possible. The user sees a single-line prompt with no affordances for multi-line content.

Compared to Claude Code (which shows line counts and grows dynamically), Aider (which provides full prompt_toolkit with auto-suggest, history search, and vim mode), and even basic shells like Fish (which autosuggests from history), KosmoKrator's prompt is a Ferrari engine in a go-kart frame.

**Severity**: High. The prompt is the primary interaction surface. A poor editing experience degrades every single user interaction.

---

## 1. Multi-Line Editing: Discoverability

### 1.1 Current State

Multi-line insertion is bound to `Shift+Enter` and `Alt+Enter` (`EditorWidget::getDefaultKeybindings()` line ~223):

```php
'new_line' => ['shift+enter', 'alt+enter'],
'submit' => [Key::ENTER],
```

The keybinding override in `TuiCoreRenderer::initialize()` (line ~234) preserves these defaults — only `copy` is cleared (to prevent the default `Ctrl+C` from eating the cancel action).

### 1.2 Problems

| Problem | Impact | Evidence |
|---------|--------|----------|
| **No visual hint that multi-line is possible** | Users assume single-line only | Welcome screen (`renderIntro`) shows slash commands but no input keybindings. No placeholder text. No line-count badge. |
| **Enter = Submit, not newline** | Correct for chat UX, but conflicts with muscle memory from editors | Users coming from VS Code or terminal editors expect Enter to insert a newline. |
| **2-line visual cap** | Multi-line content scrolls out of view immediately | `setMaxVisibleLines(2)` means a 5-line message shows only 2 lines. No scroll indicators for the input itself (the viewport scroll indicators `─── ↑ N more` are in the renderer, but they only appear when `maxDisplayRows` exceeds 2). |
| **Alt+Enter is terminal-dependent** | Some terminals (Windows Terminal, older xterm) don't emit `alt+enter` cleanly | Only `Shift+Enter` is reliable across terminals. |

### 1.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish |
|---------|-------------|-------------|-------|------|
| Multi-line trigger | Shift+Enter (undocumented) | Enter (in multi-line mode), or paste | Enter (always) | N/A (single-line) |
| Multi-line affordance | None | Shows "3 lines" count badge | Full editor chrome | N/A |
| Visual expansion | Capped at 2 lines | Auto-grows to ~40% of terminal | Full terminal height | N/A |
| Code-aware editing | No | Detects code fences, preserves indentation | Syntax highlighting | N/A |

### 1.4 Verdict

**Discoverability: 1/5.** A new user has zero visual or textual cues that multi-line input is possible. The prompt looks and behaves like a single-line text field.

---

## 2. History Navigation

### 2.1 Current State

There is **no input/prompt history recall mechanism**. The keybindings override in `TuiCoreRenderer` (lines 241–243):

```php
'history_up' => [Key::PAGE_UP],
'history_down' => [Key::PAGE_DOWN],
'history_end' => [Key::END],
```

These are bound to **conversation scroll**, not prompt history. `TuiInputHandler::handleInput()` (lines 212–225) routes `history_up`/`history_down` to `scrollHistoryUp()`/`scrollHistoryDown()`, which scroll the conversation viewport.

The `EditorWidget`'s default keybindings have `cursor_up` = `[Key::UP]` and `cursor_down` = `[Key::DOWN]`, which move the cursor vertically through multi-line content. Up/Down arrows are **not** intercepted for history recall.

### 2.2 Problems

| Problem | Impact |
|---------|--------|
| **No prompt history** | Users cannot recall previous prompts with Up/Down arrows. Must retype everything. |
| **No search-through-history** | No `Ctrl+R` reverse search. |
| **No persistent history across sessions** | Even if implemented, there is no history store. |
| **Up/Down conflict** | If history recall is added, it will conflict with multi-line cursor movement. Need context-sensitive behavior (Up on first line = history, Up on line 2+ = cursor). |

### 2.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish | Zsh |
|---------|-------------|-------------|-------|------|-----|
| Prompt history recall | ❌ None | Up/Down arrows | Up/Down arrows | Up/Down arrows | Up/Down arrows |
| Reverse search | ❌ None | No (uses Up/Down) | `Ctrl+R` via prompt_toolkit | No (has autosuggest) | `Ctrl+R` |
| Persistent history | ❌ None | Session-based | `.aider.history` | `~/.local/share/fish/fish_history` | `~/.zsh_history` |
| History deduplication | N/A | Yes | Yes | Yes | `HIST_IGNORE_DUPS` |

### 2.4 Verdict

**History: 0/5.** Complete absence. This is the single biggest gap vs. every competitor. Every other tool in the comparison table has prompt history.

---

## 3. Text Editing Capabilities

### 3.1 Current State

The `EditorDocument` provides a comprehensive set of editing operations:

| Operation | Keybinding | Status |
|-----------|-----------|--------|
| Cursor left/right | ←/→, Ctrl+B/Ctrl+F | ✅ Working |
| Cursor up/down (multi-line) | ↑/↓ | ✅ Working |
| Word left/right | Alt+←/→, Ctrl+←/→, Alt+B/F | ✅ Working |
| Line start/end | Home/End, Ctrl+A/Ctrl+E | ✅ Working |
| Character jump forward | Ctrl+] then char | ✅ Working (vim `f`-style) |
| Character jump backward | Ctrl+Alt+] then char | ✅ Working (vim `F`-style) |
| Page up/down | PageUp/PageDown | ⚠️ Routed to conversation scroll |
| Delete char backward | Backspace, Shift+Backspace | ✅ Working |
| Delete char forward | Delete, Ctrl+D, Shift+Delete | ✅ Working |
| Delete word backward | Ctrl+W, Alt+Backspace | ✅ Working |
| Delete word forward | Alt+D, Alt+Delete | ✅ Working |
| Delete entire line | Ctrl+Shift+K | ✅ Working |
| Delete to line start | Ctrl+U | ✅ Working |
| Delete to line end | Ctrl+K | ✅ Working (adds to kill ring) |
| Yank (paste from kill ring) | Ctrl+Y | ✅ Working |
| Yank-pop (cycle kill ring) | Alt+Y | ✅ Working |
| Undo | Ctrl+- | ✅ Working (100-deep) |
| Redo | Ctrl+Shift+Z | ✅ Working |

### 3.2 Problems

| Problem | Impact |
|---------|--------|
| **PageUp/PageDown hijacked** | Cannot page-scroll through multi-line input. Keys scroll the conversation instead. |
| **No selection/region** | No Shift+Arrow selection, no kill-region. All kills are line-relative. |
| **No transpose** | No Ctrl+T (transpose characters). Minor but standard Emacs binding. |
| **No case change** | No Alt+U/L/C (uppercase/lowercase/capitalize word). |
| **Ctrl+U ambiguity** | `Ctrl+U` deletes to line start in the editor, but in many terminals it's the universal "clear line" binding. Works correctly here, but users may expect it to clear the entire input. |

### 3.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish |
|---------|-------------|-------------|-------|------|
| Emacs keybindings | ✅ Core set | ✅ Core set | ✅ Full | ✅ Core set |
| Vim mode | ❌ None | ❌ None | ✅ Full vim mode | ❌ None |
| Text selection | ❌ None | ✅ Shift+Arrows | ✅ Visual mode | ❌ None |
| Undo/Redo | ✅ 100-deep | ✅ Session-level | ✅ Full | ❌ None |
| Kill ring | ✅ Full (50-entry) | ❌ None | ❌ None | ❌ None |

### 3.4 Verdict

**Text editing: 4/5.** The editing capabilities are genuinely excellent. The Emacs keybinding set is comprehensive, the kill ring is a power-user feature that no competitor has, and undo/redo works well. The main gaps are selection (preventing cut/copy of arbitrary regions) and the PageUp/PageDown hijack.

---

## 4. Paste Handling

### 4.1 Current State

Bracketed paste is fully implemented via `BracketedPasteTrait`:

1. **Detection**: Looks for `\x1b[200~` (paste start) and `\x1b[201~` (paste end) sequences
2. **Buffering**: Accumulates chunks until the end marker is received
3. **Processing**: Routes to `EditorDocument::handlePaste()` which:
   - Sanitizes UTF-8
   - Normalizes line endings (`\r\n` → `\n`)
   - **Large pastes (>10 lines)**: Creates a marker `[paste #N +M lines <id>]` for efficient display, with the full content stored for retrieval via `getText()`
   - **Small pastes (≤10 lines)**: Inserts directly into the buffer

### 4.2 Problems

| Problem | Impact |
|---------|--------|
| **Large paste markers invisible in prompt** | If you paste 15 lines, the editor shows `[paste #1 +15 lines <hex>]` but the prompt is only 2 lines tall. The marker may be longer than the visible area. |
| **No paste preview** | Unlike Claude Code which shows a diff/preview of pasted content, the user sees only a marker or the raw text scrolling through the 2-line window. |
| **No paste confirmation** | Large pastes are inserted immediately. No "You pasted 200 lines — confirm?" prompt. |
| **Terminal compatibility** | Not all terminals support bracketed paste. iTerm2, Terminal.app, and Windows Terminal do, but some older terminals don't. Fallback behavior is that each character arrives as individual keystrokes, which still works but loses the "large paste" marker optimization. |

### 4.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish |
|---------|-------------|-------------|-------|------|
| Bracketed paste | ✅ Full | ✅ Full | ✅ Full | ✅ Full |
| Large paste optimization | ✅ Marker system | ✅ Collapsed preview | ✅ Direct insert | N/A |
| Paste confirmation | ❌ None | ✅ For file paths | ❌ None | ❌ None |
| Paste preview | ❌ None | ✅ Shows content | ✅ Full edit | N/A |

### 4.4 Verdict

**Paste handling: 4/5.** The bracketed paste implementation is solid, and the large-paste marker system is clever. The main gap is that the 2-line visual cap makes it impossible to see what was pasted.

---

## 5. Auto-Completion

### 5.1 Current State

Auto-completion is handled by `TuiInputHandler::handleChange()` which detects prefixes and shows a `SelectListWidget` overlay:

| Prefix | Source | Items |
|--------|--------|-------|
| `/` | `SLASH_COMMANDS` constant | 20 commands |
| `:` | `POWER_COMMANDS` constant | 19 commands |
| `$` | `DOLLAR_COMMANDS` + `skillCompletions` | 5 + dynamic skills |

Completion behavior:
- Triggers on every change event (character typed)
- Filters by prefix match
- Shows in an overlay `SelectListWidget` with description text
- Navigate with ↑/↓, select with Enter, fill with Tab, dismiss with Esc
- For `:` commands, handles combined commands (e.g., `:trace:review` completes only the last segment)

### 5.2 Problems

| Problem | Impact |
|---------|--------|
| **No file path completion** | Users cannot Tab-complete file paths in prompts like "edit src/UI/Tui/TuiCoreR..." |
| **No context-aware completion** | No completion for tool names, function names, class names, or git branches |
| **No inline auto-suggestion** | Unlike Fish shell's gray autosuggestions, the completion requires explicit navigation to a dropdown |
| **No fuzzy matching** | Only prefix matching. `/ed` matches `/edit` but `/eit` does not. |
| **Completion dismisses on any non-prefix text** | As soon as you type anything that doesn't start with `/`, `:`, or `$`, the completion disappears. No way to re-trigger. |
| **Tab only fills, doesn't cycle** | In shells, Tab cycles through options. Here, Tab fills the selected item and closes. |

### 5.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish | Zsh |
|---------|-------------|-------------|-------|------|-----|
| Slash command completion | ✅ Dropdown | ✅ Inline | ✅ Inline | N/A | N/A |
| File path completion | ❌ None | ✅ Tab | ✅ Tab | ✅ Tab | ✅ Tab |
| Auto-suggestion | ❌ None | ✅ Gray inline | ✅ Gray inline | ✅ Gray inline | ❌ None |
| Fuzzy matching | ❌ None | ✅ Fuzzy | ✅ Fuzzy | ❌ Prefix | ✅ Configurable |
| Context-aware | ❌ None | ✅ Tool/file aware | ✅ Git/file aware | ✅ Command aware | ✅ Full |

### 5.4 Verdict

**Auto-completion: 2/5.** The slash/power/skill completion dropdown is well-implemented and works correctly. But the absence of file path completion, auto-suggestion, and fuzzy matching makes the experience feel limited compared to competitors.

---

## 6. Visual Feedback

### 6.1 Current State

The prompt renders via `EditorRenderer::render()` which produces:

1. **Top border**: `───` line (with `↑ N more` if content is above viewport)
2. **Content lines**: Up to `maxVisibleLines` (2) with cursor
3. **Bottom border**: `───` line (with `↓ N more` if content is below viewport)

The cursor is rendered using the `cursor` style from `KosmokratorStyleSheet`, which produces a block cursor when focused.

### 6.2 Problems

| Problem | Impact |
|---------|--------|
| **No line count indicator** | Unlike Claude Code's "3 lines" badge, there is no indication of how many lines the input contains |
| **No character count or token estimate** | No feedback on input length until submission |
| **Scroll indicators invisible at 2-line cap** | The `─── ↑ N more` indicator only appears when the viewport has more content than it can show. With `maxVisibleLines(2)`, a user typing 3+ lines will see the top indicator, but it's easy to miss in a busy terminal. |
| **No placeholder text** | When empty, the prompt shows nothing — no "Type a message..." or "Enter prompt..." |
| **No visual distinction between single-line and multi-line** | The prompt looks identical whether it has 1 line or 10 lines of content |
| **No syntax highlighting** | Markdown syntax in the prompt is not highlighted (no bold, no code fence detection) |

### 6.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish |
|---------|-------------|-------------|-------|------|
| Line count badge | ❌ None | ✅ "N lines" | ✅ Full editor | N/A |
| Placeholder text | ❌ None | ✅ Context-aware | ✅ Mode-aware | ✅ Right-prompt |
| Scroll indicators | ✅ Basic | ✅ Rich | ✅ Full | N/A |
| Syntax awareness | ❌ None | ✅ Markdown | ✅ Per-language | N/A |
| Token estimate | ❌ None | ✅ Live counter | ❌ None | N/A |

### 6.4 Verdict

**Visual feedback: 2/5.** The border-based scroll indicators are a nice touch but are barely visible with the 2-line cap. The absence of placeholder text, line counts, or any visual affordance makes the prompt feel like a raw input field.

---

## 7. Max Line Limit and Scroll

### 7.1 Current State

There is **no hard limit on the number of lines** the editor can contain. The `maxVisibleLines` setting (2) controls only the *display height*, not the content limit. The `EditorViewport` handles scrolling:

- `scroll_offset`: Tracks which line is at the top of the visible area
- `computeViewport()`: Adjusts scroll to keep cursor visible, accounting for word-wrap
- Scroll indicators: `─── ↑ N more` / `─── ↓ N more` in top/bottom borders

The `EditorWidget` also implements `VerticallyExpandableInterface`, but this is **not enabled** — `expandVertically()` is never called on the input.

### 7.2 Problems

| Problem | Impact |
|---------|--------|
| **No content limit warning** | Users can paste 10,000 lines with no warning. The LLM will likely truncate or reject it, but the user doesn't know. |
| **No visual indication of overflow** | With 2 visible lines and a 50-line message, the user sees only 2 lines. The `─── ↑ N more` indicator is present but minimal. |
| **Scroll context lost on submit** | After submitting and getting a response, the input is cleared (`setText('')`). Any multi-line draft is gone. |
| **No "draft" preservation** | Unlike some chat UIs that preserve the current draft when navigating away, KosmoKrator clears on submit. |

### 7.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish |
|---------|-------------|-------------|-------|------|
| Content limit | None | Soft (token limit shown) | None | None |
| Overflow feedback | Minimal (border text) | Rich (line count + scroll bar) | Full editor | N/A |
| Draft preservation | ❌ Cleared on submit | ✅ Preserved in history | ✅ Full editor | N/A |

### 7.4 Verdict

**Max line handling: 2/5.** The absence of a content limit is fine (the LLM will reject oversized input), but the lack of any visual feedback about input size is a problem. Users typing long prompts are flying blind.

---

## 8. Recommendations

### Priority 1 — Input History (Impact: Critical)

**Add Up/Down arrow prompt history recall.**

This is the single highest-impact improvement. Every competitor has it.

Implementation approach:
1. Create a `PromptHistory` class that stores the last N prompts (persisted to `~/.kosmokrator/history`)
2. In `TuiInputHandler`, intercept Up arrow when cursor is on line 0 and input is unmodified → recall previous prompt
3. Down arrow on last line → recall next prompt
4. Add `Ctrl+R` for reverse search through history
5. Deduplicate consecutive identical entries

Estimated effort: 2–3 days.

### Priority 2 — Dynamic Input Expansion (Impact: High)

**Grow the prompt to show content, up to ~30% of terminal height.**

```php
// Change in TuiCoreRenderer::initialize():
$this->input->setMinVisibleLines(1);
$this->input->setMaxVisibleLines(8); // instead of 2
$this->input->expandVertically(true); // enable dynamic growth
```

This requires testing the layout to ensure the conversation area doesn't collapse. The `VerticallyExpandableInterface` is already implemented in `EditorWidget` but not activated.

Estimated effort: 0.5 day (configuration change + layout testing).

### Priority 3 — Discoverability Hints (Impact: High)

**Add visual affordances for multi-line capability.**

1. **Placeholder text**: When the input is empty, show dim gray text like `"Shift+Enter for new line · / for commands"`
2. **Line count badge**: When content > 1 line, show `"3 lines"` in the border area
3. **Welcome screen addition**: Add a line in the Quick Reference showing input keybindings

Estimated effort: 1 day.

### Priority 4 — File Path Completion (Impact: Medium-High)

**Add Tab-completion for file paths.**

When the cursor is adjacent to a path-like string (contains `/` or `./` or `~`), Tab should complete file/directory names. This is the second most impactful completion after command completion.

Implementation approach:
1. Detect path-like patterns in `handleChange()`
2. Use `glob()` to find matching files
3. Display results in the existing `SelectListWidget` overlay

Estimated effort: 2–3 days.

### Priority 5 — Auto-Suggestion (Impact: Medium)

**Add Fish-style gray auto-suggestions from history.**

After implementing prompt history (Priority 1), show the most recent matching history entry in gray text as the user types. Accept with → or End.

This is the feature that makes Fish shell's input feel magical. It reduces repetitive typing dramatically.

Estimated effort: 2 days.

### Priority 6 — Selection Support (Impact: Medium)

**Add Shift+Arrow text selection and clipboard integration.**

The `EditorDocument` has no concept of selection/range. Adding it would enable:
- Visual selection highlight
- Copy (Ctrl+C) with selection context (currently `copy` keybinding is cleared to empty)
- Cut (Ctrl+X)
- Kill-region / copy-region-as-kill

Estimated effort: 3–5 days (significant refactoring of `EditorDocument`).

### Priority 7 — Content Feedback (Impact: Low-Medium)

**Add token estimate and input size indicator.**

Show an estimated token count in the prompt border or status bar when input exceeds a threshold. This helps users stay within context limits.

Estimated effort: 1 day.

---

## Summary Scorecard

| Dimension | Score | Notes |
|-----------|-------|-------|
| Multi-line editing engine | ★★★★☆ | Excellent foundation, just capped |
| Multi-line discoverability | ★☆☆☆☆ | Zero visual cues |
| Input history | ☆☆☆☆☆ | Completely absent |
| Text editing keybindings | ★★★★☆ | Comprehensive Emacs-style set + kill ring |
| Paste handling | ★★★★☆ | Bracketed paste + large-paste markers |
| Auto-completion | ★★☆☆☆ | Slash/power/skill only, no paths or context |
| Visual feedback | ★★☆☆☆ | Minimal, no affordances |
| Max line / overflow | ★★☆☆☆ | No limit, no feedback |
| **Overall** | **★★☆☆☆** | Engine is 4★, UX layer is 1–2★ |

The core editor is the strongest part of the input system. The kill ring alone puts it ahead of Claude Code in raw editing power. But the UX layer — discoverability, history, visual feedback, and completion — needs significant investment to match the baseline set by competitors.
