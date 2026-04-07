# UX Audit: Settings Workspace

**Date:** 2026-04-07  
**Auditor:** UX Research  
**Research question:** *How good is the settings experience in KosmoKrator's TUI?*  
**Files reviewed:**
- `src/UI/Tui/Widget/SettingsWorkspaceWidget.php` — ~1966-line full-screen settings editor widget
- `src/Settings/SettingsManager.php` — layered YAML-backed settings persistence (project → global → default)
- `src/Settings/SettingsSchema.php` — 11 categories, ~30 setting definitions with types, effects, defaults
- `src/Session/SettingsRepository.php` — SQLite-scoped key-value fallback store

---

## Executive Summary

KosmoKrator's settings workspace is **architecturally ambitious but interactionally bloated**. It attempts to be a full-featured configuration editor inside a terminal — category navigation, field editing, inline pickers, a models browser, provider setup with custom-provider creation, auth management, and a live YAML preview — all crammed into a single 1966-line widget. The two-column layout is sound in principle, but the experience is undermined by: **too many categories** (11 for ~30 settings), **inconsistent interaction modes** (picker vs. browser vs. form — each with different keybindings), **hidden functionality** (custom provider creation requires pressing `a`), and **no search** in a world where VS Code users reach for the search bar first.

**Overall grade: C+** — the data model and persistence layer are solid; the presentation and interaction design need fundamental restructuring around progressive disclosure.

---

## 1. Two-Column Layout Effectiveness

