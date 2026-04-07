# 01 — Keybinding Refactor

> **Module**: `src/UI/Tui/Input\`
> **Dependencies**: ConfigLoader (`src/ConfigLoader.php`), SettingsPaths (`src/Settings/SettingsPaths.php`)
> **Replaces**: Hardcoded keybindings in `TuiCoreRenderer`, `TuiInputHandler`, and custom widget `getDefaultKeybindings()`
> **Blocks**: Command palette (`02-widget-library/10-command-palette`), help overlay, any new input-driven feature

## 1. Problem Statement

Keybindings are scattered across the codebase with no central authority, no configurability, and no documentation:

| Issue | Detail |
|-------|--------|
| **Scattered definitions** | `TuiCoreRenderer::initialize()` sets EditorWidget keybindings inline; `TuiInputHandler::handleInput()` hard-codes raw byte comparisons (`\x01` = Ctrl+A, `\x0C` = Ctrl+L, `\x1b` = Escape, `\t` = Tab); 6 widgets each define their own `getDefaultKeybindings()` |
| **Not configurable** | No mechanism for users to remap keys — you must edit PHP source or live with the defaults |
| **No context awareness** | All keys share one flat namespace. There is no mode system — prompt keys, dashboard keys, and modal keys all coexist without layering |
| **Hidden keybindings** | Raw byte comparisons in `TuiInputHandler` (`$data === "\x01"`, `$data === "\x0C"`, `$data === "\t"`, `$data === "\x1b"`) are invisible to any keybinding listing — users cannot discover them |
| **No conflict detection** | Nothing prevents two actions from binding to the same key. Overlapping bindings silently race (first match wins) |
| **No multi-key sequences** | Vim-style chorded sequences (`g g`, `d d`) are unsupported. The `handleInput()` callback returns `true|false` for single keystrokes only |
| **No help generation** | Status bar hints and help overlays must be hand-written. Adding a keybinding requires updating help text in a separate location |

The goal: a **KeybindingRegistry** that owns all keybindings, supports contextual layers (modes), loads user overrides from YAML, detects conflicts, supports multi-key sequences, and auto-generates help text.

## 2. Research: Keybinding Systems

### Helix Editor (TOML config)

```toml
# ~/.config/helix/config.toml
[keys.normal]
g = { g = "goto_file_start", d = "goto_definition" }
C-s = "save"
"%" = "select_all"

[keys.insert]
C-x = "completion"
esc = "normal_mode"

[keys.select]
# separate key layer for visual selection mode
```

| Aspect | Design choice |
|--------|---------------|
| **Layers** | `[keys.normal]`, `[keys.insert]`, `[keys.select]` — context-scoped sections |
| **Multi-key** | Nested tables: `g = { g = "goto_file_start" }` — first `g` enters a pending state, second resolves |
| **Config format** | TOML — human-readable, no indentation sensitivity |
| **Conflict detection** | Start-up warning if two actions bind to the same key in the same layer |
| **Key representation** | `C-s` for Ctrl+S, `A-x` for Alt+x, `esc`, `space`, named keys |

### Lazygit (config YAML)

```yaml
keybinding:
  universal:
    quit: 'q'
    return: '<esc>'
    togglePanel: '<tab>'
    confirm: '<enter>'
  files:
    commitAmend: 'A'
    commitFile: 'c'
  branches:
    createPullRequest: 'o'
```

| Aspect | Design choice |
|--------|---------------|
| **Layers** | `universal`, `files`, `branches`, `commits`, `stash`, `status` — per-panel context |
| **Multi-key** | Not supported — single key per action |
| **Config format** | YAML section in `config.yml` |
| **Key representation** | Angle-bracket names: `<esc>`, `<enter>`, `<tab>`, `<c-c>` for Ctrl+C |
| **Help display** | Status bar shows contextual keybinding hints (changes per panel) |

### Vim (mode-based)

| Aspect | Design choice |
|--------|---------------|
| **Layers** | Normal, Insert, Visual, Command-line, Operator-pending — mutually exclusive modes |
| **Multi-key** | Deep support: `gg`, `dd`, `diw`, `ci"` — leader keys, operator+motion, count prefix |
| **Config format** | Vimscript `:map`, `:nmap`, `:imap` or Lua `vim.keymap.set()` |
| **Conflict detection** | None at config time — later mappings silently overwrite earlier ones |
| **Key representation** | Notation: `<C-n>`, `<Leader>w`, `<CR>` |

### Symfony TUI (built-in Keybindings class)

The Symfony TUI already provides `Keybindings`, `KeyParser`, and `Key`:

```php
// Keybindings are action → key IDs
new Keybindings([
    'submit' => [Key::ENTER],
    'cursor_up' => [Key::UP],
    'delete_word_backward' => ['ctrl+w', 'alt+backspace'],
]);

// KeyParser handles raw terminal bytes → key ID matching
$kb->matches($data, 'submit');  // true for \r, \n, etc.
```

| Aspect | Design choice |
|--------|---------------|
| **Layers** | None — single flat map per widget via `KeybindingsTrait` |
| **Multi-key** | None — single key resolution only |
| **Key representation** | `'ctrl+shift+enter'`, `Key::PAGE_UP`, `Key::ctrl('a')` |
| **Widget resolution** | `getDefaultKeybindings()` → `WidgetContext` globals → explicit `setKeybindings()` — 3-layer merge |
| **Parser** | Full Kitty keyboard protocol + legacy terminal sequences |

### Patterns Summary

