# UX Audit: Visual Hierarchy

> **Research Question**: How effective is the visual hierarchy in KosmoKrator's TUI?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `Theme.php`, `KosmokratorStyleSheet.php`, `TuiCoreRenderer.php`, `TuiToolRenderer.php`, `CollapsibleWidget.php`, `DiscoveryBatchWidget.php`, `BashCommandWidget.php`, `SubagentDisplayManager.php`, `PermissionPromptWidget.php`, `AnsweredQuestionsWidget.php`, `HistoryStatusWidget.php`, `TuiAnimationManager.php`

---

## Executive Summary

KosmoKrator's visual hierarchy has a **strong semantic color system** but suffers from **insufficient luminance contrast between content types**. The primary issue: conversation text (agent responses), tool calls, and tool results live in a narrow band of mid-tone colors (gold `#ffc850`, gray `#a0a0a0`, light-gray `#b4b4be`) that makes them hard to distinguish at a glance. Tool icons are creative but not scannable — too many unique glyphs with no grouping pattern. The status bar is well-designed but undersized for the amount of information it carries.

Compared to lazygit (active panel bright, rest dimmed 60%), Helix (prominent mode indicator, clear status zones), and Claude Code (conversation prominent, tools secondary, status minimal), KosmoKrator lacks a **clear visual priority stack**. Everything feels like it's at the same importance level.

**Severity**: High. Visual hierarchy determines whether users can scan output to find what matters — errors, decisions, key information — without reading every line. The current hierarchy requires careful reading rather than allowing peripheral scanning.

---

## 1. Color Usage Analysis

### 1.1 The Palette

KosmoKrator defines its colors in `Theme.php` (lines 57–248):

| Role | Color | Hex | Purpose |
|------|-------|-----|---------|
| Primary | Fiery red-orange | `#ff3c28` | Brand, FIGlet header |
| Primary Dim | Dark red | `#a01e1e` | Subtle accents |
| Accent | Gold | `#ffc850` | Tool calls, borders, highlights |
| Success | Green | `#50dc64` | Positive results, checkmarks |
| Warning | Amber | `#ffc850` | (Same as accent!) |
| Error | Red | `#ff5040` | Errors, failures |
| Info | Sky blue | `#64c8ff` | Informational highlights |
| Text | Light gray | `#b4b4be` | Body text |
| Dim | Gray-240 | ~`#c0c0c0` | Muted/secondary |
| Dimmer | Gray-236 | ~`#a0a0a0` | Separators, backgrounds |
| White | Near-white | `#f0f0f5` | Bold emphasis |
| Dim White | Mid-gray | `#8c8c96` | Subtle UI text |
| Code | Purple | `#c878ff` | Inline code |
| Link | Blue | `#508cff` | URLs |
| Agent General | Goldenrod | `#daa520` | General agent type |
| Agent Plan | Purple | `#a078ff` | Plan agent type |
| Agent Default | Cyan | `#64c8dc` | Explore agent type |
| Waiting | Cornflower | `#6495ed` | Queued status |

### 1.2 Issues

**Issue 1: Warning and Accent are identical.** `Theme::warning()` and `Theme::accent()` both return `#ffc850`. This means the gold color simultaneously means "important highlight" and "caution." There is no way to visually distinguish an urgent warning from a routine tool-call header.

**Issue 2: Too many colors in the same luminance band.** Gold (`#ffc850`), dim gray (`#a0a0a0`), text gray (`#b4b4be`), and dim white (`#8c8c96`) are all mid-brightness. When tool calls use gold, results use gray, and separator lines use dark gray, the contrast ratio between adjacent elements is often 1.5:1 or less — far below the 3:1 minimum for distinguishable UI elements.

**Issue 3: No color grouping by semantic category.** Tool calls, discovery batches, bash commands, and user messages all use different colors (gold, gold, gold, white respectively) but tool *results* across all types use the same gray. A file-read result and a bash-error result look nearly identical in the collapsed state (both use `tool-result` style class with `#a0a0a0`).

