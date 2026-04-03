<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

/**
 * Contract for widgets that can be expanded or collapsed (e.g. collapsible output sections).
 */
interface ToggleableWidgetInterface
{
    /** Toggle between expanded and collapsed state. */
    public function toggle(): void;

    /** Explicitly set the expanded state. */
    public function setExpanded(bool $expanded): void;

    /** Check whether the widget is currently in expanded state. */
    public function isExpanded(): bool;
}
