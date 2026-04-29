<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\FocusEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * Registers cross-cutting TUI event listeners.
 *
 * Widget-local callbacks still own widget-specific behavior. This bridge keeps
 * global input/render safeguards in Symfony's event pipeline so they also work
 * while focus is on overlays and modal widgets.
 */
final class TuiEventBridge
{
    public function __construct(
        private readonly Tui $tui,
        private readonly TuiStateStore $state,
        private readonly \Closure $forceRender,
    ) {}

    public function bind(): void
    {
        $this->tui->addListener($this->handleInput(...), priority: 100);
        $this->tui->addListener($this->handleCancel(...), priority: -100);
        $this->tui->addListener($this->handleFocus(...), priority: -100);
    }

    private function handleInput(InputEvent $event): void
    {
        if ($event->getData() !== "\x0C") {
            return;
        }

        ($this->forceRender)();
        $event->stopPropagation();
    }

    private function handleCancel(CancelEvent $event): void
    {
        $this->state->triggerRender();
    }

    private function handleFocus(FocusEvent $event): void
    {
        $target = $event->getTarget();
        $id = $target->getId();

        $this->state->setFocusedWidgetId($id !== null && $id !== '' ? $id : $target::class);
    }
}
