<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Base class for signal-driven widgets.
 *
 * Symfony TUI calls {@see beforeRender()} on every widget every frame
 * (Renderer.php:124). This hook syncs signal state into widget state
 * and calls {@see invalidate()} when changed, busting the render cache
 * and triggering a re-render of just this widget.
 *
 * Subclasses implement {@see syncFromSignals()} to read bound signals
 * and return true if the widget needs re-rendering.
 */
abstract class ReactiveWidget extends AbstractWidget
{
    /**
     * Called by the Renderer before every frame.
     *
     * Reads signal state, compares to cached widget state, and
     * invalidates the render cache if the widget changed.
     */
    public function beforeRender(): void
    {
        if ($this->syncFromSignals()) {
            $this->invalidate();
        }
    }

    /**
     * Read signals and sync into widget state.
     *
     * @return bool True if the widget needs re-rendering (state changed)
     */
    abstract protected function syncFromSignals(): bool;
}
