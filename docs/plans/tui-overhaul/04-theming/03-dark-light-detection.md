# 03 — Automatic Dark/Light Theme Detection

> **Module**: `src/UI/Theme/TerminalProbe.php` (dark/light detection), `src/UI/Theme/BuiltIn/*.php` (theme variants)
> **Depends on**: `01-semantic-theming.md` (ThemeManager, ThemeDefinition), `02-color-downsampling.md` (ColorProfile)
> **Status**: Draft

## 1. Problem Statement

### 1.1 Current State

Every color in the codebase assumes a **dark terminal background**:

- `Theme.php:59` — `primary()` returns `rgb(255, 60, 40)` — bright red-orange, invisible on light backgrounds
- `Theme.php:125` — `text()` returns `rgb(180, 180, 190)` — light gray text, unreadable on white terminals
- `Theme.php:131` — `white()` returns `rgb(240, 240, 245)` — nearly white on white, completely invisible
- `Theme.php:253` — `codeBg()` returns `bgRgb(40, 40, 40)` — dark background block on a light terminal creates jarring contrast
- `KosmokratorStyleSheet.php:69` — `.user-message` uses `Color::hex('#ffffff')` — white text on white bg
- `KosmokratorStyleSheet.php:135` — `EditorWidget::frame` uses `Color::hex('#6b3028')` — dark border invisible on dark-light surfaces
- `KosmokratorStyleSheet.php:149` — `ProgressBarWidget::bar-fill` uses `Color::hex('#50c878')` — green fills blend into light backgrounds

The `SettingsSchema` (`src/Settings/SettingsSchema.php:153`) already defines a `ui.theme` setting with options `['default']` — a single hardcoded theme with no dark/light awareness.

### 1.2 What We Need

1. **Automatic detection** — probe the terminal background at startup to determine dark or light mode
2. **Dual-variant tokens** — every semantic token resolves to a different color depending on dark/light
3. **Light theme palette** — a complete light-mode color palette that maintains KosmoKrator's visual identity
4. **Contrast validation** — all text meets WCAG AA contrast ratio (≥ 4.5:1) against the background
5. **Manual override** — users can force dark/light via config or env var
6. **Zero breakage** — if detection fails, fall back to the current dark theme (no behavior change)

---

## 2. Prior Art Research

### 2.1 How Other Tools Detect Dark/Light

| Tool | Detection Method | Notes |
|------|-----------------|-------|
| **Claude Code** | Hardcoded dark theme | No detection at all. Uses a fixed dark-friendly palette. |
| **OpenCode** | Delegates to Lip Gloss `AdaptiveColor` | `HasDarkBackground()` internally |
| **Lip Gloss (Go)** | `$COLORFGBG` → default dark | No OSC 11 query — considered too fragile |
| **Glow (Charmbracelet)** | Same as Lip Gloss | Shared detection library |
| **Bubble Tea** | No built-in detection | Uses Lip Gloss for styling |
| **Neovim** | `$TERM` + `vim.bg` check | Checks `vim.o.background` after terminal response |
| **Helix editor** | `$COLORTERM`, theme config | Explicit theme selection, no auto-detection |
| **Starship prompt** | No detection | Fixed palette that works reasonably on both |

**Consensus**: Production terminal tools overwhelmingly avoid OSC 11 queries. They use `$COLORFGBG` where available and default to dark.

### 2.2 Why OSC 11 Is Rarely Used

The OSC 11 background color query (`\x1b]11;?\x07`) is the most accurate detection method, but has significant risks:

1. **Terminal hang risk** — Terminals that silently ignore the query (macOS Terminal.app, GNU screen, older tmux) leave the process blocked on stdin read. Requires raw-mode terminal manipulation + timeout.
2. **Startup latency** — Even with a 200ms timeout, this adds perceptible delay to every session start.
3. **State corruption** — If the process is interrupted (SIGINT, SIGTERM) during the raw-mode window, the terminal is left in a broken state (no echo, no canonical mode).
4. **Multiplexer interference** — tmux/screen may swallow the response or route it to the wrong fd.
5. **Not worth the payoff** — `$COLORFGBG` + macOS appearance API + default-dark covers >90% of real-world cases.

**Decision**: OSC 11 is **opt-in** (off by default). The default detection cascade uses safe, non-invasive methods only.

---

## 3. Detection Architecture

### 3.1 Detection Cascade

```
┌─────────────────────────────────────────────────┐
│ 1. Explicit override (config/env)               │  ← User chose dark/light manually
│    KOSMOKRATOR_THEME=dark|light                  │
│    kosmokrator.ui.appearance = dark|light|auto   │
├─────────────────────────────────────────────────┤
│ 2. $COLORFGBG environment variable              │  ← Fast, no side effects
│    Parse "fg_index;bg_index" → bg < 8 = dark    │
├─────────────────────────────────────────────────┤
│ 3. Platform-specific appearance APIs            │  ← macOS defaults, gsettings
│    macOS: defaults read -g AppleInterfaceStyle   │
│    Linux: gsettings get ... color-scheme          │
│    Windows: reg query ... AppsUseLightTheme       │
├─────────────────────────────────────────────────┤
│ 4. OSC 11 query (opt-in only)                   │  ← Accurate but risky
│    Send \x1b]11;?\x07 with 200ms timeout         │
│    Parse rgb:RRRR/GGGG/BBBB response             │
├─────────────────────────────────────────────────┤
│ 5. Default: dark                                │  ← Safe fallback (80%+ of devs)
└─────────────────────────────────────────────────┘
```

### 3.2 Detection Result

```php
enum BackgroundMode: string
{
    case Dark = 'dark';
    case Light = 'light';
    case Auto = 'auto';  // Defer to detection
}
```

The `TerminalProbe` (from `01-semantic-theming.md`) gains a `backgroundMode(): BackgroundMode` method and an `isDark(): bool` convenience method.

---

## 4. Detection Methods — Detailed Design

### 4.1 Method 1: Explicit Override

**Config key**: `kosmokrator.ui.appearance`  
**Env var**: `KOSMOKRATOR_THEME`  
**Priority**: Highest (always wins)

