# UX Audit: Accessibility

> **Research Question**: How accessible is KosmoKrator's TUI?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `Theme.php`, `TuiInputHandler.php`, `TuiAnimationManager.php`, `TuiCoreRenderer.php`, `KosmokratorStyleSheet.php`, `PermissionPromptWidget.php`, `PlanApprovalWidget.php`, `SettingsWorkspaceWidget.php`, `SwarmDashboardWidget.php`, `DiscoveryBatchWidget.php`, `BashCommandWidget.php`, `HistoryStatusWidget.php`
> **Cross-reference**: `docs/plans/tui-overhaul/04-theming/01-semantic-theming.md`, `docs/plans/tui-overhaul/04-theming/02-color-downsampling.md`, `docs/deep-audit-2026-04-04.md`

---

## Executive Summary

KosmoKrator's TUI has **significant accessibility gaps**. The application unconditionally emits 24-bit RGB escape codes, relies on color-only indicators for success/error/diff states, runs continuous 30fps breathing animations with no disable mechanism beyond an undocumented env var, provides zero screen reader support, and makes no accommodation for limited terminals. No `NO_COLOR`, `TERM`, or `COLORTERM` environment variable is checked at runtime. No high-contrast or daltonized theme exists in production code.

This is not unusual — terminal app accessibility is a systemic blind spot. Claude Code ships a daltonized theme and reduced-motion setting; Charm's `huh?` library offers an `ACCESSIBLE` env var; Aider detects `TERM=dumb`. KosmoKrator currently offers none of these.

**Severity**: High. Accessibility is a compliance and inclusion issue. ~8% of males have some form of color vision deficiency. Users in CI/SSH/dumb-terminal contexts get garbled output.

**Current accessibility score: 2/10** — keyboard navigation works, but everything else is absent.

---

## 1. Color-Blind Friendliness

### 1.1 Current State

`Theme.php` (lines 56–230) defines the entire palette using hardcoded 24-bit RGB values with no alternative variants:

| Token | Color | RGB | Color-Blind Issue |
|-------|-------|-----|-------------------|
| `success()` | Green | `(80, 220, 100)` | Indistinguishable from red for protan/deutan |
| `error()` | Red | `(255, 80, 60)` | Indistinguishable from green for protan/deutan |
| `diffAdd()` | Green | `(60, 160, 80)` | Same as above |
| `diffAddBg()` | Dark green | `(20, 45, 20)` | Same |
| `diffRemove()` | Red | `(180, 60, 60)` | Same |
| `diffRemoveBg()` | Dark red | `(55, 15, 15)` | Same |
| `contextColor(0.75+)` | Red | `error()` | Same |
| `contextColor(0.0–0.5)` | Green | `success()` | Same |

**Critical: Red/green is the most common color vision deficiency pair (~8% of males).** KosmoKrator uses this pair for the most critical UX signals: success vs failure, diff additions vs removals, and context window health.

### 1.2 Color-Only Indicators

Several indicators convey meaning through color alone, without accompanying symbols or text:

| Location | Indicator | Color-Only? | Risk |
|----------|-----------|-------------|------|
| `DiscoveryBatchWidget.php:170–172` | `success` → `Theme::success().'✓'` | No (has ✓) | Low |
| `DiscoveryBatchWidget.php:170–171` | `error` → `Theme::error().'✗'` | No (has ✗) | Low |
| `BashCommandWidget.php:166` | `Theme::error().'✗ '.$r` | No (has ✗) | Low |
| `Theme::contextBar()` (line 389) | Bar segments `━`/`─` | **Yes** | **High** |
| `Theme::contextColor()` (line 361) | Green/yellow/red ratio colors | **Yes** | **High** |
| `TuiAnimationManager.php:390–400` | Breathing color (blue vs amber) | **Yes** | Medium |
| `PermissionPreviewBuilder.php:138,151` | Diff `+`/`-` lines | Partial (has prefix) | Medium |
| `KosmokratorStyleSheet.php:112,117` | `.tool-success`/`.tool-error` styles | Need audit | Medium |

The `contextBar()` is the worst offender: a 16-character bar that transitions green → yellow → red with only line-weight characters (`━` vs `─`) distinguishing filled from empty. No symbols, no labels indicating "healthy" vs "warning" vs "critical".

