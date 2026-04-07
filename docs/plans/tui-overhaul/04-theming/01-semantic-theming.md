# Semantic Theming System

> **Module**: `src/UI/Theme/` (new namespace), `src/UI/Tui/KosmokratorStyleSheet.php`
> **Dependencies**: Symfony TUI `Color`, `Style`, `StyleSheet`; existing `Theme.php` (migrated)
> **Blocks**: All widget styling, ANSI renderer colors, syntax highlighting theme

## 1. Problem Statement

### 1.1 Current State

Colors are defined in two disconnected places:

- **`Theme.php`** — 30+ static methods returning raw ANSI escape strings (`\033[38;2;...m`). Used by the ANSI renderer, diff renderer, and syntax highlighting. All colors are hardcoded RGB values.
- **`KosmokratorStyleSheet.php`** — 50+ `Color::hex('#...')` calls inlined throughout the stylesheet. Many colors duplicate or approximate the same palette from `Theme.php` (e.g., `#ffc850` = accent/gold in both, but `#ff3c28` vs `Theme::primary()` returning `rgb(255, 60, 40)`).

### 1.2 Issues

1. **No theme switching** — users cannot change the visual style without editing PHP source files.
2. **Duplicated palette** — `Theme.php` and `KosmokratorStyleSheet.php` maintain separate copies of the same color values with no single source of truth.
3. **No capability adaptation** — 24-bit true-color is always emitted, breaking on 16-color and 256-color terminals.
4. **No dark/light detection** — colors assume a dark terminal background. Light-background terminals get unreadable text.
5. **No accessibility** — no color-blind safe variant. Red/green distinction in diff views and status indicators is problematic for ~8% of males.
6. **No semantic abstraction** — callers invoke `Theme::success()` or `Color::hex('#50dc64')` depending on the renderer. No unified token system.
7. **Hard to extend** — adding a new theme requires editing 80+ scattered color literals across two files.

### 1.3 Goal

A **semantic token theming system** where:

- Colors are defined once as named tokens (`--primary`, `--success`, `--text`, etc.).
- Themes are data (PHP arrays or YAML), not code.
- Terminal capability is auto-detected and colors are downsampled gracefully.
- Dark/light background is auto-detected via OSC 11.
- At least 4 built-in themes ship with KosmoKrator.
- Users can define custom themes via `~/.kosmokrator/themes/` or inline in config.
- Both `Theme.php` (ANSI renderer) and `KosmokratorStyleSheet.php` (TUI renderer) resolve colors from the same theme instance.

---

## 2. Prior Art Research

### 2.1 Lazygit — Declarative YAML Theme Config

Lazygit uses a `config.yml` where users define colors by semantic name:

```yaml
gui:
  theme:
    activeBorderColor:
      - green
      - bold
    inactiveBorderColor:
      - white
    optionsTextColor:
      - blue
    selectedLineBgColor:
      - reverse
```

**Key insights:**
- Colors are arrays allowing attributes (bold, underline, reverse) alongside the color.
- Named colors map to standard 16 ANSI colors + 256 palette + hex.
- Theming is purely data-driven; no code changes needed.
- `activeBorderColor`, `inactiveBorderColor` — border colors are first-class semantic tokens, not afterthoughts.

**Applicability:** KosmoKrator's theme format should follow this pattern — semantic names → color + attributes. But we go further with full RGB + auto-downsampling.

### 2.2 Lip Gloss (Go) — Adaptive Colors

Lip Gloss provides `AdaptiveColor` that resolves differently based on dark/light terminal:

```go
var style = lipgloss.NewStyle().
    Foreground(lipgloss.AdaptiveColor{Light: "#000000", Dark: "#ffffff"})
```

Detection uses heuristics: check `$COLORFGBG`, `$TERM`, and OSC 11 query response.

**Key insights:**
- Every color has dark and light variants — single definition, automatic adaptation.
- No user configuration needed for basic dark/light support.
- Falls back gracefully when detection fails (assumes dark).

**Applicability:** Every semantic token in our system should support `dark` and `light` variants. The theme resolver auto-selects based on detected background.

### 2.3 Textual (Python) — CSS-Based Theming

Textual uses CSS files for theming:

```css
Screen {
    background: $surface;
    color: $text;
}

Button {
    background: $primary;
    color: $text;
    border: tall $primary-darken-2;
}
```

**Key insights:**
- CSS custom properties (`$primary`, `$surface`) are the semantic tokens.
- Color functions (`darken-2`, `lighten-1`) derive variants from base tokens.
- CSS cascade allows component-level overrides.
- Themes are `.tcss` files loadable at runtime.

**Applicability:** Our system doesn't use CSS syntax, but the token + derivation pattern is directly applicable. We provide a `Color::derive()` / `Color::shade()` / `Color::tint()` mechanism for computing variants from base tokens. Symfony TUI's `Color::mix()`, `Color::tint()`, `Color::shade()` already provide this.