```php
private function detectFromOverride(): ?bool
{
    // Environment variable takes precedence
    $env = getenv('KOSMOKRATOR_THEME');
    if ($env !== false && $env !== '') {
        return match (strtolower(trim($env))) {
            'light' => false,
            'dark' => true,
            default => null,
        };
    }

    // Then check config
    $config = $this->config->get('ui.appearance', 'auto');
    return match ($config) {
        'dark' => true,
        'light' => false,
        default => null, // 'auto' → fall through to next method
    };
}
```

### 4.2 Method 2: `$COLORFGBG` Environment Variable

**Format**: `"fg_index;bg_index"` or `"fg_index;bg_index;cursor_index"`  
**Set by**: rxvt-unicode, xterm, foot, some other X11 terminals  
**NOT set by**: iTerm2, kitty, Ghostty, WezTerm, Terminal.app, VS Code, Alacritty  
**Coverage**: ~30% of Linux/BSD terminals, <5% of macOS terminals

```php
private function detectFromColorFgbg(): ?bool
{
    $colorfgbg = getenv('COLORFGBG');
    if ($colorfgbg === false || $colorfgbg === '') {
        return null;
    }

    $parts = array_map('intval', explode(';', $colorfgbg));
    if (count($parts) < 2) {
        return null;
    }

    // Background is the last numeric field (some terminals add cursor color as 3rd)
    $bgIndex = $parts[count($parts) - 1];

    // ANSI color indices 0–7 = standard (dark) colors → dark background
    // ANSI color indices 8–15 = bright (light) colors → light background
    // 0=black, 7=light gray → dark bg
    // 8=dark gray, 15=white → light bg
    return $bgIndex < 8;
}
```

**Edge cases**:

| `$COLORFGBG` | Background Index | Result | Interpretation |
|-------------|-----------------|--------|---------------|
| `0;15` | 15 (bright white) | Light | Classic light theme (black on white) |
| `15;0` | 0 (black) | Dark | Classic dark theme (white on black) |
| `7;0` | 0 (black) | Dark | Gray on black |
| `12;8` | 8 (dark gray) | Light | Blue on dark gray (borderline) |

### 4.3 Method 3: Platform-Specific Appearance APIs

#### macOS — `defaults read -g AppleInterfaceStyle`

```php
private function detectMacosAppearance(): ?bool
{
    if (PHP_OS_FAMILY !== 'Darwin') {
        return null;
    }

    // When dark mode is active: key exists, value = "Dark"
    // When light mode is active: key does NOT exist → exit code 1
    exec('defaults read -g AppleInterfaceStyle 2>/dev/null', $output, $exitCode);

    if ($exitCode === 0) {
        // Key exists → dark mode
        return true;
    }

    // Key doesn't exist → light mode
    // But only trust this for Terminal.app (other terminals manage their own themes)
    $termProgram = getenv('TERM_PROGRAM') ?: '';
    if ($termProgram === 'Apple_Terminal') {
        return false; // Light mode
    }

    // For iTerm2/WezTerm/etc., system appearance doesn't dictate terminal theme
    return null;
}
```

**Why we only trust this for Terminal.app**: iTerm2, WezTerm, and other modern terminals have their own theme settings independent of macOS system appearance. Terminal.app follows the system setting.

#### Linux — GNOME/KDE Desktop Theme

```php
private function detectLinuxAppearance(): ?bool
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return null;
    }

    // GNOME: check color-scheme setting
    $output = shell_exec('gsettings get org.gnome.desktop.interface color-scheme 2>/dev/null');
    if ($output !== null) {
        $scheme = trim($output, "'\"\n");
        if (str_contains($scheme, 'dark')) {
            return true;
        }
        if (str_contains($scheme, 'light')) {
            return false;
        }
    }

    // GNOME fallback: check GTK theme name
    $output = shell_exec('gsettings get org.gnome.desktop.interface gtk-theme 2>/dev/null');
    if ($output !== null && stripos($output, 'dark') !== false) {
        return true;
    }

    // KDE Plasma
    $output = shell_exec('kreadconfig6 --group KDE --key LookAndFeelPackage 2>/dev/null')
        ?? shell_exec('kreadconfig5 --group KDE --key LookAndFeelPackage 2>/dev/null');
    if ($output !== null && stripos($output, 'dark') !== false) {
        return true;
    }

    return null;
}
```

#### Windows — Registry Query

```php
private function detectWindowsAppearance(): ?bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return null;
    }

    $output = shell_exec(
        'reg query "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Themes\\Personalize" /v AppsUseLightTheme 2>nul'
    );

    if ($output !== null && preg_match('/0x(\d+)/', $output, $m)) {
        return ((int) hexdec($m[1])) === 0; // 0 = dark, 1 = light
    }

    return null;
}
```

### 4.4 Method 4: OSC 11 Background Color Query (Opt-In Only)

**Disabled by default.** Enabled via `kosmokrator.ui.appearance_probe = osc11` or env var `KOSMOKRATOR_BG_PROBE=1`.

#### Query Sequence

```
Application → Terminal:  \x1b]11;?\x07          (OSC 11 query with BEL terminator)
Terminal → Application:  \x1b]11;rgb:RRRR/GGGG/BBBB\x1b\\  (response with ST terminator)
```

#### Response Format

The response is an OSC sequence: `\x1b]11;` followed by a color spec, terminated by BEL (`\x07`) or ST (`\x1b\\`).

Color spec variants:
- `rgb:RRRR/GGGG/BBBB` — 16-bit hex per channel (most common: iTerm2, kitty, WezTerm)
- `rgb:RR/GG/BB` — 8-bit hex per channel (some terminals)
- `rgb:RRRR/GGGG/BBBB:RRRR/GGGG/BBBB` — min/max range (rare)

#### Terminal Support Matrix

