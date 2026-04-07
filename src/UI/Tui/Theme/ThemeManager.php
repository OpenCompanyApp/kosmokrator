<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Theme;

/**
 * Central theming service: manages theme registry, terminal detection,
 * token resolution, color downsampling, and caching.
 *
 * Responsibilities:
 *   1. Hold a registry of named theme definitions (built-in + user)
 *   2. Detect terminal color capability and dark/light background
 *   3. Resolve semantic tokens to hex colors (with dark/light variants)
 *   4. Downsample hex colors to the terminal's color profile
 *   5. Cache resolved values for the active session
 *
 * Usage:
 *   $manager = ThemeManager::create();
 *   $manager->setTheme('cosmic');
 *
 *   // ANSI escape sequences (for Theme.php facade)
 *   $fg = $manager->ansi('primary');
 *   $bg = $manager->ansiBg('code-bg');
 *
 *   // Terminal control methods remain unchanged (no color dependency):
 *   $manager->colorProfile();           // ColorProfile enum
 *   $manager->isDark();                 // bool
 */
class ThemeManager
{
    /** @var array<string, array{tokens: array<string, string|array{dark: string, light: string}>, label?: string, description?: string, parent?: string}> */
    private array $themes = [];

    private string $activeThemeName = 'cosmic';

    private readonly ColorDownsampler $downsampler;

    private readonly ColorProfile $colorProfile;

    /** @var array<string, string> Resolved token→hex cache (for current dark/light mode) */
    private array $resolvedCache = [];

    /** @var array<string, string> Resolved token→ansi-fg cache */
    private array $ansiCache = [];

    /** @var array<string, string> Resolved token→ansi-bg cache */
    private array $ansiBgCache = [];

    private bool $isDark;

    /**
     * @param  ColorDownsampler|null  $downsampler  Optional pre-configured downsampler
     * @param  ColorProfile|null      $profile      Override detected profile (testing)
     * @param  bool|null              $isDark       Override dark/light detection (testing)
     */
    public function __construct(
        ?ColorDownsampler $downsampler = null,
        ?ColorProfile $profile = null,
        ?bool $isDark = null,
    ) {
        $this->downsampler = $downsampler ?? new ColorDownsampler($profile);
        $this->colorProfile = $this->downsampler->getProfile();
        $this->isDark = $isDark ?? self::detectIsDark();

        // Register the built-in Cosmic theme
        $this->registerBuiltInThemes();
    }

    /**
     * Convenience factory: create a manager with auto-detection.
     */
    public static function create(): self
    {
        return new self();
    }

    // ── Theme Registry ─────────────────────────────────────────────────

    /**
     * Register a theme definition.
     *
     * @param  string  $name  Unique theme identifier
     * @param  array<string, string|array{dark: string, light: string}>  $tokens  Token→color map
     * @param  string  $label  Human-readable theme name
     * @param  string  $description  Theme description
     * @param  string|null  $parent  Optional parent theme to inherit from
     */
    public function registerTheme(
        string $name,
        array $tokens,
        string $label = '',
        string $description = '',
        ?string $parent = null,
    ): void {
        $this->themes[$name] = [
            'tokens' => $tokens,
            'label' => $label ?: $name,
            'description' => $description,
            'parent' => $parent,
        ];

        // Invalidate cache if this is the active theme
        if ($name === $this->activeThemeName) {
            $this->clearCache();
        }
    }

    /**
     * Get list of registered theme names.
     *
     * @return list<string>
     */
    public function availableThemes(): array
    {
        return array_keys($this->themes);
    }

    /**
     * Check if a theme is registered.
     */
    public function hasTheme(string $name): bool
    {
        return isset($this->themes[$name]);
    }

    // ── Active Theme ───────────────────────────────────────────────────

    /**
     * Set the active theme by name.
     *
     * @throws \InvalidArgumentException if theme is not registered
     */
    public function setTheme(string $name): void
    {
        if (!isset($this->themes[$name])) {
            throw new \InvalidArgumentException("Unknown theme: '{$name}'. Available: ".implode(', ', $this->availableThemes()));
        }

        $this->activeThemeName = $name;
        $this->clearCache();
    }

    /**
     * Get the active theme name.
     */
    public function activeThemeName(): string
    {
        return $this->activeThemeName;
    }