### 2.4 Claude Code — Daltonized Theme

Claude Code includes a daltonized theme variant for color-blind users. Key characteristics:

- Avoids red/green as the sole indicator. Uses blue/orange, or shape/pattern differences.
- Success = green → replaced with cyan/blue or accompanied by icon (✓/✗).
- Error = red → replaced with orange/magenta or accompanied by icon.
- Diff adds = blue instead of green. Diff removes = orange instead of red.
- Consistent use of symbols alongside colors so information isn't color-only.

**Applicability:** We ship a `Daltonized` built-in theme that remaps all red/green-dependent tokens. The system also encourages all renderers to pair colors with symbols.

---

## 3. Architecture

### 3.1 Overview

```
┌──────────────────────────────────────────────────────────────────┐
│                        ThemeManager                               │
│  ┌─────────────┐  ┌──────────────┐  ┌─────────────────────────┐ │
│  │ TerminalProbe│  │ ThemeResolver│  │ ThemeRegistry           │ │
│  │             │  │              │  │                         │ │
│  │ colorLevel  │  │ activeTheme  │  │ built-in themes         │ │
│  │ bgLuminance │  │ resolved     │  │ user themes             │ │
│  │ isDarkBg    │  │ tokens       │  │ config overrides        │ │
│  └──────┬──────┘  └──────┬───────┘  └────────────┬────────────┘ │
│         │                │                        │              │
│         └────────────────┼────────────────────────┘              │
│                          │                                       │
│                    ┌─────▼──────┐                                │
│                    │ Resolved   │                                │
│                    │ Theme      │  (final token→Color map)       │
│                    └─────┬──────┘                                │
└──────────────────────────┼───────────────────────────────────────┘
                           │
              ┌────────────┼──────────────┐
              ▼            ▼              ▼
        ┌──────────┐ ┌───────────┐ ┌───────────────┐
        │ Theme.php│ │StyleSheet │ │TerminalTheme  │
        │ (ANSI)   │ │  (TUI)    │ │ (Syntax HL)   │
        │ resolve  │ │  resolve  │ │  resolve      │
        │ token→esc│ │  token→   │ │  token→esc    │
        │          │ │  Color    │ │               │
        └──────────┘ └───────────┘ └───────────────┘
```

### 3.2 New Files

| File | Purpose |
|------|---------|
| `src/UI/Theme/ThemeManager.php` | Central service: loads themes, probes terminal, resolves tokens |
| `src/UI/Theme/TerminalProbe.php` | Detects color capability, dark/light background |
| `src/UI/Theme/ThemeDefinition.php` | Value object: a full theme definition (token → color map) |
| `src/UI/Theme/BuiltIn/CosmicTheme.php` | Default theme (current palette refined) |
| `src/UI/Theme/BuiltIn/MinimalTheme.php` | Grayscale + single accent |
| `src/UI/Theme/BuiltIn/HighContrastTheme.php` | Maximum contrast for accessibility |
| `src/UI/Theme/BuiltIn/DaltonizedTheme.php` | Color-blind safe variant |
| `src/UI/Theme/ThemeLoader.php` | Loads user themes from YAML files |

### 3.3 Modified Files

| File | Change |
|------|--------|
| `src/UI/Theme.php` | Becomes a thin facade delegating to `ThemeManager`. Static API preserved for backward compat. |
| `src/UI/Tui/KosmokratorStyleSheet.php` | Receives `ThemeManager`, resolves tokens instead of hardcoded hex values |
| `src/UI/Ansi/KosmokratorTerminalTheme.php` | Resolves syntax highlight colors from theme tokens |
| `config/kosmokrator.yaml` | Add `ui.theme` section with theme selection + overrides |
| `src/Provider/ConfigServiceProvider.php` | Register `ThemeManager` as a singleton |

---

## 4. Semantic Token Specification

### 4.1 Token Hierarchy

Tokens are organized in three tiers:

1. **Base tokens** — the raw color values for a theme (what users override)
2. **Semantic tokens** — the functional role of a color in the UI
3. **Derived tokens** — computed from base/semantic tokens (shade, tint, mix)

### 4.2 Complete Token Set