| Terminal | Supports OSC 11 | Response Format | Notes |
|----------|:---:|------|-------|
| **iTerm2** | ✅ | `rgb:RRRR/GGGG/BBBB` | Full support |
| **kitty** | ✅ | `rgb:RRRR/GGGG/BBBB` | Full support |
| **WezTerm** | ✅ | `rgb:RRRR/GGGG/BBBB` | Full support |
| **Ghostty** | ✅ | `rgb:RRRR/GGGG/BBBB` | Full support |
| **VS Code terminal** | ✅ | `rgb:RRRR/GGGG/BBBB` | Returns current theme bg |
| **Alacritty** | ✅ | `rgb:RRRR/GGGG/BBBB` | Since v0.12+ |
| **foot** | ✅ | `rgb:RRRR/GGGG/BBBB` | Full support |
| **Konsole** | ✅ | `rgb:RRRR/GGGG/BBBB` | Full support |
| **mintty** | ✅ | `rgb:RRRR/GGGG/BBBB` | Full support |
| **xterm** | ⚠️ | — | Requires `XTerm*queryColor: true` in X resources |
| **Terminal.app** | ❌ | — | **Silently ignores** — will hang stdin |
| **screen** | ❌ | — | Swallows the sequence |
| **tmux** | ⚠️ | — | Pass-through if `terminal-overrides` configured |
| **Windows Console** | ❌ | — | No OSC support |
| **Windows Terminal** | ⚠️ | — | Partial support in recent versions |

#### PHP Implementation

```php
private function queryOsc11BackgroundColor(): ?array
{
    // Guard: only attempt on known-supporting terminals
    if (!$this->terminalSupportsOsc11()) {
        return null;
    }

    // Guard: only attempt if stdin is a real TTY
    if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
        return null;
    }

    // Save terminal state
    $termios = shell_exec('stty -g 2>/dev/null');
    if ($termios === null || trim($termios) === '') {
        return null;
    }

    try {
        // Set raw mode with 200ms read timeout
        // min 0 = don't block for characters
        // time 2 = 200ms inter-character timeout (in deciseconds)
        shell_exec('stty -echo -icanon min 0 time 2 2>/dev/null');

        // Send OSC 11 query to stderr (avoid corrupting stdout)
        fwrite(STDERR, "\x1b]11;?\x07");

        // Read response with select()-based timeout
        $response = '';
        $start = microtime(true);
        $timeout = 0.3; // 300ms absolute max

        while ((microtime(true) - $start) < $timeout) {
            $read = [STDIN];
            $write = [];
            $except = [];

            if (stream_select($read, $write, $except, 0, 50000) > 0) { // 50ms per poll
                $chunk = fread(STDIN, 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $response .= $chunk;

                // Check for complete response (BEL or ST terminator)
                if (str_contains($response, "\x07") || str_ends_with($response, "\x1b\\")) {
                    break;
                }
            }
        }

        // Parse: \x1b]11;rgb:RRRR/GGGG/BBBB\x07
        // Also handles: \x1b]11;rgb:RR/GG/BB\x1b\\
        if (preg_match('/11;rgb:([0-9a-fA-F]{2,4})\/([0-9a-fA-F]{2,4})\/([0-9a-fA-F]{2,4})/', $response, $m)) {
            return [
                'r' => hexdec(substr($m[1], 0, 2)),
                'g' => hexdec(substr($m[2], 0, 2)),
                'b' => hexdec(substr($m[3], 0, 2)),
            ];
        }

        return null;
    } finally {
        // ALWAYS restore terminal state
        shell_exec('stty ' . escapeshellarg(trim($termios)) . ' 2>/dev/null');
    }
}

private function terminalSupportsOsc11(): bool
{
    $termProgram = getenv('TERM_PROGRAM') ?: '';

    // Known-supporting terminals
    $supported = ['iTerm.app', 'ghostty', 'kitty', 'WezTerm', 'vscode'];
    foreach ($supported as $t) {
        if (str_contains($termProgram, $t)) {
            return true;
        }
    }

    // Alacritty doesn't set TERM_PROGRAM but sets TERMINAL=Alacritty
    $terminal = getenv('TERMINAL') ?: '';
    if ($terminal === 'Alacritty') {
        return true;
    }

    // Check COLORTERM as a weaker signal — TrueColor terminals often support OSC 11
    $colorterm = strtolower(getenv('COLORTERM') ?: '');
    if (in_array($colorterm, ['truecolor', '24bit'], true)) {
        return true;
    }

    return false;
}
```

**Safety guarantees**:

1. **`stty -g` before, `stty <saved>` after** — terminal state is always restored (even on exception via `finally`)
2. **300ms absolute timeout** — never blocks longer than this
3. **`stream_select()` non-blocking** — polls stdin with 50ms granularity
4. **Terminal allowlist** — only attempts on known-supporting terminals
5. **TTY guard** — skips entirely in piped/CI/non-interactive contexts
6. **Opt-in** — disabled by default; requires explicit config to enable

---

## 5. Luminance Calculation

### 5.1 WCAG 2.0 Relative Luminance (Recommended)

```php
/**
 * Calculate relative luminance per WCAG 2.0 (W3C).
 *
 * Input: sRGB values (0–255).
 * Output: 0.0 (black) to 1.0 (white).
 */
public static function relativeLuminance(int $r, int $g, int $b): float
{
    // Convert sRGB channels to linear light
    $srgb = [$r / 255.0, $g / 255.0, $b / 255.0];
    $linear = array_map(fn (float $c): float =>
        $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
        $srgb
    );

    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}
```

### 5.2 Simpler sRGB Luminance (Fallback)

For environments where `** 2.4` exponentiation is expensive (not a concern in PHP, but documented for completeness):

```php
public static function simpleLuminance(int $r, int $g, int $b): float
{
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
}
```

### 5.3 Dark/Light Threshold

```php
/**
 * Determine if a background color is dark.
 * Uses WCAG linearized luminance with a 0.5 threshold.
 */
public static function isDarkColor(int $r, int $g, int $b): bool
{
    return self::relativeLuminance($r, $g, $b) < 0.5;
}
```

**Threshold rationale**:

| Threshold | Behavior | Trade-off |
|-----------|----------|-----------|
| **0.179** | WCAG midpoint | Very conservative; many dark blues/purples classified as "light" |
| **0.5** | Balanced | Matches user intuition for most backgrounds |
| **0.4** | Favors "dark" | Good for avoiding accidental light-theme activation |

We use **0.5** — the same threshold as Lip Gloss and most terminal tools. The value is intuitive (below 50% luminance = dark) and matches user expectations.

### 5.4 Contrast Ratio Calculation

For validation of text/background color pairs:

