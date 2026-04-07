# 02 — Automatic Color Downsampling

> **Module**: `src/UI/Color/`  
> **Depends on**: `04-theming/01-theme-system.md` (Theme class)  
> **Status**: Draft

## Problem

Kosmokrator's `Theme` class (`src/UI/Theme.php`) hardcodes **TrueColor (24-bit)** escape sequences everywhere:

```php
public static function primary(): string {
    return self::rgb(255, 60, 40);  // \033[38;2;255;60;40m — TrueColor only
}
```

On terminals that don't support TrueColor (macOS Terminal.app, screen, tmux, older Windows consoles), these sequences either produce garbled output, are silently ignored, or render incorrect colors. The TUI needs automatic color capability detection and graceful degradation through the color depth stack:

```
TrueColor (16M) → 256-color → 16-color → monochrome/ASCII
```

## Existing Foundation

### Symfony Console — `AnsiColorMode` enum

File: `vendor/symfony/tui/src/Symfony/Component/Console/Output/AnsiColorMode.php`

Symfony already ships an enum with the three levels and built-in hex→ANSI conversion:

```php
enum AnsiColorMode {
    case Ansi4;   // 16-color (4-bit)
    case Ansi8;   // 256-color (8-bit)
    case Ansi24;  // TrueColor (24-bit)

    public function convertFromHexToAnsiColorCode(string $hexColor): string { ... }
}
```

Key conversion algorithms already implemented:
- **Ansi4**: `round(b/255) << 2 | round(g/255) << 1 | round(r/255)` — crude 1-bit-per-channel threshold
- **Ansi8**: Grayscale ramp (indices 232–255) + 6×6×6 color cube (indices 16–231) using `16 + 36×round(r/255×5) + 6×round(g/255×5) + round(b/255×5)`

### Symfony Console — `Terminal::getColorMode()`

File: `vendor/symfony/tui/src/Symfony/Component/Console/Terminal.php`

Detection logic (lines 31–75):
1. Check `COLORTERM` env var → `truecolor` → Ansi24, `256color` → Ansi8
2. Check `TERM` env var → same heuristics
3. Default → Ansi4

### Symfony TUI — `Style\Color`

File: `vendor/symfony/tui/src/Symfony/Component/Tui/Style/Color.php`

A rich value object with `named()`, `palette()`, `hex()`, `rgb()` factories, `toRgb()`, `toHex()`, `mix()`, `tint()`, `shade()`, and `toForegroundCode()` / `toBackgroundCode()` — but **always emits TrueColor for hex colors** (no downsampling).

### Lip Gloss (Go) — Reference Design

Lip Gloss's `colorprofile` library defines four profiles:
- **TrueColor** — full 24-bit RGB
- **ANSI256** — 8-bit color cube
- **ANSI** — basic 16 colors
- **Ascii** — no color at all

Detection probes `COLORTERM`, `TERM_PROGRAM`, `TERM`, `NO_COLOR`, `TERM_PROGRAM_VERSION` (for Terminal.app heuristic), and `WT_SESSION` (Windows Terminal). It automatically converts any color to the active profile's format.

---

## Design

### 1. `ColorProfile` enum

```
src/UI/Color/ColorProfile.php
```

```php
enum ColorProfile: string
{
    case TrueColor = 'truecolor';   // 24-bit (16M colors)
    case Ansi256   = '256';         // 8-bit (256 colors)
    case Ansi16    = '16';          // 4-bit (16 colors)
    case Ascii     = 'ascii';       // No color support

    /**
     * Whether this profile supports any ANSI color at all.
     */
    public function hasColor(): bool
    {
        return $this !== self::Ascii;
    }

    /**
     * Whether this profile supports TrueColor output.
     */
    public function isTrueColor(): bool
    {
        return $this === self::TrueColor;
    }

    /**
     * Get the maximum number of colors this profile can represent.
     */
    public function maxColors(): int
    {
        return match ($this) {
            self::TrueColor => 16_777_216,
            self::Ansi256   => 256,
            self::Ansi16    => 16,
            self::Ascii     => 0,
        };
    }
}
```

### 2. `TerminalColorDetector` — Capability Detection

```
src/UI/Color/TerminalColorDetector.php
```

Detection runs **once** at startup, cached as a static. Probes in priority order:

| Priority | Probe | Maps to | Rationale |
|----------|-------|---------|-----------|
| 1 | `NO_COLOR` env (not empty) | `Ascii` | [no-color.org](https://no-color.org) standard |
| 2 | `COLORTERM` contains `truecolor` or `24bit` | `TrueColor` | Most reliable indicator |
| 3 | `COLORTERM` contains `256color` | `Ansi256` | Explicit 256-color claim |
| 4 | `TERM_PROGRAM` = `Apple_Terminal` | `Ansi256` | macOS Terminal.app: 256 only, never TrueColor |
| 5 | `TERM_PROGRAM` = `iTerm.app` | `TrueColor` | iTerm2 supports TrueColor |
| 6 | `TERM_PROGRAM` = `WezTerm` | `TrueColor` | WezTerm supports TrueColor |
| 7 | `TERM_PROGRAM` = `ghostty` | `TrueColor` | Ghostty supports TrueColor |
| 8 | `TERM_PROGRAM` = `Hyper` | `TrueColor` | Hyper supports TrueColor |
| 9 | `TERM_PROGRAM` = `kitty` | `TrueColor` | Kitty supports TrueColor |
| 10 | `TERM` contains `truecolor` | `TrueColor` | e.g. `xterm-truecolor` |
| 11 | `TERM` contains `256color` | `Ansi256` | e.g. `xterm-256color` |
| 12 | `TERM` contains `screen` or `tmux` | `Ansi16` | screen/tmux often strip TrueColor |
| 13 | `TERM` contains `xterm` | `Ansi256` | xterm at least 256 |
| 14 | `WT_SESSION` set (Windows Terminal) | `TrueColor` | Windows Terminal supports TrueColor |
| 15 | `ConEmuANSI` = `ON` | `Ansi256` | ConEmu on Windows |
| 16 | Default | `Ansi16` | Safe fallback |

**tmux/screen refinement**: If inside tmux/screen but `COLORTERM` is set, trust `COLORTERM`. Modern tmux with `set -g default-terminal "screen-256color"` + `set -ga terminal-overrides ",*256col*:Tc"` passes TrueColor through.

```php
final class TerminalColorDetector
{
    private static ?ColorProfile $profile = null;

    public static function detect(): ColorProfile
    {
        if (self::$profile !== null) {
            return self::$profile;
        }

        self::$profile = self::doDetect();
        return self::$profile;
    }

    public static function force(ColorProfile $profile): void
    {
        self::$profile = $profile;
    }

    public static function reset(): void
    {
        self::$profile = null;
    }

    private static function doDetect(): ColorProfile
    {
        // 1. NO_COLOR standard — explicit opt-out
        if (getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '') {
            return ColorProfile::Ascii;
        }

        // 2. COLORTERM — most reliable
        $colorterm = strtolower(getenv('COLORTERM') ?: '');
        if (str_contains($colorterm, 'truecolor') || str_contains($colorterm, '24bit')) {
            return ColorProfile::TrueColor;
        }
        if (str_contains($colorterm, '256color')) {
            return ColorProfile::Ansi256;
        }

        // 3. TERM_PROGRAM — specific terminal identification
        $termProgram = getenv('TERM_PROGRAM') ?: '';
        if ($termProgram === 'Apple_Terminal') {
            return ColorProfile::Ansi256;  // Never TrueColor
        }
        if (in_array($termProgram, ['iTerm.app', 'WezTerm', 'ghostty', 'Hyper', 'kitty'], true)) {
            return ColorProfile::TrueColor;
        }

        // 4. TERM — generic terminal type
        $term = strtolower(getenv('TERM') ?: '');
        if (str_contains($term, 'truecolor')) {
            return ColorProfile::TrueColor;
        }
        if (str_contains($term, '256color')) {
            return ColorProfile::Ansi256;
        }
        // screen/tmux without explicit COLORTERM — assume limited
        if (str_contains($term, 'screen') || str_contains($term, 'tmux')) {
            return ColorProfile::Ansi16;
        }
        if (str_contains($term, 'xterm')) {
            return ColorProfile::Ansi256;
        }

        // 5. Windows Terminal
        if (getenv('WT_SESSION') !== false) {
            return ColorProfile::TrueColor;
        }

        // 6. ConEmu
        if (getenv('ConEmuANSI') === 'ON') {
            return ColorProfile::Ansi256;
        }

        // 7. Safe default
        return ColorProfile::Ansi16;
    }
}
```

### 3. `ColorConverter` — Conversion Algorithms

```
src/UI/Color/ColorConverter.php
```

Stateless utility that converts RGB `(r, g, b)` to the appropriate ANSI code for a given `ColorProfile`.

#### TrueColor → no conversion

```php
// Output: \033[38;2;R;G;Bm  (foreground)
// Output: \033[48;2;R;G;Bm  (background)
```

#### TrueColor → 256-color (Ansi8)

Uses the same algorithm as Symfony's `AnsiColorMode::degradeHexColorToAnsi8()`:

**Grayscale path** (r ≈ g ≈ b):
```
if r < 8  → index 16  (black)
if r > 248 → index 231 (white)
otherwise → index = round((r - 8) / 247 × 24) + 232
```

**Color cube path** (16–231):
```
index = 16 + 36 × round(r/255 × 5) + 6 × round(g/255 × 5) + round(b/255 × 5)
```

The 6×6×6 cube maps each channel to levels `[0, 95, 135, 175, 215, 255]`.

| R index | G index | B index | Palette range |
|---------|---------|---------|---------------|
| 0–5 | 0–5 | 0–5 | 16–231 |

#### TrueColor → 16-color (Ansi4)

Uses Symfony's `degradeHexColorToAnsi4()`:
```
index = round(b/255) << 2 | round(g/255) << 1 | round(r/255)
```

This maps to the standard 8 ANSI colors (0–7):
| Index | Color | RGB threshold |
|-------|-------|---------------|
| 0 | Black | all channels < 128 |
| 1 | Red | R ≥ 128 |
| 2 | Green | G ≥ 128 |
| 3 | Yellow | R ≥ 128, G ≥ 128 |
| 4 | Blue | B ≥ 128 |
| 5 | Magenta | R ≥ 128, B ≥ 128 |
| 6 | Cyan | G ≥ 128, B ≥ 128 |
| 7 | White | all channels ≥ 128 |

For **foreground**: `ESC[3Xm` where X = index  
For **background**: `ESC[4Xm` where X = index  

**Brightness heuristic**: If the luminance `(0.299R + 0.587G + 0.114B)` ≥ 128, use the bright variant (indices 8–15, codes 90–97 for fg, 100–107 for bg). This preserves visual intent — a bright orange `#FF6040` should map to bright-red (91), not dark red (31).

```
luminance = 0.299 × r + 0.587 × g + 0.114 × b
if luminance >= 128 → bright variant (base + 8)
```

#### 256-color → 16-color

Map the palette index through the same RGB conversion:
1. Convert palette index → RGB using the 256-color table definition
2. Apply the TrueColor → 16-color algorithm above

#### Any → Ascii

Strip all color sequences. Only retain text attributes (bold, underline, etc.) for visual differentiation.

### 4. `ColorDownsampler` — The Core Service

```
src/UI/Color/ColorDownsampler.php
```

```php
final class ColorDownsampler
{
    private ColorProfile $profile;

    public function __construct(?ColorProfile $profile = null)
    {
        $this->profile = $profile ?? TerminalColorDetector::detect();
    }

    /**
     * Convert an RGB color to a foreground ANSI sequence for the active profile.
     */
    public function foregroundRgb(int $r, int $g, int $b): string
    {
        return match ($this->profile) {
            ColorProfile::TrueColor => "\033[38;2;{$r};{$g};{$b}m",
            ColorProfile::Ansi256   => "\033[38;5;" . ColorConverter::rgbTo256($r, $g, $b) . "m",
            ColorProfile::Ansi16    => ColorConverter::rgbTo16($r, $g, $b, foreground: true),
            ColorProfile::Ascii     => '',
        };
    }

    /**
     * Convert an RGB color to a background ANSI sequence for the active profile.
     */
    public function backgroundRgb(int $r, int $g, int $b): string
    {
        return match ($this->profile) {
            ColorProfile::TrueColor => "\033[48;2;{$r};{$g};{$b}m",
            ColorProfile::Ansi256   => "\033[48;5;" . ColorConverter::rgbTo256($r, $g, $b) . "m",
            ColorProfile::Ansi16    => ColorConverter::rgbTo16($r, $g, $b, foreground: false),
            ColorProfile::Ascii     => '',
        };
    }

    /**
     * Get the active color profile.
     */
    public function getProfile(): ColorProfile
    {
        return $this->profile;
    }
}
```

### 5. Integration with Theme

The `Theme` class gains a static `ColorDownsampler` instance. All color methods route through it:

**Current** (`src/UI/Theme.php`):
```php
public static function primary(): string {
    return self::rgb(255, 60, 40);  // Always TrueColor
}
```

**After**:
```php
private static ?ColorDownsampler $downsampler = null;

public static function downsampler(): ColorDownsampler
{
    return self::$downsampler ??= new ColorDownsampler();
}

public static function primary(): string
{
    return self::downsampler()->foregroundRgb(255, 60, 40);
}

public static function diffAddBg(): string
{
    return self::downsampler()->backgroundRgb(20, 45, 20);
}
```

The API surface of `Theme` stays identical — callers don't change. The downsampler is initialized lazily on first access.

### 6. Conversion Lookup Tables

#### 256-Color RGB Reference Table

The standard xterm 256-color palette:

| Range | Description | Formula |
|-------|-------------|---------|
| 0–7 | Standard colors | Named (black, red, green, yellow, blue, magenta, cyan, white) |
| 8–15 | Bright colors | Named (bright-black…bright-white) |
| 16–231 | 6×6×6 color cube | `16 + 36×R + 6×G + B` where R,G,B ∈ [0,5], channel = `[0, 95, 135, 175, 215, 255]` |
| 232–255 | Grayscale ramp | `value = 8 + 10 × (index - 232)`, range 8–238 |

#### Color Cube Channel Levels

| Index | Channel Value |
|-------|--------------|
| 0 | 0 |
| 1 | 95 |
| 2 | 135 |
| 3 | 175 |
| 4 | 215 |
| 5 | 255 |

#### Grayscale Ramp Values

| Index | Gray Value |
|-------|-----------|
| 232 | 8 |
| 233 | 18 |
| 234 | 28 |
| 235 | 38 |
| ... | ... |
| 246 | 148 |
| ... | ... |
| 254 | 228 |
| 255 | 238 |

#### Pre-computed Named Color → 16-color Mapping

| Named Color | RGB | Ansi16 (fg) | Ansi16 (bg) |
|-------------|-----|-------------|-------------|
| Black | (0,0,0) | 30 | 40 |
| Red | (205,0,0) | 31 | 41 |
| Green | (0,205,0) | 32 | 42 |
| Yellow | (205,205,0) | 33 | 43 |
| Blue | (0,0,238) | 34 | 44 |
| Magenta | (205,0,205) | 35 | 45 |
| Cyan | (0,205,205) | 36 | 46 |
| White | (229,229,229) | 37 | 47 |
| Bright Black | (127,127,127) | 90 | 100 |
| Bright Red | (255,0,0) | 91 | 101 |
| Bright Green | (0,255,0) | 92 | 102 |
| Bright Yellow | (255,255,0) | 93 | 103 |
| Bright Blue | (92,92,255) | 94 | 104 |
| Bright Magenta | (255,0,255) | 95 | 105 |
| Bright Cyan | (0,255,255) | 96 | 106 |
| Bright White | (255,255,255) | 97 | 107 |

### 7. Edge Cases & Terminal Quirks

| Terminal | Behavior | Handling |
|----------|----------|----------|
| **macOS Terminal.app** | Supports 256-color but **not** TrueColor. `TERM_PROGRAM=Apple_Terminal` | Map to `Ansi256` |
| **screen** | Often forces `TERM=screen` which is 16-color. Even with 256-color patch, TrueColor is stripped. | Map to `Ansi16` unless `COLORTERM` is explicitly set |
| **tmux** | Can pass TrueColor through with `terminal-overrides`, but `TERM` is often `screen-256color` which doesn't indicate TrueColor | Trust `COLORTERM` over `TERM` when both present |
| **Windows Console (cmd)** | Pre-Windows 10: no ANSI at all | Map to `Ascii` (no color env vars set) |
| **Windows Terminal** | Full TrueColor support. Sets `WT_SESSION` | Map to `TrueColor` |
| **JetBrains IDE terminal** | Sets `TERMINAL_EMULATOR=JetBrains-JediTerm`, supports TrueColor | Detect `TERMINAL_EMULATOR` and map to `TrueColor` |
| **VS Code integrated terminal** | Sets `TERM_PROGRAM=vscode`, supports TrueColor | Map to `TrueColor` |
| **CI/CD (GitHub Actions, etc.)** | Often no `TERM` or `TERM=dumb` | Map to `Ascii` |
| **Emacs shell/eshell** | `TERM=eterm-color`, 16-color | Map to `Ansi16` |
| **Dumb terminals** | `TERM=dumb` | Map to `Ascii` |

Additional environment variables to probe (supplementary):

| Variable | Meaning |
|----------|---------|
| `TERMINAL_EMULATOR=JetBrains-JediTerm` | JetBrains IDE terminal → TrueColor |
| `TERM_PROGRAM=vscode` | VS Code terminal → TrueColor |
| `KITTY_WINDOW_ID` | Kitty terminal → TrueColor |
| `GHOSTTY_RESOURCES_DIR` | Ghostty → TrueColor |
| `TERM=dumb` | No capability → Ascii |

### 8. Caching Strategy

```
Detection flow:
  CLI startup → TerminalColorDetector::detect() → one-time probe → cached in static
  All Theme methods → use cached profile → no repeated detection
```

- **Static cache**: `TerminalColorDetector::$profile` — lives for the entire PHP process
- **Force override**: `TerminalColorDetector::force(ColorProfile::TrueColor)` — for `--color=always` flag
- **Force no-color**: `TerminalColorDetector::force(ColorProfile::Ascii)` — for `--color=never` flag
- **Auto-detect**: `TerminalColorDetector::detect()` — default, for `--color=auto` (the default)
- **Reset (testing)**: `TerminalColorDetector::reset()` — clears cache for re-detection in tests

### 9. Per-Session Storage

For TUI mode, the `ColorProfile` is stored alongside other terminal capabilities:

```php
// In the TUI application bootstrap
$profile = TerminalColorDetector::detect();
$terminal = new Terminal(
    width: $width,
    height: $height,
    colorProfile: $profile,
    supportsKittyGraphics: Terminal::supportsKittyGraphics(),
);
```

The `Terminal` value object carries the profile. All rendering passes receive the terminal context, ensuring consistent color output throughout the session.

For ANSI mode (non-TUI), the static cache suffices — one detection per process invocation.

### 10. CLI Flags

Kosmokrator should support standard color control flags:

| Flag | Effect |
|------|--------|
| `--color=auto` | Auto-detect (default) |
| `--color=always` / `--color` | Force TrueColor output |
| `--color=256` | Force 256-color output |
| `--color=16` | Force 16-color output |
| `--color=never` / `--no-color` | Strip all color (Ascii) |

These map directly to `TerminalColorDetector::force(...)`.

---

## File Layout

```
src/UI/Color/
├── ColorProfile.php              # Enum: TrueColor, Ansi256, Ansi16, Ascii
├── TerminalColorDetector.php     # Static detection + caching
├── ColorConverter.php            # Pure conversion functions (static)
└── ColorDownsampler.php          # Service: RGB → ANSI for active profile

src/UI/
├── Theme.php                     # Modified: route through ColorDownsampler
└── ...
```

## Implementation Order

1. **`ColorProfile` enum** — trivial, no dependencies
2. **`ColorConverter`** — pure functions, unit-testable in isolation
3. **`TerminalColorDetector`** — env probing + caching
4. **`ColorDownsampler`** — wires profile + converter
5. **`Theme` integration** — replace hardcoded `rgb()`/`bgRgb()` calls with downsampler
6. **CLI flags** — `--color` option parsing → `force()` call
7. **Tests** — integration tests with mocked env vars

## Testing Strategy

### Unit Tests — `ColorConverter`

Test every conversion path with known inputs/outputs:

```php
// TrueColor → 256
ColorConverter::rgbTo256(0, 0, 0)       // → 16  (black)
ColorConverter::rgbTo256(255, 255, 255)  // → 231 (white)
ColorConverter::rgbTo256(128, 128, 128)  // → 244 (gray)
ColorConverter::rgbTo256(255, 0, 0)      // → 196 (red)
ColorConverter::rgbTo256(0, 215, 0)      // → 41  (green)
ColorConverter::rgbTo256(95, 135, 0)     // → 64  (cube)

// TrueColor → 16
ColorConverter::rgbTo16(0, 0, 0, true)       // → "\033[30m"  (black fg)
ColorConverter::rgbTo16(255, 0, 0, true)      // → "\033[91m"  (bright red fg)
ColorConverter::rgbTo16(0, 0, 0, false)       // → "\033[40m"  (black bg)
ColorConverter::rgbTo16(255, 0, 0, false)      // → "\033[101m" (bright red bg)
```

### Integration Tests — `TerminalColorDetector`

Mock `getenv()` results for each terminal profile:

```php
// macOS Terminal.app
$_ENV['TERM_PROGRAM'] = 'Apple_Terminal';
// → Ansi256

// Modern tmux with TrueColor passthrough
$_ENV['TERM'] = 'screen-256color';
$_ENV['COLORTERM'] = 'truecolor';
// → TrueColor (COLORTERM wins)

// Bare screen
$_ENV['TERM'] = 'screen';
// → Ansi16

// NO_COLOR
$_ENV['NO_COLOR'] = '1';
// → Ascii
```

### Visual Tests

Render the full theme palette in each profile and capture screenshots. Compare against reference images to verify visual fidelity degrades gracefully.

## Performance Considerations

- **Detection**: O(1) — cached after first call. ~10 string comparisons max.
- **Conversion**: O(1) — simple arithmetic per color. No iteration or search.
- **Theme methods**: One extra method call per color (downsampler dispatch). Negligible overhead.
- **No lookup tables needed**: The conversion algorithms are closed-form expressions. Pre-computed tables would add memory overhead for negligible speed gain.