| Feature | Helix | Lazygit | Vim | Symfony TUI (current) |
|---------|-------|---------|-----|----------------------|
| Context layers | ✅ modes | ✅ panels | ✅ modes | ❌ flat per-widget |
| Multi-key chords | ✅ nested | ❌ | ✅ deep | ❌ |
| User config | TOML | YAML | Vimscript/Lua | ❌ hardcoded |
| Conflict detection | ✅ warning | ❌ | ❌ | ❌ |
| Key format | `C-s` | `<c-s>` | `<C-S>` | `ctrl+shift+s` |
| Auto help | ❌ | ✅ status bar | ❌ | ❌ |
| Runtime reload | ❌ | ❌ | ✅ | ❌ |

## 3. Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ Config Layers                                                   │
│ ┌────────────────────┐  ┌────────────────────┐                  │
│ │ defaults.yaml      │  │ ~/.kosmokrator/    │                  │
│ │ (bundled)          │  │ keybindings.yaml    │                  │
│ └────────┬───────────┘  └────────┬───────────┘                  │
│          │ merge (user overrides defaults)                      │
│          ▼                                                      │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ KeybindingRegistry                                          │ │
│ │ ┌─────────────────────────────────────────────────────────┐ │ │
│ │ │ Context: "normal"                                       │ │ │
│ │ │  "submit"        → [enter]                              │ │ │
│ │ │  "cycle_mode"    → [shift+tab]                          │ │ │
│ │ │  "history_up"    → [page_up]                            │ │ │
│ │ │  "expand_tools"  → [ctrl+o]                             │ │ │
│ │ │  "force_render"  → [ctrl+l]                             │ │ │
│ │ │  "agents_panel"  → [ctrl+a]                             │ │ │
│ │ │  "help"           → [f1, ctrl+g]                        │ │ │
│ │ │  "goto_top"       → [g g]  ← multi-key                  │ │ │
│ │ ├─────────────────────────────────────────────────────────┤ │ │
│ │ │ Context: "completion" (active when slash-completion)    │ │ │
│ │ │  "up"             → [up]                                │ │ │
│ │ │  "down"           → [down]                              │ │ │
│ │ │  "confirm"        → [enter]                             │ │ │
│ │ │  "tab_complete"   → [tab]                               │ │ │
│ │ │  "cancel"         → [escape]                            │ │ │
│ │ ├─────────────────────────────────────────────────────────┤ │ │
│ │ │ Context: "dashboard" (SwarmDashboardWidget)             │ │ │
│ │ │  "cancel"         → [escape, ctrl+c, q]                 │ │ │
│ │ │  "agents_panel"   → [ctrl+a]                            │ │ │
│ │ ├─────────────────────────────────────────────────────────┤ │ │
│ │ │ Context: "modal" (PermissionPrompt, PlanApproval)       │ │ │
│ │ │  "up"             → [up]                                │ │ │
│ │ │  "down"           → [down]                              │ │ │
│ │ │  "confirm"        → [enter]                             │ │ │
│ │ │  "cancel"         → [escape, ctrl+c]                    │ │ │
│ │ ├─────────────────────────────────────────────────────────┤ │ │
│ │ │ Context: "editor" (passthrough to EditorWidget)         │ │ │
│ │ │  Inherits Symfony TUI's full editor keybinding set      │ │ │
│ │ │  "new_line"       → [shift+enter, alt+enter]            │ │ │
│ │ │  "submit"         → [enter]                             │ │ │
│ │ │  ... (all EditorWidget defaults)                        │ │ │
│ │ └─────────────────────────────────────────────────────────┘ │ │
│ │                                                             │ │
│ │ ┌─────────────────────────────────────────────────────────┐ │ │
│ │ │ SequenceTracker                                          │ │ │
│ │ │  pending: null | ["g"]                                    │ │ │
│ │ │  timeout: 500ms (configurable)                           │ │ │
│ │ └─────────────────────────────────────────────────────────┘ │ │
│ │                                                             │ │
│ │ ┌─────────────────────────────────────────────────────────┐ │ │
│ │ │ ConflictDetector                                         │ │ │
│ │ │  Scans all contexts for overlapping key IDs              │ │ │
│ │ │  Returns Conflict[]: {action, conflictingAction, keys}   │ │ │
│ │ └─────────────────────────────────────────────────────────┘ │ │
│ │                                                             │ │
│ │ ┌─────────────────────────────────────────────────────────┐ │ │
│ │ │ HelpGenerator                                             │ │ │
│ │ │  Generates help text for a context                       │ │ │
│ │ │  Format: "⇧Tab mode · PgUp/PgDn scroll · Ctrl+O tools"  │ │ │
│ │ └─────────────────────────────────────────────────────────┘ │ │
│ └─────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

### Data Flow

```
Terminal raw bytes
       │
       ▼
  KeyParser::parse()          ← Symfony TUI, returns key ID string ("ctrl+a")
       │
       ▼
  SequenceTracker::feed()     ← If multi-key pending, accumulates; returns match or null
       │
       ├── Sequence resolved ──→ KeybindingRegistry::resolve(context, sequence)
       │                                │
       │                                ├── Action found → dispatch to handler
       │                                └── No match → passthrough to widget
       │
       └── No sequence pending ──→ KeybindingRegistry::resolve(context, keyId)
                                        │
                                        ├── Action found → dispatch to handler
                                        └── No match → passthrough to widget
```

## 4. Class Designs

### 4.1 KeybindingRegistry