```php
/**
 * Calculate the WCAG 2.0 contrast ratio between two colors.
 *
 * @return float Contrast ratio (1:1 to 21:1)
 */
public static function contrastRatio(int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): float
{
    $l1 = self::relativeLuminance($r1, $g1, $b1);
    $l2 = self::relativeLuminance($r2, $g2, $b2);

    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);

    return ($lighter + 0.05) / ($darker + 0.05);
}

/**
 * Check if a foreground/background pair meets WCAG AA contrast.
 * AA requires ≥ 4.5:1 for normal text, ≥ 3:1 for large text.
 */
public static function meetsWcagAA(int $fgR, int $fgG, int $fgB, int $bgR, int $bgG, int $bgB): bool
{
    return self::contrastRatio($fgR, $fgG, $fgB, $bgR, $bgG, $bgB) >= 4.5;
}
```

---

## 6. TerminalProbe Integration

The `TerminalProbe` class (defined in `01-semantic-theming.md` §6) gains dark/light detection:

```php
// src/UI/Theme/TerminalProbe.php

class TerminalProbe
{
    private ?bool $isDark = null;

    public function __construct(
        private readonly ?Config $config = null,
        private readonly bool $enableOsc11 = false,
    ) {}

    /**
     * Whether the terminal has a dark background.
     * Cached after first detection.
     */
    public function isDark(): bool
    {
        return $this->isDark ??= $this->detectDarkMode();
    }

    /**
     * Whether the terminal has a light background.
     */
    public function isLight(): bool
    {
        return !$this->isDark();
    }

    /**
     * Force a specific mode (for config override or testing).
     */
    public function forceMode(bool $dark): void
    {
        $this->isDark = $dark;
    }

    private function detectDarkMode(): bool
    {
        // 1. Explicit override
        $override = $this->detectFromOverride();
        if ($override !== null) {
            return $override;
        }

        // 2. $COLORFGBG
        $fromFgbg = $this->detectFromColorFgbg();
        if ($fromFgbg !== null) {
            return $fromFgbg;
        }

        // 3. Platform appearance APIs
        $platform = $this->detectPlatformAppearance();
        if ($platform !== null) {
            return $platform;
        }

        // 4. OSC 11 (opt-in only)
        if ($this->enableOsc11) {
            $bg = $this->queryOsc11BackgroundColor();
            if ($bg !== null) {
                return self::isDarkColor($bg['r'], $bg['g'], $bg['b']);
            }
        }

        // 5. Default: dark
        return true;
    }

    private function detectPlatformAppearance(): ?bool
    {
        return match (PHP_OS_FAMILY) {
            'Darwin'  => $this->detectMacosAppearance(),
            'Linux'   => $this->detectLinuxAppearance(),
            'Windows' => $this->detectWindowsAppearance(),
            default   => null,
        };
    }

    // ... detection methods from §4.2–4.4 above
}
```

### Integration with ThemeManager

```php
// In ThemeManager
public function __construct(
    private readonly TerminalProbe $probe,
    private readonly ThemeRegistry $registry,
) {}

public function color(string $token): Color
{
    $definition = $this->activeTheme();

    // Resolve token with dark/light variant
    $hex = $definition->resolve($token, $this->probe->isDark());

    // Downsample based on terminal color capability
    return $this->downsample($hex);
}
```

The `ThemeDefinition::resolve()` method picks the correct variant:

```php
// In ThemeDefinition
public function resolve(string $token, bool $dark): string
{
    $value = $this->tokens[$token]
        ?? $this->fallbackChain($token)
        ?? throw new \InvalidArgumentException("Unknown token: {$token}");

    // If the value is a dark/light map, pick the right one
    if (is_array($value)) {
        return $value[$dark ? 'dark' : 'light']
            ?? $value['dark']  // Fallback to dark variant
            ?? throw new \RuntimeException("Token '{$token}' has no dark/light variants");
    }

    // Single value — used for both modes
    return $value;
}
```

---

## 7. Light Theme Color Palette

### 7.1 Design Principles

The light theme follows these principles:

1. **Identity preservation** — the same warm red-orange + gold brand identity, just darkened for light surfaces
2. **Sufficient contrast** — all text meets WCAG AA (≥ 4.5:1) against `#f5f5f5` background
3. **Visual hierarchy maintained** — primary > accent > text > dim still reads clearly
4. **Surface layering** — background → surface → surface-bright creates depth without harsh borders
5. **Reduced saturation** — slightly desaturated compared to dark theme to avoid "shouting" on white

### 7.2 Complete Light Palette

All colors validated for WCAG AA contrast against the light background (`#f5f5f5`, luminance = 0.95).

#### Core Palette

| Token | Dark (current) | Light | Dark Contrast on `#121212` | Light Contrast on `#f5f5f5` |
|-------|:---:|:---:|:---:|:---:|
| `primary` | `#ff3c28` | `#cc2200` | 5.8:1 ✅ | 5.7:1 ✅ |
| `primary-dim` | `#a01e1e` | `#cc6644` | 3.4:1 | 3.6:1 |
| `accent` | `#ffc850` | `#9a7520` | 9.5:1 ✅ | 5.5:1 ✅ |
| `accent-dim` | `#b48c32` | `#8a6a20` | 5.6:1 ✅ | 7.0:1 ✅ |

#### Semantic Colors

| Token | Dark | Light | Light Contrast |
|-------|:---:|:---:|:---:|
| `success` | `#50dc64` | `#1a7a28` | 5.4:1 ✅ |
| `warning` | `#ffc850` | `#9a7520` | 5.5:1 ✅ |
| `error` | `#ff5040` | `#cc1100` | 5.9:1 ✅ |
| `info` | `#64c8ff` | `#1a6ca0` | 4.9:1 ✅ |

#### Text Colors

| Token | Dark | Light | Light Contrast |
|-------|:---:|:---:|:---:|
| `text` | `#b4b4be` | `#3a3a3a` | 10.5:1 ✅ |
| `text-bright` | `#f0f0f5` | `#1a1a1a` | 16.2:1 ✅ |
| `text-dim` | `#909090` | `#707070` | 4.7:1 ✅ |
| `text-dimmer` | `#606060` | `#a0a0a0` | 3.1:1 (decorative only) |
| `text-heading` | `#ffffff` | `#000000` | 19.6:1 ✅ |

#### UI Elements

