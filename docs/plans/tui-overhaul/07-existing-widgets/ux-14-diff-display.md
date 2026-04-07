# UX Audit: Diff Display

> **Research Question**: How good is the diff display in KosmoKrator's TUI?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `DiffRenderer.php`, `CollapsibleWidget.php`, `Theme.php`, `TuiToolRenderer.php`, `KosmokratorTerminalTheme.php`

---

## Executive Summary

KosmoKrator's diff display is **architecturally strong** — it has line-level coloring, word-level change highlighting, syntax highlighting, context-aware padding, and large-diff truncation. This feature set places it above most terminal diff tools (including `git diff` and lazygit) and roughly on par with Claude Code's built-in diff. However, it falls short of the **gold standard** set by `delta` in several areas: the collapsed summary provides no diff preview, file headers don't show the filename inside the diff, word-level highlighting has a high threshold that suppresses it too often, and there is no unified file header that mirrors the `diff --git` convention users know from git.

**Overall Grade**: **B** — Feature-complete but with meaningful gaps in information density and visual refinement.

---

## 1. Architecture Overview

### 1.1 Rendering Pipeline

```
file_edit tool call
  → TuiToolRenderer::showToolResult()
    → buildDiffView(old_string, new_string, path)
      → DiffRenderer::render(old, new, path)
        → padWithFileContext()       // Add surrounding lines from disk
        → SebastianBergmann/Differ   // Unified diff algorithm
        → highlight()                // Tempest syntax highlighter
        → buildHunks()               // Group into hunks with context
        → applyWordDiffs()           // Word-level pairing
        → injectStrongBg()           // Stronger BG for changed words
    → CollapsibleWidget(✓, content, lineCount)
      → setExpanded(true)           // Diffs default to expanded
```

### 1.2 Key Components

| Component | File | Role |
|---|---|---|
| `DiffRenderer` | `src/UI/Diff/DiffRenderer.php` | Core diff engine — hunks, word diffs, syntax highlighting |
| `CollapsibleWidget` | `src/UI/Tui/Widget/CollapsibleWidget.php` | Container — collapsed preview (3 lines) or full expanded view |
| `Theme` | `src/UI/Theme.php` | Color definitions — diff add/remove/context/strong backgrounds |
| `TuiToolRenderer` | `src/UI/Tui/TuiToolRenderer.php` | Orchestrator — decides when to show diff vs raw output |

---

## 2. Line-Level Diff

### 2.1 Current Implementation

**Verdict: ✅ Strong**

Lines are clearly differentiated by color:

- **Removed lines**: Red foreground (`rgb(180, 60, 60)`) + dark red background (`bgRgb(55, 15, 15)`) + line number + `-` gutter
- **Added lines**: Green foreground (`rgb(60, 160, 80)`) + dark green background (`bgRgb(20, 45, 20)`) + line number + `+` gutter
- **Context lines**: Gray foreground (`color256(244)`) + dual line numbers + `│` gutter

The current rendering (`DiffRenderer.php:188-207`):

```
  45  47 │ class UserService              ← context: dim gray, dual numbers
  46   - │ -    private string $name;     ← removed: red fg + dark red bg
  47   + │ +    private ?string $name;    ← added: green fg + dark green bg
```

### 2.2 Comparison

| Tool | Line Colors | Background Fill | Gutter Style |
|---|---|---|---|
| **KosmoKrator** | ✅ Red/Green fg | ✅ Dark red/green bg | `N -` / `N +` / `N N │` |
| **delta** | ✅ Red/Green fg | ✅ Dark bg + side markers | `N │` / `N │` with +/- prefix |
| **Claude Code** | ✅ Red/Green | ✅ Subtle bg fill | `N` + color-coded gutter |
| **GitHub** | ✅ Red/Green | ✅ Full-width bg | `N` inline |
| **lazygit** | ✅ Red/Green | ❌ No background | `+`/`-` prefix only |