```php
// src/UI/Tui/Input/KeybindingRegistry.php
namespace Kosmokrator\UI\Tui\Input;

use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Input\KeyParser;

final class KeybindingRegistry
{
    /** @var array<string, KeybindingContext> contextName → context */
    private array $contexts = [];

    /** @var array<string, array<string, string[]>> contextName → action → keyIds (raw parsed from config) */
    private array $rawBindings = [];

    private ?KeyParser $parser = null;

    /**
     * Register a context with its default bindings.
     *
     * @param array<string, string[]> $bindings action → key IDs
     */
    public function registerContext(string $name, array $bindings, string $description = ''): void;

    /**
     * Load user overrides from parsed YAML config.
     * Merges into existing contexts — user keys override defaults.
     *
     * @param array<string, array<string, string[]>> $overrides context → action → keyIds
     */
    public function loadUserOverrides(array $overrides): void;

    /**
     * Get a Symfony Keybindings object for a specific context.
     * Used by widgets that consume Keybindings natively.
     */
    public function getKeybindingsForContext(string $context): Keybindings;

    /**
     * Resolve a key ID to an action name in the given context.
     * Returns null if no binding matches.
     */
    public function resolve(string $context, string $keyId): ?string;

    /**
     * Resolve a multi-key sequence to an action name.
     * Returns null if no binding matches the full sequence.
     *
     * @param string[] $keyIds
     */
    public function resolveSequence(string $context, array $keyIds): ?string;

    /**
     * Check if any action in a context starts with the given key prefix.
     * Used by SequenceTracker to know if a partial sequence exists.
     *
     * @param string[] $prefixKeyIds
     */
    public function hasSequencePrefix(string $context, array $prefixKeyIds): bool;

    /**
     * Get all bindings for a context (for help generation).
     *
     * @return array<string, string[]> action → key IDs
     */
    public function getBindingsForContext(string $context): array;

    /**
     * Get human-readable label for an action.
     */
    public function getActionLabel(string $context, string $action): string;

    /**
     * Run conflict detection across all contexts.
     *
     * @return list<Conflict>
     */
    public function detectConflicts(): array;

    /**
     * Set the Kitty protocol state (forwarded to KeyParser).
     */
    public function setKittyProtocolActive(bool $active): void;
}
```

### 4.2 KeybindingContext (value object)

```php
// src/UI/Tui/Input/KeybindingContext.php
namespace Kosmokrator\UI\Tui\Input;

final class KeybindingContext
{
    /**
     * @param array<string, string[]> $bindings action → key IDs
     * @param array<string, string> $labels action → human-readable label
     * @param array<string, string> $groups action → group name (for help sorting)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        private array $bindings = [],
        private array $labels = [],
        private array $groups = [],
    ) {}

    /**
     * @return array<string, string[]>
     */
    public function getBindings(): array;

    /**
     * @return string[] key IDs for a given action
     */
    public function getKeysForAction(string $action): array;

    /**
     * Merge user overrides into this context.
     * @param array<string, string[]> $overrides
     */
    public function merge(array $overrides): void;

    /**
     * Get primary (first) key for an action, formatted for display.
     */
    public function getDisplayKey(string $action): string;
}
```

### 4.3 SequenceTracker

```php
// src/UI/Tui/Input/SequenceTracker.php
namespace Kosmokrator\UI\Tui\Input;

final class SequenceTracker
{
    /** @var string[] accumulated key IDs */
    private array $pending = [];

    private float $timeoutMs;

    private ?float $lastKeyTime = null;

    public function __construct(float $timeoutMs = 500.0) {}

    /**
     * Feed a key ID. Returns:
     *  - `['type' => 'resolved', 'sequence' => [...], 'action' => '...']` if a multi-key sequence completed
     *  - `['type' => 'pending']` if this key is a partial prefix of a known sequence
     *  - `['type' => 'timeout']` if the previous pending sequence expired
     *  - `['type' => 'miss']` if no sequence starts with this key
     */
    public function feed(string $keyId, string $context, KeybindingRegistry $registry): array;

    /**
     * Reset any pending sequence (e.g., on Escape).
     */
    public function reset(): void;

    /**
     * Whether we have a pending partial sequence.
     */
    public function isPending(): bool;
}
```

### 4.4 Conflict (value object)

```php
// src/UI/Tui/Input/Conflict.php
namespace Kosmokrator\UI\Tui\Input;

final class Conflict
{
    public function __construct(
        public readonly string $context,
        public readonly string $action1,
        public readonly string $action2,
        public readonly string $conflictingKey,
    ) {}
}
```

### 4.5 HelpGenerator

```php
// src/UI/Tui/Input/HelpGenerator.php
namespace Kosmokrator\UI\Tui\Input;

final class HelpGenerator
{
    /**
     * Generate a compact status-bar hint string for a context.
     * Example: "⇧Tab mode · PgUp↑/PgDn↓ scroll · Ctrl+O tools · F1 help"
     *
     * @param list<string> $includeActions  only include these actions (whitelist)
     * @param list<string> $excludeActions  exclude these actions (blacklist)
     */
    public function statusBarHint(string $context, KeybindingRegistry $registry, array $includeActions = [], array $excludeActions = []): string;

    /**
     * Generate full help overlay lines for a context.
     *
     * @return list<array{key: string, action: string, description: string, group: string}>
     */
    public function helpOverlay(string $context, KeybindingRegistry $registry): array;

    /**
     * Format a key ID for human-readable display.
     * "ctrl+shift+enter" → "Ctrl+⏎"
     * "page_up"           → "PgUp"
     * "shift+tab"         → "⇧Tab"
     */
    public function formatKey(string $keyId): string;
}
```

### 4.6 KeybindingLoader