### 1.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Charm huh? |
|---------|-------------|-------------|-------|------------|
| Daltonized theme | None (planned) | Shipped | None | None |
| Symbol+color pairing | Partial | Comprehensive | Partial | Partial |
| NO_COLOR support | None (planned) | Yes | Yes | Yes |
| Red/green avoidance | No | Yes (daltonized) | No | No |

### 1.4 Rating: 2/10

Symbols exist for some indicators (✓/✗ for success/error) but the context bar, diff colors, and phase colors are color-only.

---

## 2. Motion Sensitivity

### 2.1 Current Animation Inventory

KosmoKrator has three categories of persistent animation:

| Animation | Location | Framerate | Duration | Disable Mechanism |
|-----------|----------|-----------|----------|-------------------|
| Breathing pulse (thinking) | `TuiAnimationManager.php:378–426` | 30fps | Continuous during thinking | None at runtime |
| Breathing pulse (compacting) | `TuiAnimationManager.php:217–236` | 30fps | Continuous during compacting | None at runtime |
| Spinner frames | `TuiAnimationManager.php:71–86` | ~8fps (120ms) | During thinking | None at runtime |
| Intro animation | `AnsiIntro::animate()` | ~24fps | ~5–8s | `--no-animation` or `KOSMOKRATOR_NO_ANIM=1` |
| Power command animations | `AnsiPrometheus`, `AnsiUnleash`, etc. | ~24fps | ~3s each | `KOSMOKRATOR_NO_ANIM=1` |

The breathing animations are the most concerning: they run at **30fps continuously** for the entire duration of LLM thinking (potentially minutes). Each tick recalculates a sine wave and writes new ANSI color codes:

```php
// TuiAnimationManager.php:384–426
$this->thinkingTimerId = EventLoop::repeat(0.033, function () use ($phrase, $palette) {
    $this->breathTick++;
    $t = sin($this->breathTick * 0.07);
    // ... color modulation + render
});
```

### 2.2 Disable Mechanisms

| Mechanism | Exists | Documented | Scope |
|-----------|--------|------------|-------|
| `--no-animation` CLI flag | Yes (`AgentCommand.php:44`) | Yes (in `--help`) | Intro only |
| `KOSMOKRATOR_NO_ANIM=1` env var | Yes (`TuiCoreRenderer.php:274`) | **No** | Intro + power commands |
| Runtime toggle | **No** | N/A | N/A |
| Spinner disable | **No** | N/A | N/A |
| Breathing disable | **No** | N/A | N/A |
| `prefers-reduced-motion` detection | **No** | N/A | N/A |

**Critical gap**: Neither `--no-animation` nor `KOSMOKRATOR_NO_ANIM` disables the breathing animations or spinners during normal operation. They only affect the intro/power command cinematics.

### 2.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Charm huh? |
|---------|-------------|-------------|-------|------------|
| Reduced-motion setting | No | Yes | No | Via `ACCESSIBLE=1` |
| Animation disable | Partial (intro only) | Yes (all) | No | Yes (all) |
| Spinner disable | No | Yes | N/A | Yes |
| `prefers-reduced-motion` | No | No (terminal limitation) | No | No |

### 2.4 Rating: 3/10

The intro can be disabled, but the continuous thinking/tool animations cannot. The env var exists but is undocumented.

---

## 3. Screen Reader Support

### 3.1 Current State

**Zero screen reader support.** No evidence of any screen reader consideration anywhere in the codebase:

- No ARIA-like announcement mechanism
- No `accessibility` attributes on any widget
- No semantic labeling beyond widget IDs
- No text-based fallbacks for visual indicators
- No consideration for how screen readers interpret terminal escape sequences

The TUI framework (Symfony TUI) writes raw ANSI escape sequences to the terminal. Screen readers attempt to parse these, but:
- Color changes are announced as meaningless escape codes or ignored entirely
- Cursor movement creates confusion about reading order
- The continuous 30fps re-renders produce a stream of changes that overwhelm screen readers
- Widget IDs like `'loader'`, `'compacting-loader'`, `'slash-completion'` are internal and not announced