**Issue 4: Border colors overlap with content colors.** `borderTask()` (`#806428`, warm brown) and `borderAccent()` (`#b48c32`, dimmed gold) are very close to the accent gold `#ffc850` — they don't create distinct visual zones.

### 1.3 Comparison

| TUI | Approach | Strength |
|-----|----------|----------|
| **Lazygit** | 3-tier brightness: active panel bright white, inactive panels dimmed to ~40% | Instantly know which panel has focus |
| **Helix** | Mode indicator uses bright colored bar; status line uses distinct bg blocks | Mode is unmissable; status is structured |
| **Claude Code** | Conversation text = white, tool output = dim gray, status = minimal single line | Clear priority: read → tool → status |
| **KosmoKrator** | Everything is a shade of gold or gray | No clear priority stack |

---

## 2. Border Usage Analysis

### 2.1 Current Border Patterns

Borders are used in four distinct contexts:

| Context | Border Style | Code |
|---------|-------------|------|
| Task bar | Box-drawing `┌ │ └` in warm brown | `TuiCoreRenderer.php:654` |
| Discovery batch | Box-drawing `│ └` in accent gold | `DiscoveryBatchWidget.php:89` |
| Permission prompt | Rounded `┌─ ┐ │ └─ ┘` in accent gold | `PermissionPromptWidget.php:125` |
| Settings panel | Rounded borders in accent gold | `KosmokratorStyleSheet.php:224` |
| Editor input | `───` frame in dark red / focused red | `KosmokratorStyleSheet.php:135-141` |
| Collapsible widget | `⏋` bracket only on first line | `CollapsibleWidget.php:85` |

### 2.2 Issues

**Issue 1: No border around conversation area.** The conversation (main content) has no visual boundary — it bleeds edge-to-edge. This makes it hard to distinguish from the status bar below and the input area at the bottom. Only a subtle separator `───` (via the editor frame) separates input from content.

**Issue 2: Inconsistent border characters.** Task bar uses `┌─┐` box drawing. Discovery batch uses `│ └` tree-style connectors. Collapsible widget uses `⏋` (a single Unicode character). Permission prompt uses `┌─┐` with rounded corners. These don't form a coherent visual language.

**Issue 3: Borders don't scale to content importance.** A permission prompt (critical, blocking) and a discovery batch (informational) use nearly identical border colors (both gold-family). There's no visual escalation.

**Issue 4: Tool results lack containment.** Tool calls get a gold-colored line, but tool results float in plain gray text with only a `⏋` bracket and a `✓` indicator. Multi-line tool output has no left border or background differentiation — it looks identical to agent response text.

---

## 3. Text Weight (Bold/Italic) Analysis

### 3.1 Current Usage

| Context | Bold | Italic | Code Reference |
|---------|------|--------|---------------|
| FIGlet header | ✓ | — | `KosmokratorStyleSheet.php:43` |
| Subtitle | — | ✓ | `KosmokratorStyleSheet.php:49` |
| User message | ✓ | — | `KosmokratorStyleSheet.php:70` |
| Settings selected label | ✓ | — | `KosmokratorStyleSheet.php:231` |
| Settings selected value | ✓ | — | `KosmokratorStyleSheet.php:240` |
| Thinking loader | — | ✓ | `KosmokratorStyleSheet.php:185` |
| Compacting loader | — | ✓ | `KosmokratorStyleSheet.php:169` |
| Settings description | — | ✓ | `KosmokratorStyleSheet.php:246` |
| Agent response (markdown) | ✓ (headings) | ✓ (emphasis) | MarkdownWidget renders |

### 3.2 Issues

**Issue 1: Agent response headings not visually dominant enough.** While MarkdownWidget renders headings in bold, the color remains the default body text `#b4b4be`. There is no size change or color change for headings. In a terminal where "bold" often just brightens the color slightly, headings don't stand out.

**Issue 2: Tool call labels lack weight.** Tool calls like `☽ Read  src/UI/Theme.php` use gold color but no bold. Since gold is already used for borders and accents, tool calls don't "pop" — they blend with surrounding decorative elements.