```yaml
# ═══════════════════════════════════════════════════════════
#  CORE PALETTE — Base colors that define the theme's identity
# ═══════════════════════════════════════════════════════════

primary:        "#ff3c28"   # Brand color (fiery red-orange)
primary-dim:    "#a01e1e"   # Subdued primary for backgrounds/borders
accent:         "#ffc850"   # Highlight color (gold)
accent-dim:     "#b48c32"   # Subdued accent

# ═══════════════════════════════════════════════════════════
#  SEMANTIC — Functional colors used throughout the UI
# ═══════════════════════════════════════════════════════════

success:        "#50dc64"   # Positive/success state
warning:        "#ffc850"   # Caution/warning state
error:          "#ff5040"   # Error/danger state
info:           "#64c8ff"   # Informational state

# ═══════════════════════════════════════════════════════════
#  TEXT — Foreground colors for content
# ═══════════════════════════════════════════════════════════

text:           "#b4b4be"   # Default body text
text-bright:    "#f0f0f5"   # Emphasized text (white)
text-dim:       "#909090"   # Secondary/muted text
text-dimmer:    "#606060"   # Tertiary (separators, hints)
text-heading:   "#ffffff"   # Markdown headings

# ═══════════════════════════════════════════════════════════
#  UI ELEMENTS
# ═══════════════════════════════════════════════════════════

border-active:  "#c85a42"   # Focused widget border
border-inactive:"#6b3028"   # Unfocused widget border
border-task:    "#806428"   # Task/tool call borders
border-accent:  "#b48c32"   # Accent dialog borders
border-plan:    "#785ac8"   # Plan mode borders

background:     "#121212"   # Widget background
surface:        "#1a1a1a"   # Elevated surface
surface-bright: "#2a2a2a"   # Hovered/active surface

# ═══════════════════════════════════════════════════════════
#  DIFF
# ═══════════════════════════════════════════════════════════

diff-add:        "#3ca050"  # Added line foreground
diff-add-bg:     "#142d14"  # Added line background
diff-add-bg-strong: "#1e461e"  # Word-level add highlight
diff-remove:     "#b43c3c"  # Removed line foreground
diff-remove-bg:  "#370f0f"  # Removed line background
diff-remove-bg-strong: "#501414"  # Word-level remove highlight
diff-context:    "#909090"  # Unchanged context lines

# ═══════════════════════════════════════════════════════════
#  SYNTAX HIGHLIGHTING
# ═══════════════════════════════════════════════════════════

syntax-keyword:  "#c878ff"  # Language keywords
syntax-type:     "#ffc850"  # Type names / classes
syntax-value:    "#50dc64"  # String/boolean values
syntax-number:   "#ffc850"  # Numeric literals
syntax-literal:  "#64c8ff"  # True/false/null
syntax-variable: "#f0f0f5"  # Variable names
syntax-property: "#64c8ff"  # Object properties
syntax-comment:  "#909090"  # Comments
syntax-operator: "#f0f0f5"  # Operators
syntax-attribute:"#c878ff"  # Attributes/decorators
syntax-generic:  "#508cff"  # Generic/misc tokens
syntax-function: "#64c8ff"  # Function names

# ═══════════════════════════════════════════════════════════
#  AGENT TYPES
# ═══════════════════════════════════════════════════════════

agent-general:   "#daa520"  # General agent (goldenrod)
agent-plan:      "#a078ff"  # Plan agent (purple)
agent-explore:   "#64c8dc"  # Explore agent (cyan)
agent-waiting:   "#6495ed"  # Waiting/queued (blue)

# ═══════════════════════════════════════════════════════════
#  CODE BLOCKS
# ═══════════════════════════════════════════════════════════

code-fg:         "#c878ff"  # Inline code foreground
code-bg:         "#282828"  # Code block background

# ═══════════════════════════════════════════════════════════
#  MISCELLANEOUS
# ═══════════════════════════════════════════════════════════

link:            "#508cff"  # URL/link color
separator:       "#404040"  # Horizontal rule / separator
status-bar:      "#909090"  # Status bar text
thinking:        "#70a0d0"  # Thinking/processing indicator
compacting:      "#d04040"  # Compaction indicator
```

### 4.3 Token Resolution Rules

1. **Dark/Light dual values**: Every token may define `dark` and `light` variants:

   ```yaml
   text:
     dark: "#b4b4be"
     light: "#2a2a2a"
   ```

   When only a single value is provided, it's used for both. The `TerminalProbe` determines which variant to use.

2. **Derived tokens**: Tokens can reference other tokens with modifiers:

   ```yaml
   primary-dim: "shade(primary, 40)"    # 40% darker than primary
   border-task: "mix(primary, accent, 50)"  # 50/50 mix
   ```

   Supported functions: `shade(token, %)`, `tint(token, %)`, `mix(token_a, token_b, %)`, `alpha(token, %)`.

3. **Fallback chain**: If a token is missing, resolution falls back:
   - `text-heading` → `text-bright` → `text` → terminal default
   - `border-active` → `primary` → terminal default
   - `syntax-keyword` → `code-fg` → `accent` → terminal default

---

## 5. Theme Format Specification

### 5.1 PHP Theme Definition (Built-in Themes)

Built-in themes are PHP classes for type safety and IDE support:

```php
// src/UI/Theme/BuiltIn/CosmicTheme.php
namespace Kosmokrator\UI\Theme\BuiltIn;

use Kosmokrator\UI\Theme\ThemeDefinition;

class CosmicTheme extends ThemeDefinition
{
    public function name(): string { return 'cosmic'; }
    public function label(): string { return 'Cosmic'; }
    public function description(): string { return 'The default KosmoKrator theme — warm reds, golds, and cosmic purples'; }

    protected function tokens(): array
    {
        return [
            'primary'        => ['dark' => '#ff3c28', 'light' => '#cc2200'],
            'primary-dim'    => ['dark' => '#a01e1e', 'light' => '#cc6644'],
            'accent'         => ['dark' => '#ffc850', 'light' => '#b89020'],
            'accent-dim'     => ['dark' => '#b48c32', 'light' => '#c8a840'],
            'success'        => ['dark' => '#50dc64', 'light' => '#1a8c2a'],
            'warning'        => ['dark' => '#ffc850', 'light' => '#b89020'],
            'error'          => ['dark' => '#ff5040', 'light' => '#cc2200'],
            'info'           => ['dark' => '#64c8ff', 'light' => '#2070b0'],
            'text'           => ['dark' => '#b4b4be', 'light' => '#3a3a3a'],
            'text-bright'    => ['dark' => '#f0f0f5', 'light' => '#1a1a1a'],
            'text-dim'       => ['dark' => '#909090', 'light' => '#707070'],
            'text-dimmer'    => ['dark' => '#606060', 'light' => '#a0a0a0'],
            'text-heading'   => ['dark' => '#ffffff', 'light' => '#000000'],
            'border-active'  => ['dark' => '#c85a42', 'light' => '#b04530'],
            'border-inactive'=> ['dark' => '#6b3028', 'light' => '#c09888'],
            'border-task'    => ['dark' => '#806428', 'light' => '#a08040'],
            'border-accent'  => ['dark' => '#b48c32', 'light' => '#8a6a20'],
            'border-plan'    => ['dark' => '#785ac8', 'light' => '#6040a0'],
            'background'     => ['dark' => '#121212', 'light' => '#f5f5f5'],
            'surface'        => ['dark' => '#1a1a1a', 'light' => '#e8e8e8'],
            'surface-bright' => ['dark' => '#2a2a2a', 'light' => '#d0d0d0'],
            // ... diff, syntax, agent tokens
        ];
    }
}
```

### 5.2 YAML User Theme (Custom Themes)

User themes are YAML files in `~/.kosmokrator/themes/`:

```yaml
# ~/.kosmokrator/themes/my-theme.yaml
name: my-theme
label: "My Custom Theme"
description: "A custom dark theme"
parent: cosmic  # Optional: inherit from a built-in theme, override only what you want

tokens:
  primary: "#00aaff"       # Blue instead of red-orange
  accent: "#ff6600"        # Orange accent
  success: "#00cc66"
  error: "#ff3366"
  text: "#c0c0c0"
  # All other tokens inherited from 'cosmic'
```

### 5.3 Config Integration

In `config/kosmokrator.yaml` (or `.kosmokrator/config.yaml`):

```yaml
ui:
  renderer: auto
  intro_animated: true
  theme: cosmic                    # Theme name or path to YAML file
  # Inline token overrides (applied on top of the named theme):
  theme_overrides:
    primary: "#00aaff"
    accent: "#ff6600"
```

---

## 6. Terminal Probe

### 6.1 Color Level Detection

```php
// src/UI/Theme/TerminalProbe.php

enum ColorLevel: int
{
    case Mono = 0;        // No color support
    case Basic16 = 1;     // 16 ANSI colors
    case Palette256 = 2;  // 256-color palette
    case TrueColor = 3;   // 24-bit true color
}
```

Detection sequence:

| Check | Result |
|-------|--------|
| `COLORTERM=truecolor` or `COLORTERM=24bit` | `TrueColor` |
| `TERM=xterm-256color` or `TERM` contains `256color` | `Palette256` |
| `TERM=xterm`, `TERM=vt100`, etc. | `Basic16` |
| `NO_COLOR=1` (https://no-color.org) | `Mono` |
| No `TERM` set (CI/headless) | `Mono` |
| `TERM_PROGRAM=iTerm.app` or `WezTerm` or `kitty` | `TrueColor` (overrides) |

### 6.2 Dark/Light Background Detection

Three strategies, tried in order:

1. **`$COLORFGBG`** — Set by some terminals (`0;15` = dark bg, light fg). Parse the bg component.
2. **OSC 11 query** — Send `\033]11;?\033\\` and read the response (if terminal supports it, with a 100ms timeout).
3. **Fallback** — Assume dark.

The probe stores a `bgLuminance` float (0.0 = black, 1.0 = white). Threshold `0.5` determines dark vs light.

### 6.3 Color Downsampling

When `ColorLevel < TrueColor`, RGB colors are mapped to the best available representation:

- **TrueColor**: Use `\033[38;2;r;g;bm` (unchanged).
- **Palette256**: Map RGB → nearest 256-color index using Euclidean distance in the 6×6×6 color cube.
- **Basic16**: Map RGB → nearest named ANSI color (black, red, green, yellow, blue, magenta, cyan, white + bright variants).
- **Mono**: Strip all color; rely on bold/italic/underline for emphasis.

Symfony TUI's `Color` class already handles output formatting per type. The downsample logic converts the `Color` object to the appropriate type before it reaches the terminal layer.

```php
// In ThemeManager
public function resolveColor(string $token): Color
{
    $hex = $this->getResolvedToken($token); // '#ff3c28'

    return match ($this->colorLevel) {
        ColorLevel::TrueColor => Color::hex($hex),
        ColorLevel::Palette256 => Color::palette($this->nearest256($hex)),
        ColorLevel::Basic16 => Color::named($this->nearest16($hex)),
        ColorLevel::Mono => Color::named('default'),
    };
}
```

---

## 7. ThemeManager Service

### 7.1 API

```php
namespace Kosmokrator\UI\Theme;

class ThemeManager
{
    public function __construct(
        private readonly TerminalProbe $probe,
        private readonly ThemeRegistry $registry,
    ) {}

    /** Get the currently active theme definition. */
    public function activeTheme(): ThemeDefinition { ... }

    /** Resolve a semantic token to a Symfony TUI Color object (downsampled). */
    public function color(string $token): Color { ... }

    /** Resolve a semantic token to an ANSI escape string (for Theme.php facade). */
    public function ansi(string $token): string { ... }

    /** Resolve a semantic token to a background ANSI escape string. */
    public function ansiBg(string $token): string { ... }

    /** Get all resolved tokens as a flat map [token => Color]. */
    public function resolvedTokens(): array { ... }

    /** Whether the terminal has a dark background. */
    public function isDark(): bool { ... }

    /** Current terminal color level. */
    public function colorLevel(): ColorLevel { ... }

    /** Switch the active theme at runtime. */
    public function setTheme(string $name): void { ... }
}
```

### 7.2 Registration in ConfigServiceProvider

```php
// In ConfigServiceProvider::register():
$this->app->singleton(ThemeManager::class, function ($app) {
    $config = $app['config'];
    $probe = new TerminalProbe();
    $registry = new ThemeRegistry();

    // Register built-in themes
    $registry->register(new BuiltIn\CosmicTheme());
    $registry->register(new BuiltIn\MinimalTheme());
    $registry->register(new BuiltIn\HighContrastTheme());
    $registry->register(new BuiltIn\DaltonizedTheme());

    // Load user themes from ~/.kosmokrator/themes/*.yaml
    (new ThemeLoader())->loadUserThemes($registry);

    // Create manager and activate configured theme
    $manager = new ThemeManager($probe, $registry);
    $manager->setTheme($config->get('ui.theme', 'cosmic'));

    // Apply inline overrides
    $overrides = $config->get('ui.theme_overrides', []);
    if ($overrides) {
        $manager->applyOverrides($overrides);
    }

    return $manager;
});
```

---

## 8. Migration: Theme.php Facade

### 8.1 Backward-Compatible Delegation

The existing `Theme.php` static API is preserved. Internally, each method delegates to the `ThemeManager` singleton:

```php
// src/UI/Theme.php — after migration
class Theme
{
    private static ?ThemeManager $manager = null;

    /** Set the global ThemeManager instance (called during bootstrap). */
    public static function setManager(ThemeManager $manager): void
    {
        self::$manager = $manager;
    }

    private static function m(): ThemeManager
    {
        return self::$manager ??= self::defaultManager();
    }

    public static function primary(): string   { return self::m()->ansi('primary'); }
    public static function success(): string   { return self::m()->ansi('success'); }
    public static function error(): string     { return self::m()->ansi('error'); }
    public static function text(): string      { return self::m()->ansi('text'); }
    public static function dim(): string       { return self::m()->ansi('text-dim'); }
    // ... all existing methods preserved

    // Terminal control methods remain unchanged (no color dependency):
    public static function hideCursor(): string { return "\033[?25l"; }
    public static function moveTo(int $r, int $c): string { return "\033[{$r};{$c}H"; }
    // ... etc
}
```

**Migration strategy:**

1. Phase 1: Add `ThemeManager` + built-in themes. `Theme.php` constructs a default `CosmicTheme` manager if none injected (backward compat, no behavior change).
2. Phase 2: Wire `ThemeManager` into the DI container. Inject it into `KosmokratorStyleSheet` and `KosmokratorTerminalTheme`.
3. Phase 3: Add config loading, terminal probe, user themes.

### 8.2 Methods That Stay Hardcoded

These `Theme.php` methods don't use colors and remain as-is:

- `reset()`, `bold()`, `italic()`, `strikethrough()`
- `hideCursor()`, `showCursor()`, `clearScreen()`, `moveTo()`
- `toolIcon()`, `toolLabel()`
- `formatTokenCount()`, `formatCost()`, `relativePath()`
- `contextColor()`, `contextBar()` — these are derived from semantic tokens (`success`, `warning`, `error`), so they delegate to the manager.

---

## 9. Migration: KosmokratorStyleSheet

### 9.1 Current State

50+ hardcoded `Color::hex('#...')` calls in `KosmokratorStyleSheet::create()`.

### 9.2 Target State

`KosmokratorStyleSheet::create()` accepts a `ThemeManager` and resolves tokens:

```php
class KosmokratorStyleSheet
{
    public static function create(ThemeManager $theme): StyleSheet
    {
        return new StyleSheet([
            '.figlet-header' => new Style(
                color: $theme->color('primary'),
                bold: true,
                font: 'big',
                padding: new Padding(1, 2, 0, 2),
            ),

            '.subtitle' => new Style(
                color: $theme->color('accent'),
                italic: true,
                textAlign: TextAlign::Center,
                padding: new Padding(0, 2, 0, 2),
            ),

            '.tagline' => new Style(
                color: $theme->color('text-dim'),
                textAlign: TextAlign::Center,
                padding: new Padding(0, 2, 0, 2),
            ),

            '.user-message' => new Style(
                color: $theme->color('text-bright'),
                bold: true,
                padding: new Padding(1, 2, 0, 2),
            ),

            '.separator' => new Style(
                color: $theme->color('separator'),
                padding: new Padding(1, 2, 0, 2),
            ),

            '.tool-call' => new Style(
                padding: new Padding(1, 2, 0, 2),
                color: $theme->color('accent'),
            ),

            '.tool-success' => new Style(
                color: $theme->color('success'),
                padding: new Padding(0, 3, 0, 3),
            ),

            '.tool-error' => new Style(
                color: $theme->color('error'),
                padding: new Padding(0, 3, 0, 3),
            ),

            EditorWidget::class.'::frame' => new Style(
                color: $theme->color('border-inactive'),
            ),

            EditorWidget::class.':focus::frame' => new Style(
                color: $theme->color('border-active'),
            ),

            '.permission-prompt' => new Style(
                border: Border::all(1, BorderPattern::rounded(), $theme->color('accent')),
                padding: new Padding(0, 1, 0, 1),
                color: $theme->color('accent'),
            ),

            // ... all other selectors
        ]);
    }
}
```

### 9.3 Token → Selector Mapping

| StyleSheet Selector | Token |
|---------------------|-------|
| `.figlet-header` color | `primary` |
| `.subtitle` color | `accent` |
| `.tagline` / `.welcome` color | `text-dim` |
| `.user-message` color | `text-bright` |
| `.separator` color | `separator` |
| `.tool-call` / `.task-call` color | `accent` |
| `.tool-result` / `.tool-batch` / `.tool-shell` color | `text-dim` |
| `.tool-success` color | `success` |
| `.tool-error` color | `error` |
| `.status-bar` color | `status-bar` |
| `EditorWidget::frame` color | `border-inactive` |
| `EditorWidget:focus::frame` color | `border-active` |
| `ProgressBarWidget::bar-fill/progress` color | `success` |
| `ProgressBarWidget::bar-empty` color | `text-dimmer` |
| `.compacting` / `.compacting::spinner` color | `compacting` |
| `CancellableLoaderWidget` / `::spinner` / `::message` color | `thinking` |
| `.permission-prompt` border + color | `accent` |
| `SettingsListWidget` border | `accent` |
| `SettingsListWidget::label-selected` color | `text-bright` |
| `SettingsListWidget::value` color | `info` |
| `SettingsListWidget::value-selected` color | `success` |
| `SettingsListWidget::description` color | `text-dim` |
| `SettingsListWidget::hint` color | `text-dimmer` |

---

## 10. Migration: KosmokratorTerminalTheme

### 10.1 Target State

```php
class KosmokratorTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public function __construct(
        private readonly ThemeManager $theme,
    ) {}

    public function before(TokenType $tokenType): string
    {
        $token = match ($tokenType) {
            TokenTypeEnum::KEYWORD    => 'syntax-keyword',
            TokenTypeEnum::OPERATOR   => 'syntax-operator',
            TokenTypeEnum::TYPE       => 'syntax-type',
            TokenTypeEnum::VALUE      => 'syntax-value',
            TokenTypeEnum::NUMBER     => 'syntax-number',
            TokenTypeEnum::LITERAL    => 'syntax-literal',
            TokenTypeEnum::VARIABLE   => 'syntax-variable',
            TokenTypeEnum::PROPERTY   => 'syntax-property',
            TokenTypeEnum::GENERIC    => 'syntax-generic',
            TokenTypeEnum::COMMENT    => 'syntax-comment',
            TokenTypeEnum::ATTRIBUTE  => 'syntax-attribute',
            TokenTypeEnum::INJECTION  => null,
            TokenTypeEnum::HIDDEN     => null,
            default                   => null,
        };

        if ($token === null) {
            return $tokenType === TokenTypeEnum::HIDDEN ? "\033[8m" : '';
        }

        return $this->theme->ansi($token);
    }

    public function after(TokenType $tokenType): string
    {
        return Theme::reset();
    }

    public static function detectLanguage(string $path): string { /* unchanged */ }
}
```

---

## 11. Built-in Themes

### 11.1 Cosmic (Default)

The current KosmoKrator palette, refined:

| Token | Dark | Light |
|-------|------|-------|
| `primary` | `#ff3c28` | `#cc2200` |
| `accent` | `#ffc850` | `#b89020` |
| `success` | `#50dc64` | `#1a8c2a` |
| `error` | `#ff5040` | `#cc2200` |
| `info` | `#64c8ff` | `#2070b0` |
| `text` | `#b4b4be` | `#3a3a3a` |
| `text-bright` | `#f0f0f5` | `#1a1a1a` |

This is the palette already defined in `Theme.php` and `KosmokratorStyleSheet.php`, with light-mode variants added.

### 11.2 Minimal

Grayscale with a single blue accent. For users who want a clean, distraction-free look:

| Token | Dark | Light |
|-------|------|-------|
| `primary` | `#6688cc` | `#4466aa` |
| `accent` | `#8899bb` | `#556688` |
| `success` | `#88aa88` | `#447744` |
| `error` | `#cc8888` | `#aa4444` |
| `info` | `#7799bb` | `#4466aa` |
| `text` | `#aaaaaa` | `#444444` |
| `text-bright` | `#dddddd` | `#222222` |

All syntax-highlighting tokens map to 2–3 shades of gray-blue. Borders are uniform gray. No saturated colors anywhere.

### 11.3 High Contrast

Maximum contrast for users with low vision or bright environments:

| Token | Dark | Light |
|-------|------|-------|
| `primary` | `#ff6600` | `#ff4400` |
| `accent` | `#ffff00` | `#cc9900` |
| `success` | `#00ff00` | `#008800` |
| `error` | `#ff0000` | `#cc0000` |
| `info` | `#00ffff` | `#006688` |
| `text` | `#ffffff` | `#000000` |
| `text-bright` | `#ffffff` | `#000000` |

All borders are bright white/yellow. Bold is enabled on all text by default.

### 11.4 Daltonized

Color-blind accessible theme. Based on Claude Code's approach:

**Key changes from Cosmic:**
- `success` → cyan/teal instead of green (`#00cccc` dark, `#008888` light)
- `error` → orange/magenta instead of red (`#ff8800` dark, `#cc6600` light)
- `diff-add` → blue instead of green (`#4488ff` dark)
- `diff-remove` → orange instead of red (`#ff8800` dark)
- All status indicators pair colors with symbols (✓/✗/⚠)
- `warning` → yellow-orange (already distinguishable)
- Syntax highlighting avoids red/green as the only distinction

| Token | Dark | Light |
|-------|------|-------|
| `primary` | `#ff8844` | `#cc6622` |
| `accent` | `#ffc850` | `#b89020` |
| `success` | `#00cccc` | `#008888` |
| `error` | `#ff8800` | `#cc6600` |
| `info` | `#4488ff` | `#3366cc` |
| `diff-add` | `#4488ff` | `#3366cc` |
| `diff-remove` | `#ff8800` | `#cc6600` |

---

## 12. Implementation Plan

### Phase 1: Foundation (no behavior change)

| Step | File | Description |
|------|------|-------------|
| 1.1 | `src/UI/Theme/ColorLevel.php` | Enum for color capability levels |
| 1.2 | `src/UI/Theme/TerminalProbe.php` | Detect COLORTERM, TERM, OSC 11 |
| 1.3 | `src/UI/Theme/ThemeDefinition.php` | Abstract base for theme definitions |
| 1.4 | `src/UI/Theme/BuiltIn/CosmicTheme.php` | Default theme (current palette) |
| 1.5 | `src/UI/Theme/ThemeRegistry.php` | Registry of available themes |
| 1.6 | `src/UI/Theme/ThemeManager.php` | Core service with `color()`, `ansi()` methods |
| 1.7 | `tests/Unit/UI/Theme/` | Unit tests for probe, manager, registry |

**Deliverable:** `ThemeManager` can resolve `'cosmic'` theme tokens to `Color` objects. All tests pass. No existing code changes yet.

### Phase 2: Wire Theme.php

| Step | File | Description |
|------|------|-------------|
| 2.1 | `src/UI/Theme.php` | Add `setManager()` + delegate color methods |
| 2.2 | `src/Provider/ConfigServiceProvider.php` | Register `ThemeManager` singleton |
| 2.3 | Bootstrap | Call `Theme::setManager()` during app boot |

**Deliverable:** All existing renderers use the new system transparently. No visual change.

### Phase 3: Migrate KosmokratorStyleSheet

| Step | File | Description |
|------|------|-------------|
| 3.1 | `src/UI/Tui/KosmokratorStyleSheet.php` | Accept `ThemeManager`, resolve tokens |
| 3.2 | `src/UI/Tui/TuiRenderer.php` | Pass `ThemeManager` to stylesheet |
| 3.3 | Visual tests | Verify TUI rendering matches pre-migration |

**Deliverable:** TUI renderer fully theme-aware.

### Phase 4: Migrate Syntax Highlighting

| Step | File | Description |
|------|------|-------------|
| 4.1 | `src/UI/Ansi/KosmokratorTerminalTheme.php` | Accept `ThemeManager`, resolve syntax tokens |
| 4.2 | Update all `new KosmokratorTerminalTheme` call sites | Inject manager |

**Deliverable:** Syntax highlighting uses theme tokens.

### Phase 5: Additional Built-in Themes

| Step | File | Description |
|------|------|-------------|
| 5.1 | `src/UI/Theme/BuiltIn/MinimalTheme.php` | Grayscale theme |
| 5.2 | `src/UI/Theme/BuiltIn/HighContrastTheme.php` | Maximum contrast theme |
| 5.3 | `src/UI/Theme/BuiltIn/DaltonizedTheme.php` | Color-blind safe theme |
| 5.4 | Tests | Verify each theme resolves all tokens |

### Phase 6: User Customization

| Step | File | Description |
|------|------|-------------|
| 6.1 | `src/UI/Theme/ThemeLoader.php` | Load YAML themes from `~/.kosmokrator/themes/` |
| 6.2 | `config/kosmokrator.yaml` | Add `ui.theme` and `ui.theme_overrides` config keys |
| 6.3 | `src/UI/Theme/ThemeDefinition.php` | Support `parent` inheritance |
| 6.4 | `src/Command/ConfigCommand.php` | Add `/theme` subcommand for runtime switching |

### Phase 7: Terminal Adaptation

| Step | File | Description |
|------|------|-------------|
| 7.1 | `src/UI/Theme/TerminalProbe.php` | Full OSC 11 implementation with timeout |
| 7.2 | `src/UI/Theme/ColorDownsampler.php` | RGB → 256 → 16 color mapping |
| 7.3 | Integration | Auto-downsample based on probed `ColorLevel` |
| 7.4 | Dark/light testing | Verify light-mode variants on light backgrounds |

---

## 13. Testing Strategy

### 13.1 Unit Tests

| Test | Validates |
|------|-----------|
| `TerminalProbeTest` | Color level detection from env vars |
| `ThemeManagerTest` | Token resolution, dark/light switching, fallback chains |
| `ThemeDefinitionTest` | Token inheritance, override merging |
| `ThemeLoaderTest` | YAML parsing, validation, inheritance |
| `ColorDownsamplerTest` | RGB → 256/16 accuracy |

### 13.2 Visual Regression Tests

- Snapshot test each built-in theme against a representative TUI layout
- Compare rendered ANSI output per theme
- Verify downsampled output doesn't use unsupported escape sequences

### 13.3 Integration Tests

- Full render cycle: `ThemeManager` → `KosmokratorStyleSheet` → widget rendering
- Full render cycle: `ThemeManager` → `Theme.php` → ANSI renderer output
- Config loading: YAML theme → `ThemeManager` → resolved tokens

---

## 14. Open Questions

| # | Question | Default Answer |
|---|----------|----------------|
| 1 | Should themes support customizing padding/borders (not just colors)? | No — tokens are color-only. Layout stays in stylesheet. |
| 2 | Runtime theme switching (hot-reload) or restart required? | Hot-reload via `ThemeManager::setTheme()` + stylesheet rebuild. |
| 3 | Per-project themes (`.kosmokrator/config.yaml`)? | Yes — follows existing config layering (bundled → user → project). |
| 4 | Export current theme to YAML for user editing? | Yes — `ThemeManager::exportYaml()` dumps the resolved theme as a starter file. |
| 5 | Token derivation syntax (`shade(primary, 40)`) in YAML? | Deferred to Phase 8. Phase 1–7 use explicit values only. |