    /**
     * Apply inline token overrides on top of the active theme.
     *
     * @param  array<string, string|array{dark: string, light: string}>  $overrides
     */
    public function applyOverrides(array $overrides): void
    {
        $theme = &$this->themes[$this->activeThemeName];
        foreach ($overrides as $token => $value) {
            $theme['tokens'][$token] = $value;
        }
        $this->clearCache();
    }

    // ── Token Resolution ───────────────────────────────────────────────

    /**
     * Resolve a semantic token to an ANSI foreground escape sequence.
     *
     * Uses the terminal's color profile for downsampling.
     */
    public function ansi(string $token): string
    {
        if (isset($this->ansiCache[$token])) {
            return $this->ansiCache[$token];
        }

        $hex = $this->resolveToken($token);

        return $this->ansiCache[$token] = $this->downsampler->foregroundHex($hex);
    }

    /**
     * Resolve a semantic token to an ANSI background escape sequence.
     */
    public function ansiBg(string $token): string
    {
        if (isset($this->ansiBgCache[$token])) {
            return $this->ansiBgCache[$token];
        }

        $hex = $this->resolveToken($token);

        return $this->ansiBgCache[$token] = $this->downsampler->backgroundHex($hex);
    }

    /**
     * Resolve a semantic token to a hex color string (#RRGGBB).
     *
     * Picks the correct dark/light variant based on terminal background.
     */
    public function resolveToken(string $token): string
    {
        if (isset($this->resolvedCache[$token])) {
            return $this->resolvedCache[$token];
        }

        $hex = $this->doResolveToken($token, $this->isDark);

        return $this->resolvedCache[$token] = $hex;
    }

    /**
     * Get all resolved tokens as a flat map [token => hex].
     *
     * @return array<string, string>
     */
    public function resolvedTokens(): array
    {
        foreach (ThemeTokens::names() as $name) {
            $this->resolveToken($name);
        }

        return $this->resolvedCache;
    }

    // ── Terminal Info ──────────────────────────────────────────────────

    /**
     * Whether the terminal has a dark background.
     */
    public function isDark(): bool
    {
        return $this->isDark;
    }

    /**
     * Whether the terminal has a light background.
     */
    public function isLight(): bool
    {
        return !$this->isDark;
    }

    /**
     * Get the current terminal color profile.
     */
    public function colorProfile(): ColorProfile
    {
        return $this->colorProfile;
    }

    /**
     * Get the active downsampler.
     */
    public function downsampler(): ColorDownsampler
    {
        return $this->downsampler;
    }

    /**
     * Force dark/light mode (for config override or testing).
     */
    public function forceDarkMode(bool $dark): void
    {
        $this->isDark = $dark;
        $this->clearCache();
    }

    // ── Internal Resolution ────────────────────────────────────────────