**Issue 3: No weight hierarchy within tool output.** Success indicators (`✓`), error indicators (`✗`), and content text all use the same weight. An error should visually dominate more than a success.

**Issue 4: Status bar items are all the same weight.** The status bar renders `Edit · Guardian ◈ · Ready` all in their respective colors but with no bold/italic differentiation. The mode label ("Edit") is arguably the most important element but has no typographic emphasis.

---

## 4. Spacing Analysis

### 4.1 Current Padding Values

From `KosmokratorStyleSheet.php`:

| Style Class | Top | Right | Bottom | Left |
|------------|-----|-------|--------|------|
| `.session` | 0 | 0 | 0 | 0 |
| `.figlet-header` | 1 | 2 | 0 | 2 |
| `.subtitle` | 0 | 2 | 0 | 2 |
| `.welcome` | 1 | 2 | 0 | 2 |
| `.user-message` | 1 | 2 | 0 | 2 |
| `.separator` | 1 | 2 | 0 | 2 |
| `.response` | 1 | 2 | 0 | 2 |
| `.tool-call` | 1 | 2 | 0 | 2 |
| `.task-call` | 0 | 2 | 0 | 2 |
| `.tool-result` | 0 | 3 | 0 | 3 |
| `.tool-batch` | 1 | 2 | 0 | 2 |
| `.tool-shell` | 1 | 2 | 0 | 2 |
| `.tool-success` | 0 | 3 | 0 | 3 |
| `.tool-error` | 0 | 3 | 0 | 3 |
| `.status-bar` | 0 | 1 | 0 | 1 |
| EditorWidget | 0 | 1 | 0 | 1 |
| MarkdownWidget | 0 | 2 | 0 | 2 |

### 4.2 Issues

**Issue 1: Bottom padding is always 0.** Every single style class has `bottom: 0`. This means there is no whitespace between adjacent conversation elements. A tool call sits directly on top of its result. An agent response ends and the next user message begins with no vertical separation. This creates a "wall of text" effect that makes scanning difficult.

**Issue 2: No spacing hierarchy.** Top padding is either `0` or `1` — there is no `2` or `3` for major section breaks. All conversation elements have the same inter-element spacing (1 line or 0 lines), eliminating any rhythm or grouping.

**Issue 3: Tool results have no top padding but tool calls do.** A tool call gets 1 line of top padding, but its result gets 0. This means when multiple tool calls appear in sequence, the *call* has a blank line before it but the *result* is crammed against the next call. The visual grouping is: `[gap] [call] [result][gap] [call] [result]` rather than `[gap] [call + result] [gap] [call + result]`.

**Issue 4: Status bar has minimal padding (0, 1, 0, 1).** This makes the status bar feel cramped and easily missed — it doesn't have enough visual weight to serve as a reliable system-information zone.

### 4.3 Comparison

| TUI | Approach | Effective Spacing |
|-----|----------|-------------------|
| **Lazygit** | 1-line gaps between list items, section headers with double spacing | Sections clearly delineated |
| **Helix** | Status line has 1-line padding top/bottom, gutter between line numbers and code | Clear visual zones |
| **Claude Code** | 1-line gap between turns, tool results indented with surrounding whitespace | Conversational rhythm |
| **KosmoKrator** | 0 or 1 line between everything, no bottom padding anywhere | Flat, undifferentiated |

---

## 5. Tool Icons Analysis

### 5.1 Current Icon Set

From `Theme.php` (lines 296–318):

| Tool | Icon | Rationale |
|------|------|-----------|
| file_read | ☽ | Moon — illumination |
| file_write | ☉ | Sun — creation |
| file_edit | ♅ | Uranus — transformation |
| apply_patch | ✎ | Inscription |
| bash | ⚡︎ | Lightning |
| grep | ⊛ | Astral search |
| glob | ✧ | Star cluster |
| subagent | ⏺ | Orbital |
| execute_lua | ✦ | Spark |
| shell_start | ◌ | Opening orbit |
| shell_write | ↦ | Input arrow |
| shell_read | ↤ | Output arrow |
| shell_kill | ✕ | Termination |
| Default | ◈ | Generic gem |

