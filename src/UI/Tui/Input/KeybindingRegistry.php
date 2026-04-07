<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Input;

use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Input\KeyParser;

/**
 * Centralized keybinding storage with context-scoped resolution.
 *
 * Owns all keybindings for every UI context, supports multi-key sequences,
 * YAML-driven user overrides, and conflict detection.
 */
final class KeybindingRegistry
{
    /**
     * Parsed bindings per context: contextName → action → keyIds[].
     *
     * Key IDs use Symfony TUI notation: "ctrl+a", "shift+enter", "page_up", etc.
     * Multi-key sequences use space-separated keys within a single string: "g g".
     *
     * @var array<string, array<string, string[]>>
     */
    private array $bindings = [];

    /**
     * Human-readable labels per context: contextName → action → label.
     *
     * @var array<string, array<string, string>>
     */
    private array $labels = [];

    /**
     * Groups for help display: contextName → action → group name.
     *
     * @var array<string, array<string, string>>
     */
    private array $groups = [];

    /**
     * Reverse lookup index: contextName → keyId → action.
     * Rebuilt on every registration for fast resolve().
     *
     * @var array<string, array<string, string>>
     */
    private array $keyToAction = [];

    /**
     * Sequence index: contextName → serialized sequence (e.g. "g g") → action.
     *
     * @var array<string, array<string, string>>
     */
    private array $sequenceIndex = [];

    /**
     * Prefix index for partial sequence matching: contextName → firstKey → true.
     *
     * @var array<string, array<string, bool>>
     */
    private array $prefixIndex = [];

    private ?KeyParser $parser = null;

    private bool $kittyProtocolActive = false;

    /**
     * Register a context with its bindings, labels, and groups.
     *
     * @param array<string, string[]> $bindings  action → keyIds
     * @param array<string, string>   $labels    action → human-readable label
     * @param array<string, string>   $groups    action → group name
     */
    public function registerContext(
        string $name,
        array $bindings,
        array $labels = [],
        array $groups = [],
        string $description = '',
    ): void {
        $this->bindings[$name] = $bindings;
        $this->labels[$name] = $labels;
        $this->groups[$name] = $groups;
        $this->rebuildIndex($name);
    }

    /**
     * Bulk-load configuration parsed from YAML.
     *
     * Expected structure:
     * ```php
     * [
     *     'contexts' => [
     *         'normal' => [
     *             'description' => '...',
     *             'bindings' => ['history_up' => ['page_up'], ...],
     *             'labels'   => ['history_up' => 'Scroll up', ...],
     *             'groups'   => ['history_up' => 'Navigation', ...],
     *         ],
     *         ...
     *     ],
     * ]
     * ```
     *
     * @param array<string, mixed> $config
     */
    public function loadFromConfig(array $config): void
    {
        $contexts = $config['contexts'] ?? [];
        if (!\is_array($contexts)) {
            return;
        }

        foreach ($contexts as $name => $ctxConfig) {
            if (!\is_array($ctxConfig)) {
                continue;
            }

            $bindings = $ctxConfig['bindings'] ?? [];
            $labels = $ctxConfig['labels'] ?? [];
            $groups = $ctxConfig['groups'] ?? [];
            $description = $ctxConfig['description'] ?? '';

            // Remove empty/null bindings (user unbinding an action)
            $bindings = array_filter(
                $bindings,
                static fn(mixed $v): bool => $v !== null && $v !== [],
            );

            $this->registerContext(
                $name,
                $bindings,
                \is_array($labels) ? $labels : [],
                \is_array($groups) ? $groups : [],
                \is_string($description) ? $description : '',
            );
        }
    }

