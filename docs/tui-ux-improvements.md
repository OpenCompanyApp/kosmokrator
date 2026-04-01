# TUI UX Improvements

Comparative analysis of KosmoKrator vs OpenCode vs Claude Code terminal UIs, ranked by UX impact.

## Benchmark Comparison

| Aspect | KosmoKrator | OpenCode | Claude Code |
|--------|-------------|----------|-------------|
| **Framework** | Custom Symfony TUI (PHP) | Custom OpenTUI (SolidJS/Bun) | Forked Ink (React/Node) |
| **Rendering** | Widget tree, diff-based screen updates | 60 FPS, SolidJS reactive | Double-buffered Yoga flexbox |
| **Themes** | 1 hardcoded theme | 35+ themes, JSON-defined, auto dark/light | 6 themes incl. daltonized + ANSI fallback |
| **Diffs** | Word-level with syntax highlight | Split/unified, tree-sitter, 11 theme tokens | Native Rust NAPI module, word-level |
| **Spinners** | 14 custom sets, breathing animation | Knight Rider gradient, per-agent colors | Glimmer wave, stall-aware color shift |
| **Input** | Multi-line EditorWidget | Rich textarea, extmarks, frecency autocomplete | Vim mode, voice, image paste, typeahead |

---

## Ranked Improvements (Highest to Lowest UX Impact)

### 1. Collapsed Tool Groups

**Impact**: Very High — single biggest readability win  
**Effort**: Medium  
**Source**: Original design (stacked brackets)

Every tool call is currently rendered individually. Sequential `file_read` × 5 shows 5 separate entries, drowning the conversation in noise.

**What others do**:
- Claude Code auto-collapses sequential Read/Grep/Glob calls into `"Reading 5 files"` or `"Searching 3 patterns"` — a single expandable line.
- OpenCode uses `InlineTool` for simple tools (single line) and `BlockTool` for complex ones (expandable).

**What to build**:
- Detect consecutive same-type tool calls (file_read, grep, glob, bash)
- Collapse into a summary line with expand-to-detail on Ctrl+O
- Show aggregate stats (file count, match count, total time)

#### Mockups — Stacked Brackets Style

##### Before (current behavior)

```
☽ Read  src/UI/Theme.php
✓ ⏋ 237 lines (ctrl+o to reveal)

☽ Read  src/UI/Tui/TuiRenderer.php
✓ ⏋ 1180 lines (ctrl+o to reveal)

☽ Read  src/UI/Diff/DiffRenderer.php
✓ ⏋ 95 lines (ctrl+o to reveal)

♅ Edit  src/UI/Theme.php
✓ 3 replacements applied

⊛ Search  pattern: "render()"  path: src/
✓ ⏋ 14 matches across 5 files (ctrl+o to reveal)
```

12 lines of visual noise for 3 reads, 1 edit, 1 search.

##### After (collapsed — default view)

Same scenario rendered in 5 lines:

```
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
☽ Read  3 files · 412 lines · 1.2s
   ⊛ src/UI/Theme.php
   ⊛ src/UI/Tui/TuiRenderer.php
   ⊛ src/UI/Diff/DiffRenderer.php
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄

┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
⊛ Search  14 matches in 5 files · 0.8s
   pattern: "render()" in src/
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
```

Edit and Write calls remain ungrouped — they always render individually as today:

```
♅ Edit  src/UI/Theme.php
✓ 3 replacements applied
```

##### Expanded view (Ctrl+O on the Read group)

```
┌── ☽ Read  3 files · 412 lines · 1.2s ──────────────┐
│  src/UI/Theme.php             237 lines              │
│  src/UI/Tui/TuiRenderer.php   1180 lines             │
│  src/UI/Diff/DiffRenderer.php 95 lines               │
├──────────────────────────────────────────────────────│
│  Theme.php                                            │
│  ⏋ 1   <?php                                        │
│  │  2   namespace KosmoKrator\UI;                    │
│  │  3                                                │
│  │  ... (ctrl+o to collapse)                         │
├──────────────────────────────────────────────────────│
│  TuiRenderer.php                                      │
│  ⏋ 1   <?php                                        │
│  │  ... (ctrl+o to collapse)                         │
├──────────────────────────────────────────────────────│
│  DiffRenderer.php                                     │
│  ⏋ 1   <?php                                        │
│  │  ... (ctrl+o to collapse)                         │
└──────────────────────────────────────────────────────┘
```