### 3.2 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Charm huh? |
|---------|-------------|-------------|-------|------------|
| Screen reader mode | None | None | None | `ACCESSIBLE=1` env |
| Text announcements | None | None | None | Simplified output |
| Semantic labels | None | None | None | Partial |

Charm's `huh?` is the only terminal UI library that explicitly addresses this. When `ACCESSIBLE=1` is set, it:
- Disables all animations and spinners
- Replaces visual indicators with plain text
- Simplifies the layout to a single-column flow
- Uses numbered selections instead of cursor-based navigation

### 3.3 Rating: 1/10

No screen reader support exists. The continuous re-rendering actively harms screen reader output.

---

## 4. Keyboard-Only Navigation

### 4.1 Current State

KosmoKrator's keyboard navigation is **the strongest accessibility dimension**. The entire UI is keyboard-driven by design:

**Input handling** (`TuiInputHandler.php`):

| Key | Action | Context |
|-----|--------|---------|
| Enter | Submit message | Prompt |
| Shift+Enter / Alt+Enter | New line | Prompt |
| Shift+Tab | Cycle mode (edit/plan/ask) | Prompt |
| Page Up / Page Down | Scroll history | Prompt |
| End | Jump to live output | Prompt (when browsing) |
| Escape / Ctrl+C | Cancel request | Prompt (when thinking) |
| Escape / Ctrl+C | Exit | Prompt (idle) |
| Tab | Accept completion | Completion open |
| ↑ / ↓ | Navigate completions | Completion open |
| Ctrl+A | Toggle agent dashboard | During agent activity |
| Ctrl+L | Force re-render | Always |

**Widget keybindings** (all use `KeybindingsTrait`):

| Widget | Keys | Navigation |
|--------|------|------------|
| `PermissionPromptWidget` | ↑/↓ navigate, Enter confirm, Escape/Ctrl+C cancel | Arrow keys |
| `PlanApprovalWidget` | ↑/↓/←/→ navigate, Enter confirm, Escape/Ctrl+C cancel | Arrow keys |
| `SettingsWorkspaceWidget` | ↑/↓/←/→ navigate, Tab/Shift+Tab categories, Enter select, Ctrl+S save, Escape discard, s/q save+close | Full keyboard |
| `SwarmDashboardWidget` | Escape/Ctrl+C close | Minimal |
| `SelectListWidget` (completions) | ↑/↓ navigate, Enter/tab select, Escape close | Arrow keys |

### 4.2 Gaps

1. **No Tab-to-focus**: Unlike GUI applications, there is no universal Tab order through widgets. Focus is implicit — the input always has focus unless a modal is open.
2. **No F1/? help overlay**: No discoverable keyboard shortcut reference accessible from the prompt.
3. **Ctrl+A for agents**: Single-key chord for dashboard is not documented or discoverable.
4. **No keyboard shortcut for tool result expansion**: `expand_tools` keybinding exists but is not documented and its default key is unclear.
5. **No number-based selection**: Some competitors (Charm huh?) allow number-key selection from lists, which is faster and more accessible.

### 4.3 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Lazygit |
|---------|-------------|-------------|-------|---------|
| Full keyboard navigation | Yes | Yes | Yes (readline) | Yes |
| Shortcut help overlay | No | Partial | Yes (`?`) | Yes (`?`) |
| Keybinding customization | No | No | Yes | Yes |
| Number-based selection | No | No | No | No |

### 4.4 Rating: 7/10

Keyboard navigation works comprehensively. The gaps are in discoverability, not capability.

---

## 5. Terminal Compatibility

### 5.1 Current State

`Theme.php` unconditionally emits 24-bit color sequences:

```php
// Theme.php:26-29
public static function rgb(int $r, int $g, int $b): string
{
    return self::ESC."[38;2;{$r};{$g};{$b}m";  // Always 24-bit
}
```

`TuiCoreRenderer.php` also uses hardcoded escape sequences:

```php
// TuiCoreRenderer.php:76
private string $currentModeColor = "\033[38;2;80;200;120m";
// TuiCoreRenderer.php:82
private string $currentPermissionColor = "\033[38;2;180;180;200m";
```