```php
// src/UI/Tui/Input/KeybindingLoader.php
namespace Kosmokrator\UI\Tui\Input;

use Kosmokrator\Settings\SettingsPaths;
use Symfony\Component\Yaml\Yaml;

final class KeybindingLoader
{
    public function __construct(
        private readonly SettingsPaths $paths,
    ) {}

    /**
     * Load bundled defaults from config/keybindings.yaml.
     * @return array<string, array<string, string[]>>
     */
    public function loadDefaults(): array;

    /**
     * Load user overrides from ~/.kosmokrator/keybindings.yaml.
     * Returns empty array if file doesn't exist.
     * @return array<string, array<string, string[]>>
     */
    public function loadUserOverrides(): array;

    /**
     * Load project-level overrides from .kosmokrator/keybindings.yaml.
     * @return array<string, array<string, string[]>>
     */
    public function loadProjectOverrides(): array;

    /**
     * Validate a parsed keybinding config structure.
     * Returns list of validation errors.
     *
     * @return list<string>
     */
    public function validate(array $config): array;
}
```

## 5. YAML Config Format

### 5.1 Bundled Defaults (`config/keybindings.yaml`)

```yaml
# KosmoKrator keybinding defaults
# Contexts map to UI modes/states. Each context contains action → key mappings.
# Keys use Symfony TUI notation: ctrl+a, shift+enter, alt+backspace, page_up, f1, etc.
# Multi-key sequences use space-separated keys: "g g", "d d"

contexts:
  normal:
    description: "Default mode — prompt is focused, browsing conversation"
    bindings:
      # Navigation
      history_up:    [page_up]
      history_down:  [page_down]
      history_end:   [end]
      scroll_step_up:   [ctrl+up]
      scroll_step_down: [ctrl+down]

      # Mode & panel
      cycle_mode:      [shift+tab]
      expand_tools:    [ctrl+o]
      force_render:    [ctrl+l]
      agents_panel:    [ctrl+a]
      help:            [f1, ctrl+g]

      # Multi-key sequences
      goto_top:        ["g g"]           # double-g: jump to top of conversation
      goto_bottom:     ["G"]             # shift+g: jump to live output

    labels:
      history_up:      "Scroll up"
      history_down:    "Scroll down"
      history_end:     "Jump to live"
      cycle_mode:      "Cycle mode"
      expand_tools:    "Toggle tool results"
      force_render:    "Force refresh"
      agents_panel:    "Agent dashboard"
      help:            "Help"

  completion:
    description: "Slash/power/skill command completion dropdown is visible"
    bindings:
      up:           [up]
      down:         [down]
      confirm:      [enter]
      tab_complete: [tab]
      cancel:       [escape]
    labels:
      up:           "Previous item"
      down:         "Next item"
      confirm:      "Select"
      tab_complete: "Accept"
      cancel:       "Dismiss"

  dashboard:
    description: "Swarm dashboard / agents panel overlay"
    bindings:
      cancel:        [escape, ctrl+c, q]
      agents_panel:  [ctrl+a]
    labels:
      cancel:        "Close"
      agents_panel:  "Toggle agents"

  modal:
    description: "Modal dialogs (permission prompt, plan approval, questions)"
    bindings:
      up:      [up]
      down:    [down]
      left:    [left]
      right:   [right]
      confirm: [enter]
      cancel:  [escape, ctrl+c]
    labels:
      up:      "Previous"
      down:    "Next"
      left:    "Previous option"
      right:   "Next option"
      confirm: "Confirm"
      cancel:  "Cancel"

  settings:
    description: "Settings panel"
    bindings:
      up:       [up]
      down:     [down]
      left:     [left]
      right:    [right]
      confirm:  [enter]
      cancel:   [escape, ctrl+c]
      save:     [ctrl+s]
      backspace: [backspace]
      tab_next: [tab]
      tab_prev: [shift+tab]
    labels:
      up:       "Up"
      down:     "Down"
      left:     "Left"
      right:    "Right"
      confirm:  "Select"
      cancel:   "Close"
      save:     "Save"
      backspace: "Delete"
      tab_next: "Next category"
      tab_prev: "Previous category"

  editor:
    description: "Text editor keybindings (passthrough to Symfony TUI EditorWidget)"
    bindings:
      # These override EditorWidget defaults at the config level
      copy:            []
      new_line:        [shift+enter, alt+enter]
      submit:          [enter]
    labels:
      new_line:  "New line"
      submit:    "Send message"
      copy:      "Copy"

# Sequence timeout in milliseconds (for multi-key bindings like "g g")
sequence_timeout_ms: 500
```

### 5.2 User Overrides (`~/.kosmokrator/keybindings.yaml`)

```yaml
# User keybinding overrides — only specify what you want to change
contexts:
  normal:
    bindings:
      # Use Ctrl+P/N for history scroll (emacs muscle memory)
      history_up:    [ctrl+p, page_up]
      history_down:  [ctrl+n, page_down]
      # Remap help to Ctrl+H
      help:          [ctrl+h]
```

### 5.3 Project Overrides (`.kosmokrator/keybindings.yaml`)

```yaml
# Project-specific keybindings — checked into repo for team consistency
contexts:
  normal:
    bindings:
      # Team convention: Ctrl+J opens agent dashboard instead of Ctrl+A
      agents_panel: [ctrl+j]
```

### 5.4 Merge Semantics

Merging follows the same pattern as the existing `ConfigLoader`:

```
Final config = defaults ← user overrides ← project overrides
```

- **Array values** (key lists) are **replaced**, not merged. If a user specifies `history_up: [ctrl+p]`, they get only `ctrl+p` — the default `page_up` is removed.
- **New actions** can be added. If a user adds `debug_dump: [ctrl+shift+d]`, it's available.
- **Setting `null` or `[]`** unbinds an action entirely.