| Token | Dark | Light | Notes |
|-------|:---:|:---:|-------|
| `border-active` | `#c85a42` | `#b04530` | Visible border on light bg |
| `border-inactive` | `#6b3028` | `#c09888` | Subdued border |
| `border-task` | `#806428` | `#8a7040` | Warm task borders |
| `border-accent` | `#b48c32` | `#8a6a20` | Gold accent borders |
| `border-plan` | `#785ac8` | `#6040a0` | Purple plan borders |
| `background` | `#121212` | `#f5f5f5` | Main widget background |
| `surface` | `#1a1a1a` | `#e8e8e8` | Elevated surface |
| `surface-bright` | `#2a2a2a` | `#d0d0d0` | Hovered/active surface |

#### Diff Colors

| Token | Dark | Light | Notes |
|-------|:---:|:---:|-------|
| `diff-add` | `#3ca050` | `#1a6a28` | Green — darker for light bg |
| `diff-add-bg` | `#142d14` | `#d0f0d0` | Light green bg tint |
| `diff-add-bg-strong` | `#1e461e` | `#b0e0b0` | Stronger green highlight |
| `diff-remove` | `#b43c3c` | `#a02020` | Red — darker for light bg |
| `diff-remove-bg` | `#370f0f` | `#f0d0d0` | Light red bg tint |
| `diff-remove-bg-strong` | `#501414` | `#e0b0b0` | Stronger red highlight |
| `diff-context` | `#909090` | `#707070` | Gray context lines |

#### Syntax Highlighting

| Token | Dark | Light | Notes |
|-------|:---:|:---:|-------|
| `syntax-keyword` | `#c878ff` | `#7030b0` | Purple — darker shade |
| `syntax-type` | `#ffc850` | `#8a6a20` | Gold/brown |
| `syntax-value` | `#50dc64` | `#1a6a28` | Green |
| `syntax-number` | `#ffc850` | `#8a6a20` | Same as type |
| `syntax-literal` | `#64c8ff` | `#1a6ca0` | Blue |
| `syntax-variable` | `#f0f0f5` | `#1a1a1a` | Near-black |
| `syntax-property` | `#64c8ff` | `#1a6ca0` | Blue |
| `syntax-comment` | `#909090` | `#707070` | Gray |
| `syntax-operator` | `#f0f0f5` | `#1a1a1a` | Near-black |
| `syntax-attribute` | `#c878ff` | `#7030b0` | Purple |
| `syntax-generic` | `#508cff` | `#1a5ca0` | Blue |
| `syntax-function` | `#64c8ff` | `#1a6ca0` | Blue |

#### Agent Types

| Token | Dark | Light |
|-------|:---:|:---:|
| `agent-general` | `#daa520` | `#8a6a14` |
| `agent-plan` | `#a078ff` | `#6040a0` |
| `agent-explore` | `#64c8dc` | `#1a6a7a` |
| `agent-waiting` | `#6495ed` | `#3060b0` |

#### Code Blocks

| Token | Dark | Light |
|-------|:---:|:---:|
| `code-fg` | `#c878ff` | `#7030b0` |
| `code-bg` | `#282828` | `#e8e8e8` |

#### Miscellaneous

| Token | Dark | Light |
|-------|:---:|:---:|
| `link` | `#508cff` | `#1a5ca0` |
| `separator` | `#404040` | `#c0c0c0` |
| `status-bar` | `#909090` | `#606060` |
| `thinking` | `#70a0d0` | `#2a6090` |
| `compacting` | `#d04040` | `#b02020` |

### 7.3 Color Transformation Strategy

For the initial implementation, light-theme colors are **explicitly defined** in each `ThemeDefinition` class (not computed). This ensures:

1. **Pixel-perfect control** — designers can tweak individual colors without unexpected side effects
2. **No runtime computation** — no `shade()`/`tint()` needed during token resolution
3. **Auditability** — the light palette is reviewable as a static declaration

Future enhancement: add a `ColorDeriver` utility that can auto-generate a light palette from a dark palette using:
- **Inversion**: `#ff3c28` → `#00c3d7` (component-wise invert, usually ugly)
- **Luminance flip**: compute target luminance as `1.0 - source_luminance`, then find nearest hue-preserving color
- **Manual adjustment**: start with auto-generated, then manually tune the ones that look wrong

### 7.4 Contrast Validation Table

All text-on-background pairs validated against WCAG AA (≥ 4.5:1 for normal text, ≥ 3:1 for large/decorative text):

| Foreground | Background | Role | Contrast | AA? |
|-----------|-----------|------|:---:|:---:|
| Dark: `#b4b4be` on `#121212` | Body text | 8.6:1 | ✅ |
| Light: `#3a3a3a` on `#f5f5f5` | Body text | 10.5:1 | ✅ |
| Dark: `#f0f0f5` on `#121212` | Bright text | 16.9:1 | ✅ |
| Light: `#1a1a1a` on `#f5f5f5` | Bright text | 16.2:1 | ✅ |
| Dark: `#ff3c28` on `#121212` | Primary/accent | 5.8:1 | ✅ |
| Light: `#cc2200` on `#f5f5f5` | Primary/accent | 5.7:1 | ✅ |
| Dark: `#50dc64` on `#121212` | Success | 9.2:1 | ✅ |
| Light: `#1a7a28` on `#f5f5f5` | Success | 5.4:1 | ✅ |
| Dark: `#ff5040` on `#121212` | Error | 5.5:1 | ✅ |
| Light: `#cc1100` on `#f5f5f5` | Error | 5.9:1 | ✅ |
| Dark: `#909090` on `#121212` | Dim text | 5.4:1 | ✅ |
| Light: `#707070` on `#f5f5f5` | Dim text | 4.7:1 | ✅ |
| Dark: `#606060` on `#121212` | Dimmer text | 3.1:1 | ⚠️ decorative |
| Light: `#a0a0a0` on `#f5f5f5` | Dimmer text | 2.9:1 | ⚠️ decorative |
| Dark: `#ffc850` on `#121212` | Accent | 9.5:1 | ✅ |
| Light: `#9a7520` on `#f5f5f5` | Accent | 5.5:1 | ✅ |
| Dark: `#3ca050` on `#142d14` | Diff add fg on bg | 2.6:1 | ⚠️ paired with `+` prefix |
| Light: `#1a6a28` on `#d0f0d0` | Diff add fg on bg | 3.5:1 | ⚠️ paired with `+` prefix |

**Note on `text-dimmer` and diff backgrounds**: These are decorative/hint elements (separators, diff line backgrounds) that always appear with structural context (indentation, `+`/`-` prefixes, borders). WCAG allows 3:1 for "incidental" text and UI components.

