# UX Audit: Navigation & Keybinding Discoverability

> **Research Question**: How discoverable is navigation in KosmoKrator's TUI?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `TuiInputHandler.php`, `TuiCoreRenderer.php`, `HistoryStatusWidget.php`, `EditorWidget.php` (symfony/tui), `BashCommandWidget.php`, `CollapsibleWidget.php`, `DiscoveryBatchWidget.php`, `SubagentDisplayManager.php`, `KosmokratorStyleSheet.php`

---

## Executive Summary

KosmoKrator's TUI has **eleven distinct keybindings** beyond basic text editing, **zero persistent keybinding hints**, **no help screen**, and **no command palette**. A new user has no in-UI way to discover that `Shift+Tab` cycles modes, `Ctrl+O` toggles tool output, or `Page Up/Down` scrolls history. The only discoverability mechanism is the inline `(ctrl+o to reveal)` hints that appear on collapsed tool output — but these are fragile, ephemeral, and don't cover navigation or mode switching.

Compared to lazygit (always-visible keybinding bar), Helix (space-prefixed discoverable menu), and Claude Code (`?` overlay), KosmoKrator's navigation is effectively **invisible**.

**Severity**: Critical. Undiscoverable navigation is the single biggest barrier to adoption after onboarding. Users who can't navigate can't use the tool.

---

## 1. Complete Keybinding Inventory

### 1.1 All Keybindings Currently Active

Sourced from `TuiCoreRenderer.php:237-244` (overridden), `EditorWidget.php:537-580` (defaults), and `TuiInputHandler.php:150-266` (handlers).

#### Navigation & Scrolling

| Key | Action | Source | Discoverable? |
|-----|--------|--------|---------------|
| `Page Up` | Scroll conversation history up | `TuiCoreRenderer.php:241` | ❌ No hint |
| `Page Down` | Scroll conversation history down | `TuiCoreRenderer.php:242` | ❌ No hint |
| `End` | Jump to live output (when browsing history) | `TuiCoreRenderer.php:243` | ⚠️ Only shown in `HistoryStatusWidget` |
| `Ctrl+L` | Force full re-render | `TuiInputHandler.php:230-234` | ❌ No hint |

#### Mode & Interaction

| Key | Action | Source | Discoverable? |
|-----|--------|--------|---------------|
| `Shift+Tab` | Cycle mode (Edit → Plan → Ask) | `TuiCoreRenderer.php:240` | ❌ No hint |
| `Ctrl+O` | Toggle all tool results (expand/collapse) | `EditorWidget.php:579` | ⚠️ Inline hints on collapsed widgets |
| `Ctrl+A` | Open swarm dashboard | `TuiInputHandler.php:203-209` | ⚠️ Only shown during active swarm |
| `Escape` | Cancel / close / quit | `TuiInputHandler.php:269-299` | ❌ No hint |

#### Slash/Power Command Prefixes

| Prefix | Triggers | Source | Discoverable? |
|--------|----------|--------|---------------|
| `/` | Slash command completion dropdown | `TuiInputHandler.php:305-306` | ✅ Auto-discovered on typing `/` |
| `:` | Power command completion dropdown | `TuiInputHandler.php:309-313` | ❌ No hint that `:` exists |
| `$` | Skill command completion dropdown | `TuiInputHandler.php:314-316` | ❌ No hint that `$` exists |

#### Text Editing (Editor defaults)

| Key | Action | Discoverable? |
|-----|--------|---------------|
| `Enter` | Submit message | ✅ Universal convention |
| `Shift+Enter` / `Alt+Enter` | New line | ⚠️ Overridden in `TuiCoreRenderer.php:239` |
| `Ctrl+W` / `Alt+Backspace` | Delete word backward | ❌ No hint |
| `Ctrl+K` | Delete to line end | ❌ No hint |
| `Ctrl+U` | Delete to line start | ❌ No hint |
| `Ctrl+Y` | Yank (paste from kill ring) | ❌ No hint |
| `Ctrl+Shift+K` | Delete entire line | ❌ No hint |
| `Ctrl+-` | Undo | ❌ No hint |
| `Ctrl+Shift+Z` | Redo | ❌ No hint |

### 1.2 Keybindings That Users Don't Know About (Ranked by Impact)