### 5.2 Issues

**Issue 1: Icons are not scannable.** Each tool has a unique Unicode glyph with no shared visual properties. Users must learn 15+ symbols. There is no pattern like "file tools are squares, shell tools are arrows, search tools are circles."

**Issue 2: Some icons are visually similar.** `✦` (execute_lua), `✧` (glob), `⊛` (grep), and `◈` (default) are all small, star-like shapes. At a glance on a terminal, they look nearly identical.

**Issue 3: Icons don't convey action.** `☽` for file_read requires knowing "moon = illumination = revealing text." Compare to lazygit's approach of using colored text labels (`+` for add, `-` for delete) or Claude Code's approach of using no icons at all — just styled labels.

**Issue 4: The cosmic theme overrides usability.** The alchemical/astrological icon system is on-brand but creates unnecessary cognitive load. Icons should reduce scan time, not require decryption.

### 5.3 Recommendation

Consider a hybrid approach: keep cosmic icons as an opt-in theme but default to a simpler, more scannable system. At minimum, group related tools visually:
- File tools: `▎` variants or simple `R`/`W`/`E` badges
- Shell tools: `>` prefix
- Search tools: `/` prefix

---

## 6. Status Bar Analysis

### 6.1 Current Implementation

The status bar is a `ProgressBarWidget` at the bottom of the session, rendering:
```
Edit · Guardian ◈ · 45.2k/200k · claude-sonnet-4-20250514
```

Code at `TuiCoreRenderer.php:770-779`:
```php
$this->statusBar->setMessage(
    "{$this->currentModeColor}{$this->currentModeLabel}{$r} {$sep} "
    ."{$this->currentPermissionColor}{$this->currentPermissionLabel}{$r} {$sep} "
    .$this->statusDetail
);
```

Style: `color: #909090`, `padding: 0 1 0 1` (from `KosmokratorStyleSheet.php:123-126`).

### 6.2 Issues

**Issue 1: Status bar is the same color as tool results.** The status bar uses `#909090`, which is nearly identical to tool result text at `#a0a0a0`. The status bar doesn't have a distinct visual identity.

**Issue 2: No background differentiation.** The status bar is plain text on the terminal background — no reversed colors, no background fill, no border. Compare to lazygit (white text on blue background) or Helix (colored segments with background fill). Without a background, the status bar visually merges with conversation content above it.

**Issue 3: Context bar is the only "widget" element.** The progress bar portion (`━━━━━━━━────────────`) shows context window usage. This is the only visual element in the status bar that isn't text. It works well — the color transitions (green → yellow → red) are immediately meaningful. But it's too small (20 characters) and easily missed.

**Issue 4: Mode label doesn't dominate.** The mode label ("Edit", "Plan", "Ask") is the most important piece of status information — it determines what the agent is allowed to do. It uses a color (default: green `#50c878`) but no bold, no background, no size increase. It's visually identical to the model name next to it.

### 6.3 Comparison

| TUI | Status Bar Style | Strength |
|-----|-----------------|----------|
| **Lazygit** | Reversed colors (white on blue), keybinding hints right-aligned | Unmissable, functional |
| **Helix** | Multi-segment with colored backgrounds, mode in bright color | Information-dense but structured |
| **Claude Code** | Single dim line: model + cost + tokens | Minimal, doesn't distract |
| **KosmoKrator** | Dim gray text, no background, inline context bar | Easy to miss entirely |

---

## 7. Conversation vs. Tool Output Distinction

### 7.1 Current Visual Separation

