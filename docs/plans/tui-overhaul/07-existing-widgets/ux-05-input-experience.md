# UX Audit: Input Experience

> **Research Question**: How good is the input experience in KosmoKrator's TUI?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `TuiInputHandler.php`, `TuiCoreRenderer.php`, `EditorWidget.php`, `EditorDocument.php`, `BracketedPasteTrait.php`, `SelectListWidget.php`, `Keybindings.php`, `Key.php`, `HistoryStatusWidget.php`, `src/Command/Slash/*.php`

---

## Executive Summary

KosmoKrator's input experience is **structurally solid but feature-incomplete**. The underlying `EditorWidget` from Symfony's TUI library provides a real multi-line editor with undo/redo, kill ring, bracketed paste, word-level cursor movement, and configurable keybindings. However, the experience atop this foundation is missing several features that distinguish world-class terminal input from merely functional: **no input history recall, no auto-suggestion, no file-path completion, no contextual help at the prompt, and limited discoverability of the three command namespaces** (`/`, `:`, `$`).

The prompt occupies 1–2 lines (`setMinVisibleLines(1)`, `setMaxVisibleLines(2)`), which constrains the user to essentially a single-line experience despite the multi-line engine underneath. Compared to Claude Code's full-featured input, Aider's prompt_toolkit richness, and even Lazygit's simple-but-contextual input, KosmoKrator's prompt feels like a well-engineered text field waiting for its UX layer.

**Severity**: High. Input is the primary interaction surface — it is the single widget the user touches every time they use the product. Every friction point here compounds across every session.

---

## 1. Multi-Line Editing Experience

### 1.1 Current State

The `EditorWidget` is configured in `TuiCoreRenderer::initialize()` (line ~289):

```php
$this->input = new EditorWidget;
$this->input->setMinVisibleLines(1);
$this->input->setMaxVisibleLines(2);
$this->input->setKeybindings(new Keybindings([
    'copy' => [],
    'new_line' => ['shift+enter', 'alt+enter'],
    'cycle_mode' => ['shift+tab'],
    'history_up' => [Key::PAGE_UP],
    'history_down' => [Key::PAGE_DOWN],
    'history_end' => [Key::END],
]));
```

**The multi-line capability exists but is artificially constrained:**

| Feature | Status | Details |
|---------|--------|---------|
| Multi-line text buffer | ✅ Working | `EditorDocument` stores `string[]` lines |
| New line insertion | ✅ Working | `Shift+Enter` or `Alt+Enter` |
| Cursor navigation (up/down) | ✅ Working | Arrow keys, `Ctrl+B`/`Ctrl+F` |
| Word-level movement | ✅ Working | `Alt+←`/`Alt+→`, `Alt+B`/`Alt+F` |
| Line start/end | ✅ Working | `Home`/`End`, `Ctrl+A`/`Ctrl+E` |
| Character jump (`f`/`F` style) | ✅ Working | `Ctrl+]` forward, `Ctrl+Alt+]` backward |
| Undo/Redo | ✅ Working | `Ctrl+-` undo, `Ctrl+Shift+Z` redo (100-deep stack) |
| Kill ring | ✅ Working | `Ctrl+K` kill-to-end, `Ctrl+Y` yank, `Alt+Y` yank-pop |
| Visible line expansion | ⚠️ Capped | Max 2 visible lines despite multi-line content |
| Auto-grow on content | ⚠️ Limited | `verticallyExpanded` is not enabled on the input |

### 1.2 Problems

1. **Max 2 visible lines** — If a user types a 10-line message, they can only see 2 lines at a time. There is no scroll indicator, no line counter, no visual hint that content exists above/below the viewport.

2. **Shift+Enter is undiscoverable** — The only way to insert a newline is `Shift+Enter` or `Alt+Enter`. There is no hint in the UI that this is possible. The welcome screen (`renderIntro`) shows no keybinding hints for multi-line input.

3. **Enter submits immediately** — `Enter` is bound to `submit`, not to newline. This is the correct default for a chat-style interface, but combined with the 2-line cap, it means users who discover multi-line editing quickly hit a wall.

4. **No auto-indent or continuation** — When pressing `Shift+Enter`, the cursor moves to the next line at column 0. There is no continuation indent, no markdown awareness, no code-fence detection.