    /**
     * Resolve a token through the active theme + fallback chain.
     */
    private function doResolveToken(string $token, bool $dark): string
    {
        // 1. Try the active theme (with parent inheritance)
        $resolved = $this->resolveFromTheme($token, $this->activeThemeName, $dark, []);
        if ($resolved !== null) {
            return $resolved;
        }

        // 2. Try the fallback chain from ThemeTokens
        foreach (ThemeTokens::fallbackChain($token) as $fallback) {
            $resolved = $this->resolveFromTheme($fallback, $this->activeThemeName, $dark, []);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 3. Use the built-in Cosmic defaults from ThemeTokens
        $default = $dark ? ThemeTokens::defaultDark($token) : ThemeTokens::defaultLight($token);
        if ($default !== null) {
            return $default;
        }

        throw new \InvalidArgumentException("Unknown semantic token: '{$token}'");
    }

    /**
     * Resolve a token from a specific theme, following parent inheritance.
     *
     * @param  string  $token
     * @param  string  $themeName
     * @param  bool  $dark
     * @param  list<string>  $visited  Guard against circular inheritance
     * @return string|null Hex color or null if not found
     */
    private function resolveFromTheme(string $token, string $themeName, bool $dark, array $visited): ?string
    {
        if (in_array($themeName, $visited, true)) {
            return null; // Circular inheritance guard
        }
        $visited[] = $themeName;

        if (!isset($this->themes[$themeName])) {
            return null;
        }

        $theme = $this->themes[$themeName];
        $tokens = $theme['tokens'];

        if (isset($tokens[$token])) {
            $value = $tokens[$token];

            // Dark/light variant map
            if (is_array($value)) {
                if ($dark && isset($value['dark'])) {
                    return $value['dark'];
                }
                if (!$dark && isset($value['light'])) {
                    return $value['light'];
                }
                // Fallback to whichever is available
                if (isset($value['dark'])) {
                    return $value['dark'];
                }
                if (isset($value['light'])) {
                    return $value['light'];
                }

                return null;
            }

            // Single string value — used for both modes
            if (is_string($value)) {
                return $value;
            }
        }

        // Try parent theme if defined
        if (isset($theme['parent']) && $theme['parent'] !== '') {
            return $this->resolveFromTheme($token, $theme['parent'], $dark, $visited);
        }

        return null;
    }

    /**
     * Clear the resolution caches.
     */
    private function clearCache(): void
    {
        $this->resolvedCache = [];
        $this->ansiCache = [];
        $this->ansiBgCache = [];
    }

    /**
     * Detect whether the terminal has a dark background.
     *
     * Uses $COLORFGBG and platform heuristics. Falls back to dark (safe default).
     */
    private static function detectIsDark(): bool
    {
        // 1. Explicit override via KOSMOKRATOR_THEME env var
        $env = getenv('KOSMOKRATOR_THEME');
        if ($env !== false && $env !== '') {
            $val = strtolower(trim($env));
            if ($val === 'light') {
                return false;
            }
            if ($val === 'dark') {
                return true;
            }
        }

        // 2. $COLORFGBG environment variable
        $colorfgbg = getenv('COLORFGBG');
        if ($colorfgbg !== false && $colorfgbg !== '') {
            $parts = array_map('intval', explode(';', $colorfgbg));
            if (count($parts) >= 2) {
                // Background is the last numeric field
                $bgIndex = $parts[count($parts) - 1];

                // ANSI indices 0–7 = dark, 8–15 = light
                return $bgIndex < 8;
            }
        }

        // 3. Platform-specific detection (macOS only for now)
        if (PHP_OS_FAMILY === 'Darwin') {
            $termProgram = getenv('TERM_PROGRAM') ?: '';
            if ($termProgram === 'Apple_Terminal') {
                // Check macOS system appearance
                exec('defaults read -g AppleInterfaceStyle 2>/dev/null', $output, $exitCode);
                // Key exists → dark mode
                return $exitCode === 0;
            }
        }

        // 4. Default: dark (80%+ of developer terminals)
        return true;
    }

    /**
     * Register the built-in Cosmic theme (the default KosmoKrator palette).
     */
    private function registerBuiltInThemes(): void
    {
        // Cosmic theme — the default, derived from the original Theme.php colors
        $this->registerTheme(
            'cosmic',
            self::cosmicTokens(),
            'Cosmic',
            'The default KosmoKrator theme — warm reds, golds, and cosmic purples',
        );

        $this->registerTheme('minimal', self::minimalTokens(), 'Minimal', 'Muted, desaturated colors for a calm, low-distraction interface', 'cosmic');
        $this->registerTheme('high-contrast', self::highContrastTokens(), 'High Contrast', 'Maximum readability with bright saturated colors on pure backgrounds', 'cosmic');
        $this->registerTheme('daltonized', self::daltonizedTokens(), 'Daltonized', 'Color-blind friendly palette using the Okabe-Ito scheme', 'cosmic');
    }

    /**
     * Get the Cosmic theme token map.
     *
     * @return array<string, array{dark: string, light: string}>
     */
    private static function cosmicTokens(): array
    {
        $tokens = [];
        foreach (ThemeTokens::all() as $name => $def) {
            $tokens[$name] = [
                'dark' => $def['dark'],
                'light' => $def['light'],
            ];
        }

        return $tokens;
    }

    /**
     * Get the Minimal theme token map — muted, desaturated colors.
     *
     * @return array<string, array{dark: string, light: string}>
     */
    private static function minimalTokens(): array
    {
        return [
            // Core
            'primary' => ['dark' => '#8899aa', 'light' => '#667788'],
            'primary-dim' => ['dark' => '#667788', 'light' => '#889999'],
            'accent' => ['dark' => '#aaaaaa', 'light' => '#777777'],
            'accent-dim' => ['dark' => '#888888', 'light' => '#999999'],
            // Semantic
            'success' => ['dark' => '#77aa88', 'light' => '#447755'],
            'warning' => ['dark' => '#aaaa77', 'light' => '#887744'],
            'error' => ['dark' => '#aa7777', 'light' => '#884444'],
            'info' => ['dark' => '#7799aa', 'light' => '#446677'],
            // Text
            'text' => ['dark' => '#cccccc', 'light' => '#444444'],
            'text-bright' => ['dark' => '#eeeeee', 'light' => '#222222'],
            'text-dim' => ['dark' => '#888888', 'light' => '#888888'],
            'text-dimmer' => ['dark' => '#666666', 'light' => '#aaaaaa'],
            'text-heading' => ['dark' => '#ffffff', 'light' => '#000000'],
            // UI
            'border-active' => ['dark' => '#888899', 'light' => '#777788'],
            'border-inactive' => ['dark' => '#555566', 'light' => '#aaaaaa'],
            'border-task' => ['dark' => '#777766', 'light' => '#999988'],
            'border-accent' => ['dark' => '#888888', 'light' => '#999999'],
            'border-plan' => ['dark' => '#7777aa', 'light' => '#666699'],
            'background' => ['dark' => '#1a1a1e', 'light' => '#f0f0f0'],
            'surface' => ['dark' => '#222226', 'light' => '#e4e4e4'],
            'surface-bright' => ['dark' => '#2e2e32', 'light' => '#d4d4d4'],
            // Diff
            'diff-add' => ['dark' => '#669977', 'light' => '#336644'],
            'diff-add-bg' => ['dark' => '#1a2a1e', 'light' => '#ddeedd'],
            'diff-add-bg-strong' => ['dark' => '#2a3a2e', 'light' => '#bbddbb'],
            'diff-remove' => ['dark' => '#996666', 'light' => '#884444'],
            'diff-remove-bg' => ['dark' => '#2a1a1a', 'light' => '#eedddd'],
            'diff-remove-bg-strong' => ['dark' => '#3a2a2a', 'light' => '#ddbbbb'],
            'diff-context' => ['dark' => '#888888', 'light' => '#888888'],
            // Syntax
            'syntax-keyword' => ['dark' => '#9988aa', 'light' => '#776688'],
            'syntax-type' => ['dark' => '#aaaa88', 'light' => '#888866'],
            'syntax-value' => ['dark' => '#88aa88', 'light' => '#668866'],
            'syntax-number' => ['dark' => '#aaaa88', 'light' => '#888866'],
            'syntax-literal' => ['dark' => '#8899aa', 'light' => '#667788'],
            'syntax-variable' => ['dark' => '#cccccc', 'light' => '#444444'],
            'syntax-property' => ['dark' => '#8899aa', 'light' => '#667788'],
            'syntax-comment' => ['dark' => '#777777', 'light' => '#999999'],
            'syntax-operator' => ['dark' => '#cccccc', 'light' => '#444444'],
            'syntax-attribute' => ['dark' => '#9988aa', 'light' => '#776688'],
            'syntax-generic' => ['dark' => '#8899aa', 'light' => '#667788'],
            'syntax-function' => ['dark' => '#8899aa', 'light' => '#667788'],
            // Agent
            'agent-general' => ['dark' => '#aaaa88', 'light' => '#888866'],
            'agent-plan' => ['dark' => '#8877aa', 'light' => '#776699'],
            'agent-explore' => ['dark' => '#88aaaa', 'light' => '#668888'],
            'agent-waiting' => ['dark' => '#8888bb', 'light' => '#666699'],
            // Code blocks
            'code-fg' => ['dark' => '#9988aa', 'light' => '#776688'],
            'code-bg' => ['dark' => '#222226', 'light' => '#e4e4e4'],
            // Misc
            'link' => ['dark' => '#8899aa', 'light' => '#667788'],
            'separator' => ['dark' => '#444444', 'light' => '#cccccc'],
            'status-bar' => ['dark' => '#888888', 'light' => '#777777'],
            'thinking' => ['dark' => '#8899aa', 'light' => '#667788'],
            'compacting' => ['dark' => '#997777', 'light' => '#885555'],
        ];
    }

    /**
     * Get the High Contrast theme token map — maximum readability.
     *
     * @return array<string, array{dark: string, light: string}>
     */
    private static function highContrastTokens(): array
    {
        return [
            // Core
            'primary' => ['dark' => '#ff6666', 'light' => '#cc0000'],
            'primary-dim' => ['dark' => '#cc4444', 'light' => '#aa2222'],
            'accent' => ['dark' => '#ffff66', 'light' => '#aa9900'],
            'accent-dim' => ['dark' => '#cccc44', 'light' => '#887700'],
            // Semantic
            'success' => ['dark' => '#66ff66', 'light' => '#008800'],
            'warning' => ['dark' => '#ffff66', 'light' => '#aa8800'],
            'error' => ['dark' => '#ff4444', 'light' => '#cc0000'],
            'info' => ['dark' => '#66bbff', 'light' => '#0066cc'],
            // Text
            'text' => ['dark' => '#ffffff', 'light' => '#000000'],
            'text-bright' => ['dark' => '#ffffff', 'light' => '#000000'],
            'text-dim' => ['dark' => '#cccccc', 'light' => '#333333'],
            'text-dimmer' => ['dark' => '#999999', 'light' => '#666666'],
            'text-heading' => ['dark' => '#ffffff', 'light' => '#000000'],
            // UI
            'border-active' => ['dark' => '#ff6666', 'light' => '#cc0000'],
            'border-inactive' => ['dark' => '#888888', 'light' => '#888888'],
            'border-task' => ['dark' => '#ffff66', 'light' => '#aa9900'],
            'border-accent' => ['dark' => '#ffff66', 'light' => '#aa9900'],
            'border-plan' => ['dark' => '#bb88ff', 'light' => '#7744cc'],
            'background' => ['dark' => '#000000', 'light' => '#ffffff'],
            'surface' => ['dark' => '#111111', 'light' => '#eeeeee'],
            'surface-bright' => ['dark' => '#222222', 'light' => '#dddddd'],
            // Diff
            'diff-add' => ['dark' => '#66ff66', 'light' => '#008800'],
            'diff-add-bg' => ['dark' => '#003300', 'light' => '#ccffcc'],
            'diff-add-bg-strong' => ['dark' => '#006600', 'light' => '#88ff88'],
            'diff-remove' => ['dark' => '#ff4444', 'light' => '#cc0000'],
            'diff-remove-bg' => ['dark' => '#330000', 'light' => '#ffcccc'],
            'diff-remove-bg-strong' => ['dark' => '#660000', 'light' => '#ff8888'],
            'diff-context' => ['dark' => '#cccccc', 'light' => '#333333'],
            // Syntax
            'syntax-keyword' => ['dark' => '#ff88ff', 'light' => '#9900cc'],
            'syntax-type' => ['dark' => '#ffff66', 'light' => '#aa8800'],
            'syntax-value' => ['dark' => '#66ff66', 'light' => '#008800'],
            'syntax-number' => ['dark' => '#ffaa44', 'light' => '#cc7700'],
            'syntax-literal' => ['dark' => '#66bbff', 'light' => '#0066cc'],
            'syntax-variable' => ['dark' => '#ffffff', 'light' => '#000000'],
            'syntax-property' => ['dark' => '#66bbff', 'light' => '#0066cc'],
            'syntax-comment' => ['dark' => '#999999', 'light' => '#666666'],
            'syntax-operator' => ['dark' => '#ffffff', 'light' => '#000000'],
            'syntax-attribute' => ['dark' => '#ff88ff', 'light' => '#9900cc'],
            'syntax-generic' => ['dark' => '#66bbff', 'light' => '#0066cc'],
            'syntax-function' => ['dark' => '#66bbff', 'light' => '#0066cc'],
            // Agent
            'agent-general' => ['dark' => '#ffff66', 'light' => '#aa8800'],
            'agent-plan' => ['dark' => '#bb88ff', 'light' => '#7744cc'],
            'agent-explore' => ['dark' => '#66ffcc', 'light' => '#008866'],
            'agent-waiting' => ['dark' => '#88aaff', 'light' => '#4466cc'],
            // Code blocks
            'code-fg' => ['dark' => '#ff88ff', 'light' => '#9900cc'],
            'code-bg' => ['dark' => '#111111', 'light' => '#eeeeee'],
            // Misc
            'link' => ['dark' => '#66bbff', 'light' => '#0066cc'],
            'separator' => ['dark' => '#666666', 'light' => '#aaaaaa'],
            'status-bar' => ['dark' => '#cccccc', 'light' => '#333333'],
            'thinking' => ['dark' => '#66bbff', 'light' => '#0066cc'],
            'compacting' => ['dark' => '#ff4444', 'light' => '#cc0000'],
        ];
    }

    /**
     * Get the Daltonized theme token map — color-blind friendly Okabe-Ito palette.
     *
     * @return array<string, array{dark: string, light: string}>
     */
    private static function daltonizedTokens(): array
    {
        return [
            // Core
            'primary' => ['dark' => '#E69F00', 'light' => '#C07800'],
            'primary-dim' => ['dark' => '#A07000', 'light' => '#886000'],
            'accent' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            'accent-dim' => ['dark' => '#3D8AB8', 'light' => '#2A6A90'],
            // Semantic
            'success' => ['dark' => '#009E73', 'light' => '#007A58'],
            'warning' => ['dark' => '#F0E442', 'light' => '#C0B830'],
            'error' => ['dark' => '#D55E00', 'light' => '#AA4A00'],
            'info' => ['dark' => '#0072B2', 'light' => '#005A8E'],
            // Text
            'text' => ['dark' => '#cccccc', 'light' => '#333333'],
            'text-bright' => ['dark' => '#f0f0f0', 'light' => '#111111'],
            'text-dim' => ['dark' => '#999999', 'light' => '#777777'],
            'text-dimmer' => ['dark' => '#666666', 'light' => '#aaaaaa'],
            'text-heading' => ['dark' => '#ffffff', 'light' => '#000000'],
            // UI
            'border-active' => ['dark' => '#E69F00', 'light' => '#C07800'],
            'border-inactive' => ['dark' => '#666666', 'light' => '#aaaaaa'],
            'border-task' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            'border-accent' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            'border-plan' => ['dark' => '#CC79A7', 'light' => '#A05A82'],
            'background' => ['dark' => '#121212', 'light' => '#f5f5f5'],
            'surface' => ['dark' => '#1a1a1a', 'light' => '#e8e8e8'],
            'surface-bright' => ['dark' => '#2a2a2a', 'light' => '#d0d0d0'],
            // Diff
            'diff-add' => ['dark' => '#009E73', 'light' => '#007A58'],
            'diff-add-bg' => ['dark' => '#0a2a20', 'light' => '#ccf0e4'],
            'diff-add-bg-strong' => ['dark' => '#144433', 'light' => '#99ddc0'],
            'diff-remove' => ['dark' => '#D55E00', 'light' => '#AA4A00'],
            'diff-remove-bg' => ['dark' => '#2a1500', 'light' => '#f0dcc8'],
            'diff-remove-bg-strong' => ['dark' => '#442200', 'light' => '#ddbb99'],
            'diff-context' => ['dark' => '#999999', 'light' => '#777777'],
            // Syntax
            'syntax-keyword' => ['dark' => '#CC79A7', 'light' => '#A05A82'],
            'syntax-type' => ['dark' => '#E69F00', 'light' => '#C07800'],
            'syntax-value' => ['dark' => '#009E73', 'light' => '#007A58'],
            'syntax-number' => ['dark' => '#E69F00', 'light' => '#C07800'],
            'syntax-literal' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            'syntax-variable' => ['dark' => '#f0f0f0', 'light' => '#111111'],
            'syntax-property' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            'syntax-comment' => ['dark' => '#999999', 'light' => '#777777'],
            'syntax-operator' => ['dark' => '#f0f0f0', 'light' => '#111111'],
            'syntax-attribute' => ['dark' => '#CC79A7', 'light' => '#A05A82'],
            'syntax-generic' => ['dark' => '#0072B2', 'light' => '#005A8E'],
            'syntax-function' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            // Agent
            'agent-general' => ['dark' => '#E69F00', 'light' => '#C07800'],
            'agent-plan' => ['dark' => '#CC79A7', 'light' => '#A05A82'],
            'agent-explore' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            'agent-waiting' => ['dark' => '#0072B2', 'light' => '#005A8E'],
            // Code blocks
            'code-fg' => ['dark' => '#CC79A7', 'light' => '#A05A82'],
            'code-bg' => ['dark' => '#1e1e1e', 'light' => '#e8e8e8'],
            // Misc
            'link' => ['dark' => '#0072B2', 'light' => '#005A8E'],
            'separator' => ['dark' => '#444444', 'light' => '#c0c0c0'],
            'status-bar' => ['dark' => '#999999', 'light' => '#666666'],
            'thinking' => ['dark' => '#56B4E9', 'light' => '#3A8EC0'],
            'compacting' => ['dark' => '#D55E00', 'light' => '#AA4A00'],
        ];
    }
}
