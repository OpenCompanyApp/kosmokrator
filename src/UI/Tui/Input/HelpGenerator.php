<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Input;

/**
 * Auto-generates help text from the KeybindingRegistry.
 *
 * Produces both compact status-bar hints and full help overlay data,
 * formatted from the registry's binding/label/group information.
 */
final class HelpGenerator
{
    /**
     * Human-readable key display map.
     *
     * Maps Symfony TUI key identifiers to short display forms.
     */
    private const KEY_DISPLAY_MAP = [
        'escape' => 'Esc',
        'enter' => '↵',
        'tab' => 'Tab',
        'space' => 'Space',
        'backspace' => '⌫',
        'delete' => 'Del',
        'insert' => 'Ins',
        'home' => 'Home',
        'end' => 'End',
        'page_up' => 'PgUp',
        'page_down' => 'PgDn',
        'up' => '↑',
        'down' => '↓',
        'left' => '←',
        'right' => '→',
        'f1' => 'F1',
        'f2' => 'F2',
        'f3' => 'F3',
        'f4' => 'F4',
        'f5' => 'F5',
        'f6' => 'F6',
        'f7' => 'F7',
        'f8' => 'F8',
        'f9' => 'F9',
        'f10' => 'F10',
        'f11' => 'F11',
        'f12' => 'F12',
    ];

    /**
     * Separator used between items in the status bar hint.
     */
    private const HINT_SEPARATOR = ' · ';

    /**
     * Generate a compact status-bar hint string for a context.
     *
     * Example: "⇧Tab mode · PgUp↑/PgDn↓ scroll · Ctrl+O tools · F1 help"
     *
     * @param list<string> $includeActions  Only include these actions (whitelist, empty = all)
     * @param list<string> $excludeActions  Exclude these actions (blacklist)
     */
    public function statusBarHint(
        string $context,
        KeybindingRegistry $registry,
        array $includeActions = [],
        array $excludeActions = [],
    ): string {
        $bindings = $registry->getBindingsForContext($context);
        $hints = [];

        foreach ($bindings as $action => $keyIds) {
            // Skip excluded actions
            if (\in_array($action, $excludeActions, true)) {
                continue;
            }
            // Skip if whitelist is set and action is not in it
            if ($includeActions !== [] && !\in_array($action, $includeActions, true)) {
                continue;
            }
            // Skip empty key lists (unbound)
            if ($keyIds === []) {
                continue;
            }
            // Skip multi-key sequences for status bar (too verbose)
            $singleKeys = array_filter($keyIds, static fn(string $k): bool => !str_contains($k, ' '));
            if ($singleKeys === []) {
                continue;
            }

            $displayKey = $this->formatKey(reset($singleKeys));
            $label = $registry->getActionLabel($context, $action);
            $hints[] = $displayKey . ' ' . $label;
        }

        return implode(self::HINT_SEPARATOR, $hints);
    }

    /**
     * Generate full help overlay data for a context.
     *
     * Returns grouped rows sorted by group, suitable for rendering as a
     * table or panel. Each row has: formatted key(s), action, description, group.
     *
     * @return list<array{key: string, action: string, description: string, group: string}>
     */
    public function helpOverlay(string $context, KeybindingRegistry $registry): array
    {
        $bindings = $registry->getBindingsForContext($context);
        $rows = [];

        foreach ($bindings as $action => $keyIds) {
            if ($keyIds === []) {
                continue;
            }

            $formattedKeys = array_map($this->formatKey(...), $keyIds);
            $group = $registry->getActionGroup($context, $action);

            $rows[] = [
                'key' => implode(' / ', $formattedKeys),
                'action' => $action,
                'description' => $registry->getActionLabel($context, $action),
                'group' => $group,
            ];
        }

        // Sort: grouped items first (alphabetically by group), then ungrouped
        usort($rows, function (array $a, array $b): int {
            if ($a['group'] !== $b['group']) {
                // Ungrouped (empty string) goes last
                if ($a['group'] === '') {
                    return 1;
                }
                if ($b['group'] === '') {
                    return -1;
                }

                return strcmp($a['group'], $b['group']);
            }

            return strcmp($a['action'], $b['action']);
        });

        return $rows;
    }

    /**
     * Format a key ID for human-readable display.
     *
     * Examples:
     *   "ctrl+shift+enter" → "Ctrl+Shift+↵"
     *   "page_up"           → "PgUp"
     *   "shift+tab"         → "⇧Tab"
     *   "ctrl+a"            → "Ctrl+A"
     *   "g g"               → "g g"
     */
    public function formatKey(string $keyId): string
    {
        // Multi-key sequence: format each key and rejoin
        if (str_contains($keyId, ' ')) {
            $parts = explode(' ', $keyId);

            return implode(' ', array_map($this->formatKey(...), $parts));
        }

        // Parse modifiers + base key
        $parts = explode('+', $keyId);
        $baseKey = array_pop($parts);
        $modifiers = array_map('strtolower', $parts);

        // Format the base key
        $displayBase = self::KEY_DISPLAY_MAP[$baseKey] ?? $baseKey;

        // If the base is a single letter and there's no shift modifier, capitalize it
        if (\strlen($displayBase) === 1 && ctype_alpha($displayBase)) {
            $hasShift = \in_array('shift', $modifiers, true);
            if (!$hasShift) {
                $displayBase = strtoupper($displayBase);
            }
        }

        // Format modifier prefix
        // When shift is combined with other modifiers, use text form "Shift+"
        // When shift is the only modifier, use the compact ⇧ symbol
        $modifierDisplay = '';
        $hasOtherModifiers = $modifiers !== [] && count(array_filter($modifiers, static fn(string $m): bool => $m !== 'shift')) > 0;
        foreach ($modifiers as $mod) {
            $modifierDisplay .= match ($mod) {
                'ctrl' => 'Ctrl+',
                'shift' => $hasOtherModifiers ? 'Shift+' : '⇧',
                'alt' => 'Alt+',
                default => ucfirst($mod) . '+',
            };
        }

        return $modifierDisplay . $displayBase;
    }
}