**No terminal capability detection exists in production code.** The following standards are not checked:

| Standard | Purpose | Checked? |
|----------|---------|----------|
| `NO_COLOR` (no-color.org) | User opt-out of color | **No** |
| `COLORTERM` | Truecolor support detection | **No** |
| `TERM` | Terminal type identification | **No** |
| `TERM=dumb` | Minimal terminal flag | **No** |
| `TERM_PROGRAM` | Terminal emulator identification | **No** |

The deep audit (`docs/deep-audit-2026-04-04.md:77`) flagged this:

> Unconditional 24-bit color + Unicode. No `NO_COLOR`, `COLORTERM`, or `TERM` check. Garbled on limited terminals.

### 5.2 Unicode Dependence

`Theme::toolIcon()` (line 294) returns Unicode glyphs that may not render in all terminals:

- `☽`, `☉`, `♅`, `✎`, `✦`, `⚡︎`, `⊛`, `✧`, `⊕`, `⊙`, `☰`, `⊘`, `⏺`, `◈`
- Spinner frames: `☿`, `♀`, `♁`, `♂`, `♃`, `♄`, `♅`, `♆`, `🜁`, `🜂`, `🜃`, `🜄`, `ᚠ`, `ᚢ`, `ᚦ`, `ᚨ`, `ᚱ`, `ᚲ`, `ᚷ`, `ᚹ`

Many of these are outside the BMP (alchemical symbols) or are rarely included in terminal fonts.

### 5.3 Planned Improvements

The theming overhaul plan (`04-theming/02-color-downsampling.md`) includes:
- `TerminalProbe` class to detect `COLORTERM`, `TERM`, `NO_COLOR`
- Color level enum: `Ascii`, `Ansi16`, `Ansi256`, `TrueColor`
- Automatic downconversion from TrueColor to supported level
- `NO_COLOR=1` → `Ascii` profile (monochrome)

**None of this is implemented yet.**

### 5.4 Comparison

| Feature | KosmoKrator | Claude Code | Aider | Charm huh? |
|---------|-------------|-------------|-------|------------|
| NO_COLOR support | Planned | Yes | Yes | Yes |
| TERM detection | Planned | Yes | Yes | Yes |
| Dumb terminal fallback | Planned | Yes | Yes | Yes |
| Color downsampling | Planned | Yes | No | No |
| ASCII fallback for icons | No | Partial | No | Yes (`ACCESSIBLE=1`) |

### 5.5 Rating: 2/10

No terminal capability detection. 24-bit color and obscure Unicode are unconditional.

---

## 6. High Contrast Mode

### 6.1 Current State

**No high-contrast mode exists.** The current palette is optimized for dark terminal backgrounds:

- Body text: `rgb(180, 180, 190)` — low contrast against dark backgrounds
- Dim text: `color256(240)` — very low contrast
- Dimmer text: `color256(236)` — nearly invisible on some dark themes
- Border colors: heavily dimmed variants (`rgb(128, 100, 40)`, `rgb(120, 90, 200)`)

Light terminal backgrounds receive no accommodation. The planned `HighContrastTheme` (`04-theming/01-semantic-theming.md:168`) would use bright white/yellow borders and bold text, but is not implemented.

### 6.2 Contrast Ratios

Using the default dark terminal background (~`rgb(30, 30, 30)`):

| Element | Foreground | Approximate Contrast Ratio | WCAG AA (4.5:1) |
|---------|------------|---------------------------|-----------------|
| `text()` | `rgb(180, 180, 190)` | ~8.5:1 | ✅ Pass |
| `dim()` | `color256(240)` (~`rgb(200, 200, 200)`) | ~9:1 | ✅ Pass |
| `dimmer()` | `color256(236)` (~`rgb(130, 130, 130)`) | ~4:1 | ❌ Fail |
| `dimWhite()` | `rgb(140, 140, 150)` | ~4.5:1 | ⚠️ Borderline |
| `borderTask()` | `rgb(128, 100, 40)` | ~2.5:1 | ❌ Fail |
| `diffAddBg()` | `rgb(20, 45, 20)` bg | ~1.5:1 (bg only) | ❌ Fail |
| `diffRemoveBg()` | `rgb(55, 15, 15)` bg | ~1.5:1 (bg only) | ❌ Fail |