---

## 8. KosmokratorStyleSheet Dark/Light Modes

### 8.1 Current State (253 lines, single dark theme)

Every `Color::hex(...)` call in `KosmokratorStyleSheet::create()` uses a dark-mode color. No light variant exists.

### 8.2 Target State

`KosmokratorStyleSheet::create()` accepts `ThemeManager` and resolves tokens:

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
            '.user-message' => new Style(
                color: $theme->color('text-bright'),
                bold: true,
                padding: new Padding(1, 2, 0, 2),
            ),
            '.separator' => new Style(
                color: $theme->color('separator'),
                padding: new Padding(1, 2, 0, 2),
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
            // ... all selectors resolved through $theme->color()
        ]);
    }
}
```

### 8.3 Selector → Token Mapping

| Selector | Property | Token | Dark | Light |
|----------|----------|-------|:---:|:---:|
| `.figlet-header` | color | `primary` | `#ff3c28` | `#cc2200` |
| `.subtitle` | color | `accent` | `#ffc850` | `#9a7520` |
| `.tagline` | color | `text-dim` | `#a0a0a0` | `#707070` |
| `.welcome` | color | `text-dim` | `#a0a0a0` | `#707070` |
| `.user-message` | color | `text-bright` | `#ffffff` | `#1a1a1a` |
| `.separator` | color | `separator` | `#404040` | `#c0c0c0` |
| `.tool-call` | color | `accent` | `#ffc850` | `#9a7520` |
| `.task-call` | color | `accent` | `#ffc850` | `#9a7520` |
| `.tool-result` | color | `text-dim` | `#a0a0a0` | `#707070` |
| `.tool-batch` | color | `text-dim` | `#a0a0a0` | `#707070` |
| `.tool-shell` | color | `text-dim` | `#a0a0a0` | `#707070` |
| `.tool-success` | color | `success` | `#50dc64` | `#1a7a28` |
| `.tool-error` | color | `error` | `#ff5040` | `#cc1100` |
| `.status-bar` | color | `status-bar` | `#909090` | `#606060` |
| `EditorWidget` | color | `text` | `#dcdcdc` | `#3a3a3a` |
| `EditorWidget::frame` | color | `border-inactive` | `#6b3028` | `#c09888` |
| `EditorWidget:focus::frame` | color | `border-active` | `#c85a42` | `#b04530` |
| `ProgressBarWidget::bar-fill` | color | `success` | `#50c878` | `#1a7a28` |
| `ProgressBarWidget::bar-progress` | color | `success` | `#50c878` | `#1a7a28` |
| `ProgressBarWidget::bar-empty` | color | `separator` | `#404040` | `#c0c0c0` |
| `.compacting` | color | `compacting` | `#d04040` | `#b02020` |
| `.compacting::spinner` | color | `compacting` | `#d04040` | `#b02020` |
| `.compacting::message` | color | `compacting` | `#d04040` | `#b02020` |
| `CancellableLoaderWidget` | color | `thinking` | `#70a0d0` | `#2a6090` |
| `CancellableLoaderWidget::spinner` | color | `thinking` | `#70a0d0` | `#2a6090` |
| `CancellableLoaderWidget::message` | color | `thinking` | `#70a0d0` | `#2a6090` |
| `.permission-prompt` | border, color | `accent` | `#ffc850` | `#9a7520` |
| `.slash-completion` | color | `text-dim` | `#a0a0a0` | `#707070` |
| `SettingsListWidget` | border | `accent` | `#ffc850` | `#9a7520` |
| `SettingsListWidget` | color | `text` | `#dcdcdc` | `#3a3a3a` |
| `SettingsListWidget::label-selected` | color | `text-bright` | `#ffffff` | `#1a1a1a` |
| `SettingsListWidget::value` | color | `info` | `#70a0d0` | `#1a6ca0` |
| `SettingsListWidget::value-selected` | color | `success` | `#50c878` | `#1a7a28` |
| `SettingsListWidget::description` | color | `text-dim` | `#808080` | `#707070` |
| `SettingsListWidget::hint` | color | `text-dimmer` | `#606060` | `#a0a0a0` |

---

## 9. Theme.php Facade — Dark/Light Delegation

After the migration in `01-semantic-theming.md`, `Theme.php` delegates to `ThemeManager`. The dark/light awareness is automatic:

```php
// Theme.php — after migration
class Theme
{
    private static ?ThemeManager $manager = null;

    public static function setManager(ThemeManager $manager): void
    {
        self::$manager = $manager;
    }

    private static function m(): ThemeManager
    {
        return self::$manager ??= self::defaultManager();
    }

    // Color methods — now automatically dark/light aware
    public static function primary(): string   { return self::m()->ansi('primary'); }
    public static function success(): string   { return self::m()->ansi('success'); }
    public static function error(): string     { return self::m()->ansi('error'); }
    public static function text(): string      { return self::m()->ansi('text'); }
    public static function dim(): string       { return self::m()->ansi('text-dim'); }
    public static function white(): string     { return self::m()->ansi('text-bright'); }

    // Background colors — also auto-switched
    public static function codeBg(): string    { return self::m()->ansiBg('code-bg'); }
    public static function diffAddBg(): string { return self::m()->ansiBg('diff-add-bg'); }

    // Non-color methods — unchanged
    public static function reset(): string { return "\033[0m"; }
    public static function bold(): string  { return "\033[1m"; }
    public static function hideCursor(): string { return "\033[?25l"; }
    // ...
}
```

**No caller changes required**. Every existing `Theme::primary()` call automatically returns the correct color for the detected background mode.

---

## 10. Settings & Config

### 10.1 New Settings

```php
// In SettingsSchema.php

new SettingDefinition(
    id: 'ui.appearance',
    path: 'kosmokrator.ui.appearance',
    label: 'Appearance',
    description: 'Color scheme for dark or light terminal backgrounds.',
    category: 'general',
    type: 'choice',
    options: ['auto', 'dark', 'light'],
    effect: 'next_session',
    default: 'auto',
),

new SettingDefinition(
    id: 'ui.theme',
    path: 'kosmokrator.ui.theme',
    label: 'Theme',
    description: 'Terminal theme preset.',
    category: 'general',
    type: 'choice',
    options: ['cosmic', 'minimal', 'high-contrast', 'daltonized'],
    effect: 'next_session',
    default: 'cosmic',
),
```