1. **`Shift+Tab` (mode cycling)** — The most impactful undiscoverable binding. Switches between Edit, Plan, and Ask modes. No user will try `Shift+Tab` unprompted. The status bar shows the current mode label but never explains how to change it.

2. **`Page Up/Down` (history scrolling)** — Users expect vim-style `j/k` or arrow-key scrolling. Page Up/Down is a reasonable choice but never communicated. The `HistoryStatusWidget` shows hints only *after* the user is already scrolling (chicken-and-egg problem).

3. **`:` (power commands)** — The completion dropdown is a great UX, but only if you know to type `:`. No hint anywhere suggests this prefix exists.

4. **`$` (skill commands)** — Same as power commands. Completely invisible until discovered.

5. **`Ctrl+O` (tool result toggle)** — Gets partial discoverability from inline `⊛ (ctrl+o to reveal)` hints, but this only appears on collapsed output. The "collapse all" direction (pressing `Ctrl+O` when everything is expanded) has zero discoverability.

6. **`Ctrl+A` (swarm dashboard)** — Only hinted with `ctrl+a for dashboard` during active swarm operations (`SubagentDisplayManager.php:249`). Invisible at all other times.

7. **`Ctrl+L` (force re-render)** — Debug-oriented. Low impact, but worth documenting.

---

## 2. Current Discoverability Mechanisms

### 2.1 What Exists

| Mechanism | Location | Covers | Assessment |
|-----------|----------|--------|------------|
| Mode label in status bar | `TuiCoreRenderer.php:770-779` | Current mode only | Shows *what* mode you're in, not *how to change it* |
| History status bar | `HistoryStatusWidget.php:59-63` | `PgUp/PgDn scroll  End latest` | Only visible *while scrolling* — circular dependency |
| Inline collapse hints | `BashCommandWidget.php:184,217`, `CollapsibleWidget.php:97,99`, `DiscoveryBatchWidget.php:97,116` | `ctrl+o to reveal/collapse` | Good for individual widgets, doesn't scale |
| Swarm dashboard hint | `SubagentDisplayManager.php:249` | `ctrl+a for dashboard` | Only during swarm ops |
| `/` command completion | `TuiInputHandler.php:305-306` | All slash commands | Excellent — auto-discovers on `/` keystroke |

### 2.2 What's Missing

- **No persistent keybinding bar** (lazygit-style footer)
- **No help overlay** (`?` key — Helix, Claude Code)
- **No command palette** (`Ctrl+Shift+P` — VS Code convention)
- **No first-run keybinding cheat sheet** (beyond the ASCII art shown at startup)
- **No contextual hints** that appear based on state (e.g., "Press Shift+Tab to switch modes")
- **No `?` binding** — the `?` key is not bound to anything
- **No tooltip/toast system** for progressive disclosure

---

## 3. Comparison with Reference TUIs

### 3.1 Lazygit

**Strategy**: Always-visible keybinding bar at screen bottom.

```
┌──────────────────────────────────────────────────────────┐
│ 1) Files    2) Branches   3) Commits   4) Stash          │
│                                                          │
│ │ staged │                                                │
│ │ file A │                                                │
│ │ file B │                                                │
│                                                          │
│ ── Staging ─────────────────────────────────────────────  │
│ c commit  a stage/unstage  d discard  o expand  ? help  │
└──────────────────────────────────────────────────────────┘
```

**What works**: The bottom bar changes contextually per panel. Every available action is always visible. The `?` key opens a full keybinding list.

**Adoptable pattern**: Context-sensitive footer bar that shows the 5-6 most relevant shortcuts for the current state.

### 3.2 Helix

**Strategy**: Space-prefixed command menu (command palette via modal).

```
┌──────────────────────────────────────────────────────────┐
│                                                          │
│  space >                                                 │
│  f   file picker                                         │
│  b   buffer picker                                       │
│  s   symbol picker                                       │
│  w   window mode                                         │
│  ?   help                                                │
│                                                          │
│ NORMAL │ utils.rs │ UTF-8 │ rust                        │
└──────────────────────────────────────────────────────────┘
```

**What works**: `space` acts as a discoverable prefix — pressing it shows all available commands. Mode indicator in status bar. `:help` opens comprehensive documentation.

**Adoptable pattern**: A prefix key that reveals available commands. Less critical for KosmoKrator since the `/`, `:`, `$` prefixes already serve this role.