| Element | Color | Style Class | Border |
|---------|-------|-------------|--------|
| User message | White `#ffffff` + bold + bg `#23232d` | `.user-message` | None |
| Agent response | Default `#b4b4be` (MarkdownWidget) | `.response` | None |
| Tool call | Gold `#ffc850` | `.tool-call` | None |
| Tool result (success) | Green `#50dc64` | `.tool-success` | None |
| Tool result (error) | Red `#ff5040` | `.tool-error` | None |
| Tool result (collapsed) | Gray `#a0a0a0` | `.tool-result` | `⏋` bracket |
| Discovery batch | Gold `#ffc850` header, gray body | `.tool-batch` | `│ └` connectors |
| Bash command | Gold icon + gray output | `.tool-shell` | `└` connector |
| Task bar | Warm brown `#806428` | inline | `┌ │ └` box |

### 7.2 Issues

**Issue 1: Agent responses and tool results are nearly the same brightness.** Agent markdown renders in `#b4b4be`. Tool results render in `#a0a0a0`. The luminance difference is ~12% — imperceptible at reading speed. When scanning backwards through a conversation, it's difficult to quickly distinguish "what the agent said" from "what a tool returned."

**Issue 2: User messages are the only element with a background color.** `TuiCoreRenderer.php:372` applies `bgRgb(35, 35, 45)` to user messages. This creates a clear visual distinction for user input but nothing else gets a background treatment. The user message "pops" while everything else is flat.

**Issue 3: Tool calls and discovery batch headers use the same gold.** A single tool call `☽ Read src/Theme.php` and the discovery batch header `☽ Reading the omens` both use `Theme::accent()` gold. There's no visual way to distinguish "one tool" from "batch of tools" at the header level.

**Issue 4: Collapsed tool results lose context.** When a `CollapsibleWidget` is collapsed, it shows only `✓` (or `✗`) with a `⏋` bracket and 3 preview lines. The preview lines inherit the tool-result gray color. There is no indicator of *which tool* produced this result — the tool-call header is a separate widget above, separated by 0 pixels of bottom padding. At a scroll position where only the result is visible, context is lost.

### 7.3 Comparison with Claude Code

Claude Code's hierarchy model is the gold standard for this category:

| Priority | Element | Visual Treatment |
|----------|---------|-----------------|
| **P0 — Primary** | Agent response | Full brightness, markdown formatting, generous spacing |
| **P1 — Secondary** | Tool calls | Dimmed, indented, collapsible |
| **P2 — Tertiary** | Tool results | Very dim, collapsed by default |
| **P3 — Ambient** | Status info | Single line, minimal formatting |

KosmoKrator's current model:

| Priority | Element | Visual Treatment |
|----------|---------|-----------------|
| **??** | User message | White + bold + background fill (prominent) |
| **??** | Tool call | Gold (brighter than response!) |
| **??** | Agent response | Light gray (dimmer than tool calls!) |
| **??** | Tool result | Gray (same brightness class as response) |
| **??** | Status | Dim gray (lowest brightness) |

The hierarchy is **inverted**: tool calls are visually brighter than agent responses. This is the single most damaging visual hierarchy problem.

---

## 8. Specific Screen-by-Screen Analysis

### 8.1 During Agent Thinking

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  ⟡ Implement a caching layer for the widget system                          │
│                                                                              │
│  ┌ Tasks                                                                     │
│  │ ● Implement cache interface                                  1:23         │
│  │ ○ Add cache invalidation                                                   │
│  │ ○ Write integration tests                                                  │
│  └                                                                            │
│  ⟐ Consulting the Oracle at Delphi...                           0:15         │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────── │
│  ▏                                                                            │
│  ─────────────────────────────────────────────────────────────────────────── │
│  Edit · Guardian ◈ · 45.2k/200k · claude-sonnet-4-20250514                   │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Hierarchy assessment:**
- Task bar (brown border) — ✓ Distinct from conversation
- Thinking loader (blue, italic) — ✓ Animated, eye-catching
- Status bar — ✗ Too dim, blends with everything