Expanded shows per-file results nested in the bracket. Each file's content
is individually collapsible. The bracket border uses `Theme::borderTask()`.

##### Tool-Type Specific Rendering

**Read groups** (file_read × N):
```
Collapsed:
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
☽ Read  3 files · 412 lines · 1.2s
   ⊛ src/UI/Theme.php
   ⊛ src/UI/Tui/TuiRenderer.php
   ⊛ src/UI/Diff/DiffRenderer.php
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
```

**Search groups** (grep × N):
```
Collapsed:
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
⊛ Search  2 patterns · 24 matches in 8 files · 0.6s
   "render()" → 14 matches
   "Theme::" → 10 matches
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
```

**Glob groups** (glob × N):
```
Collapsed:
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
✧ Glob  2 patterns · 18 files · 0.3s
   "src/**/*.php" → 14 files
   "config/*.yaml" → 4 files
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
```

**Bash groups** (bash × N — sequential commands):
```
Collapsed:
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
⚡ Bash  3 commands · 4.8s
   ⊛ composer test
   ⊛ phpstan analyse
   ⊛ composer lint
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
```

**Mixed read + search** (grep → file_read × N — common agent pattern):
```
Collapsed:
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
⊛ Search  "render()" → 3 matches
☽ Read  3 files · 412 lines · 1.2s
   ⊛ src/UI/Theme.php
   ⊛ src/UI/Tui/TuiRenderer.php
   ⊛ src/UI/Diff/DiffRenderer.php
┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
```

A search followed immediately by reads of the matching files merges into
a single bracket group with both the search summary and the file list.

**Solo tool call** (no consecutive same-type → no collapse):
```
☽ Read  src/UI/Theme.php
✓ ⏋ 237 lines (ctrl+o to reveal)
```

A single tool call without a same-type neighbor renders exactly as today.
No bracket, no collapse — the original single-line format.

##### Design Decisions

- **`┄┄┄` bracket lines**: Use the KosmoKrator dim color (`Theme::dimmer()`) with the
  box-drawing `┄` (dotted horizontal) to visually bracket groups without heavy lines.
  These are distinctive enough to scan but don't add visual weight.

- **`⊛` bullet for file entries**: Reuses the existing `Theme::toolIcon('grep')` symbol
  as a generic "item" bullet inside groups — maintains the celestial icon language.

- **Summary line**: `Icon ToolType  N files · total lines · duration` — aggregate
  stats at a glance. Duration is the total wall time across all calls in the group.

- **Expand depth**: Ctrl+O first expands the group to show per-file results. A second
  Ctrl+O on an individual file within the group expands that file's content. Two levels
  of progressive disclosure.

- **Edit and Write are never grouped**: `file_edit` and `file_write` calls are always
  rendered individually, even when consecutive. Each edit is a meaningful action the user
  needs to see and potentially review. Only read-only tools (file_read, grep, glob) and
  bash are eligible for grouping.

- **Interruption breaks the group**: If the agent streams text between tool calls, the
  group breaks. Only strictly consecutive same-type calls with no interleaved content
  are grouped.

- **Mixed search+read pattern**: A grep/glob immediately followed by file_reads of the
  results merges into one group — this is the most common agent exploration pattern
  and deserves special treatment.

- **Failed calls**: A failed call in the group shows `✗` instead of `⊛` and the error
  message is shown inline even in collapsed view:
  ```
  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
  ☽ Read  3 files · ✗ 1 failed · 1.2s
     ⊛ src/UI/Theme.php
     ✗ src/UI/MissingFile.php — not found
     ⊛ src/UI/Diff/DiffRenderer.php
  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
  ```