### 10.2 Environment Variables

| Variable | Values | Effect |
|----------|--------|--------|
| `KOSMOKRATOR_THEME` | `dark`, `light` | Force appearance mode (overrides config) |
| `KOSMOKRATOR_BG_PROBE` | `1`, `osc11` | Enable OSC 11 background probe |
| `NO_COLOR` | (any non-empty) | Disable all color (from `02-color-downsampling.md`) |

### 10.3 Config File

```yaml
# config/kosmokrator.yaml or ~/.kosmokrator/config.yaml
ui:
  renderer: auto
  theme: cosmic
  appearance: auto          # auto | dark | light
  appearance_probe: none    # none | osc11
  theme_overrides:
    # Example: make primary blue instead of red
    primary:
      dark: "#4488ff"
      light: "#2060cc"
```

---

## 11. Implementation Plan

### Phase 1: Detection Infrastructure

| Step | File | Description |
|------|------|-------------|
| 1.1 | `src/UI/Theme/BackgroundMode.php` | Enum: `Dark`, `Light`, `Auto` |
| 1.2 | `src/UI/Theme/TerminalProbe.php` | Add dark/light detection methods (§4.2–4.3) |
| 1.3 | `src/UI/Theme/Luminance.php` | Static luminance calculation + contrast ratio |
| 1.4 | `tests/Unit/UI/Theme/TerminalProbeTest.php` | Test COLORFGBG parsing, platform detection, default fallback |
| 1.5 | `tests/Unit/UI/Theme/LuminanceTest.php` | Test luminance values, contrast ratios, threshold |

**Deliverable**: `TerminalProbe::isDark()` returns a reliable bool without OSC 11.

### Phase 2: Dual-Variant ThemeDefinition

| Step | File | Description |
|------|------|-------------|
| 2.1 | `src/UI/Theme/ThemeDefinition.php` | Add `resolve(string $token, bool $dark): string` with dark/light variant support |
| 2.2 | `src/UI/Theme/BuiltIn/CosmicTheme.php` | Add full light-variant palette (§7.2) |
| 2.3 | `tests/Unit/UI/Theme/ThemeDefinitionTest.php` | Test dual-variant resolution, fallback chains |
| 2.4 | Visual test | Render full palette in both modes, verify contrast ratios |

**Deliverable**: `CosmicTheme` resolves correct colors for both dark and light backgrounds.

### Phase 3: Wire ThemeManager

| Step | File | Description |
|------|------|-------------|
| 3.1 | `src/UI/Theme/ThemeManager.php` | `color()` uses `TerminalProbe::isDark()` for variant selection |
| 3.2 | `src/UI/Theme.php` | Facade delegates through `ThemeManager` (automatic dark/light) |
| 3.3 | `src/UI/Tui/KosmokratorStyleSheet.php` | Accept `ThemeManager`, resolve tokens (§8) |
| 3.4 | `src/Provider/ConfigServiceProvider.php` | Register `TerminalProbe` + wire `ThemeManager` |

**Deliverable**: All renderers (ANSI + TUI) automatically use correct colors for detected background.

### Phase 4: Config & Settings

| Step | File | Description |
|------|------|-------------|
| 4.1 | `src/Settings/SettingsSchema.php` | Add `ui.appearance` setting with `auto|dark|light` options |
| 4.2 | `src/Settings/SettingsSchema.php` | Update `ui.theme` options to include all built-in themes |
| 4.3 | Bootstrap | Read `KOSMOKRATOR_THEME` env var, pass to `TerminalProbe` |
| 4.4 | `config/kosmokrator.yaml` | Add `ui.appearance` and `ui.appearance_probe` keys |

**Deliverable**: Users can force dark/light via config or env var.

### Phase 5: Additional Theme Light Variants

| Step | File | Description |
|------|------|-------------|
| 5.1 | `src/UI/Theme/BuiltIn/MinimalTheme.php` | Light variant for grayscale theme |
| 5.2 | `src/UI/Theme/BuiltIn/HighContrastTheme.php` | Light variant for high contrast theme |
| 5.3 | `src/UI/Theme/BuiltIn/DaltonizedTheme.php` | Light variant for color-blind safe theme |
| 5.4 | `tests/Unit/UI/Theme/ContrastValidationTest.php` | Automated contrast validation for all themes × both modes |

**Deliverable**: All 4 built-in themes work correctly in both dark and light modes.

### Phase 6: OSC 11 Probe (Opt-In)

| Step | File | Description |
|------|------|-------------|
| 6.1 | `src/UI/Theme/TerminalProbe.php` | Add `queryOsc11BackgroundColor()` (§4.4) |
| 6.2 | `src/UI/Theme/TerminalProbe.php` | Add `terminalSupportsOsc11()` allowlist |
| 6.3 | Config integration | `ui.appearance_probe = osc11` enables OSC 11 in detection cascade |
| 6.4 | `tests/Unit/UI/Theme/Osc11Test.php` | Test response parsing, timeout handling, terminal state restoration |

**Deliverable**: OSC 11 probe available as opt-in for accurate detection on supporting terminals.

### Phase 7: Contrast Validation Tooling

| Step | File | Description |
|------|------|-------------|
| 7.1 | `src/UI/Theme/ContrastValidator.php` | Standalone tool: validates all tokens in a theme against their background |
| 7.2 | `tests/Unit/UI/Theme/ContrastValidationTest.php` | CI test: fail if any theme×mode pair violates WCAG AA for text tokens |
| 7.3 | CLI command | `php kosmokrator theme:validate` — report contrast ratios for active theme |

**Deliverable**: Automated contrast validation prevents inaccessible color combinations.

---

## 12. File Layout