### 3.3 Vim

**Strategy**: Layered help system (`:help`, `:help key-objects`, `:map`).

**What works**: Deep documentation accessible from within the editor. `:help` understands context. Keybinding listing via `:map`.

**Adoptable pattern**: Comprehensive help accessible via `?` — less relevant for KosmoKrator's conversational UI but the `?` convention is universal.

### 3.4 Claude Code

**Strategy**: `?` overlay showing all shortcuts in a centered modal.

```
┌──────────────────────────────────────────────────────────┐
│                                                          │
│  User: fix the login bug                                 │
│  Assistant: I'll analyze the login flow...               │
│                                                          │
│          ┌────────────────────────────────────┐           │
│          │ Keyboard Shortcuts                 │           │
│          │                                    │           │
│          │ Esc       Interrupt                │           │
│          │ Ctrl+O    Toggle tool output       │           │
│          │ Ctrl+L    Clear / refresh          │           │
│          │ ?         Show this help           │           │
│          │                                    │           │
│          │ Press any key to close             │           │
│          └────────────────────────────────────┘           │
│                                                          │
│ > _                                                      │
└──────────────────────────────────────────────────────────┘
```

**What works**: Single keypress, modal overlay, immediately dismissible, shows everything at once. Zero visual clutter when not active.

**Adoptable pattern**: This is the ideal model for KosmoKrator. A `?`-triggered modal overlay that shows all bindings, dismissible by any key.

---

## 4. Audit Findings by Question

### 4.1 What keybindings exist that users don't know about?

**Seven critical bindings are effectively hidden** (see §1.2). The most damaging are:

- **`Shift+Tab`** for mode cycling — users see "Edit" in the status bar but have no way to learn how to switch
- **`Page Up/Down`** for history — users will try arrow keys, mouse scroll, or `j/k` before stumbling onto Page keys
- **`:` and `$` prefixes** — entire categories of functionality invisible without documentation

### 4.2 Are keybinding hints visible anywhere?

**Barely**. The only persistent hint is the mode label in the status bar (`TuiCoreRenderer.php:775`), which shows *what* mode you're in but not *how to change it*. The history scroll hints only appear after you start scrolling. The `ctrl+o` hints only appear on collapsed tool output.

### 4.3 How does a new user discover navigation?

**They can't — except by accident.** There is no in-UI mechanism for a new user to learn the keybindings. The only paths are:
1. Reading external documentation
2. Typing `/` and seeing the command dropdown (the one genuinely discoverable feature)
3. Pressing keys at random

### 4.4 Is there a help screen (`?` key)?

**No.** The `?` character is unbound in `TuiInputHandler.php`. Pressing `?` simply inserts a `?` character in the prompt. There is no `HelpOverlayWidget`, no help modal, and no keybinding listing anywhere in the codebase.

### 4.5 Mode switching (`Shift+Tab`) — is it discoverable?

**Completely undiscoverable.** The status bar at `TuiCoreRenderer.php:775` renders:

```
Edit · Guardian ◈ · Ready
```

This tells the user the current mode but provides zero indication that `Shift+Tab` cycles modes. No tooltip, no hint text, no change-in-state notification (e.g., "Switched to Plan mode — Shift+Tab to cycle").

### 4.6 Scrolling — is it obvious how to scroll history?

**No.** The keybinding is `Page Up/Down` (overridden at `TuiCoreRenderer.php:241-242`), which is reasonable but never communicated. The `HistoryStatusWidget` (`HistoryStatusWidget.php:63`) shows `PgUp/PgDn scroll  End latest` — but only *after* the user has already started scrolling. This is a classic chicken-and-egg discoverability failure.

Additionally, there's no scroll indicator (scrollbar, percentage, or "X messages above") when in live mode. Users don't know there *is* scrollable history.

### 4.7 Tool result toggling (`Ctrl+O`) — discoverable?

**Partially.** Inline hints like `⊛ +42 lines (ctrl+o to reveal)` (`BashCommandWidget.php:184`) provide good discoverability for *expanding* collapsed output. However:
- The reverse action (collapse expanded output) has the hint `⊛ (ctrl+o to collapse)` — less visible since it's on a single line
- The global toggle (`TuiInputHandler.php:236-239`) expands/collapses *all* tool results simultaneously, but this is never hinted
- There's no visual indicator that `Ctrl+O` is a toggle (vs. one-shot expand)