---

### 2. Theme System Overhaul

**Impact**: High — foundation for all visual customization  
**Effort**: Large  
**Source**: OpenCode, Claude Code

All colors are hardcoded in `Theme.php` and duplicated in `KosmokratorStyleSheet`. No user customization, no dark/light detection, no color-blind support.

**What others do**:
- OpenCode: 35+ JSON themes with dark/light variants, auto-detects terminal background via OSC 11, user themes from `.opencode/themes/*.json`.
- Claude Code: 6 themes including daltonized (color-blind friendly) and ANSI-only fallback, auto-detects via `$COLORFGBG` + OSC 11 polling.

**What to build**:
- Extract colors into JSON-based theme definitions (`~/.kosmokrator/themes/*.json`)
- OSC 11 auto dark/light detection at startup
- Daltonized variant for color-blind accessibility
- ANSI 16-color fallback for limited terminals
- Single source of truth — eliminate duplication between `Theme` and `KosmokratorStyleSheet`

---

### 3. Sticky Scroll + "New Messages" Indicator

**Impact**: High — users lose context when forced to bottom  
**Effort**: Medium  
**Source**: Claude Code

Currently auto-scrolls to bottom on every render — users cannot review previous content without being pulled back.

**What others do**:
- Claude Code: When scrolled up during streaming, shows a floating `"N new messages"` pill at bottom. Click or keypress jumps back to bottom. Sticky prompt header stays visible at top.
- OpenCode: `stickyScroll={true}` auto-scrolls but doesn't fight the user.

**What to build**:
- Track user scroll position — stop force-scrolling when user has scrolled up
- Show subtle `"↓ N new messages"` indicator when content is below the viewport
- Keybinding or click to jump back to bottom

---

### 4. Diff Preview in Permission Dialogs

**Impact**: High — trust and safety UX  
**Effort**: Medium  
**Source**: Claude Code, OpenCode

Permission prompts currently show tool name and path only. Users must approve blind or cancel to inspect.

**What others do**:
- Claude Code: Per-tool permission components — `FileEditPermissionRequest` shows an inline diff of proposed changes right in the permission dialog.
- OpenCode: Three-way choice ("Allow once" / "Allow always" / "Reject") with pattern-based "Allow always" showing what glob patterns get whitelisted.

**What to build**:
- Show inline diff preview in the permission dialog for `file_edit` calls
- Show file content preview for `file_write` calls (with truncation)
- Add "Allow always for this path" pattern-based approval option

---

### 5. Stall-Aware Spinner with Context Verbs

**Impact**: Medium-High — makes waiting feel informed  
**Effort**: Small  
**Source**: Claude Code, OpenCode

Current spinners use fixed Greek mythology phrases. No feedback on progress or what the agent is actually doing.

**What others do**:
- Claude Code: Spinner color shifts from blue toward red after 8 seconds of no progress (stall detection). Per-grapheme glimmer wave sweeps across the loading text. Context-aware verbs rotate: "thinking", "reading", "editing".
- OpenCode: Knight Rider gradient scanner with per-agent colors and alpha falloff trails.

**What to build**:
- Gradual spinner color shift: blue → amber → red based on elapsed idle time
- Context-aware verbs: "Reading files..." during file reads, "Running tests..." during bash, "Thinking..." during LLM calls
- Optional text shimmer/glimmer effect on the loading message

---

### 6. Retry UI with Countdown

**Impact**: Medium — prevents confusion during failures  
**Effort**: Small  
**Source**: OpenCode

Subagent retries are tracked internally but not surfaced. Users see a spinner during retries with no explanation.

**What others do**:
- OpenCode: On API failure, shows retry countdown timer, attempt number ("Attempt 2/3"), and truncated error message (click to expand full error).

**What to build**:
- Surface retry state visually: "Retrying (attempt 2/3)..."
- Show truncated error message with expand option
- Countdown timer between retries

