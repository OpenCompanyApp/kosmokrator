<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Theme;

/**
 * Semantic token definitions for the theming system.
 *
 * Defines all 50+ color tokens organized into categories:
 *   - Core:     brand identity colors
 *   - Semantic: functional state colors (success, error, etc.)
 *   - Text:     foreground content colors
 *   - UI:       borders, backgrounds, surfaces
 *   - Diff:     diff rendering colors
 *   - Syntax:   syntax highlighting token colors
 *   - Agent:    agent type indicator colors
 *   - Misc:     links, separators, status indicators
 *
 * Each token has:
 *   - A unique string name (used as key in theme definitions)
 *   - A human-readable label
 *   - A category for grouping
 *   - A fallback chain for resolution when a token is missing
 *   - A dark and light default value (for the built-in Cosmic theme)
 *
 * @phpstan-type TokenDefinition array{label: string, category: string, fallback: list<string>, dark: string, light: string}
 */
final class ThemeTokens
{
    /**
     * Get all token definitions.
     *
     * @return array<string, TokenDefinition>
     */
    public static function all(): array
    {
        return [
            // ── Core Palette ──────────────────────────────────────────────
            'primary' => [
                'label' => 'Primary brand color',
                'category' => 'core',
                'fallback' => [],
                'dark' => '#ff3c28',
                'light' => '#cc2200',
            ],
            'primary-dim' => [
                'label' => 'Subdued primary',
                'category' => 'core',
                'fallback' => ['primary'],
                'dark' => '#a01e1e',
                'light' => '#cc6644',
            ],
            'accent' => [
                'label' => 'Highlight / accent color',
                'category' => 'core',
                'fallback' => [],
                'dark' => '#ffc850',
                'light' => '#9a7520',
            ],
            'accent-dim' => [
                'label' => 'Subdued accent',
                'category' => 'core',
                'fallback' => ['accent'],
                'dark' => '#b48c32',
                'light' => '#8a6a20',
            ],

            // ── Semantic ──────────────────────────────────────────────────
            'success' => [
                'label' => 'Positive / success state',
                'category' => 'semantic',
                'fallback' => [],
                'dark' => '#50dc64',
                'light' => '#1a7a28',
            ],
            'warning' => [
                'label' => 'Caution / warning state',
                'category' => 'semantic',
                'fallback' => [],
                'dark' => '#ffc850',
                'light' => '#9a7520',
            ],
            'error' => [
                'label' => 'Error / danger state',
                'category' => 'semantic',
                'fallback' => [],
                'dark' => '#ff5040',
                'light' => '#cc1100',
            ],
            'info' => [
                'label' => 'Informational state',
                'category' => 'semantic',
                'fallback' => [],
                'dark' => '#64c8ff',
                'light' => '#1a6ca0',
            ],

            // ── Text ─────────────────────────────────────────────────────
            'text' => [
                'label' => 'Default body text',
                'category' => 'text',
                'fallback' => [],
                'dark' => '#b4b4be',
                'light' => '#3a3a3a',
            ],
            'text-bright' => [
                'label' => 'Emphasized text',
                'category' => 'text',
                'fallback' => ['text'],
                'dark' => '#f0f0f5',
                'light' => '#1a1a1a',
            ],
            'text-dim' => [
                'label' => 'Secondary / muted text',
                'category' => 'text',
                'fallback' => ['text'],
                'dark' => '#909090',
                'light' => '#707070',
            ],
            'text-dimmer' => [
                'label' => 'Tertiary text (separators, hints)',
                'category' => 'text',
                'fallback' => ['text-dim', 'text'],
                'dark' => '#606060',
                'light' => '#a0a0a0',
            ],
            'text-heading' => [
                'label' => 'Markdown heading text',
                'category' => 'text',
                'fallback' => ['text-bright', 'text'],
                'dark' => '#ffffff',
                'light' => '#000000',
            ],

            // ── UI Elements ──────────────────────────────────────────────
            'border-active' => [
                'label' => 'Focused widget border',
                'category' => 'ui',
                'fallback' => ['primary'],
                'dark' => '#c85a42',
                'light' => '#b04530',
            ],
            'border-inactive' => [
                'label' => 'Unfocused widget border',
                'category' => 'ui',
                'fallback' => ['primary-dim', 'primary'],
                'dark' => '#6b3028',
                'light' => '#c09888',
            ],
            'border-task' => [
                'label' => 'Task / tool call border',
                'category' => 'ui',
                'fallback' => ['accent-dim', 'accent'],
                'dark' => '#806428',
                'light' => '#8a7040',
            ],
            'border-accent' => [
                'label' => 'Accent dialog border',
                'category' => 'ui',
                'fallback' => ['accent-dim', 'accent'],
                'dark' => '#b48c32',
                'light' => '#8a6a20',
            ],
            'border-plan' => [
                'label' => 'Plan mode border',
                'category' => 'ui',
                'fallback' => ['info'],
                'dark' => '#785ac8',
                'light' => '#6040a0',
            ],
            'background' => [
                'label' => 'Widget background',
                'category' => 'ui',
                'fallback' => [],
                'dark' => '#121212',
                'light' => '#f5f5f5',
            ],
            'surface' => [
                'label' => 'Elevated surface',
                'category' => 'ui',
                'fallback' => ['background'],
                'dark' => '#1a1a1a',
                'light' => '#e8e8e8',
            ],
            'surface-bright' => [
                'label' => 'Hovered / active surface',
                'category' => 'ui',
                'fallback' => ['surface', 'background'],
                'dark' => '#2a2a2a',
                'light' => '#d0d0d0',
            ],

            // ── Diff ─────────────────────────────────────────────────────
            'diff-add' => [
                'label' => 'Added line foreground',
                'category' => 'diff',
                'fallback' => ['success'],
                'dark' => '#3ca050',
                'light' => '#1a6a28',
            ],
            'diff-add-bg' => [
                'label' => 'Added line background',
                'category' => 'diff',
                'fallback' => [],
                'dark' => '#142d14',
                'light' => '#d0f0d0',
            ],
            'diff-add-bg-strong' => [
                'label' => 'Word-level add highlight',
                'category' => 'diff',
                'fallback' => ['diff-add-bg'],
                'dark' => '#1e461e',
                'light' => '#b0e0b0',
            ],
            'diff-remove' => [
                'label' => 'Removed line foreground',
                'category' => 'diff',
                'fallback' => ['error'],
                'dark' => '#b43c3c',
                'light' => '#a02020',
            ],
            'diff-remove-bg' => [
                'label' => 'Removed line background',
                'category' => 'diff',
                'fallback' => [],
                'dark' => '#370f0f',
                'light' => '#f0d0d0',
            ],
            'diff-remove-bg-strong' => [
                'label' => 'Word-level remove highlight',
                'category' => 'diff',
                'fallback' => ['diff-remove-bg'],
                'dark' => '#501414',
                'light' => '#e0b0b0',
            ],
            'diff-context' => [
                'label' => 'Unchanged context line',
                'category' => 'diff',
                'fallback' => ['text-dim', 'text'],
                'dark' => '#909090',
                'light' => '#707070',
            ],

            // ── Syntax Highlighting ──────────────────────────────────────
            'syntax-keyword' => [
                'label' => 'Language keywords',
                'category' => 'syntax',
                'fallback' => ['code-fg', 'accent'],
                'dark' => '#c878ff',
                'light' => '#7030b0',
            ],
            'syntax-type' => [
                'label' => 'Type names / classes',
                'category' => 'syntax',
                'fallback' => ['syntax-keyword', 'accent'],
                'dark' => '#ffc850',
                'light' => '#8a6a20',
            ],
            'syntax-value' => [
                'label' => 'String / boolean values',
                'category' => 'syntax',
                'fallback' => ['success'],
                'dark' => '#50dc64',
                'light' => '#1a6a28',
            ],
            'syntax-number' => [
                'label' => 'Numeric literals',
                'category' => 'syntax',
                'fallback' => ['syntax-type', 'accent'],
                'dark' => '#ffc850',
                'light' => '#8a6a20',
            ],
            'syntax-literal' => [
                'label' => 'True / false / null',
                'category' => 'syntax',
                'fallback' => ['info'],
                'dark' => '#64c8ff',
                'light' => '#1a6ca0',
            ],
            'syntax-variable' => [
                'label' => 'Variable names',
                'category' => 'syntax',
                'fallback' => ['text-bright', 'text'],
                'dark' => '#f0f0f5',
                'light' => '#1a1a1a',
            ],
            'syntax-property' => [
                'label' => 'Object properties',
                'category' => 'syntax',
                'fallback' => ['info'],
                'dark' => '#64c8ff',
                'light' => '#1a6ca0',
            ],
            'syntax-comment' => [
                'label' => 'Comments',
                'category' => 'syntax',
                'fallback' => ['text-dim', 'text'],
                'dark' => '#909090',
                'light' => '#707070',
            ],
            'syntax-operator' => [
                'label' => 'Operators',
                'category' => 'syntax',
                'fallback' => ['text-bright', 'text'],
                'dark' => '#f0f0f5',
                'light' => '#1a1a1a',
            ],
            'syntax-attribute' => [
                'label' => 'Attributes / decorators',
                'category' => 'syntax',
                'fallback' => ['syntax-keyword'],
                'dark' => '#c878ff',
                'light' => '#7030b0',
            ],
            'syntax-generic' => [
                'label' => 'Generic / misc tokens',
                'category' => 'syntax',
                'fallback' => ['info'],
                'dark' => '#508cff',
                'light' => '#1a5ca0',
            ],
            'syntax-function' => [
                'label' => 'Function names',
                'category' => 'syntax',
                'fallback' => ['info'],
                'dark' => '#64c8ff',
                'light' => '#1a6ca0',
            ],

            // ── Agent Types ──────────────────────────────────────────────
            'agent-general' => [
                'label' => 'General agent',
                'category' => 'agent',
                'fallback' => ['accent'],
                'dark' => '#daa520',
                'light' => '#8a6a14',
            ],
            'agent-plan' => [
                'label' => 'Plan agent',
                'category' => 'agent',
                'fallback' => ['info'],
                'dark' => '#a078ff',
                'light' => '#6040a0',
            ],
            'agent-explore' => [
                'label' => 'Explore agent',
                'category' => 'agent',
                'fallback' => ['info'],
                'dark' => '#64c8dc',
                'light' => '#1a6a7a',
            ],
            'agent-waiting' => [
                'label' => 'Waiting / queued',
                'category' => 'agent',
                'fallback' => ['info'],
                'dark' => '#6495ed',
                'light' => '#3060b0',
            ],

            // ── Code Blocks ─────────────────────────────────────────────
            'code-fg' => [
                'label' => 'Inline code foreground',
                'category' => 'misc',
                'fallback' => ['accent'],
                'dark' => '#c878ff',
                'light' => '#7030b0',
            ],
            'code-bg' => [
                'label' => 'Code block background',
                'category' => 'misc',
                'fallback' => ['surface'],
                'dark' => '#282828',
                'light' => '#e8e8e8',
            ],

            // ── Miscellaneous ───────────────────────────────────────────
            'link' => [
                'label' => 'URL / link color',
                'category' => 'misc',
                'fallback' => ['info'],
                'dark' => '#508cff',
                'light' => '#1a5ca0',
            ],
            'separator' => [
                'label' => 'Horizontal rule / separator',
                'category' => 'misc',
                'fallback' => ['text-dimmer', 'text-dim'],
                'dark' => '#404040',
                'light' => '#c0c0c0',
            ],
            'status-bar' => [
                'label' => 'Status bar text',
                'category' => 'misc',
                'fallback' => ['text-dim', 'text'],
                'dark' => '#909090',
                'light' => '#606060',
            ],
            'thinking' => [
                'label' => 'Thinking / processing indicator',
                'category' => 'misc',
                'fallback' => ['info'],
                'dark' => '#70a0d0',
                'light' => '#2a6090',
            ],
            'compacting' => [
                'label' => 'Compaction indicator',
                'category' => 'misc',
                'fallback' => ['error'],
                'dark' => '#d04040',
                'light' => '#b02020',
            ],
        ];
    }

    /**
     * Get all token names grouped by category.
     *
     * @return array<string, list<string>>
     */
    public static function byCategory(): array
    {
        $categories = [];
        foreach (self::all() as $name => $def) {
            $categories[$def['category']][] = $name;
        }

        return $categories;
    }

    /**
     * Get all token names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get the fallback chain for a token.
     *
     * @return list<string>
     */
    public static function fallbackChain(string $token): array
    {
        $all = self::all();

        return $all[$token]['fallback'] ?? [];
    }

    /**
     * Get the default dark-mode hex value for a token.
     */
    public static function defaultDark(string $token): ?string
    {
        $all = self::all();

        return $all[$token]['dark'] ?? null;
    }

    /**
     * Get the default light-mode hex value for a token.
     */
    public static function defaultLight(string $token): ?string
    {
        $all = self::all();

        return $all[$token]['light'] ?? null;
    }

    /**
     * Check if a token name is valid.
     */
    public static function isValid(string $token): bool
    {
        return isset(self::all()[$token]);
    }
}