### 1.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish Shell |
|---------|-------------|-------------|-------|------------|
| Multi-line editing | Shift+Enter, 2-line cap | Enter for newline in multi-line mode | Full multi-line with continuation | Single-line only |
| Visible expansion | No | Grows to fill available space | Unlimited | N/A |
| Line count indicator | No | Yes (shows "3 lines" in prompt) | Yes | N/A |
| Markdown awareness | No | Code fence auto-detection | Yes | N/A |

---

## 2. Command Discovery

### 2.1 Three Command Namespaces

KosmoKrator has three distinct command prefixes, defined in `TuiInputHandler`:

| Prefix | Type | Count | Example |
|--------|------|-------|---------|
| `/` | Slash commands | 21 | `/edit`, `/compact`, `/settings` |
| `:` | Power commands | 20 | `:unleash`, `:doctor`, `:team` |
| `$` | Skill/dollar commands | 5+ dynamic | `$list`, `$create`, `$show` |

**Total: 46+ commands across three namespaces.**

### 2.2 Completion System

The `handleChange()` method triggers auto-completion:

```
Type '/'  → Shows all 21 slash commands
Type ':'  → Shows all 20 power commands  
Type '$'  → Shows 5 dollar commands + dynamic skills
```

The `SelectListWidget` renders a dropdown with:
- `→` prefix for selected item
- Label + description alignment
- Scroll indicator `(3/21)` when items overflow `maxVisible` (default 5)
- `↑`/`↓` to navigate, `Tab` to accept, `Enter` to execute, `Esc` to dismiss

### 2.3 Problems

1. **Discovery requires typing the prefix** — There is no way to browse commands without first typing `/`, `:`, or `$`. No command palette, no `Ctrl+Shift+P`-style omnibox, no `?` help trigger at an empty prompt.

2. **Three namespaces are undocumented in the UI** — The welcome tutorial (`renderIntro`) only shows `/` commands. The `:` and `$` namespaces are invisible until the user reads external documentation or tries typing a colon.

3. **No fuzzy matching** — Completion is prefix-only (`str_starts_with`). Typing `/comp` shows `/compact`, but typing `comp` at an empty prompt shows nothing.

4. **Tab completion replaces text** — When the user presses `Tab`, the completion replaces the input text with `tabValue.' '`. This means partial typing + Tab works, but there's no cycling through completions with repeated Tab presses.

5. **No argument completion** — Commands like `/forget <id>`, `/resume <session>`, `:unleash <task>` take arguments, but there is no completion for these arguments. The user must know the ID or type it manually.

6. **Hidden Ctrl+A shortcut** — `TuiInputHandler::handleInput()` has a hardcoded `\x01` (Ctrl+A) that triggers `/agents`. This is invisible, undocumented, and conflicts with the standard `Ctrl+A` = "move to line start" binding (which is remapped, but still confusing).

### 2.4 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Lazygit |
|---------|-------------|-------------|-------|---------|
| Command prefix | `/`, `:`, `$` | `/` only | `/` only | Key-based navigation |
| Completion dropdown | Yes (prefix match) | Yes (fuzzy) | Yes (tab) | Context menus |
| Argument completion | No | Yes (file paths, etc.) | Yes (file paths) | N/A |
| Command palette | No | Yes (Ctrl+K) | No | `?` key |
| Fuzzy matching | No | Yes | Partial | N/A |

---

## 3. History Navigation

### 3.1 Current State

History navigation in `TuiInputHandler` controls **conversation scroll** — not input history:

```php
'history_up' => [Key::PAGE_UP],
'history_down' => [Key::PAGE_DOWN],
'history_end' => [Key::END],
```

These scroll the conversation viewport up/down by `historyScrollStep()` (max of 6 rows or `terminal_rows - 10`). The `HistoryStatusWidget` shows:

```
 │ Browsing history                    PgUp/PgDn scroll  End latest │
```

**There is no input/command history recall.** The prompt has no `↑`/`↓` arrow history. Pressing `↑` moves the cursor up within the multi-line text (or to line start if on the first line). There is no `.history` file, no session-based history, no recall of previous messages.

### 3.2 Problems

1. **No input history** — This is the single biggest gap. Every message must be typed from scratch. If a user wants to repeat a similar prompt, they must retype it entirely.