### Current layout

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ⚙ Settings  scope: project  provider: OpenAI  model: GPT-4.1  Saved    │
│ Separate settings workspace. Save writes YAML-backed config...           │
│ ──────────────────────────────────────────────────────────────────────── │
│ ┌ Categories ──────┐  ┌ Context & Memory ──────────────────────────────┐ │
│ │• General         │  │› Memories                    on          ▾     │ │
│ │  Models          │  │  Auto compact               on          ▾     │ │
│ │  Provider Setup  │  │  Compact threshold           60                │ │
│ │  Auth            │  │  Reserved output tokens      16000             │ │
│ │• Context & Memory│  │  Warning buffer              24000             │ │
│ │  Agent           │  │  Auto compact buffer         12000             │ │
│ │  Permissions     │  │  Blocking buffer             3000              │ │
│ │  Integrations    │  │  Prune protect               40000             │ │
│ │  Subagents       │  │  Prune minimum savings       20000             │ │
│ │  Advanced        │  │                                              │ │
│ │  Audio           │  │                                              │ │
│ └──────────────────┘  └──────────────────────────────────────────────┘ │
│                                                                          │
│ ┌ Details ────────────────────────────────────────────────────────────┐ │
│ │ Enable memory recall and persistence features.                       │ │
│ │ Source: default  Effect: next_turn                                   │ │
│ └──────────────────────────────────────────────────────────────────────┘ │
│ Tab/Shift+Tab category  ↑↓ fields/list  → open list  ...  r reset      │
└──────────────────────────────────────────────────────────────────────────┘
```

### Assessment

| Aspect | Grade | Notes |
|--------|-------|-------|
| Category + fields split | **B** | Standard pattern, works well for TUI. Helix uses the same two-pane approach in its picker. |
| Details panel at bottom | **B+** | Good idea — shows context without switching modes. VS Code does this in a sidebar too. |
| Proportions | **C** | Categories column at 22% width is tight. 11 category names don't all fit on screen without scrolling. |
| Header density | **C** | Three-line header wastes vertical space. The second line ("Separate settings workspace...") is a meta-description that adds no value during active use. |

### Comparison: Helix

Helix uses a **single-column picker** with `:set` and space-prefixed key sequences. No category sidebar — just a searchable list. This is faster for known settings but worse for discovery. KosmoKrator's two-column approach is better for discovery, but the 11 categories over-fragment ~30 settings.

### Comparison: Lazygit

Lazygit has **no in-app settings UI**. Configuration lives entirely in a YAML file with extensive inline comments. This is the "power user" approach — zero UI complexity, maximum flexibility, but zero discoverability for new users.

### Recommendation

Reduce from 11 categories to 4–5 groups. Merge single-setting categories (Auth, Integrations, Advanced) into their logical parents. The details panel is worth keeping.

---

## 2. Category Organization

### Current categories (from `SettingsSchema::categories()`)

| Category | Settings count | Quality |
|----------|---------------|---------|
| `general` | 3 (Renderer, Theme, Intro animation) | ✅ Good grouping |
| `models` | 2 (Default provider, Default model) | ⚠️ Redundant with Models browser |
| `provider_setup` | 8+ fields (dynamic, conditional visibility) | 🔴 Complex — browser + form hybrid |
| `auth` | 0 visible fields (handled inside provider_setup) | 🔴 Empty category |
| `context_memory` | 8 (memories, auto_compact, thresholds, buffers) | ⚠️ Too many similar number fields |
| `agent` | 5 (mode, temperature, max_tokens, retries, reasoning) | ✅ Good |
| `permissions` | 1 (Permission mode) | 🔴 Single-setting category |
| `integrations` | 0 visible fields in schema | 🔴 Empty category |
| `subagents` | 6 (provider, model × depth + depth2 + concurrency/depth/retries/watchdog) | ⚠️ Excessive depth granularity |
| `advanced` | 0 visible fields in schema | 🔴 Empty category |
| `audio` | 6 (completion_sound, soundfont, timeouts, retries) | ✅ Good grouping |

**4 out of 11 categories are empty or single-setting.** This means users tab through empty sections, which creates the impression of a larger system than actually exists.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **Ghost categories** | 🔴 High | Auth, Integrations, Advanced appear in the nav but have no fields defined in the schema. User clicks and sees an empty box. This violates the "no dead ends" principle. |
| **Models vs. Provider Setup overlap** | 🔴 High | The "Models" category shows a provider+model tree browser. "Provider Setup" also lets you select a provider and configure it. These are two paths to the same destination. Users won't know which to use. |
| **Number fatigue in Context & Memory** | 🟡 Medium | Six nearly-identical number fields (threshold, buffer, reserve, warning, compact, blocking, prune). Only expert users know the difference between `warning_buffer_tokens` (24000) and `auto_compact_buffer_tokens` (12000). These should have grouped sub-labels or be collapsed under an "Advanced" toggle. |
| **Subagent depth² naming** | 🟡 Medium | `subagent_depth2_provider` and `subagent_depth2_model` — the "depth2" naming is cryptic. Schema says "depth-2+ subagents" but the UI just says "Sub-subagent provider". This is confusing. |

### Comparison: VS Code

VS Code organizes settings into ~15 top-level groups, but each group has 10–50 settings. The search bar is the primary navigation mechanism. Users rarely browse categories — they search. VS Code also shows "Commonly Used" as the default view.

### Comparison: Vim

Vim's `:set` system has no categories at all. Users type `:set` + tab-completion or `:help options` for a flat, searchable list grouped by topic in the help docs. The key insight: **categories belong in documentation, not in the interaction surface.**

---

## 3. Setting Clarity

### Description quality

Each setting in `SettingsSchema` has a `description` string. Reviewing quality:

| Setting | Description | Grade |
|---------|-------------|-------|
| Renderer | "Preferred renderer for KosmoKrator sessions." | ✅ Clear |
| Intro animation | "Play the startup animation before opening the REPL." | ✅ Clear |
| Default provider | "Default provider used when a session starts." | ✅ Clear |
| Auto compact | "Compact context automatically before hitting the model limit." | ✅ Clear |
| Compact threshold | "Legacy threshold percentage for compaction fallback." | 🟡 "Legacy" suggests it shouldn't be shown |
| Reserved output tokens | "Headroom reserved for the assistant response." | ⚠️ What units? (tokens, but not stated) |
| Warning buffer | "When remaining input budget drops below this, show warnings." | ⚠️ Jargon: "input budget" |
| Prune protect | "Recent tool-result tokens protected from micro pruning." | 🔴 "Micro pruning" is undefined |
| Prune minimum savings | "Minimum savings required before a prune pass is accepted." | 🔴 "Prune pass" is undefined |
| Reasoning effort | "Controls extended thinking/reasoning for supported models. Off disables reasoning params entirely." | ✅ Good |
| Idle watchdog seconds | "Cancel only when a running subagent stops making progress updates for too long. Set 0 to disable." | ✅ Clear, includes disable instruction |

### Effect indicators

Each setting has an `effect` field: `applies_now`, `next_turn`, or `next_session`. This is shown in the Details panel as "Effect: next_session". This is good — it tells users whether to expect immediate feedback. However:

- The indicator is **text-only** in the details panel. A visual badge (🟢 now, 🟡 next turn, 🔴 restart) would be faster to scan.
- Settings that require a restart don't prevent the user from expecting immediate changes.

### Comparison: Helix

Helix's TOML config has inline comments with examples:
```toml
# Number of lines of command output to show in the picker
# Default: 10
bufferline = "multiple"  # "never" | "always" | "multiple"
```

KosmoKrator's descriptions are shorter and don't include examples or valid ranges. Adding `"Default: 60"` or `"Range: 0–100"` to the details panel would help.

---

## 4. Value Editing: Cycling vs. Typing

### Current mechanism

The widget has **three distinct editing modes**:

1. **Inline picker** (for `choice`, `toggle`, `dynamic_choice` fields) — overlays a scrollable, filterable list over the fields column. Activated with `→` or `Enter`.
2. **Text editing** (for `text`, `number` fields) — inline cursor editing with `editBuffer`. Activated with `Enter`.
3. **Browser modes** (for Models and Provider Setup) — replace the fields column entirely with a tree/list browser.

### Picker assessment

```
┌ Select Default model ────────────────────────────┐
│› GPT-4.1                            gpt-4.1      │
│  GPT-4.1 mini                       gpt-4.1-mini │
│  GPT-4.1 mini                       gpt-4.1-mini │
│  o3                                  o3           │
│  o4-mini                             o4-mini      │
│                                                   │
│                                                   │
└───────────────────────────────────────────────────┘
```

| Aspect | Grade | Notes |
|--------|-------|-------|
| Type-to-filter | **A** | Fuzzy filtering by label + value + description. Excellent. |
| Scroll centering | **A** | Window centers around the selected index. Smooth. |
| Visual feedback | **B** | Selected item gets `›` cursor. Could use color inversion for stronger contrast. |
| Escape behavior | **B** | First Esc clears the query; second Esc closes the picker. Good two-level undo. |
| Tab-while-picker | **B-** | Tab inside the picker cycles category *and closes the picker*. This is surprising — Tab usually means "next field". |

### Text editing assessment

Text editing uses a simple append buffer. Key problems:

| Issue | Severity | Detail |
|-------|----------|--------|
| **No cursor positioning** | 🔴 High | The buffer is append-only with backspace at end. Users can't move the cursor to fix a typo in the middle of a URL. This is painful for long values like provider URLs. |
| **No paste handling** | 🟡 Medium | `normalizeEditInput()` strips bracketed paste sequences. This works but means pasting a 60-char API key is a fragile operation. |
| **Wide editor confusion** | 🟡 Medium | Fields with `usesWideEditor()` show "editing below" in the field list and the actual editing happens in the details panel. This split-brain editing is disorienting. |
| **No validation** | 🟡 Medium | Number fields accept any text. URL fields accept non-URLs. The save goes through `normalizeValue()` which does minimal coercion. |

### Comparison: Vim's `:set`

Vim cycles through boolean values with `:set option!` (toggle) and allows `:set option=value` for strings. It also supports `:set option+=value` (append) and `:set option-=value` (remove). The key insight is **both cycling AND direct entry** are available for the same setting. KosmoKrator forces you into one mode based on field type.

### Comparison: VS Code

VS Code uses **inline dropdowns** for enum settings and **text fields** for strings, with a search/filter on dropdowns. The key difference: the dropdown appears *inline* in the same list, not as an overlay that replaces the entire fields column. This preserves spatial context.

---

## 5. Model/Provider Setup Flow

### Current flow

There are **two separate paths** to configure a model:

**Path A: Models category** → flat list of providers and models → select one
**Path B: Provider Setup category** → select a provider from a list → enter a form → edit provider fields (status, auth, driver, URL, API key, custom fields)

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **Two paths, one goal** | 🔴 Critical | Users must choose between "Models" and "Provider Setup". For 90% of users who just want to pick a model, the Models browser is fine. But if auth fails, they need to navigate to Provider Setup. The relationship between the two is never explained. |
| **Provider Setup has two sub-modes** | 🔴 High | Within Provider Setup, there's a *browser* (list of providers) and an *editing form* (fields). Left arrow goes back to the browser. This is a nested navigation that isn't communicated. The footer changes but the visual transition is subtle. |
| **Auth status is buried** | 🟡 Medium | The header shows "provider: OpenAI" but not auth status. You must navigate to Provider Setup to see if your API key is valid. |
| **Model reset on provider change** | 🟡 Medium | `handleFieldSideEffects()` resets the model when the provider changes. This is correct behavior, but there's no confirmation or undo. A user switching providers temporarily loses their model selection. |
| **Free-text providers aren't obvious** | 🟡 Medium | Some providers (like OpenRouter) support "any model" via free-text entry. The details panel explains this, but the field still shows a picker with `▾`. It should show a text input for free-text providers. |

### Comparison: Lazygit

Lazygit has no provider concept — it wraps git. Not directly comparable.

### Comparison: Helix

Helix's `languages.toml` file lets you configure language servers. The structure is hierarchical: language → server → command, args. Helix doesn't have a provider/model split because it's not an AI tool. But the **file-based approach** means users can see the full configuration at a glance.

### Recommended flow

```
Provider + Model should be a single unified screen, not two categories.
The "quick pick" (Models browser) should be the default.
Provider Setup should be accessible as a detail/advanced view.
```

---

## 6. Custom Provider Setup

### Current flow

1. Navigate to "Provider Setup" category
2. See a list of built-in providers + "Custom (new)" option
3. Press `a` to jump to a new custom provider draft (or select "Custom (new)")
4. Fill in: ID, Label, Driver, Auth, URL, Default model, Model ID, Context, Max output, Input/Output modalities
5. The Details panel shows a live YAML preview
6. Press `s` or `q` to save

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **`a` key is undiscoverable** | 🔴 Critical | The only way to create a new custom provider quickly is pressing `a`. This is mentioned in the footer as "a new custom" — but footers are the least-read UI element. There is no visible "Add Custom Provider" button or empty state prompting creation. |
| **Too many fields for a new provider** | 🔴 High | Creating a custom provider requires filling in 11 fields (ID, label, driver, auth, URL, default model, model ID, context, max output, input modalities, output modalities). Most users only know the URL and API key. The rest require understanding OpenAI-compatible API structure. |
| **No field validation or required indicators** | 🔴 High | `buildCustomProvider()` silently returns `null` if `provider_id` or `model_id` are empty. There's no inline validation, no "required field" indicator, and no error message. The user presses save and... nothing visible happens if the provider is incomplete. |
| **Auto-generated IDs are opaque** | 🟡 Medium | `nextCustomProviderId()` generates `custom_1`, `custom_2`, etc. These become YAML keys and provider identifiers. They're not human-readable in the config file. |
| **Model creation is coupled** | 🟡 Medium | A custom provider *must* define at least one model inline. This conflation of provider and model setup is confusing. Lazygit and Helix both keep provider/connection config separate from feature/model config. |
| **Delete requires `x` key** | 🟡 Medium | Deleting a custom provider requires pressing `x` on a custom provider. Another hidden single-key command. No confirmation dialog. |
| **No URL validation** | 🟡 Medium | The URL field is a text input. No validation that the entered text is a valid URL, or that the endpoint responds. |

### Comparison: VS Code

VS Code's "Open User Settings (JSON)" mode lets advanced users add custom settings by editing JSON directly, with schema validation and autocomplete. KosmoKrator's YAML preview in the details panel is a step in this direction, but it's read-only — you can't edit the YAML directly.

### Comparison: Helix

Helix's `languages.toml` is purely file-based. If you want to add a custom language server, you edit the file. The advantage: full control, copy-paste from docs, version control. The disadvantage: no validation until you restart. KosmoKrator could offer both paths: GUI for guided setup, and "edit config file" for power users.

---

## 7. Navigation and Discoverability

### Keybinding analysis

The widget has **context-dependent keybindings** that change based on mode:

| Mode | Keys | Purpose |
|------|------|---------|
| Field browsing | `↑↓` navigate, `→`/`Enter` edit, `Tab`/`Shift+Tab` cycle categories | Standard |
| Text editing | Type to input, `Enter` save, `Esc` cancel, `Backspace` delete | Standard |
| Picker overlay | `↑↓` navigate, `Enter` select, `Esc` close/clear, type to filter | Good |
| Models browser | `↑↓` browse tree, `Enter` select, same footer as fields | OK |
| Provider Setup browser | `↑↓` browse providers, `Enter`/`→` configure, `←` back | Confusing |
| Provider Setup form | Same as field browsing + `←` back to browser, `r` reset field | OK |
| Global shortcuts | `s` save, `q` save-and-close, `g` global scope, `p` project scope, `r` reset field, `a` add custom, `x` delete custom | Overloaded |

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **`q` as "save and close" is dangerous** | 🔴 Critical | In virtually every TUI tool, `q` means "quit without saving". Here, `q` *saves* if there are changes. A user pressing `q` to cancel will silently save unintended changes. This violates the principle of least astonishment. |
| **`s` and `q` both save** | 🟡 Medium | `s` saves and stays. `q` saves and closes. But `Ctrl+S` is the keybinding for "save" and `s` is a raw key override. Having two save keys with different behaviors is confusing. |
| **No undo** | 🟡 Medium | `r` resets a single field to its original value, but there's no global undo. If a user accidentally changes 5 fields and wants to revert, they must `r` each one individually. |
| **Scope switching is instant and invisible** | 🟡 Medium | Pressing `g` or `p` changes the scope immediately. The scope label updates in the header, but there's no modal confirmation or visual emphasis. A user could accidentally switch to global scope and overwrite system-wide settings. |
| **No search** | 🔴 High | With 30+ settings across 11 categories, there's no way to search for a setting by name. VS Code's settings search is its primary navigation mechanism. Even Vim has `:help` + `/` search. |

### Comparison: VS Code Settings Search

VS Code's settings UI has a search bar that filters settings in real-time across all categories. It searches names, descriptions, and values. This is the single most-used feature of VS Code settings. Its absence in KosmoKrator is the biggest usability gap.

### Comparison: Lazygit

Lazygit's keybinding model is: every screen shows its keybindings in the bottom panel, organized by context. Keys are always shown — never hidden behind modes. KosmoKrator shows keybindings in a footer line, but the footer is truncated on narrow terminals and uses dense text that's hard to scan.

---

## 8. Widget Code Structure Concerns

While this is a UX audit, the code structure directly impacts maintainability and future UX improvements:

| Concern | Location | Impact |
|---------|----------|--------|
| **1966 lines in a single widget** | `SettingsWorkspaceWidget.php` | Adding new features (search, undo, validation) requires understanding the entire file. |
| **16+ private state variables** | Lines 27–75 | The widget manages category index, field index, editing state, edit buffer, picker state, picker query, scope, provider setup state, values map, original values, callbacks, and delete state. This is a state machine with implicit transitions. |
| **Side effects in field changes** | `handleFieldSideEffects()` (585–705) | Changing `agent.default_provider` triggers a cascade of 10+ value resets. This makes the settings feel unpredictable — changing one thing changes others. |
| **No separation of concerns** | N/A | Rendering, input handling, state management, and data building are all in one class. Models browser, Provider Setup, picker, and field editing each deserve their own sub-component. |

---

## 9. Competitive Landscape Summary

| Feature | KosmoKrator | Lazygit | Helix | Vim | VS Code |
|---------|-------------|---------|-------|-----|---------|
| In-app settings UI | ✅ Full TUI | ❌ File only | ❌ File only | ✅ `:set` commands | ✅ Rich GUI |
| Search/filter | ⚠️ Picker only | ❌ | ❌ | ⚠️ `:help` search | ✅ Real-time search |
| Categories | ⚠️ 11 (many empty) | N/A | N/A | ❌ Flat list | ✅ ~15 dense groups |
| Value editing | ⚠️ Picker + text | N/A | N/A | ⚠️ `:set` strings | ✅ Inline dropdowns/text |
| Custom provider setup | ✅ Guided form | N/A | N/A | N/A | N/A |
| Live YAML preview | ✅ Read-only | N/A | N/A | N/A | ⚠️ JSON view |
| Validation | ❌ None | ❌ | ✅ Schema | ❌ | ✅ Schema + inline |
| Save model | ⚠️ Auto on `q`/`s` | File save | File save | Explicit `:mkvimrc` | Auto-save |
| Undo/Revert | ⚠️ Per-field `r` | Git-diffable | File-based | ✅ `:set {option}&` | ✅ Reset button |
| Config scope layers | ✅ Project/global | File only | File only | ✅ `:set` vs vimrc | ✅ Workspace/user |

---

## 10. Recommendations

### R1: Merge categories (Priority: 🔴 High)

**Current:** 11 categories → **Proposed:** 5 categories

```
1. General         → ui.renderer, ui.theme, ui.intro_animated, agent.mode
2. AI Provider     → default_provider, default_model, + provider setup, auth, custom providers
3. Context         → memories, auto_compact, all buffer/threshold settings
4. Agent           → temperature, max_tokens, retries, reasoning_effort, permission_mode
5. Subagents       → subagent_* fields
```

Remove: Auth (empty), Integrations (empty), Advanced (empty). Merge Audio into General. Merge Permissions into Agent. Merge Models + Provider Setup into a single "AI Provider" section.

### R2: Add search (Priority: 🔴 High)

```
┌──────────────────────────────────────────────────────────────┐
│ ⚙ Settings                          🔍 [_filter___________] │
│                                                              │
│ ┌──────────────────────────────────────────────────────────┐ │
│ │  3 matches for "compact"                                 │ │
│ │  › Auto compact                            on       ▾   │ │
│ │    Compact threshold                       60           │ │
│ │    Auto compact buffer                     12000        │ │
│ └──────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