KosmoKrator matches delta and Claude Code in having both foreground and background fills — this is the right approach for readability. The dual line numbers for context lines (`45 47 │`) are a nice touch that neither delta nor lazygit show.

### 2.3 Issues

1. **Gutter alignment shifts** — Removed lines show `46  -` (number + dash), added lines show `47  +`, context shows `45 47 │`. The total gutter width varies, causing the code content to jump horizontally between line types. This is a minor but persistent alignment issue.

2. **Context line numbers are dim** — They use `diffContext()` (gray 244), same color as the line content itself, making the numbers blend into the code. Delta uses a distinctly different shade for line numbers.

---

## 3. Word-Level Highlighting

### 3.1 Current Implementation

**Verdict: ✅ Present, but conservative**

`DiffRenderer::applyWordDiffs()` (`DiffRenderer.php:233-290`) and `wordDiffPair()` (`DiffRenderer.php:299-367`) implement word-level diffs:

1. Paired removed/added lines are tokenized by whitespace (`preg_split('/(\s+)/'`)
2. Token-level diff is computed via `SebastianBergmann/Differ`
3. Changed token ranges get a **stronger background** color:
   - Strong remove: `bgRgb(80, 20, 20)` vs normal `bgRgb(55, 15, 15)`
   - Strong add: `bgRgb(30, 70, 30)` vs normal `bgRgb(20, 45, 20)`

### 3.2 Threshold Behavior

The `WORD_DIFF_THRESHOLD = 0.4` means word-level highlighting is **suppressed when >40% of tokens changed**. This is a reasonable heuristic — when a line is mostly rewritten, word diffing adds noise. However:

- **The delta is subtle**: `rgb(55,15,15)` → `rgb(80,20,20)` for removed is only a 25-unit red shift. On many terminal themes (especially dark ones), this is barely perceptible.
- **Whitespace-only tokenization**: Splitting on `\s+` means that changing `foo(bar)` to `foo(baz)` produces one large changed token `foo(bar)` → `foo(baz)` rather than highlighting just `bar` → `baz`. Character-level granularity within tokens is absent.

### 3.3 Comparison

| Tool | Word-Level | Granularity | Visibility |
|---|---|---|---|
| **KosmoKrator** | ✅ Yes | Whitespace tokens | Low (subtle bg shift) |
| **delta** | ✅ Yes | Word/character | High (distinct bg + underline) |
| **Claude Code** | ✅ Yes | Word | High (bold bg highlight) |
| **GitHub** | ✅ Yes | Word (split on punct) | Very high (bright bg patch) |
| **lazygit** | ❌ No | — | — |

### 3.4 Issues

1. **Whitespace tokenization too coarse** — Changes like `$foo = bar` → `$foo = baz` highlight the entire `bar`/`baz` token, which is fine. But `$foo->bar()` → `$foo->baz()` highlights `bar()` / `baz()` as whole tokens. Delta and GitHub split on punctuation boundaries.

2. **Strong BG is too subtle** — The 25-unit RGB shift is hard to see, especially in terminals with limited color fidelity or transparency. Consider increasing the delta to 50+ units, or using a distinctly different hue (e.g., warmer red for strong remove).

3. **No underline or bold for word diffs** — Delta uses underline on changed words, which is universally visible regardless of terminal color support. Adding bold or underline to the strong region would improve visibility.

---

## 4. Syntax Highlighting

### 4.1 Current Implementation

**Verdict: ✅ Implemented, with limitations**

`DiffRenderer::highlight()` uses Tempest Highlighter with `KosmokratorTerminalTheme`:

```
Token mapping:
  KEYWORD    → purple (code)
  OPERATOR   → white
  TYPE       → gold (warning)
  VALUE      → green (success)
  NUMBER     → gold (accent)
  LITERAL    → sky blue (info)
  COMMENT    → dim gray
  PROPERTY   → sky blue (info)
  GENERIC    → blue (link)
```