### 6.3 Rating: 2/10

No high-contrast mode. Several elements fail WCAG AA contrast requirements on dark backgrounds.

---

## 7. Text Size / Scaling

### 7.1 Current State

**No text size or scaling support.** Terminal UIs are inherently constrained to the terminal's font configuration. KosmoKrator does not:

- Detect or adapt to terminal font size
- Provide a wide/compact mode for different character cell sizes
- Adjust layout for narrow terminals (no minimum width enforcement)
- Use any proportional sizing system

`HistoryStatusWidget.php:64` hardcodes spacing calculations based on `$context->getColumns()`, which adapts to terminal width but not to character aspect ratio or font size.

### 7.2 Rating: 3/10

Standard terminal limitation. Some adaptation is possible (responsive layout) but none is implemented.

---

## 8. WCAG Principles Applied to Terminal Apps

WCAG 2.1 is designed for web content but its four principles map to terminal apps:

### 8.1 Perceivable

| Principle | Status | Evidence |
|-----------|--------|----------|
| Non-text content has alternatives | ❌ Fail | Unicode icons (`☽`, `☉`, etc.) have no text fallback |
| Color is not the only visual means | ❌ Fail | Context bar, breathing colors, diff backgrounds |
| Content adaptable to presentation | ❌ Fail | No theme switching, no NO_COLOR support |
| Content distinguishable (contrast) | ❌ Fail | `dimmer()`, borders, diff backgrounds fail AA |

### 8.2 Operable

| Principle | Status | Evidence |
|-----------|--------|----------|
| Keyboard accessible | ✅ Pass | All interactions keyboard-driven |
| Enough time | ⚠️ Partial | No timeout indicators, animations run indefinitely |
| Seizures/physical reactions | ❌ Fail | 30fps continuous animation with no disable |
| Navigable | ⚠️ Partial | No help overlay, no shortcut discoverability |
| Input modalities | ⚠️ Partial | Mouse support planned but not shipped |

### 8.3 Understandable

| Principle | Status | Evidence |
|-----------|--------|----------|
| Readable | ✅ Pass | Human-readable tool labels, clear prompts |
| Predictable | ✅ Pass | Consistent navigation patterns |
| Input assistance | ⚠️ Partial | Completions exist, no undo/history, no help |

### 8.4 Robust

| Principle | Status | Evidence |
|-----------|--------|----------|
| Compatible with assistive tech | ❌ Fail | No screen reader support |
| Compatible with terminals | ❌ Fail | No capability detection, garbled on limited terminals |

---

## 9. Accessibility Checklist

### Current State

- [ ] **A1: NO_COLOR support** — Application respects the `NO_COLOR` environment variable (no-color.org)
- [ ] **A2: Color-blind safe palette** — A daltonized theme variant is available
- [ ] **A3: No color-only indicators** — All color-dependent information has a symbol/text alternative
- [ ] **A4: High-contrast theme** — A theme with WCAG AA-compliant contrast ratios
- [ ] **A5: Reduced-motion toggle** — All animations can be disabled via setting or env var
- [ ] **A6: Breathing animation disable** — The 30fps breathing pulse can be stopped
- [ ] **A7: Spinner disable** — Loading spinners can be replaced with static text
- [ ] **A8: Screen reader mode** — An `ACCESSIBLE=1` or equivalent env var simplifies output
- [ ] **A9: Terminal capability detection** — `COLORTERM`, `TERM`, `NO_COLOR` are probed
- [ ] **A10: 16-color fallback** — Colors degrade gracefully for basic terminals
- [ ] **A11: ASCII icon fallback** — Unicode icons have ASCII alternatives for limited fonts
- [ ] **A12: Dumb terminal detection** — `TERM=dumb` triggers minimal output mode
- [ ] **A13: Contrast ratios** — All text meets WCAG AA (4.5:1) against expected backgrounds
- [ ] **A14: Keyboard shortcut help** — A `?` or `F1` help overlay lists all bindings
- [ ] **A15: Focus indicators** — Active/focused widgets have a visible indicator beyond cursor
- [ ] **A16: Configurable cursor shape** — Users can choose block/bar/underline
- [ ] **A17: Keybinding customization** — Users can remap keys via config file
- [ ] **A18: Text-based progress** — Progress bars have a text alternative (percentage, label)
- [ ] **A19: Announce state changes** — Phase transitions (thinking → tools → idle) have text output
- [ ] **A20: Minimum terminal width** — Layout degrades gracefully below 80 columns

