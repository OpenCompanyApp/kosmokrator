<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

/**
 * Represents a single tab in a TabsWidget.
 *
 * Immutable value object carrying a stable identifier, display label, and
 * optional keyboard shortcut digit (1–9).
 */
final class TabItem
{
    /**
     * @param string    $id       Stable identifier (does not change with reorder). Used in ChangeEvent.
     * @param string    $label    Display label shown in the tab bar.
     * @param int|null  $shortcut Optional keyboard shortcut digit (1–9). Null = no shortcut.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?int $shortcut = null,
    ) {}

    /**
     * Convenience factory for numbered tabs (shortcut auto-assigned from 1-based position).
     *
     * IDs are derived from labels by lowercasing and replacing non-alphanumeric characters with hyphens.
     * Shortcuts are assigned to the first 9 tabs only.
     *
     * @param list<string> $labels
     * @return list<self>
     */
    public static function fromLabels(array $labels): array
    {
        $tabs = [];
        foreach ($labels as $i => $label) {
            $shortcut = ($i < 9) ? $i + 1 : null;
            $tabs[] = new self(
                id: strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '-', $label)),
                label: $label,
                shortcut: $shortcut,
            );
        }

        return $tabs;
    }
}