Language detection via `KosmokratorTerminalTheme::detectLanguage()` supports PHP, JS, TS, Python, SQL, HTML, CSS, JSON, XML, YAML, Markdown, Dockerfile, dotenv, INI, Twig, diff — **15 languages**.

### 4.2 How It Works

The highlighting is applied to the **full padded old/new blocks** before diff line mapping (`DiffRenderer.php:97-112`). This means syntax colors are baked into each line's `[2]` entry before hunk construction. The approach is correct — it ensures consistent highlighting across context/added/removed lines.

### 4.3 Issues

1. **Diff background colors override syntax colors** — The added/removed line backgrounds (`diffAddBg`, `diffRemoveBg`) are applied as full-line ANSI bg fills. When the syntax highlighter produces foreground colors, they compete with the background. In practice, green syntax tokens on a green diff background (or purple keywords on a red removal background) can reduce readability.

2. **No language for `.blade.php`, `.vue`, `.jsx`** — The detect function falls back to empty string for these extensions, resulting in no syntax highlighting. This is a common pain point.

3. **`.ts`/`.tsx` mapped to `javascript`** — TypeScript-specific tokens (type annotations, interfaces) won't be highlighted differently.

---

## 5. Context Lines

### 5.1 Current Implementation

**Verdict: ✅ Good**

`DiffRenderer::CONTEXT_LINES = 3` provides 3 lines of context before and after each change. This matches `git diff`'s default and is the standard.

The `padWithFileContext()` method (`DiffRenderer.php:448-488`) reads the actual file from disk and prepends/appends surrounding lines. This is a clever approach — it ensures context lines come from the file's current state, not just the old/new strings.

### 5.2 Issues

1. **File must exist on disk** — If `padWithFileContext()` can't read the file (new file, or path is empty), it falls back to `baseOffset = 0`. This means diffs for new files or in-memory-only edits may lack context. For `file_edit`, the file always exists, so this is fine.

2. **Context merging** — `buildHunks()` merges hunks when gaps are < `2 * CONTEXT_LINES` (6 lines). This is correct and avoids showing redundant separators between close changes.

---

## 6. File Headers

### 6.1 Current Implementation

**Verdict: ⚠️ Missing from diff content**

The file path is shown in the **tool call header** (`TuiToolRenderer.php:149`):

```
♅ Edit  src/Service/UserService.php
```

But the diff content itself has **no file header line**. There is no `diff --git a/file b/file` header, no `--- a/file` / `+++ b/file` lines, and no styled filename banner inside the diff.

The diff content starts immediately with context lines:

```
  45  47 │ class UserService
  46   - │ -    private string $name;
  47   + │ +    private ?string $name;
```

### 6.2 Comparison

| Tool | File Header | Location |
|---|---|---|
| **KosmoKrator** | ❌ Not in diff | Path shown in tool call header only |
| **delta** | ✅ Styled banner | `src/Service/UserService.php` centered, colored |
| **Claude Code** | ✅ Path shown | Above diff block |
| **GitHub** | ✅ Full header | `a/file → b/file` with fold controls |
| **lazygit** | ✅ File name | In side panel + diff header |

### 6.3 Issues

1. **When scrolled past the tool call, the user loses file context** — In a long conversation with multiple edits, scrolling through expanded diffs shows hunks and code but no filename. The user must scroll up to find the tool call header.

2. **No path in the collapsed preview** — When the `CollapsibleWidget` is collapsed (3-line preview), it shows the first 3 lines of diff content. These are typically context lines — gray code with no filename. The user sees:
   ```
   ✓ ⏋  45  47 │ class UserService
       │  46   - │ -    private string $name;
       │  47   + │ +    private ?string $name;
       ⊛ +5 lines (ctrl+o to reveal)
   ```
   There's no indication of which file this diff belongs to.

---