### 8.2 During Tool Execution (Discovery Phase)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  ⟡ Implement a caching layer for the widget system                          │
│                                                                              │
│  ☽ Reading the omens                                                         │
│   │ 2 reads  1 search  1 probe                                               │
│   │ src/CacheInterface.php                                                   │
│   │ src/Cache/RedisCache.php                                                 │
│   │ "cache" in src/                                                          │
│   │ php -r "echo ini_get('extension_dir');"                                  │
│   └ ⊛ Details (ctrl+o to reveal)                                            │
│                                                                              │
│  ⟐ running... (3s)                                                           │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────── │
│  ▏                                                                            │
│  ─────────────────────────────────────────────────────────────────────────── │
│  Edit · Guardian ◈ · 47.1k/200k · claude-sonnet-4-20250514                   │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Hierarchy assessment:**
- Discovery batch (gold header) — ✓ Good grouping
- Items within batch — ✗ All same gray, no status indicators in collapsed view
- "running..." loader — ✓ Blue animation distinguishes from content

### 8.3 After Agent Response

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  ⟡ Implement a caching layer for the widget system                          │
│                                                                              │
│  ☽ Read  src/CacheInterface.php                                              │
│  ✓ ⏋ <?php declare(strict_types=1); interface CacheInterface { ...          │
│     ⊛ +42 lines (ctrl+o to reveal)                                          │
│                                                                              │
│  ⚡ php -r "echo ini_get('extension_dir');"                                  │
│  └ /usr/lib/php/extensions                                                   │
│                                                                              │
│  ☽ Read  src/Cache/RedisCache.php                                            │
│  ✓ ⏋ <?php declare(strict_types=1); class RedisCache implements ...         │
│     ⊛ +128 lines (ctrl+o to reveal)                                         │
│                                                                              │
│  Here's my implementation of the caching layer. I've created                 │
│  a `CacheInterface` that supports get, set, delete, and clear                │
│  operations, with a RedisCache implementation that...                        │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────── │
│  ▏                                                                            │
│  ─────────────────────────────────────────────────────────────────────────── │
│  Edit · Guardian ◈ · 52.8k/200k · claude-sonnet-4-20250514                   │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Hierarchy assessment:**
- Tool calls (gold `☽ Read`) — ✓ Distinct from response
- Tool results (gray `✓ ⏋ ...`) — ✗ Same brightness as agent response
- Agent response (body text) — ✗ Not visually prominent enough; should be the star
- Inter-element spacing — ✗ Everything equidistant, no grouping

---

## 9. Before/After Analysis

### 9.1 Current State Summary

| Aspect | Rating | Notes |
|--------|--------|-------|
| Color semantics | ★★☆☆☆ | Warning = accent; too many mid-tone colors |
| Border consistency | ★★★☆☆ | Some borders are good (task bar, permissions); others missing |
| Text weight usage | ★★☆☆☆ | Limited to intro/settings; not used for content hierarchy |
| Spacing rhythm | ★☆☆☆☆ | No bottom padding anywhere; flat layout |
| Tool icons | ★★☆☆☆ | Creative but not scannable; too many unique glyphs |
| Status bar | ★★☆☆☆ | Functional but invisible; no background, no emphasis |
| Conv. vs. tool output | ★★☆☆☆ | Tool calls brighter than agent responses (inverted) |
| **Overall** | **★★☆☆☆** | **Strong palette design, weak hierarchy execution** |

### 9.2 Proposed Visual Hierarchy Model

Based on the analysis above, here is the recommended priority stack:

```
P0 — PRIMARY (user must see immediately)
├── User messages (white + bold + bg)          ← Already good
├── Agent responses (bright white text)         ← Currently too dim
└── Errors/failures (red, bold)                 ← Currently no bold

P1 — SECONDARY (supports understanding)
├── Tool call headers (gold, but dimmed)        ← Currently same brightness
├── Tool results (collapsed, gray, indented)    ← Currently same brightness
├── Discovery batches (contained, dimmed)       ← Currently same brightness
└── Permission prompts (bright borders, bg)     ← Already good

P2 — TERTIARY (ambient awareness)
├── Status bar (reversed colors, bg fill)       ← Currently invisible
├── Task bar (current design, slight dimming)   ← Already decent
└── Thinking/compacting loaders (blue, italic)  ← Already good

P3 — DECORATIVE (brand, not information)
├── Orrery/intro art                            ← Already good
├── Quick reference card                        ← Already good
└── Separator lines                             ← Already good
```

### 9.3 Specific "After" Recommendations

#### Color Changes

| Element | Current | Proposed | Reason |
|---------|---------|----------|--------|
| Agent response text | `#b4b4be` | `#e0e0e4` (near-white) | Must be brightest content |
| Tool call label | `#ffc850` (gold) | `#c8a040` (dimmed gold) | Should be secondary to response |
| Tool result (collapsed) | `#a0a0a0` (gray) | `#787880` (darker gray) | Must recede below response |
| Status bar text | `#909090` | Reversed: `#1a1a2e` bg, `#c0c0c0` text | Must be visually distinct |
| Warning | `#ffc850` (same as accent!) | `#ff9f43` (orange) | Must differ from accent |

#### Spacing Changes

| Element | Current Padding | Proposed Padding | Reason |
|---------|----------------|-----------------|--------|
| `.response` | `1, 2, 0, 2` | `1, 2, 1, 2` | Space after agent response |
| `.tool-call` | `1, 2, 0, 2` | `0, 3, 0, 3` | Less space before; more indent |
| `.tool-result` | `0, 3, 0, 3` | `0, 3, 1, 3` | Space after result group |
| `.user-message` | `1, 2, 0, 2` | `1, 2, 1, 2` | Space after user message |
| `.separator` | `1, 2, 0, 2` | `1, 2, 1, 2` | Space around separators |
| `.status-bar` | `0, 1, 0, 1` | `0, 1, 0, 1` + bg fill | Background fill for visibility |

#### Text Weight Changes

| Element | Current | Proposed | Reason |
|---------|---------|----------|--------|
| Agent response headings | Bold only | Bold + bright white `#f0f0f5` | Headings must dominate |
| Tool call label | No bold | No bold (keep dimmed) | Already proposed to dim color |
| Error indicators (`✗`) | No bold | Bold + red `#ff5040` | Errors must pop |
| Mode label in status bar | No bold | Bold | Most important status info |
| Status bar overall | No bg | Reversed colors (bg fill) | Must be a distinct zone |

#### Border Changes

| Element | Current | Proposed | Reason |
|---------|---------|----------|--------|
| Tool result block | `⏋` bracket only | Left `│` border for full content | Clear containment |
| Permission prompt | Gold rounded border | Red-orange rounded border for danger tools | Severity escalation |
| Discovery batch | `│ └` connectors | Keep, but dim to `#606060` | Less visual weight |
| Conversation area | No border | Thin top border above status bar | Zone separation |

---

## 10. Architectural Observations

### 10.1 Style System Limitation

The stylesheet (`KosmokratorStyleSheet.php`) uses a class-based system that maps to `Style` objects. However, several visual properties are applied *inline* via ANSI escape sequences in widget code rather than through the stylesheet:

- Tool call colors are hardcoded in `TuiToolRenderer.php` (e.g., `$gold = Theme::accent()` at line 701)
- Tool result colors are hardcoded in `CollapsibleWidget.php` (e.g., `$border = Theme::borderTask()` at line 69)
- Bash widget colors are hardcoded in `BashCommandWidget.php` (e.g., `$gold = Theme::accent()` at line 121)
- Status bar colors are hardcoded in `TuiCoreRenderer.php` (e.g., `$sep = Theme::dim()` at line 773)

This means **the stylesheet only controls about 40% of the visual output**. The other 60% is scattered across widget render methods as inline `Theme::*()` calls. This makes global visual hierarchy changes difficult — you can't just update the stylesheet; you must find and update every widget.

**Recommendation**: Establish a convention where widgets define their visual style via stylesheet classes, and `Theme::*()` calls are used only for dynamic colors (breathing animations, context-bar colors, etc.). This would make visual hierarchy adjustments centralized and auditable.

### 10.2 No "Focus" or "Active" Visual State

Unlike lazygit or Helix, KosmoKrator has no concept of "focused/active element." The TUI is a single-stream conversation — there are no panels to focus. However, this means there is no mechanism for "highlight the current thing" beyond the blinking cursor in the input area. When scrolling through history, there is no visual indicator of "current position" beyond the `HistoryStatusWidget` bar.

### 10.3 Widget-Level Color Inconsistency

The same logical element type uses different colors across widgets:
- **Tool call header**: Gold in `TuiToolRenderer` but cyan `#70a0d0` in `TuiAnimationManager` thinking loader
- **Success indicator**: Green `#50dc64` in `TuiToolRenderer` but green `#50c878` in stylesheet
- **Dim text**: `#a0a0a0` in stylesheet but `Theme::dim()` returns color-256(240) = `#c0c0c0` in Theme.php

These inconsistencies suggest the color system evolved organically. A single source of truth (the stylesheet) would prevent drift.

---

## 11. Summary of Findings

### Critical Issues (P0)

1. **Inverted hierarchy**: Tool calls (gold) are brighter than agent responses (gray). The agent's words should be the most prominent element on screen.
2. **No bottom padding**: Creates a "wall of text" effect. Without bottom padding, every element blends into the next.
3. **Warning = Accent**: Two semantically different states share the same color, removing the ability to convey urgency.

### Major Issues (P1)

4. **Status bar is invisible**: No background, dim color, blends with content. Users will miss critical information (mode, context usage, model).
5. **Tool results lack containment**: Collapsed results have a single `⏋` bracket and no persistent tool-type context. Scanning backwards loses information.
6. **Icons are not scannable**: 15+ unique Unicode glyphs with no visual grouping pattern.

### Minor Issues (P2)

7. **Inconsistent border styles**: Box-drawing, tree connectors, and `⏋` brackets coexist without a unified system.
8. **No color grouping for tool categories**: File tools, shell tools, and search tools all look the same.
9. **Stylesheet covers only ~40% of visual output**: The rest is inline ANSI codes in widget render methods.

### Positive Findings

- **User message treatment is excellent**: White + bold + background fill makes user input immediately identifiable.
- **Task bar design is strong**: The `┌ │ └` box with breathing animation creates a clear, distinct zone.
- **Permission prompt design is strong**: Bordered, focused, with clear option hierarchy.
- **Context progress bar**: The `━━────` bar with color transitions is intuitive and informative.
- **Breathing animations**: The sine-wave color modulation on thinking/compacting/subagent loaders creates "alive" feeling without being distracting.
- **Discovery batch grouping**: The tree-style connector system groups related exploration operations effectively.
- **Collapsible widgets**: The expand/collapse pattern with preview lines is well-implemented and reduces visual noise.

---

## 12. Recommended Priority Stack for Implementation

| Priority | Change | Impact | Effort |
|----------|--------|--------|--------|
| **1** | Increase agent response text brightness | High | Low (stylesheet + Theme.php) |
| **2** | Add bottom padding to all conversation styles | High | Low (stylesheet only) |
| **3** | Dim tool call/result colors below response brightness | High | Medium (multiple widgets) |
| **4** | Add background fill to status bar | High | Medium (TuiCoreRenderer + stylesheet) |
| **5** | Separate warning color from accent color | Medium | Low (Theme.php) |
| **6** | Add left border to tool result blocks | Medium | Medium (CollapsibleWidget, BashCommandWidget) |
| **7** | Bold agent response headings in bright white | Medium | Low (MarkdownWidget config) |
| **8** | Bold error indicators | Medium | Low (TuiToolRenderer) |
| **9** | Group tool icons by category (file/shell/search) | Medium | Low (Theme.php) |
| **10** | Standardize border characters across widgets | Low | Medium (multiple widgets) |
| **11** | Migrate inline colors to stylesheet classes | Low | High (refactor) |