---

## 5. Recommendations

### 5.1 Priority: Implement `?` Help Overlay (Critical)

Add a `HelpOverlayWidget` triggered by `?` when the prompt is empty (or always — with insertion of `?` deferred when the overlay is not shown). This is the single highest-impact change.

See §6 for the full mockup.

**Implementation notes**:
- Bind `?` in `TuiInputHandler::handleInput()` — when prompt text is empty, show overlay; otherwise insert `?`
- Use `TuiModalManager` pattern (overlay container widget)
- Dismiss on `Escape`, `?`, or `Enter`
- Show all keybindings organized by category

### 5.2 Priority: Contextual Footer Hints (High)

Add a lazygit-style footer bar showing the 5-6 most relevant shortcuts for the current state. This replaces or supplements the mode indicator in the status bar.

**States and their hints**:

| State | Footer Text |
|-------|-------------|
| Idle (prompt empty) | `Enter send · Shift+Tab mode · PgUp scroll · / commands · ? help` |
| Idle (text in prompt) | `Enter send · Shift+Enter newline · Shift+Tab mode · / commands · ? help` |
| Browsing history | `PgUp/PgDn scroll · End jump to live · ? help` |
| During swarm | `Ctrl+A dashboard · Esc cancel · ? help` |
| Ask modal open | `Enter confirm · Esc cancel` |

**Implementation**: Modify `HistoryStatusWidget` to be always-visible (not just during scroll), or create a new `KeybindingFooterWidget`. Render as a single styled line below the conversation, above the prompt.

### 5.3 Priority: Mode Change Toast (High)

When `Shift+Tab` is pressed and the mode changes, show a brief non-blocking toast:

```
═══ Switched to Plan mode ═══
```

This serves as both confirmation and education — the user learns the binding by seeing it in action.

**Implementation**: Flash a `TextWidget` in the conversation for 2 seconds, then remove it. Or use the existing status bar area.

### 5.4 Priority: Prefix Hints in Empty Prompt (Medium)

When the prompt is empty, show placeholder text with prefix hints:

```
> Type a message, / for commands, : for workflows, $ for skills...
```

