<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

interface ToggleableWidgetInterface
{
    public function toggle(): void;

    public function setExpanded(bool $expanded): void;

    public function isExpanded(): bool;
}