    /**
     * Load user overrides from parsed YAML config.
     *
     * Merge semantics: array values (key lists) are **replaced**, not merged.
     * Setting `null` or `[]` unbinds an action entirely.
     *
     * @param array<string, mixed> $overrides
     */
    public function loadUserOverrides(array $overrides): void
    {
        $contexts = $overrides['contexts'] ?? [];
        if (!\is_array($contexts)) {
            return;
        }

        foreach ($contexts as $name => $ctxConfig) {
            if (!\is_array($ctxConfig)) {
                continue;
            }

            $overrideBindings = $ctxConfig['bindings'] ?? [];
            if (!\is_array($overrideBindings)) {
                continue;
            }

            // Merge into existing context or create new
            $existing = $this->bindings[$name] ?? [];
            foreach ($overrideBindings as $action => $keys) {
                if ($keys === null || $keys === []) {
                    // Unbind
                    unset($existing[$action]);
                } else {
                    $existing[$action] = $keys;
                }
            }
            $this->bindings[$name] = $existing;

            // Merge labels if provided
            $overrideLabels = $ctxConfig['labels'] ?? [];
            if (\is_array($overrideLabels)) {
                $existingLabels = $this->labels[$name] ?? [];
                foreach ($overrideLabels as $action => $label) {
                    $existingLabels[$action] = $label;
                }
                $this->labels[$name] = $existingLabels;
            }

            // Merge groups if provided
            $overrideGroups = $ctxConfig['groups'] ?? [];
            if (\is_array($overrideGroups)) {
                $existingGroups = $this->groups[$name] ?? [];
                foreach ($overrideGroups as $action => $group) {
                    $existingGroups[$action] = $group;
                }
                $this->groups[$name] = $existingGroups;
            }

            $this->rebuildIndex($name);
        }
    }

    /**
     * Resolve a single key ID to an action name in the given context.
     *
     * Returns null if no binding matches.
     */
    public function resolve(string $context, string $keyId): ?string
    {
        return $this->keyToAction[$context][$keyId] ?? null;
    }

    /**
     * Resolve a multi-key sequence to an action name.
     *
     * @param string[] $keyIds
     */
    public function resolveSequence(string $context, array $keyIds): ?string
    {
        $serialized = implode(' ', $keyIds);

        return $this->sequenceIndex[$context][$serialized] ?? null;
    }