- `/` key activates search mode across all categories
- Results appear in the fields column regardless of current category
- Escape clears search and returns to category view

### R3: Unify provider/model configuration (Priority: 🔴 High)

**Proposed mockup — AI Provider screen:**

```
┌ AI Provider ────────────────────────────────────────────────┐
│                                                              │
│  Provider                                  OpenAI     ▾     │
│  Model                                     GPT-4.1   ▾     │
│  ── Auth ────────────────────────────────────────────────── │
│  Status                                    ✅ Authenticated │
│  API Key                                   sk-••••••••4f2d  │
│  ── Models ──────────────────────────────────────────────── │
│  ▸ Browse all models (24 available)                         │
│  ── Advanced ─────────────────────────────────────────────  │
│  ▸ Custom provider setup                                    │
│  ▸ Driver: openai                                           │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

The key change: **provider and model are always visible at the top**. Auth status is inline, not hidden in a separate category. Advanced options (custom providers, driver) are collapsed under disclosure triangles.

### R4: Fix `q` behavior (Priority: 🔴 Critical)

**Current:** `q` saves and closes.  
**Proposed:** `q` closes *without saving* (standard TUI convention). `Ctrl+S` saves. Add an unsaved-changes confirmation on close:

```
┌ Unsaved Changes ────────────────────────────────────────────┐
│  You have 3 unsaved changes.                                │
│                                                              │
│  [S] Save and close   [D] Discard and close   [Esc] Cancel  │
└──────────────────────────────────────────────────────────────┘
```

### R5: Progressive disclosure for advanced settings (Priority: 🟡 Medium)

```
┌ Context & Memory ───────────────────────────────────────────┐
│  Memories                                  on           ▾   │
│  Auto compact                              on           ▾   │
│                                                              │
│  ── Advanced ──────────────────────────────────────────────  │
│  ▸ Compact threshold (60)                                   │
│  ▸ Reserved output tokens (16000)                           │
│  ▸ Warning buffer (24000)                                   │
│  ▸ Auto compact buffer (12000)                              │
│  ▸ Blocking buffer (3000)                                   │
│  ▸ Prune protect (40000)                                    │
│  ▸ Prune minimum savings (20000)                            │
│                                                              │
│  Press → to expand advanced section                         │
└──────────────────────────────────────────────────────────────┘
```

Advanced fields are collapsed by default. The summary line shows current values. Expanding reveals the full list with descriptions.

### R6: Inline validation and required indicators (Priority: 🟡 Medium)

- Required fields get a `*` indicator
- Number fields validate on save (non-empty, numeric, range)
- URL fields validate format
- API key fields show mask (`•••••`) with a reveal toggle
- Custom provider form shows inline errors:

```
│  URL *            https://api.exampl…  ⚠ Invalid URL        │
│  Model ID *       (empty)             ⚠ Required            │
```

### R7: Effect badges instead of text (Priority: 🟢 Low)

Replace `Effect: next_session` with visual badges:

- 🟢 `now` — changes take effect immediately
- 🟡 `turn` — changes take effect next turn
- 🔵 `restart` — changes require a session restart

These appear inline next to the field value, not buried in the details panel.

### R8: "Edit config file" escape hatch (Priority: 🟢 Low)

Add a key (e.g., `e`) that opens the YAML config file in the user's `$EDITOR`. This is the Lazygit/Helix philosophy: the GUI is for guided setup, the file is for power users. The YAML file should include inline comments (generated from schema descriptions).

---

## 11. Proposed Settings Layout Mockup

### Full redesigned layout

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ⚙ Settings  [project]  OpenAI / GPT-4.1  ✅ Authenticated  🔍 [______] │
│ ──────────────────────────────────────────────────────────────────────── │
│ ┌ General ──┐  ┌ Default Provider ────────────────────────────────────┐ │
│ │• AI       │  │                                              OpenAI ▾  │ │
│ │  General  │  │ Default Model                              GPT-4.1 ▾  │ │
│ │  Context  │  │  Auth status                      ✅ Authenticated     │ │
│ │  Agent    │  │  API key                              sk-••••4f2d     │ │
│ │  Subagent │  │                                                   │  │ │
│ └───────────┘  │  ── Inline Details ────────────────────────────────  │ │
│                │  GPT-4.1 — OpenAI's latest flagship model. 128k      │ │
│                │  context. Recommended for complex tasks.              │ │
│                │  🟢 Change takes effect now                           │ │
│                │  Available: o3, o4-mini, GPT-4.1 mini, ...           │ │
│                └───────────────────────────────────────────────────────┘ │
│                                                                          │
│ Tab categories  ↑↓ fields  → expand/picker  Enter edit  / search  ? help │
└──────────────────────────────────────────────────────────────────────────┘
```