2. **Arrow keys are consumed by multi-line navigation** — `↑`/`↓` move the cursor within multi-line text. This is correct behavior, but it means there is no natural key available for history recall without a mode switch.

3. **Ctrl+R reverse search is absent** — There is no incremental history search (like bash's `Ctrl+R`).

4. **No cross-session history persistence** — Even if history recall were added, there is no persistence mechanism. The `EditorDocument`'s undo stack resets on every submit (`setText('')`).

### 3.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Fish Shell |
|---------|-------------|-------------|-------|------------|
| Input history recall | None | `↑`/`↓` arrows | `↑`/`↓` arrows | `↑`/`↓` arrows |
| Reverse search | None | `Ctrl+R` | `Ctrl+R` | Built-in |
| History persistence | None | Yes | Yes | Yes (~/.local/share/fish/) |
| Multi-line vs history | Multi-line wins | Mode-aware | Multi-line wins | N/A |

---

## 4. Auto-Completion

### 4.1 Current State

Auto-completion exists in exactly one form: the slash/power/dollar command dropdown triggered by typing `/`, `:`, or `$`. There is:

- **No file-path completion** — When a user types a filename, there is no `Tab` completion
- **No variable completion** — No completion for environment variables, session IDs, memory IDs
- **No context-aware suggestions** — The prompt does not suggest completions based on conversation context
- **No auto-suggestion** — No Fish-style "gray ghost text" showing likely completions

### 4.2 What Exists

The `SelectListWidget` completion system:
- Triggers on prefix match (`/`, `:`, `$`)
- Shows a dropdown with label + description
- Supports `↑`/`↓` navigation with wrapping
- `Tab` accepts and replaces input text
- `Enter` executes immediately
- `Esc` dismisses
- Scroll indicator for overflow
- Max 5 items visible at once

This is a good foundation, but it covers only command discovery, not content assistance.

---

## 5. Keybinding Consistency

### 5.1 Default EditorWidget Keybindings

From `EditorWidget::getDefaultKeybindings()`:

| Action | Keys |
|--------|------|
| Submit | `Enter` |
| New line | `Shift+Enter` |
| Cancel | `Esc`, `Ctrl+C` |
| Undo | `Ctrl+-` |
| Redo | `Ctrl+Shift+Z` |
| Delete line | `Ctrl+Shift+K` |
| Kill to end | `Ctrl+K` |
| Yank | `Ctrl+Y` |
| Yank pop | `Alt+Y` |
| Word delete back | `Ctrl+W`, `Alt+Backspace` |
| Word delete fwd | `Alt+D`, `Alt+Delete` |
| Line start | `Home`, `Ctrl+A` |
| Line end | `End`, `Ctrl+E` |
| Word left | `Alt+←`, `Ctrl+←`, `Alt+B` |
| Word right | `Alt+→`, `Ctrl+→`, `Alt+F` |
| Jump forward | `Ctrl+]` |
| Jump backward | `Ctrl+Alt+]` |

### 5.2 KosmoKrator Overrides

From `TuiCoreRenderer::initialize()`:

| Action | Keys | Override |
|--------|------|----------|
| Copy | `[]` (empty) | **Disables** Ctrl+C copy |
| New line | `Shift+Enter`, `Alt+Enter` | Same as default |
| Cycle mode | `Shift+Tab` | **New** binding |
| History up | `PageUp` | **Remaps** from default `Ctrl+Up` |
| History down | `PageDown` | **Remaps** from default `Ctrl+Down` |
| History end | `End` | **Conflicts** with `cursor_line_end` |

### 5.3 Problems

1. **`End` key conflict** — `End` is bound to both `cursor_line_end` (in EditorWidget defaults) and `history_end` (in KosmoKrator overrides). The `history_end` binding is only active when `isBrowsingHistory()` returns true, but this is fragile — the user might press `End` intending to jump to line end while browsing, and get teleported to live output instead.

2. **`Ctrl+C` is Cancel, not Copy** — KosmoKrator explicitly disables `copy` (`'copy' => []`) so that `Ctrl+C` works as cancel. This is the correct choice for a TUI (matches bash, vim, etc.), but it means there is no clipboard copy mechanism for selected text.

3. **`Shift+Tab` for mode cycling is non-standard** — `Shift+Tab` typically means "reverse Tab" in UI conventions. Using it for mode cycling is clever but undiscoverable.

4. **No keybinding display** — There is no `F1` help, no `?` overlay, no keybinding cheat sheet accessible from the prompt. The welcome tutorial shows command syntax but not keyboard shortcuts.

5. **Inconsistency between TUI and ANSI modes** — The ANSI fallback renderer (`AnsiCoreRenderer.php`, line 90) uses `readline()` for input, which has its own keybinding conventions (emacs-mode by default). A user switching between TUI and ANSI mode would encounter different editing behaviors.

---

## 6. Error Recovery

### 6.1 Current State

| Scenario | Behavior |
|----------|----------|
| Invalid slash command | Silently sent as regular message (e.g., `/foo` → sent as user text) |
| Typo in power command | Same — `:typo` is sent as user text |
| Accidental submit (empty) | `if (trim($value) !== '')` — empty submits are silently ignored |
| Cancel during active request | Cancels via `DeferredCancellation`, clears state |
| Cancel during prompt | `Ctrl+C` → graceful `/quit` via suspension resume |
| Cancel during modal (ask) | Resumes ask suspension with empty string |
| Undo within editor | `Ctrl+-` works, but stack resets on submit |

### 6.2 Problems

1. **Invalid commands are not caught** — Typing `/edti` submits as a regular chat message. The AI then has to interpret the typo. There is no "Did you mean `/edit`?" suggestion.

2. **No confirmation for destructive commands** — `/new` clears the conversation with no "Are you sure?" prompt. `/quit` exits immediately.

3. **Submit with accidental content** — If a user has typed something and accidentally hits `Enter`, there is no way to recall or edit the submitted message. The undo stack is cleared on submit.

4. **No draft preservation** — When a mode switch occurs (`Shift+Tab` → cycle mode), the current text is saved to `pendingEditorRestore`. But if the user submits or cancels by other means, the draft is lost.

---

## 7. Paste Handling

### 7.1 Current State

The `BracketedPasteTrait` handles terminal bracketed paste sequences (`\x1b[200~` ... `\x1b[201~`):

1. **Detects paste start/end markers** — Accumulates chunks until the end marker
2. **Routes to `EditorDocument::handlePaste()`** — Which has special handling for large pastes (>10 lines):
   - Creates a marker like `[paste #1 +42 lines <a1b2c3d4>]`
   - Inserts the marker text in the editor
   - Stores the real content in `pasteMarkers[]` for later retrieval via `getText()`
3. **Small pastes** (≤10 lines) — Inserted directly as text with line-by-line insertion

### 7.2 Problems

1. **Large paste markers are invisible in the editor** — The user sees `[paste #1 +42 lines <a1b2c3d4>]` as literal text. There is no visual distinction (no folding, no dim styling, no "click to expand"). The user may think their paste was corrupted.

2. **No paste preview** — There is no way to review the pasted content before submitting.

3. **No paste size warning** — Pasting 10,000 lines goes through without any "This is a large paste. Continue?" prompt.

4. **Bracketed paste only works in supported terminals** — Terminals that don't support bracketed paste mode will send each character individually, resulting in the paste being processed as rapid typing (with potential auto-repeat issues).

5. **The 2-line cap hides pastes** — Combined with `setMaxVisibleLines(2)`, even a moderate paste shows only 2 lines of the marker, making the paste experience feel broken.

---

## 8. Accessibility

### 8.1 Current State

There is **no explicit accessibility support**:

- **No screen reader support** — No ARIA-like announcements, no `accessibility` attributes on widgets
- **No high-contrast mode** — The color scheme uses RGB values with no fallback
- **No keyboard-only navigation documentation** — All mouse/keyboard interactions are undocumented
- **No focus indicators** beyond the cursor block — There is no visual focus ring
- **The cursor shape is a block** — `CursorShape::Block` is used, which is the most visible option but not configurable

### 8.2 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Lazygit |
|---------|-------------|-------------|-------|---------|
| Screen reader mode | None | None | None | None |
| High-contrast mode | None | None | None | Yes (theme support) |
| Keyboard navigation | Full | Full | Full | Full |
| Focus indicators | Block cursor | Block cursor | N/A (readline) | Highlighted panels |

Note: Terminal accessibility is universally poor across all compared tools. This is a systemic gap in the terminal UI ecosystem, not specific to KosmoKrator.

---

## 9. Recommendations

### 9.1 Priority 1: Input History (Critical)

This is the single highest-impact improvement. Without history recall, every interaction is one-shot.

**Implementation plan:**

```
Session history file: ~/.kosmokrator/history/{session-id}.jsonl
Global history file: ~/.kosmokrator/history/global.jsonl (last 1000 entries)

Binding: ↑ / ↓ when cursor is on first/last line of a single-line input
         Ctrl+↑ / Ctrl+↓ for history regardless of cursor position
         Ctrl+R for reverse incremental search
```

**Mockup — History Recall:**

```
┌──────────────────────────────────────────────────────────────────────┐
│  ⟡ Refactor the authentication middleware to use PSR-15_          │ ← gray ghost of previous command
│                                                                      │
│  ↑ Navigate history · Enter to accept · Esc to dismiss              │
└──────────────────────────────────────────────────────────────────────┘
```

### 9.2 Priority 1: Expand Visible Lines (Critical)

Remove or significantly raise `setMaxVisibleLines(2)`. The multi-line editor should auto-grow to fill available space.

**Recommended config:**

```php
$this->input->setMinVisibleLines(1);
$this->input->setMaxVisibleLines(null);  // unlimited, auto-grow
$this->input->expandVertically(true);     // fill available space
```

**Mockup — Multi-line prompt with auto-grow:**

```
┌──────────────────────────────────────────────────────────────────────┐
│ ... conversation content ...                                         │
│ ... conversation content ...                                         │
├──────────────────────────────────────────────────────────────────────┤
│  ⟡ Refactor the authentication middleware to use PSR-15             │
│  standards. The current implementation has hardcoded dependencies   │
│  that make testing difficult. Use the existing MiddlewareInterface  │
│  and add proper type hints throughout._                              │
│                                                                      │
│  Shift+Enter newline · 3 lines · Enter submit                       │
├──────────────────────────────────────────────────────────────────────┤
│  Edit · Guardian ◈ · 12k/200k · claude-sonnet-4-20250514           │
└──────────────────────────────────────────────────────────────────────┘
```

### 9.3 Priority 2: Command Palette (High)

Add a `Ctrl+K` command palette that provides fuzzy search across all commands and recent actions.

**Mockup — Command Palette:**

```
┌──────────────────────────────────────────────────────────────────────┐
│ ... conversation content ...                                         │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ Search commands...                                            │  │
│  │ ─────────────────────────────────────────────────────────────  │  │
│  │ → /edit        Switch to edit mode (full tool access)         │  │
│  │   /plan        Switch to plan mode (read-only)                │  │
│  │   /compact     Compact conversation context                   │  │
│  │   :doctor      Self-diagnostic check                         │  │
│  │   :team        Staged pipeline with specialized agent roles   │  │
│  │   $list        List all available skills                      │  │
│  │ ─────────────────────────────────────────────────────────────  │  │
│  │ ↑↓ navigate · Enter select · Esc close                        │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                      │
│  ⟡ _|                                                              │
├──────────────────────────────────────────────────────────────────────┤
│  Edit · Guardian ◈ · 12k/200k · claude-sonnet-4-20250514           │
└──────────────────────────────────────────────────────────────────────┘
```

### 9.4 Priority 2: Fuzzy Completion (High)

Upgrade the prefix-match completion to fuzzy matching. This is especially important for the `:` power commands which have unusual names.

**Example:**
- Type `:doc` → matches `:doctor`, `:docs`
- Type `:un` → matches `:unleash`, `:undo` (if existed)
- Type `/se` → matches `/settings`, `/seed`, `/sessions`

### 9.5 Priority 2: Argument Completion (High)

Add argument-aware completion for commands that take parameters:

```
/forget <tab>  →  Shows list of memory IDs with previews
/resume <tab>  →  Shows list of sessions with timestamps
:unleash       →  Shows "Enter a task description..." placeholder
```

### 9.6 Priority 2: Fish-Style Auto-Suggestions (High)

Show a gray "ghost" suggestion when the current input matches a history entry. This is the single most loved feature of Fish shell.

**Mockup — Auto-Suggestion:**

```
┌──────────────────────────────────────────────────────────────────────┐
│  ⟡ refactor the aut_                                                │
│              hentication middleware to use PSR-15 standards          │
│              ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^             │
│              (dim gray, accepts with → or End)                       │
└──────────────────────────────────────────────────────────────────────┘
```

Accept: `→` (right arrow) or `End` to accept the suggestion.
Dismiss: Continue typing or `Esc`.

### 9.7 Priority 3: Keybinding Hints in Prompt Footer

Add a contextual hint line below the prompt that shows relevant keybindings based on the current state.

**Mockup — Contextual Hints:**

```
Empty prompt:
  ⟡ _
  Shift+Tab cycle mode · / commands · : power · $ skills · ? help

After typing /:
  ⟡ /co_
  → /compact    Compact conversation context
    /commands   List available commands
  ↑↓ navigate · Tab accept · Enter run · Esc dismiss

Multi-line active:
  ⟡ This is my first line
    and this is the second_
  Shift+Enter newline · 2 lines · Enter submit · Ctrl+Z undo
```

### 9.8 Priority 3: Paste Improvement

**For large pastes:**
1. Show a visual fold indicator instead of raw marker text
2. Add a paste preview on submit: "Submit with 42-line paste? [Y/n/e(expand)]"
3. Consider auto-compacting: "Large paste detected. Using /compact-style summary"

**Mockup — Paste Fold:**

```
  ⟡ Here is my code change:
    ▸ [42 lines pasted] — Enter to expand, Ctrl+Y to yank_
```

### 9.9 Priority 3: "Did You Mean?" for Invalid Commands

When a submitted message starts with `/`, `:`, or `$` but doesn't match any command, show a suggestion:

```
  ⟡ /edti
  ✗ Unknown command /edti. Did you mean /edit? [Tab to correct, Enter to send as message]
```

### 9.10 Priority 4: Accessibility Improvements

1. **Configurable cursor shape** — Allow users to choose between block, bar, and underline
2. **High-contrast theme** — A `--high-contrast` flag that switches to safe 16-color palette
3. **Keybinding remapping** — Allow users to customize keybindings via a config file
4. **Reduce motion** — Respect `NO_COLOR` and `TERM=dumb` environment variables (currently partially supported via `KOSMOKRATOR_NO_ANIM`)

---

## 10. Keybinding Map (Current State)

### 10.1 Complete Keybinding Reference

| Key | Context | Action |
|-----|---------|--------|
| `Enter` | Input | Submit message |
| `Shift+Enter` | Input | Insert newline |
| `Alt+Enter` | Input | Insert newline |
| `Esc` | Input | Cancel / dismiss completion |
| `Ctrl+C` | Input | Cancel request or quit |
| `Ctrl+-` | Input | Undo |
| `Ctrl+Shift+Z` | Input | Redo |
| `Ctrl+K` | Input | Kill to end of line |
| `Ctrl+U` | Input | Delete to line start |
| `Ctrl+Y` | Input | Yank from kill ring |
| `Alt+Y` | Input | Cycle kill ring |
| `Ctrl+W` | Input | Delete word backward |
| `Alt+D` | Input | Delete word forward |
| `Alt+Backspace` | Input | Delete word backward |
| `Ctrl+A` | Input | Move to line start |
| `Ctrl+E` | Input | Move to line end |
| `Home` | Input | Move to line start |
| `End` | Input (browsing history) | Jump to live output |
| `End` | Input (not browsing) | Move to line end |
| `Alt+←` / `Ctrl+←` | Input | Move word left |
| `Alt+→` / `Ctrl+→` | Input | Move word right |
| `Ctrl+]` | Input | Jump to character (forward) |
| `Ctrl+Alt+]` | Input | Jump to character (backward) |
| `Ctrl+Shift+K` | Input | Delete entire line |
| `Ctrl+D` | Input | Delete char forward |
| `Ctrl+F` | Input | Move cursor right |
| `Ctrl+B` | Input | Move cursor left |
| `Shift+Space` | Input | Insert regular space |
| `Shift+Tab` | Input | Cycle mode (edit→plan→ask) |
| `PageUp` | Input | Scroll conversation up |
| `PageDown` | Input | Scroll conversation down |
| `Ctrl+O` | Input | Toggle all tool results |
| `Ctrl+L` | Input | Force re-render |
| `Ctrl+A` (raw `\x01`) | Input (hidden) | Show agents dashboard |
| `Tab` | Completion open | Accept selected completion |
| `↑` / `↓` | Completion open | Navigate completion list |

### 10.2 Conflicts and Issues

1. **`Ctrl+A` dual meaning** — Line start in editor, agents dashboard via raw byte check. The keybinding system handles this (editor default is overridden), but the raw `\x01` check in `handleInput` runs before keybinding matching, making `Ctrl+A` always trigger agents dashboard. **This is a bug.**

2. **`End` context-dependent behavior** — Same key, different action depending on scroll state. Fragile and error-prone.

3. **`Ctrl+C` disabled for copy** — Explicitly set to empty array. No alternative copy mechanism exists.

---

## 11. Competitive Feature Matrix

| Feature | KosmoKrator | Claude Code | Aider | Lazygit | Fish Shell |
|---------|:-----------:|:-----------:|:-----:|:-------:|:----------:|
| Multi-line editor | ✅ 2-line cap | ✅ Full | ✅ Full | N/A | N/A |
| Undo/Redo | ✅ | ❌ | ❌ | N/A | N/A |
| Kill ring | ✅ | ❌ | ❌ | N/A | N/A |
| Bracketed paste | ✅ | ✅ | ✅ | N/A | ✅ |
| Input history recall | ❌ | ✅ | ✅ | N/A | ✅ |
| Reverse search | ❌ | ✅ | ✅ | N/A | ✅ |
| Command completion | ✅ Prefix | ✅ Fuzzy | ✅ Tab | ✅ Menu | ✅ Fuzzy |
| Argument completion | ❌ | ✅ | ✅ | N/A | ✅ |
| Auto-suggestion | ❌ | ❌ | ❌ | N/A | ✅ |
| Command palette | ❌ | ✅ | ❌ | ❌ | ❌ |
| Keybinding display | ❌ | ✅ | ❌ | ✅ | ❌ |
| Syntax highlighting | ❌ | ✅ | ❌ | N/A | ✅ |
| Vim mode | ❌ | ✅ | ✅ | ❌ | ❌ |
| Emacs mode | Partial | ✅ | ✅ | ❌ | ❌ |
| Fuzzy matching | ❌ | ✅ | ❌ | ❌ | ✅ |

**KosmoKrator's unique strengths**: Undo/redo, kill ring, character jump mode — features inherited from Symfony's `EditorWidget` that most competitors lack.

**KosmoKrator's critical gaps**: Input history, auto-suggestion, fuzzy matching, argument completion.

---

## 12. Summary Scorecard

| Dimension | Score | Notes |
|-----------|-------|-------|
| Multi-line editing | 5/10 | Engine is solid but 2-line cap cripples it |
| Command discovery | 6/10 | Completion dropdown works well for `/`, poor for `:` and `$` |
| History navigation | 2/10 | Conversation scroll works; input recall is absent |
| Auto-completion | 4/10 | Command prefix matching only; no content completion |
| Keybinding consistency | 6/10 | Generally good; `Ctrl+A` bug and `End` conflict |
| Error recovery | 5/10 | Cancel flows are well-designed; no typo correction |
| Paste handling | 7/10 | Bracketed paste + large-paste markers are advanced |
| Accessibility | 2/10 | Block cursor only; no other accommodations |
| **Overall** | **4.6/10** | Solid foundation, missing UX layer |

---

## 13. Implementation Priority

| Priority | Feature | Effort | Impact |
|----------|---------|--------|--------|
| **P0** | Input history with `↑`/`↓` recall | Medium | Critical |
| **P0** | Remove `setMaxVisibleLines(2)` cap | Trivial | Critical |
| **P1** | Contextual keybinding hints below prompt | Small | High |
| **P1** | Command palette (`Ctrl+K`) | Medium | High |
| **P1** | Fuzzy matching for completion | Small | High |
| **P1** | Fish-style auto-suggestions | Medium | High |
| **P2** | Argument completion for commands | Medium | Medium |
| **P2** | "Did you mean?" for typos | Small | Medium |
| **P2** | Paste fold visualization | Medium | Medium |
| **P3** | `Ctrl+A` bug fix | Trivial | Medium |
| **P3** | Configurable cursor shape | Small | Low |
| **P3** | High-contrast theme | Medium | Low |
| **P3** | Keybinding remapping config | Large | Low |