---

### 7. Virtual Scrolling / Conversation Pruning

**Impact**: Medium — prevents degradation in long sessions  
**Effort**: Large  
**Source**: Claude Code

The conversation container grows unboundedly — old widgets are never removed. Long sessions accumulate thousands of widgets, degrading rendering performance.

**What others do**:
- Claude Code: Full virtual scrolling — only visible messages are rendered. `OffscreenFreeze` wraps off-screen subtrees to prevent re-renders. Height caching keyed by content hash.
- OpenCode: `ScrollBox` with sticky scroll behavior.

**What to build**:
- Widget pruning: remove widgets scrolled far out of view
- Re-create on scroll-back (or mark as "older messages" with load trigger)
- Even a simple "trim beyond N widgets" with a "show older messages" affordance would help

---

### 8. Streaming Markdown Prefix Caching

**Impact**: Medium — reduces rendering cost during streaming  
**Effort**: Medium  
**Source**: Claude Code

Currently re-renders the full markdown widget on every `streamChunk()`.

**What others do**:
- Claude Code: Only re-parses the last markdown block during streaming — stable prefix is memoized. Uses a fast-path regex check (`hasMarkdownSyntax()`) to skip parsing plain text entirely.

**What to build**:
- Split streaming markdown into "committed prefix" (stable, parsed once) and "active tail" (re-parsed per chunk)
- Fast-path detection: skip markdown parsing for plain text chunks
- Reduces parse + render cost proportional to chunk size rather than total message size

---

### 9. Terminal Self-Healing

**Impact**: Medium — prevents corrupted terminal state  
**Effort**: Small  
**Source**: Claude Code

If terminal state gets corrupted (after SIGCONT, resize, background/foreground), there is no recovery mechanism.

**What others do**:
- Claude Code: Re-asserts kitty keyboard protocol, mouse tracking, and focus reporting after SIGCONT/suspend/resize events.

**What to build**:
- Signal handlers that re-initialize terminal modes after SIGCONT and resize
- Re-assert alternate screen, cursor visibility, and color settings
- Periodic terminal state validation

---

### 10. Plugin/Slot System for UI Extensibility

**Impact**: Medium — future-proofing for ecosystem  
**Effort**: Large  
**Source**: OpenCode

No extensibility mechanism for third-party UI additions.

**What others do**:
- OpenCode: Full plugin API with named UI slots (`sidebar_content`, `home_logo`), route registration, command registration, and `replace` or `append` modes.

**What to build**:
- Named slot system in the widget tree where plugins can inject content
- Route registration for custom views
- Command registration for plugin slash commands
- Plugin discovery from `~/.kosmokrator/plugins/`

---

## Quick Reference: Effort vs Impact

| # | Improvement | Effort | Impact |
|---|------------|--------|--------|
| 1 | Collapsed tool groups | Medium | Very High |
| 2 | Theme system overhaul | Large | High |
| 3 | Sticky scroll + new messages | Medium | High |
| 4 | Diff preview in permissions | Medium | High |
| 5 | Stall-aware spinner + verbs | Small | Medium-High |
| 6 | Retry countdown UI | Small | Medium |
| 7 | Virtual scrolling / pruning | Large | Medium |
| 8 | Streaming markdown caching | Medium | Medium |
| 9 | Terminal self-healing | Small | Medium |
| 10 | Plugin/slot system | Large | Medium |

### Recommended Implementation Order

1. **Quick wins first** (1-2 days each): Stall-aware spinner (#5), Retry countdown (#6), Terminal self-healing (#9)
2. **Core UX** (1-2 weeks): Collapsed tool groups (#1), Sticky scroll (#3)
3. **Infrastructure** (2-4 weeks): Theme system (#2), Diff preview in permissions (#4)
4. **Optimization** (1-2 weeks): Streaming markdown caching (#8), Virtual scrolling (#7)
5. **Future** (3-4 weeks): Plugin/slot system (#10)