## 7. Collapsed View

### 7.1 Current Implementation

**Verdict: ⚠️ Weak for diffs**

`CollapsibleWidget` shows `PREVIEW_LINES = 3` lines of content when collapsed. For diffs, these 3 lines are the first 3 rendered lines — typically the start of the first hunk (context lines + first changes).

The collapse hint shows:
```
⊛ +N lines (ctrl+o to reveal)
```

### 7.2 Issues

1. **No diff summary in collapsed state** — When collapsed, the widget shows raw diff lines, not a human-readable summary like "3 additions, 2 removals". The change summary line (`✧ 3 additions, 2 removals`) is only visible at the bottom of the expanded view.

2. **First 3 lines may be pure context** — If the first change is 4+ lines into the diff, the collapsed preview shows only gray context lines with no colored additions/removals. The user sees nothing visually distinctive.

3. **Header is just `✓`** — The status indicator shows success/failure but not the file path. The tool call above has the path, but in the collapsed result widget, there's no path reference.

### 7.3 Comparison

| Tool | Collapsed View | Summary Quality |
|---|---|---|
| **KosmoKrator** | First 3 diff lines | Low — raw lines, no summary |
| **Claude Code** | Inline `[Edit file.rs]` badge + summary | High — filename + stat |
| **GitHub** | `+3 -2` diffstat bar | High — visual stat |
| **lazygit** | File list with `+N/-M` | High — stat per file |

---

## 8. Large Diffs

### 8.1 Current Implementation

**Verdict: ✅ Handled**

`DiffRenderer::MAX_HUNKS = 500` truncates diffs after 500 hunks, with a message:

```
    ... 42 more hunks omitted
```

Binary files get special handling (`DiffRenderer.php:55-61`):
```
[Binary file changed: old 12.3kB → new 14.7kB]
```

### 8.2 Issues

1. **500 hunks is very generous** — Most edits are <50 hunks. A 500-hunk diff would fill thousands of lines. Consider a lower default (50-100) with an option to expand.

2. **No "show more" interaction** — Truncated hunks are simply omitted with no way to see them. The user must scroll up to see earlier hunks.

3. **No line-level truncation within hunks** — A single hunk with 200 changed lines renders fully. For very large single-hunk changes (e.g., replacing an entire function), there's no intra-hunk truncation.

---

## 9. Feature Gap Analysis vs Gold Standard

### 9.1 Feature Matrix

| Feature | KosmoKrator | delta | Claude Code | GitHub | lazygit |
|---|---|---|---|---|---|
| Line-level coloring | ✅ | ✅ | ✅ | ✅ | ✅ |
| Background fills | ✅ | ✅ | ✅ | ✅ | ❌ |
| Word-level highlighting | ✅ (subtle) | ✅ (strong) | ✅ (strong) | ✅ (strong) | ❌ |
| Syntax highlighting | ✅ | ✅ | ✅ | ✅ | ❌ |
| File header | ❌ | ✅ | ✅ | ✅ | ✅ |
| Line numbers | ✅ (dual) | ✅ | ✅ | ✅ | ✅ |
| Context lines | ✅ (3) | ✅ (configurable) | ✅ | ✅ | ✅ |
| Hunk separators | ✅ (`· · ✧ · ·`) | ✅ (`⋯`) | ✅ | ✅ (fold) | ✅ |
| Change summary | ✅ (bottom) | ✅ | ✅ | ✅ (stat bar) | ✅ |
| Collapsed preview | ❌ (raw lines) | N/A | ✅ (badge) | ✅ (stat) | ✅ (stat) |
| Binary diff | ✅ | ✅ | ✅ | ✅ | ✅ |
| Large diff truncation | ✅ (500 hunks) | N/A | ✅ | ✅ | ✅ |
| No-newline-at-EOF | ✅ | ✅ | ❌ | ✅ | ✅ |

### 9.2 Unique Strengths