    /**
     * Check if any action in a context starts with the given key prefix.
     *
     * Used by SequenceTracker to know if a partial sequence exists.
     *
     * @param string[] $prefixKeyIds
     */
    public function hasSequencePrefix(string $context, array $prefixKeyIds): bool
    {
        $serialized = implode(' ', $prefixKeyIds);

        // Check if any sequence starts with this prefix
        $sequences = $this->sequenceIndex[$context] ?? [];
        foreach (array_keys($sequences) as $seqKey) {
            if (str_starts_with($seqKey . ' ', $serialized . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a Symfony Keybindings object for a specific context.
     *
     * Used by widgets that consume Keybindings natively (e.g., EditorWidget).
     * Only single-key bindings are included (multi-key sequences are excluded).
     */
    public function getKeybindingsForContext(string $context): Keybindings
    {
        $bindings = $this->bindings[$context] ?? [];
        $singleKeyBindings = [];

        foreach ($bindings as $action => $keyIds) {
            $singleKeys = [];
            foreach ($keyIds as $keyId) {
                // Skip multi-key sequences (contain spaces)
                if (!str_contains($keyId, ' ')) {
                    $singleKeys[] = $keyId;
                }
            }
            if ($singleKeys !== []) {
                $singleKeyBindings[$action] = $singleKeys;
            }
        }

        $parser = $this->parser ?? new KeyParser();
        $parser->setKittyProtocolActive($this->kittyProtocolActive);

        return new Keybindings($singleKeyBindings, $parser);
    }

    /**
     * Get all bindings for a context (for help generation).
     *
     * @return array<string, string[]> action → key IDs
     */
    public function getBindingsForContext(string $context): array
    {
        return $this->bindings[$context] ?? [];
    }

    /**
     * Get human-readable label for an action.
     */
    public function getActionLabel(string $context, string $action): string
    {
        return $this->labels[$context][$action] ?? $this->humanizeAction($action);
    }

    /**
     * Get the group name for an action (for help display sorting).
     */
    public function getActionGroup(string $context, string $action): string
    {
        return $this->groups[$context][$action] ?? '';
    }

    /**
     * Get all registered context names.
     *
     * @return string[]
     */
    public function getContextNames(): array
    {
        return array_keys($this->bindings);
    }

    /**
     * Get all labels for a context.
     *
     * @return array<string, string>
     */
    public function getLabelsForContext(string $context): array
    {
        return $this->labels[$context] ?? [];
    }

    /**
     * Get all groups for a context.
     *
     * @return array<string, string>
     */
    public function getGroupsForContext(string $context): array
    {
        return $this->groups[$context] ?? [];
    }

    /**
     * Run conflict detection across all contexts.
     *
     * Detects:
     *  1. Single-key overlap: two actions in the same context share a key ID.
     *  2. Sequence prefix collision: a single-key binding is a prefix of a multi-key sequence.
     *
     * @return list<Conflict>
     */
    public function detectConflicts(): array
    {
        $conflicts = [];

        foreach ($this->bindings as $contextName => $bindings) {
            // 1. Single-key overlap detection
            $keyToAction = [];
            foreach ($bindings as $action => $keyIds) {
                foreach ($keyIds as $keyId) {
                    // Normalize: sequences use space separator
                    if (str_contains($keyId, ' ')) {
                        continue; // multi-key sequences checked separately
                    }
                    if (isset($keyToAction[$keyId])) {
                        $conflicts[] = new Conflict(
                            $contextName,
                            $keyToAction[$keyId],
                            $action,
                            $keyId,
                        );
                    } else {
                        $keyToAction[$keyId] = $action;
                    }
                }
            }

            // 2. Sequence prefix collision
            foreach ($bindings as $action => $keyIds) {
                foreach ($keyIds as $keyId) {
                    if (!str_contains($keyId, ' ')) {
                        continue;
                    }
                    $parts = explode(' ', $keyId);
                    $firstKey = $parts[0];

                    // If the first key of a sequence is also a single-key binding
                    if (isset($keyToAction[$firstKey])) {
                        $conflicts[] = new Conflict(
                            $contextName,
                            $keyToAction[$firstKey],
                            $action,
                            $firstKey . ' (sequence prefix)',
                        );
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * Set the Kitty keyboard protocol state.
     */
    public function setKittyProtocolActive(bool $active): void
    {
        $this->kittyProtocolActive = $active;
    }

    /**
     * Check whether a context is registered.
     */
    public function hasContext(string $context): bool
    {
        return isset($this->bindings[$context]);
    }

    /**
     * Rebuild internal lookup indices for a context.
     */
    private function rebuildIndex(string $context): void
    {
        $bindings = $this->bindings[$context] ?? [];
        $keyToAction = [];
        $sequenceIndex = [];
        $prefixIndex = [];

        foreach ($bindings as $action => $keyIds) {
            foreach ($keyIds as $keyId) {
                if (str_contains($keyId, ' ')) {
                    // Multi-key sequence
                    $sequenceIndex[$keyId] = $action;
                    $parts = explode(' ', $keyId);
                    $prefixIndex[$parts[0]] = true;
                } else {
                    // Single key — first-registered wins on conflict
                    $keyToAction[$keyId] ??= $action;
                }
            }
        }

        $this->keyToAction[$context] = $keyToAction;
        $this->sequenceIndex[$context] = $sequenceIndex;
        $this->prefixIndex[$context] = $prefixIndex;
    }

    /**
     * Convert an action name to a human-readable fallback label.
     *
     * "history_up" → "History up"
     */
    private function humanizeAction(string $action): string
    {
        return ucfirst(str_replace('_', ' ', $action));
    }
}