This mimics search input patterns (Google's "Search or type URL"). The placeholder disappears on first keystroke.

**Implementation**: Render placeholder text in the `EditorWidget` when `getText()` returns empty string. Style with dim/low-contrast color.

### 5.5 Priority: Progressive Disclosure (Medium)

Show contextual hints as the user gains experience:
- **First session**: Show full help overlay automatically on startup (after intro animation)
- **Sessions 2-5**: Show abbreviated footer hints
- **Session 6+**: Show only status bar (current behavior)

This requires a persistent counter (stored in `~/.kosmokrator/preferences.json` or similar).

### 5.6 Priority: Help Overlay onboarding (Low — covered by 5.1)

The `?` help overlay should be mentioned in:
- The intro animation's command cheat sheet
- The status bar on first run
- Any error messages ("Unknown key — press ? for help")

---

## 6. Help Overlay Mockup

### 6.1 Trigger

Press `?` at any time when the prompt is empty. Press `?` again, `Escape`, or `Enter` to dismiss.

### 6.2 Visual Design

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  User: implement auth middleware                                             │
│  Assistant: I'll create the middleware...                                    │
│  ── file_read: src/Middleware/Auth.php ─────────────────────────── ⊛ collapse│
│  1  <?php                                                                    │
│  2  declare(strict_types=1);                                                 │
│                                                                              │
│           ┌─────────────────────────────────────────────────┐                │
│           │                                                 │                │
│           │  ⌨  Keyboard Shortcuts                         │                │
│           │  ─────────────────────────                      │                │
│           │                                                 │                │
│           │  Navigation                                     │                │
│           │  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄ │                │
│           │  PgUp/PgDn  Scroll conversation history         │                │
│           │  End         Jump to latest output              │                │
│           │  Ctrl+L      Force re-render                    │                │
│           │                                                 │                │
│           │  Modes                                         │                │
│           │  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄ │                │
│           │  Shift+Tab  Cycle: Edit → Plan → Ask           │                │
│           │                                                 │                │
│           │  Tool Output                                   │                │
│           │  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄ │                │
│           │  Ctrl+O     Expand / collapse all tool results │                │
│           │                                                 │                │
│           │  Commands                                      │                │
│           │  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄ │                │
│           │  / prefix   Slash commands (type / to see)     │                │
│           │  : prefix   Power workflows (type : to see)    │                │
│           │  $ prefix   Skills (type $ to see)             │                │
│           │                                                 │                │
│           │  Swarm                                         │                │
│           │  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄ │                │
│           │  Ctrl+A      Open swarm dashboard              │                │
│           │                                                 │                │
│           │  General                                       │                │
│           │  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄ │                │
│           │  Escape      Cancel / close                    │                │
│           │  ?           This help screen                  │                │
│           │                                                 │                │
│           │          Press ? or Esc to close                │                │
│           └─────────────────────────────────────────────────┘                │
│                                                                              │
│ Edit · Guardian ◈ · Ready                                                    │
│ > _                                                                          │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 6.3 Design Principles for the Overlay

1. **Centered modal** — doesn't obscure the full conversation, maintains spatial context
2. **Categorized** — Navigation, Modes, Tool Output, Commands, Swarm, General
3. **Concise** — one line per binding, no walls of text
4. **Dismissable** — any key closes it (not just Escape — reduce frustration)
5. **Styled consistently** — uses the existing `Theme::accent()`, `Theme::dim()`, `Theme::borderAccent()` palette
6. **No mouse required** — pure keyboard interaction

### 6.4 Alternative: Compact Single-Line Footer (Lazygit Style)

For users who don't want the overlay, a persistent footer provides ambient discoverability:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  ...conversation...                                                          │
│                                                                              │
│  Edit · Guardian ◈ · Ready                                                  │
│  Enter send · Shift+Tab mode · PgUp/Dn scroll · Ctrl+O tools · ? help      │
│ > _                                                                          │
└──────────────────────────────────────────────────────────────────────────────┘
```

This can coexist with the `?` overlay — the footer provides ambient awareness, the overlay provides full detail.

---

## 7. Severity Assessment

| Finding | Severity | Effort | Impact |
|---------|----------|--------|--------|
| No help screen (`?`) | 🔴 Critical | Medium | Highest single improvement |
| `Shift+Tab` undiscoverable | 🔴 Critical | Low (toast) | High — mode is core concept |
| No persistent keybinding hints | 🟠 High | Medium | High — ambient learning |
| `Page Up/Down` scroll undiscoverable | 🟠 High | Low (hint text) | High — navigation is essential |
| `:` and `$` prefix undiscoverable | 🟡 Medium | Low (placeholder) | Medium — `/` already works |
| History scroll hints circular | 🟡 Medium | Low (always show hint) | Medium |
| `Ctrl+O` global toggle undocumented | 🟡 Medium | Low (add to help overlay) | Medium |
| No progressive disclosure | 🟢 Low | High | Nice-to-have |

---

## 8. Recommended Implementation Order

1. **`?` help overlay** — single biggest win, ~1 day of work, uses existing overlay/modal patterns
2. **Mode change toast** — trivial to implement, huge educational value
3. **Contextual footer hints** — moderate effort, persistent discoverability
4. **Empty-prompt placeholder** — simple, teaches `/`, `:`, `$` prefixes
5. **History hint always visible** — change `HistoryStatusWidget` to show subtle hint in live mode
6. **Progressive disclosure counter** — lowest priority, requires persistence layer

---

## 9. Key Code References

| Component | File | Lines |
|-----------|------|-------|
| Keybinding definitions | `TuiCoreRenderer.php` | 237–244 |
| Default editor keybindings | `EditorWidget.php` (vendor) | 537–580 |
| Input handler (all key logic) | `TuiInputHandler.php` | 150–266 |
| History scroll hints | `HistoryStatusWidget.php` | 59–63 |
| Tool collapse hints | `BashCommandWidget.php` | 184, 217 |
| Tool collapse hints | `CollapsibleWidget.php` | 97, 99 |
| Discovery batch hints | `DiscoveryBatchWidget.php` | 97, 116 |
| Swarm dashboard hint | `SubagentDisplayManager.php` | 249 |
| Status bar rendering | `TuiCoreRenderer.php` | 770–779 |
| Mode cycling | `TuiCoreRenderer.php` | 856–867 |
| Tool result toggle | `TuiInputHandler.php` | 399–413 |