1. **Dual line numbers on context lines** — Showing both old and new line numbers (`45 47 │`) is rare and very useful.
2. **File-aware context padding** — Reading the actual file to provide real context lines (not just diff algorithm output) is clever.
3. **No-newline-at-EOF detection** — Proper `\ No newline at end of file` handling.

### 9.3 Key Gaps

1. **No file header inside diff** — Users lose context when scrolling.
2. **Word highlighting too subtle** — Background shift is nearly invisible.
3. **Collapsed view shows no summary** — Wastes the primary "at a glance" moment.
4. **No character-level diff** — Only whitespace-token-level.
5. **`apply_patch` gets no diff** — Only `file_edit` triggers the diff renderer.

---

## 10. Recommendations

### 10.1 Priority 1 — Add File Header Banner (Impact: High, Effort: Low)

Add a styled file path line at the top of each diff, before the first hunk:

**Current:**
```
  45  47 │ class UserService
  46   - │ -    private string $name;
```

**Proposed:**
```
  ── src/Service/UserService.php ──────────────────
  45  47 │ class UserService
  46   - │ -    private string $name;
```

Implementation: Prepend a file header line in `DiffRenderer::render()` using the `$path` parameter. Style with `Theme::dim()` and a horizontal rule. The path should be relative (`Theme::relativePath()`).

### 10.2 Priority 2 — Improve Word-Level Highlight Visibility (Impact: High, Effort: Medium)

Three changes:

1. **Increase color contrast**: Change strong backgrounds to be more distinct:
   - Remove: `bgRgb(120, 30, 30)` instead of `bgRgb(80, 20, 20)` 
   - Add: `bgRgb(40, 100, 40)` instead of `bgRgb(30, 70, 30)`

2. **Add underline to changed words**: Wrap strong regions in `\033[4m` (underline) for terminals that don't render color differences well.

3. **Improve tokenization**: Split on punctuation boundaries in addition to whitespace:
   ```php
   // Current: split on whitespace only
   preg_split('/(\s+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE)
   
   // Proposed: split on whitespace and punctuation boundaries
   preg_split('/(\s+|[.,;:(){}\[\]<>+\-*\/=!?&|@#$%^~]+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE)
   ```

### 10.3 Priority 3 — Collapsed Diff Summary (Impact: High, Effort: Medium)

When a `CollapsibleWidget` contains diff content (file_edit result), show a meaningful summary instead of raw lines:

**Current collapsed:**
```
✓ ⏋  45  47 │ class UserService
    │  46   - │ -    private string $name;
    │  47   + │ +    private ?string $name;
    ⊛ +5 lines (ctrl+o to reveal)
```

**Proposed collapsed:**
```
✓ ⏋  src/Service/UserService.php · +2 −1
    ⊛ ctrl+o to expand
```

Implementation options:
- **Option A**: `DiffRenderer` returns metadata (file, additions, removals) alongside the rendered string. `TuiToolRenderer` uses metadata for the collapsed summary.
- **Option B**: `CollapsibleWidget` accepts an optional `summary` parameter for collapsed display.

Option A is cleaner — it separates concerns properly.

### 10.4 Priority 4 — Syntax Highlighting Compatibility with Diff Colors (Impact: Medium, Effort: Medium)

The conflict between syntax highlighting foreground colors and diff background colors needs resolution. Two approaches:

1. **Desaturate syntax tokens inside diff lines** — When rendering a removed line, apply a color transform to the highlighted tokens that moves them toward the line's diff color. For example, keywords on a removed line should be tinted red rather than pure purple.

2. **Two-pass rendering** — Highlight first, then overlay diff colors. The current approach does this implicitly, but the diff background is applied as a full-line fill that can wash out syntax highlighting. Consider applying syntax colors as **subtle tints** on diff lines rather than full-strength colors.