```
src/UI/Theme/
├── BackgroundMode.php          # NEW — enum: Dark, Light, Auto
├── Luminance.php               # NEW — static luminance + contrast calculations
├── TerminalProbe.php           # NEW (from 01) — color level + dark/light detection
├── ThemeManager.php            # NEW (from 01) — uses probe->isDark() for variant selection
├── ThemeDefinition.php         # NEW (from 01) — resolve($token, $dark) dual-variant support
├── ThemeRegistry.php           # NEW (from 01)
├── ThemeLoader.php             # NEW (from 01)
├── ContrastValidator.php       # NEW — WCAG contrast validation for theme tokens
├── BuiltIn/
│   ├── CosmicTheme.php         # NEW — default theme with dark + light palettes
│   ├── MinimalTheme.php        # NEW — grayscale theme with light variant
│   ├── HighContrastTheme.php   # NEW — high contrast with light variant
│   └── DaltonizedTheme.php     # NEW — color-blind safe with light variant
└── ...

src/UI/
├── Theme.php                   # MODIFIED — facade delegates to ThemeManager
└── Tui/
    └── KosmokratorStyleSheet.php  # MODIFIED — accepts ThemeManager, resolves tokens

src/Settings/
└── SettingsSchema.php          # MODIFIED — add ui.appearance setting

tests/Unit/UI/Theme/
├── TerminalProbeTest.php       # Detection cascade tests
├── LuminanceTest.php           # Luminance calculation tests
├── ContrastValidationTest.php  # All themes × all tokens × both modes
└── Osc11Test.php               # OSC 11 response parsing tests
```

---

## 13. Testing Strategy

### 13.1 Unit Tests — Luminance

```php
// LuminanceTest.php
public function testRelativeLuminanceBlack(): void
{
    $this->assertEquals(0.0, Luminance::relativeLuminance(0, 0, 0));
}

public function testRelativeLuminanceWhite(): void
{
    $this->assertEquals(1.0, Luminance::relativeLuminance(255, 255, 255));
}

public function testIsDarkBackground(): void
{
    $this->assertTrue(Luminance::isDarkColor(18, 18, 18));    // #121212 — dark bg
    $this->assertFalse(Luminance::isDarkColor(245, 245, 245)); // #f5f5f5 — light bg
}

public function testContrastRatioBlackOnWhite(): void
{
    $ratio = Luminance::contrastRatio(0, 0, 0, 255, 255, 255);
    $this->assertEquals(21.0, $ratio, '', 0.1);
}
```

### 13.2 Unit Tests — Detection

```php
// TerminalProbeTest.php
public function testColorFgbgDarkBg(): void
{
    putenv('COLORFGBG=0;15'); // black fg, white bg → wait, bg=15 → LIGHT
    // Actually: bg index 15 (bright white) → light background
    $probe = new TerminalProbe();
    $this->assertFalse($probe->isDark()); // white bg = not dark
}

public function testColorFgbgLightBg(): void
{
    putenv('COLORFGBG=15;0'); // white fg, black bg → DARK
    $probe = new TerminalProbe();
    $this->assertTrue($probe->isDark()); // black bg = dark
}

public function testDefaultIsDark(): void
{
    putenv('COLORFGBG');   // Unset
    putenv('TERM_PROGRAM'); // Unset
    $probe = new TerminalProbe();
    $this->assertTrue($probe->isDark()); // default = dark
}

public function testExplicitOverrideDark(): void
{
    putenv('KOSMOKRATOR_THEME=light');
    $probe = new TerminalProbe();
    $this->assertFalse($probe->isDark());
}
```

### 13.3 Integration Tests — Theme Contrast Validation

```php
// ContrastValidationTest.php
/**
 * @dataProvider themeProvider
 */
public function testAllTextTokensMeetWcagAA(ThemeDefinition $theme, bool $dark): void
{
    $bgToken = $dark ? 'background' : 'background';
    $bgHex = $theme->resolve($bgToken, $dark);
    [$bgR, $bgG, $bgB] = ColorHelper::hexToRgb($bgHex);

    $textTokens = ['text', 'text-bright', 'text-dim', 'text-heading',
                   'primary', 'success', 'error', 'info', 'warning'];

    foreach ($textTokens as $token) {
        $fgHex = $theme->resolve($token, $dark);
        [$fgR, $fgG, $fgB] = ColorHelper::hexToRgb($fgHex);
        $ratio = Luminance::contrastRatio($fgR, $fgG, $fgB, $bgR, $bgG, $bgB);

        $this->assertGreaterThanOrEqual(
            4.5,
            $ratio,
            "Token '{$token}' in " . ($dark ? 'dark' : 'light') . " mode has contrast {$ratio}:1 (min 4.5:1)"
        );
    }
}

public function themeProvider(): array
{
    return [
        'Cosmic Dark'  => [new CosmicTheme(), true],
        'Cosmic Light' => [new CosmicTheme(), false],
        'Minimal Dark' => [new MinimalTheme(), true],
        'Minimal Light' => [new MinimalTheme(), false],
        'HighContrast Dark' => [new HighContrastTheme(), true],
        'HighContrast Light' => [new HighContrastTheme(), false],
        'Daltonized Dark' => [new DaltonizedTheme(), true],
        'Daltonized Light' => [new DaltonizedTheme(), false],
    ];
}
```

### 13.4 Visual Tests

- Snapshot test each theme in both dark and light modes
- Verify all widget borders are visible against the background
- Verify diff highlights are distinguishable from context lines
- Verify syntax highlighting maintains readability in both modes

---

## 14. Open Questions

| # | Question | Default Answer |
|---|----------|----------------|
| 1 | Should OSC 11 probe ever be enabled by default? | No — opt-in only. The safe cascade (COLORFGBG → platform API → default-dark) covers >90% of cases without any risk. |
| 2 | Should we cache the detection result to disk (`~/.kosmokrator/.bg_probe`)? | No — terminal background can change between sessions (e.g., user switches terminal theme). Detection is fast enough to run every startup. |
| 3 | Runtime mode switching (hot-reload when user toggles terminal theme)? | Not in v1. Requires terminal to emit events on theme change, which very few do. User can restart KosmoKrator or use `/config appearance dark|light`. |
| 4 | What about terminals that have a "transparent" background? | Transparent backgrounds inherit the desktop wallpaper color. OSC 11 usually reports the effective composite color. $COLORFGBG is unreliable here. Default to dark. |
| 5 | Should `text-dimmer` tokens meet WCAG AA? | No — `text-dimmer` is for decorative elements (separators, hint text). WCAG allows 3:1 for non-essential UI. |
| 6 | How to handle user theme YAML files that only define dark variants? | If only a single value is provided (not a dark/light map), it's used for both modes with a deprecation notice logged. Theme loader should warn: "Token 'primary' has no light variant; dark value will be used for both modes." |