## 6. Context Resolution

### 6.1 Context Stack

At any point in time, the TUI has an **active context** determined by UI state:

```
Context priority (first match wins):
1. completion  — if slash/power/skill completion dropdown is visible
2. dashboard   — if SwarmDashboardWidget is focused
3. modal       — if a modal dialog (PermissionPrompt, PlanApproval) is open
4. settings    — if SettingsWorkspaceWidget is focused
5. normal      — default, when the prompt editor has focus
```

The `editor` context is always active in parallel — EditorWidget processes its own keybindings internally. The registry only intercepts the actions that KosmoKrator adds on top of the base editor behavior (like `cycle_mode`, `history_up`).

### 6.2 Context Provider Interface

```php
// src/UI/Tui/Input/ContextProvider.php
namespace Kosmokrator\UI\Tui\Input;

interface ContextProvider
{
    /**
     * Return the name of the currently active keybinding context.
     */
    public function getActiveContext(): string;
}
```

The main `TuiCoreRenderer` (or a dedicated `TuiFocusManager` if one is introduced) implements this interface by inspecting widget focus state.

## 7. Multi-Key Sequence Design

### 7.1 How It Works

```
User presses "g"
  → SequenceTracker.feed("g", "normal", registry)
  → registry.hasSequencePrefix("normal", ["g"]) → true (e.g., "g g" → goto_top)
  → Returns ['type' => 'pending']
  → UI shows "g..." in status bar to indicate pending sequence

User presses "g" (within 500ms)
  → SequenceTracker.feed("g", "normal", registry)
  → registry.resolveSequence("normal", ["g", "g"]) → "goto_top"
  → Returns ['type' => 'resolved', 'sequence' => ["g","g"], 'action' => 'goto_top']
  → Action dispatched

User presses "j" instead (not a valid continuation)
  → SequenceTracker.feed("j", "normal", registry)
  → registry.hasSequencePrefix("normal", ["g","j"]) → false
  → Returns ['type' => 'timeout']
  → The "g" is discarded, "j" is processed as a normal key
```

### 7.2 YAML Representation

Multi-key sequences use space-separated key IDs in quotes:

```yaml
bindings:
  goto_top:    ["g g"]         # press g, then g
  goto_bottom: ["G"]           # single key (shift+g)
  delete_line: ["d d"]         # press d, then d
```

The parser splits on spaces within a binding string. `"g g"` becomes `["g", "g"]`.

### 7.3 Limitations