Delta handles this well by using a darker, more saturated background that preserves foreground readability. Consider darkening the current backgrounds:
- Remove: `bgRgb(45, 10, 10)` (darker)
- Add: `bgRgb(15, 35, 15)` (darker)

### 10.5 Priority 5 — Diff for `apply_patch` (Impact: Medium, Effort: Medium)

`apply_patch` tool calls currently show raw text output, not formatted diffs. Since the tool already has `PermissionPreviewBuilder::previewPatch()` which parses unified diff format, the same parsing can be reused to render styled diffs for `apply_patch` results.

### 10.6 Priority 6 — Configurable Context Lines (Impact: Low, Effort: Low)

`CONTEXT_LINES = 3` is hardcoded. Allow configuration (e.g., via `/settings` or a keyboard shortcut to cycle 0→3→5→10 context lines). This is a quality-of-life improvement for power users.

---

## 11. Visual Mockups

### 11.1 Current Diff Display (Expanded)

```
✓ ⏋                                                        
     44  46 │ /**                                                    
     45  47 │  * Get the user's full name                           
     46   - │     public function getFullName(): string             
     47   - │     {                                                 
     48   - │         return $this->firstName . ' ' . $this->lastName;
     49   + │     public function getFullName(bool $withTitle = false): string
     50   + │     {                                                 
     51   + │         $name = $this->firstName . ' ' . $this->lastName;
     52   + │         if ($withTitle && $this->title) {             
     53   + │             $name = $this->title . ' ' . $name;      
     54   + │         }                                             
     55   + │         return $name;                                 
     56   + │     }                                                 
     57  53 │                                                       
     58  54 │     public function getEmail(): string               
          · · ✧ · ·                                                
     60  56 │     /**                                               
                                                         
         ✧ 9 additions, 3 removals
```

### 11.2 Proposed Diff Display (Expanded)

```
✓ ⏋  ── src/Service/UserService.php ────────────────────────
     44  46 │ /**                                                    
     45  47 │  * Get the user's full name                           
     46   - │     public function getFullName(): string             
     47   - │     {                                                 
     48   - │         return $this->firstName . ' ' . $this->lastName;
     49   + │     public function getFullName(bool $withTitle = false): string
     50   + │     {                                                 
     51   + │         $name = $this->firstName . ' ' . $this->lastName;
     52   + │         if ($withTitle && $this->title) {             
     53   + │             $name = $this->title . ' ' . $name;      
     54   + │         }                                             
     55   + │         return $name;                                 
     56   + │     }                                                 
     57  53 │                                                       
     58  54 │     public function getEmail(): string               
          · · ✧ · ·                                                
     60  56 │     /**                                               
                                                         
         ✧ 9 additions, 3 removals
```

Key changes:
- File path banner at top of diff content
- Stronger word-level highlighting on `$withTitle = false` and `if ($withTitle ...)` additions
- Context line numbers slightly dimmer than content

### 11.3 Current Collapsed View

```
✓ ⏋  44  46 │ /**
      45  47 │  * Get the user's full name
      46   - │     public function getFullName(): string
      ⊛ +14 lines (ctrl+o to reveal)
```

### 11.4 Proposed Collapsed View

```
✓ ⏋  src/Service/UserService.php  +9 −3
      ⊛ ctrl+o to expand
```

Key changes:
- File path replaces raw context lines
- Addition/removal counts visible at a glance
- Color the `+9` green and `−3` red for instant recognition

### 11.5 Word-Level Highlight Comparison

**Current (subtle):**
```
Background: rgb(55,15,15) → rgb(80,20,20) on removals
Visible delta: ~25 units — barely perceptible
```

**Proposed (visible):**
```
Background: rgb(55,15,15) → rgb(120,30,30) on removals + underline
Visible delta: ~65 units + underline — clearly distinct
```

Mockup of a line change `return $this->name` → `return $this->fullName`:

```
Current:
  [dark-red-bg] 48   - │     return $this->name; [/bg]
  [dark-green-bg] 49  + │     return $this->fullName; [/bg]
  (entire lines are uniformly colored — no word distinction)

Proposed:
  [dark-red-bg] 48   - │     return $this->[strong-red-bg+underline]name[/strong]; [/bg]
  [dark-green-bg] 49  + │     return $this->[strong-green-bg+underline]fullName[/strong]; [/bg]
```

---

## 12. Code-Level Findings

### 12.1 `DiffRenderer.php` — Positive Patterns

| Pattern | Location | Assessment |
|---|---|---|
| `padWithFileContext()` | Lines 448-488 | ✅ Elegant — reads disk file for real context |
| `WORD_DIFF_THRESHOLD` | Line 29 | ✅ Good heuristic — prevents noisy word diffs |
| Binary file detection | Lines 49-61 | ✅ Proper NUL-byte check |
| `noNewlineFlags` tracking | Lines 87-94 | ✅ Thorough edge case handling |
| `computeMaxLineNumber()` | Lines 496-510 | ✅ Dynamic gutter width |

### 12.2 `DiffRenderer.php` — Issues

| Issue | Location | Severity |
|---|---|---|
| No file header output | `render()` / `renderLines()` | Medium — user loses context |
| Whitespace-only tokenization | `tokenize()` line 375 | Medium — coarse word diff |
| `MAX_HUNKS = 500` too high | Line 24 | Low — unlikely to matter in practice |
| Strong bg color delta too small | Lines 437-442 | Medium — word highlighting nearly invisible |
| `injectStrongBg()` byte-level fallback | Lines 394-433 | Low — safety fallback for broken UTF-8 |

### 12.3 `CollapsibleWidget.php` — Diff-Specific Issues

| Issue | Location | Severity |
|---|---|---|
| `PREVIEW_LINES = 3` shows raw diff lines | Line 17 | Medium — poor summary |
| No awareness of content type | Entire class | Medium — treats diff same as plain text |
| Header is just `✓`/`✗` | Constructor | Low — no file path in result header |

### 12.4 `TuiToolRenderer.php` — Diff-Specific Issues

| Issue | Location | Severity |
|---|---|---|
| Only `file_edit` gets diff view | Line 256 | Medium — `apply_patch` and `file_write` don't |
| Diff auto-expands but has no summary | Line 273 | Low — expanding is good, but header lacks info |
| `buildDiffView()` discards metadata | Line 431 | Medium — no way to extract +N/-M counts for header |

---

## 13. Summary Scorecard

| Dimension | Score | Notes |
|---|---|---|
| Line-level clarity | **A** | Strong colors + backgrounds + dual line numbers |
| Word-level highlighting | **C+** | Implemented but too subtle, coarse tokenization |
| Syntax highlighting | **B** | Works, 15 languages, but conflicts with diff colors |
| Context lines | **A-** | 3 lines + file-aware padding is excellent |
| File headers | **D** | Missing from diff content entirely |
| Collapsed view | **D+** | Raw lines instead of summary, no filename |
| Large diff handling | **B** | 500-hunk truncation works, but no expand option |
| Edge cases (binary, no-newline) | **A** | Properly handled |
| **Overall** | **B** | Strong foundation, needs visual polish |

---

## 14. Recommended Implementation Order

1. **Add file header to diff content** — 1 hour, high impact
2. **Return diff metadata from `DiffRenderer`** — 2 hours (refactor return type)
3. **Implement collapsed diff summary** — 2 hours (depends on #2)
4. **Increase word-highlight contrast + add underline** — 1 hour
5. **Improve tokenization for punctuation** — 2 hours
6. **Add diff view for `apply_patch`** — 3 hours
7. **Configurable context lines** — 1 hour
8. **Desaturate syntax tokens in diff lines** — 3 hours (complex color math)

**Total estimated effort**: ~15 hours for a world-class diff display.