### Passed (2)

- [x] **K1: Full keyboard navigation** — All interactions accessible via keyboard
- [x] **K2: Consistent navigation patterns** — ↑/↓/Enter/Escape used consistently across widgets

---

## 10. Comparison Matrix

| Accessibility Feature | KosmoKrator | Claude Code | Aider | Charm huh? | Lazygit |
|-----------------------|-------------|-------------|-------|------------|---------|
| Color-blind theme | Planned | ✅ Daltonized | ❌ | ❌ | ❌ |
| NO_COLOR support | Planned | ✅ | ✅ | ✅ | ✅ |
| Reduced motion | Partial (intro) | ✅ | ❌ | ✅ (`ACCESSIBLE`) | ❌ |
| Screen reader mode | ❌ | ❌ | ❌ | ✅ (`ACCESSIBLE=1`) | ❌ |
| High-contrast theme | Planned | ✅ | ❌ | ❌ | ✅ |
| Terminal detection | Planned | ✅ | ✅ | ✅ | ✅ |
| Full keyboard nav | ✅ | ✅ | ✅ | ✅ | ✅ |
| Shortcut help | ❌ | ❌ | ✅ (`?`) | ❌ | ✅ (`?`) |
| Configurable bindings | ❌ | ❌ | ✅ | ❌ | ✅ |
| ASCII fallback | ❌ | Partial | ❌ | ✅ | ❌ |
| Cursor shape config | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 11. Recommendations

### Priority 1: Foundation (enables all other accessibility work)

| # | Recommendation | Effort | Impact | Files |
|---|---------------|--------|--------|-------|
| R1 | **Implement `TerminalProbe`** — detect `NO_COLOR`, `COLORTERM`, `TERM`, `TERM_PROGRAM` | Medium | Critical | New `src/UI/Theme/TerminalProbe.php` |
| R2 | **Respect `NO_COLOR`** — when set, `Theme::rgb()` and `Theme::color256()` return empty strings | Low | High | `Theme.php` |
| R3 | **Add `--accessible` CLI flag** — disables animations, spinners, breathing; simplifies icons | Medium | High | `AgentCommand.php`, `TuiCoreRenderer.php`, `TuiAnimationManager.php` |
| R4 | **Support `KOSMOKRATOR_ACCESSIBLE=1` env var** — same as `--accessible`, for non-CLI contexts | Low | Medium | `TuiCoreRenderer.php` |

### Priority 2: Color-Blind Safety

| # | Recommendation | Effort | Impact | Files |
|---|---------------|--------|--------|-------|
| R5 | **Ship Daltonized theme** — remap success→cyan, error→orange, diff-add→blue, diff-remove→orange | Medium | High | New `src/UI/Theme/BuiltIn/DaltonizedTheme.php` |
| R6 | **Add symbols to context bar** — append text labels: `(healthy)`, `(warning)`, `(critical)` | Low | High | `Theme::contextBar()` |
| R7 | **Pair all colors with symbols** — audit every color-only indicator and add a symbol or text | Low | High | `DiscoveryBatchWidget`, `BashCommandWidget`, `PermissionPreviewBuilder` |

### Priority 3: Motion Control

| # | Recommendation | Effort | Impact | Files |
|---|---------------|--------|--------|-------|
| R8 | **Disable breathing animation in accessible mode** — replace with static colored text + elapsed timer | Low | High | `TuiAnimationManager.php:378–426` |
| R9 | **Replace spinners with static indicator** — `◆` instead of rotating frames in accessible mode | Low | Medium | `TuiAnimationManager.php:71–86` |
| R10 | **Document `KOSMOKRATOR_NO_ANIM`** — add to `--help` output and docs | Low | Low | `AgentCommand.php` |
| R11 | **Add `reduced_motion` setting** — `kosmokrator.ui.reduced_motion` config option | Low | Medium | `SettingsSchema.php` |