Key changes:
1. **5 categories** instead of 11
2. **Search bar** in the header (`/` to focus)
3. **Auth status inline** — no separate Provider Setup category
4. **Details merged into field area** — no separate bottom panel for basic info
5. **Scope indicator** is a toggle, not a hidden `g`/`p` shortcut
6. **Footer is minimal** — help is available via `?`

---

## 12. Summary Scorecard

| Dimension | Current | Target | Gap |
|-----------|---------|--------|-----|
| Layout clarity | C+ | A | Remove ghost categories, merge provider paths |
| Category organization | C | A | 11 → 5 categories, remove empty ones |
| Setting descriptions | B | A | Add units, examples, valid ranges |
| Value editing | B- | A | Add cursor positioning, validation, inline edit |
| Provider/model setup | C | A | Unify into single screen with progressive disclosure |
| Custom provider flow | D+ | B | Wizard-style flow, validation, discoverability |
| Navigation/discoverability | C | A | Search, visual cues, standard `q` behavior |
| Error prevention | D | A | Validation, confirmation dialogs, undo |

**The highest-impact changes are:**
1. **Fix `q` behavior** (dangerous data loss risk — 1 day)
2. **Add search** (biggest usability win — 3 days)
3. **Merge categories** (reduce navigation friction — 2 days)
4. **Unify provider/model screen** (eliminate user confusion — 5 days)
5. **Add validation** (prevent broken configs — 3 days)