- **Max depth**: 2 keys. Three-key sequences (like vim's `d i w`) are not supported in v1. The sequence parser can be extended later.
- **No count prefix**: `3dd` (vim's count+operator) is not supported. This is out of scope for KosmoKrator's use case.
- **Timeout only**: No "leader key" mode where you explicitly enter/exit sequence mode. Sequences resolve purely on timeout + prefix matching.

## 8. Conflict Detection

### 8.1 Algorithm

For each context, build a trie of all key IDs per action. A conflict exists when:

1. **Single-key overlap**: Two different actions in the same context share any key ID.
2. **Sequence prefix collision**: A single-key binding is a prefix of a multi-key sequence. Example: if `g` is bound to `goto_line` AND `g g` is bound to `goto_top`, pressing `g` is ambiguous.

### 8.2 Reporting

```php
/**
 * @return list<Conflict>
 */
public function detectConflicts(): array
{
    $conflicts = [];
    foreach ($this->contexts as $context) {
        $keyToAction = [];
        foreach ($context->getBindings() as $action => $keyIds) {
            foreach ($keyIds as $keyId) {
                if (isset($keyToAction[$keyId])) {
                    $conflicts[] = new Conflict($context->name, $keyToAction[$keyId], $action, $keyId);
                }
                $keyToAction[$keyId] = $action;
            }
        }
    }
    return $conflicts;
}
```

Conflicts are logged as warnings at startup. They do not prevent the application from running — first-registered wins.

## 9. Help Display Integration

### 9.1 Status Bar

The status bar currently shows: `Edit · Guardian ◈ · 12k/200k · model-name`

After the refactor, it also shows a **keybinding hint** that changes based on context:

```
Edit · Guardian ◈ · 12k/200k · model-name · ⇧Tab mode · PgUp↑/PgDn↓ · F1 help
```

The `HelpGenerator::statusBarHint()` method generates this string. It uses the `ContextProvider` to know which context is active.

### 9.2 Help Overlay (F1 / Ctrl+G)

Pressing F1 or Ctrl+G renders a full-screen help overlay listing all keybindings for the current context:

```
┌─ Keybindings ─ Normal Mode ────────────────────────────┐
│                                                         │
│  Navigation                                             │
│  ─────────────────────────────────────────────────────  │
│  PgUp          Scroll up                                │
│  PgDn          Scroll down                              │
│  End           Jump to live output                      │
│  g g           Jump to top of conversation              │
│  G             Jump to live output                      │
│                                                         │
│  Mode & Panels                                          │
│  ─────────────────────────────────────────────────────  │
│  ⇧Tab          Cycle mode (edit → plan → ask)           │
│  Ctrl+O        Toggle tool results                      │
│  Ctrl+L        Force screen refresh                     │
│  Ctrl+A        Agent dashboard                          │
│                                                         │
│  Esc / Ctrl+C  Cancel / Quit                            │
│  ↵             Send message                             │
│  ⇧↵            New line                                 │
│                                                         │
│  Press Esc to close                                     │
└─────────────────────────────────────────────────────────┘
```

## 10. Migration Plan

### Phase 1: Build the Registry (non-breaking)

Create all new classes without changing existing code:

| File | Status |
|------|--------|
| `src/UI/Tui/Input/KeybindingRegistry.php` | **New** |
| `src/UI/Tui/Input/KeybindingContext.php` | **New** |
| `src/UI/Tui/Input/SequenceTracker.php` | **New** |
| `src/UI/Tui/Input/Conflict.php` | **New** |
| `src/UI/Tui/Input/HelpGenerator.php` | **New** |
| `src/UI/Tui/Input/KeybindingLoader.php` | **New** |
| `src/UI/Tui/Input/ContextProvider.php` | **New** |
| `config/keybindings.yaml` | **New** — bundled defaults |

Write unit tests for:
- `KeybindingRegistry::resolve()` — single keys
- `KeybindingRegistry::resolveSequence()` — multi-key
- `SequenceTracker::feed()` — pending → resolved → timeout flows
- `Conflict` detection — overlapping single keys, sequence prefix collisions
- `HelpGenerator::formatKey()` — all key ID formats
- `KeybindingLoader::validate()` — invalid YAML structures

### Phase 2: Wire Registry into TuiCoreRenderer

Modify `TuiCoreRenderer` to:

1. Create `KeybindingRegistry` in `initialize()`
2. Load defaults + user overrides via `KeybindingLoader`
3. Run `detectConflicts()` and log warnings
4. Pass registry to `TuiInputHandler` (new constructor parameter)

**No behavioral change yet** — the registry runs in parallel but existing hardcoded checks still function.

| File | Change |
|------|--------|
| `src/UI/Tui/TuiCoreRenderer.php` | Add `KeybindingRegistry` construction in `initialize()` |
| `src/UI/Tui/TuiInputHandler.php` | Add `KeybindingRegistry` constructor parameter |

### Phase 3: Replace Hardcoded Keys in TuiInputHandler

Migrate each raw byte comparison and `$kb->matches()` call in `TuiInputHandler::handleInput()` to use the registry:

| Current code | New code |
|-------------|---------|
| `$data === "\x01"` (Ctrl+A) | `$registry->resolve('normal', $keyId) === 'agents_panel'` |
| `$data === "\x0C"` (Ctrl+L) | `$registry->resolve('normal', $keyId) === 'force_render'` |
| `$data === "\x1b"` (Escape in completion) | `$registry->resolve('completion', $keyId) === 'cancel'` |
| `$data === "\t"` (Tab in completion) | `$registry->resolve('completion', $keyId) === 'tab_complete'` |
| `$kb->matches($data, 'history_up')` | `$registry->resolve('normal', $keyId) === 'history_up'` |
| `$kb->matches($data, 'cycle_mode')` | `$registry->resolve('normal', $keyId) === 'cycle_mode'` |
| `$kb->matches($data, 'expand_tools')` | `$registry->resolve('normal', $keyId) === 'expand_tools'` |

Key change: `handleInput()` receives the **parsed key ID** (string like `"ctrl+a"`) instead of raw bytes. The `KeyParser` parsing moves one level up.

| File | Change |
|------|--------|
| `src/UI/Tui/TuiInputHandler.php` | Rewrite `handleInput()` to use registry + key IDs |

### Phase 4: Migrate Widget Keybindings

Move `getDefaultKeybindings()` from each custom widget into the registry:

| Widget | Current bindings | Registry context |
|--------|-----------------|------------------|
| `SwarmDashboardWidget` | `cancel → [escape, ctrl+c]` | `dashboard` |
| `PermissionPromptWidget` | `up/down/confirm/cancel` | `modal` |
| `PlanApprovalWidget` | `up/down/left/right/confirm/cancel` | `modal` |
| `SettingsWorkspaceWidget` | `up/down/left/right/confirm/cancel/save/backspace` | `settings` |
| `HistoryStatusWidget` | display-only (key hints) | `normal` |

Each widget keeps its `getDefaultKeybindings()` for Symfony TUI compatibility but now delegates to the registry when available:

```php
protected static function getDefaultKeybindings(): array
{
    // If a registry is available via context, use its bindings
    $contextBindings = static::getRegistryBindings('modal');
    if ($contextBindings !== null) {
        return $contextBindings;
    }
    // Fallback to hardcoded defaults
    return [
        'up' => [Key::UP],
        'down' => [Key::DOWN],
        // ...
    ];
}
```

### Phase 5: EditorWidget Keybinding Delegation

Move the EditorWidget keybindings from `TuiCoreRenderer::initialize()`:

```php
// Before (inline in TuiCoreRenderer)
$this->input->setKeybindings(new Keybindings([
    'copy' => [],
    'new_line' => ['shift+enter', 'alt+enter'],
    'cycle_mode' => ['shift+tab'],
    'history_up' => [Key::PAGE_UP],
    'history_down' => [Key::PAGE_DOWN],
    'history_end' => [Key::END],
]));
```

```php
// After (from registry)
$editorKb = $registry->getKeybindingsForContext('editor');
$this->input->setKeybindings($editorKb);
```

The `editor` context in `keybindings.yaml` only specifies the overrides. The `KeybindingRegistry::getKeybindingsForContext()` method merges these with Symfony's `EditorWidget::getDefaultKeybindings()`.

### Phase 6: Help Overlay & Status Bar Integration

1. Add `HelpGenerator` integration to the status bar rendering in `TuiCoreRenderer::refreshStatusBar()`
2. Implement the help overlay as a new widget (or reuse `ContainerWidget` + `TextWidget`)
3. Bind F1/Ctrl+G to toggle the help overlay in the `normal` context

### Phase 7: User Config Discovery

1. Add `keybindings.yaml` to `~/.kosmokrator/` auto-detection in `KeybindingLoader`
2. Add `.kosmokrator/keybindings.yaml` to project config discovery
3. Document the keybinding config format in README / help command
4. Add `/keybindings` slash command to show current keybindings and config path

## 11. Current Keybinding Inventory

All keybindings that must be migrated, catalogued from the codebase:

### TuiCoreRenderer (inline in `initialize()`)

| Action | Key(s) | Location |
|--------|--------|----------|
| `copy` | *(disabled)* | `TuiCoreRenderer.php:238` |
| `new_line` | `shift+enter`, `alt+enter` | `TuiCoreRenderer.php:239` |
| `cycle_mode` | `shift+tab` | `TuiCoreRenderer.php:240` |
| `history_up` | `page_up` | `TuiCoreRenderer.php:241` |
| `history_down` | `page_down` | `TuiCoreRenderer.php:242` |
| `history_end` | `end` | `TuiCoreRenderer.php:243` |

### TuiInputHandler (raw byte comparisons)

| Action | Raw byte | Key | Context | Location |
|--------|----------|-----|---------|----------|
| Completion navigate | — | `up`, `down` | completion | `TuiInputHandler.php:155` |
| Completion confirm | — | `enter` | completion | `TuiInputHandler.php:161` |
| Completion tab | `\t` | `tab` | completion | `TuiInputHandler.php:181` |
| Completion cancel | `\x1b` | `escape` | completion | `TuiInputHandler.php:196` |
| Agents panel | `\x01` | `ctrl+a` | normal | `TuiInputHandler.php:203` |
| Scroll up | — | `page_up` | normal | `TuiInputHandler.php:212` |
| Scroll down | — | `page_down` | normal | `TuiInputHandler.php:218` |
| Jump to live | — | `end` | normal (while browsing) | `TuiInputHandler.php:224` |
| Force render | `\x0C` | `ctrl+l` | normal | `TuiInputHandler.php:230` |
| Expand tools | — | `ctrl+o` | normal | `TuiInputHandler.php:236` |
| Cycle mode | — | `shift+tab` | normal | `TuiInputHandler.php:242` |

### EditorWidget (Symfony defaults, active during editing)

| Action | Key(s) | Location |
|--------|--------|----------|
| `cursor_up` | `up` | `EditorWidget.php:539` |
| `cursor_down` | `down` | `EditorWidget.php:540` |
| `cursor_left` | `left`, `ctrl+b` | `EditorWidget.php:541` |
| `cursor_right` | `right`, `ctrl+f` | `EditorWidget.php:542` |
| `cursor_word_left` | `alt+left`, `ctrl+left`, `alt+b` | `EditorWidget.php:543` |
| `cursor_word_right` | `alt+right`, `ctrl+right`, `alt+f` | `EditorWidget.php:544` |
| `cursor_line_start` | `home`, `ctrl+a` | `EditorWidget.php:545` |
| `cursor_line_end` | `end`, `ctrl+e` | `EditorWidget.php:546` |
| `jump_forward` | `ctrl+]` | `EditorWidget.php:547` |
| `jump_backward` | `ctrl+alt+]` | `EditorWidget.php:548` |
| `page_up` | `page_up` | `EditorWidget.php:549` |
| `page_down` | `page_down` | `EditorWidget.php:550` |
| `delete_char_backward` | `backspace`, `shift+backspace` | `EditorWidget.php:553` |
| `delete_char_forward` | `delete`, `ctrl+d`, `shift+delete` | `EditorWidget.php:554` |
| `delete_word_backward` | `ctrl+w`, `alt+backspace` | `EditorWidget.php:555` |
| `delete_word_forward` | `alt+d`, `alt+delete` | `EditorWidget.php:556` |
| `delete_line` | `ctrl+shift+k` | `EditorWidget.php:557` |
| `delete_to_line_start` | `ctrl+u` | `EditorWidget.php:558` |
| `delete_to_line_end` | `ctrl+k` | `EditorWidget.php:559` |
| `insert_space` | `shift+space` | `EditorWidget.php:562` |
| `new_line` | `shift+enter` | `EditorWidget.php:563` |
| `submit` | `enter` | `EditorWidget.php:564` |
| `select_cancel` | `escape`, `ctrl+c` | `EditorWidget.php:565` |
| `copy` | `ctrl+c` | `EditorWidget.php:568` |
| `yank` | `ctrl+y` | `EditorWidget.php:571` |
| `yank_pop` | `alt+y` | `EditorWidget.php:572` |
| `undo` | `ctrl+-` | `EditorWidget.php:575` |
| `redo` | `ctrl+shift+z` | `EditorWidget.php:576` |
| `expand_tools` | `ctrl+o` | `EditorWidget.php:579` |

### Custom Widgets

| Widget | Action | Key(s) | Location |
|--------|--------|--------|----------|
| `SwarmDashboardWidget` | `cancel` | `escape`, `ctrl+c` | `SwarmDashboardWidget.php:247` |
| `SwarmDashboardWidget` | *(inline)* `q` | `q` | `SwarmDashboardWidget.php:61` |
| `SwarmDashboardWidget` | *(inline)* `ctrl+a` | `ctrl+a` | `SwarmDashboardWidget.php:61` |
| `PermissionPromptWidget` | `up` | `up` | `PermissionPromptWidget.php:169` |
| `PermissionPromptWidget` | `down` | `down` | `PermissionPromptWidget.php:170` |
| `PermissionPromptWidget` | `confirm` | `enter` | `PermissionPromptWidget.php:171` |
| `PermissionPromptWidget` | `cancel` | `escape`, `ctrl+c` | `PermissionPromptWidget.php:172` |
| `PlanApprovalWidget` | `up` | `up` | `PlanApprovalWidget.php:221` |
| `PlanApprovalWidget` | `down` | `down` | `PlanApprovalWidget.php:222` |
| `PlanApprovalWidget` | `left` | `left` | `PlanApprovalWidget.php:223` |
| `PlanApprovalWidget` | `right` | `right` | `PlanApprovalWidget.php:224` |
| `PlanApprovalWidget` | `confirm` | `enter` | `PlanApprovalWidget.php:225` |
| `PlanApprovalWidget` | `cancel` | `escape`, `ctrl+c` | `PlanApprovalWidget.php:226` |
| `SettingsWorkspaceWidget` | `up` | `up` | `SettingsWorkspaceWidget.php:429` |
| `SettingsWorkspaceWidget` | `down` | `down` | `SettingsWorkspaceWidget.php:430` |
| `SettingsWorkspaceWidget` | `left` | `left` | `SettingsWorkspaceWidget.php:431` |
| `SettingsWorkspaceWidget` | `right` | `right` | `SettingsWorkspaceWidget.php:432` |
| `SettingsWorkspaceWidget` | `confirm` | `enter` | `SettingsWorkspaceWidget.php:433` |
| `SettingsWorkspaceWidget` | `cancel` | `escape`, `ctrl+c` | `SettingsWorkspaceWidget.php:434` |
| `SettingsWorkspaceWidget` | `save` | `ctrl+s` | `SettingsWorkspaceWidget.php:435` |
| `SettingsWorkspaceWidget` | `backspace` | `backspace` | `SettingsWorkspaceWidget.php:436` |
| `SettingsWorkspaceWidget` | *(inline)* `tab`/`shift+tab` | `tab`, `shift+tab` | `SettingsWorkspaceWidget.php:164,234` |
| `SettingsWorkspaceWidget` | *(inline)* `s` | `s` | `SettingsWorkspaceWidget.php:240` |

### Existing Conflict

> ⚠️ **`ctrl+a`** is bound to **both** `cursor_line_start` (EditorWidget) and `agents_panel` (TuiInputHandler). The current code resolves this because `TuiInputHandler::handleInput()` intercepts the raw byte `\x01` **before** the EditorWidget processes it. After migration, context resolution will handle this: in `normal` context the registry intercepts `ctrl+a` for `agents_panel`; in `editor` context (when the editor handles its own keybindings) `ctrl+a` moves to line start. The prompt's onInput callback returning `true` is the current mechanism — the registry preserves this by handling the `normal` context first.

> ⚠️ **`ctrl+c`** is bound to **both** `copy` (EditorWidget) and `cancel` (multiple widgets). KosmoKrator currently disables copy via `'copy' => []` in the EditorWidget keybindings override. The registry will maintain this disable.

> ⚠️ **`ctrl+o`** is bound to **both** `expand_tools` (EditorWidget) and `expand_tools` (TuiInputHandler). This is the same action — the EditorWidget binding is for when the editor is unfocused (it doesn't apply).

## 12. Testing Strategy

| Test | What it covers |
|------|----------------|
| `KeybindingRegistryTest` | Register contexts, resolve single keys, resolve sequences, merge overrides, detect conflicts |
| `SequenceTrackerTest` | Pending → resolved, pending → timeout, reset on escape, configurable timeout |
| `HelpGeneratorTest` | Format key IDs, generate status bar hints, generate help overlay data |
| `KeybindingLoaderTest` | Parse YAML defaults, merge user overrides, validate invalid config |
| `ConflictTest` | Detect single-key overlap, sequence prefix collision |
| Integration test | Full flow: load YAML → register contexts → feed key events → dispatch actions |

## 13. File Layout

```
src/UI/Tui/Input/
├── KeybindingRegistry.php      # Central registry
├── KeybindingContext.php        # Value object for a context
├── SequenceTracker.php          # Multi-key sequence state machine
├── Conflict.php                 # Value object for conflict reports
├── HelpGenerator.php            # Help text generation
├── KeybindingLoader.php         # YAML config loading
├── ContextProvider.php          # Interface for active context resolution

config/
└── keybindings.yaml             # Bundled defaults

tests/UI/Tui/Input/
├── KeybindingRegistryTest.php
├── SequenceTrackerTest.php
├── HelpGeneratorTest.php
├── KeybindingLoaderTest.php
└── ConflictTest.php
```

## 14. Open Questions

1. **Should the registry own the KeyParser?** Currently `KeyParser` lives on the Symfony `Keybindings` object. The registry needs a parser to produce `Keybindings` instances for widgets. Recommendation: yes, the registry holds one `KeyParser` and shares it.

2. **Should widget keybindings go through the registry or stay in `getDefaultKeybindings()`?** Recommendation: hybrid. Widgets that are KosmoKrator-specific (PermissionPrompt, PlanApproval, Settings, SwarmDashboard) use the registry. Symfony-provided widgets (EditorWidget, SelectListWidget) continue using `getDefaultKeybindings()` with overrides injected via `setKeybindings()`.

3. **Runtime reload?** Should `/keybindings reload` reload from disk? Low priority for v1 but the architecture supports it — just call `KeybindingLoader` again and `loadUserOverrides()`.

4. **Keybinding profiles?** Could support named profiles (e.g., `vim`, `emacs`, `default`) that swap entire binding sets. Out of scope for v1 but the context structure supports it.

5. **Macro recording?** Recording a sequence of actions and binding to a key. Out of scope for v1.