### Priority 4: Terminal Compatibility

| # | Recommendation | Effort | Impact | Files |
|---|---------------|--------|--------|-------|
| R12 | **Implement color downsampling** — 24-bit → 256-color → 16-color → monochrome cascade | Medium | High | New `src/UI/Theme/ColorDownsampler.php` |
| R13 | **ASCII icon fallback** — when terminal doesn't support Unicode, use `*`, `>`, `!`, etc. | Low | Medium | `Theme::toolIcon()` |
| R14 | **Detect `TERM=dumb`** — fall back to line-buffered plain text output | Medium | Medium | `TerminalProbe.php` |
| R15 | **Minimum width enforcement** — warn or adapt layout below 80 columns | Low | Low | `TuiCoreRenderer.php` |

### Priority 5: Discoverability & Robustness

| # | Recommendation | Effort | Impact | Files |
|---|---------------|--------|--------|-------|
| R16 | **Add `?` help overlay** — show keyboard shortcuts for current context | Medium | Medium | New widget |
| R17 | **Add cursor shape configuration** — block/bar/underline via setting | Low | Low | `EditorWidget` config |
| R18 | **Announce phase transitions as text** — output "Thinking...", "Executing tools...", "Done." as plain text in accessible mode | Low | Medium | `TuiCoreRenderer.php` |
| R19 | **Keybinding configuration file** — `~/.config/kosmokrator/keybindings.json` | High | Medium | New subsystem |

---

## 12. Proposed Accessible Mode Specification

Inspired by Charm's `ACCESSIBLE` env var and Claude Code's reduced-motion setting:

```
KOSMOKRATOR_ACCESSIBLE=1 kosmokrator
# or
kosmokrator --accessible
# or
/settings → UI → accessible: true
```

**Behavior when enabled:**

| Feature | Normal | Accessible |
|---------|--------|------------|
| Colors | 24-bit RGB | 16-color ANSI or monochrome (via `NO_COLOR`) |
| Intro animation | Full Theogony | Static logo for 0.5s |
| Thinking indicator | Breathing + spinner | Static `◆ Thinking... (1:23)` |
| Compacting indicator | Breathing + spinner | Static `◆ Compacting... (0:05)` |
| Power command animations | Full cinematic | Skipped |
| Tool icons | Unicode `☽☉♅✎` | ASCII `[R][W][E][P]` |
| Spinner frames | Unicode glyphs | Static `◆` |
| Completions | Popup list | Inline numbered list |
| Diff colors | Red/green backgrounds | `+`/`-` prefixes with bold |
| Context bar | Colored bar | Text: `Context: 45k/200k (22%) — healthy` |

This mode would also be **automatically activated** when:
- `TERM=dumb`
- `NO_COLOR` is set (for the monochrome aspects)
- stdout is not a TTY (pipe/redirect)

---

## 13. Implementation Priority

```
Phase 1 (immediate):  R1, R2, R3, R10  — TerminalProbe, NO_COLOR, --accessible flag, docs
Phase 2 (with theming): R5, R6, R7, R12, R13  — Daltonized theme, symbols, downsampling, ASCII
Phase 3 (animation):  R4, R8, R9, R11, R18  — Breathing disable, spinner fallback, phase text
Phase 4 (polish):     R14, R15, R16, R17, R19  — Dumb terminal, help overlay, keybindings
```

Phase 1 can be shipped independently and immediately improves accessibility for users in CI, SSH, and limited terminal environments.

---

## 14. Conclusion

KosmoKrator's accessibility posture is typical for terminal applications: keyboard navigation works well, but everything else is absent. The planned theming overhaul (`04-theming/`) addresses color capability detection and daltonized themes, but does not cover animation control, screen reader support, or an accessible mode toggle.

The single highest-impact change is **implementing an accessible mode** (R3/R4) that disables all animations and simplifies output. This would immediately improve the experience for users with motion sensitivity, screen readers, limited terminals, and CI environments. Combined with `NO_COLOR` support (R2) and terminal capability detection (R1), KosmoKrator would go from accessibility laggard to accessibility leader among terminal-based coding agents.
