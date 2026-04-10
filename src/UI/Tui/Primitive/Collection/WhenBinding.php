<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Collection;

use Athanor\Effect;
use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\Display\Loader;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Binding object returned by When::show().
 *
 * Encapsulates the conditional show/hide lifecycle. The parent composition
 * calls {@see attach()} to bind this condition to a ContainerWidget.
 */
final class WhenBinding
{
    private ?AbstractWidget $child = null;

    private ?Effect $effect = null;

    private bool $lastValue = false;

    /**
     * @param  Signal<bool>  $condition
     * @param  \Closure(): AbstractWidget  $factory
     */
    public function __construct(
        private readonly Signal $condition,
        private readonly \Closure $factory,
    ) {}

    /**
     * Attach this conditional binding to a parent container.
     *
     * Creates an Effect that watches the condition signal. When it
     * transitions true→false, removes the child. When false→true,
     * creates the child via the factory and adds it.
     *
     * For Loader children, calls mount()/unmount() for proper lifecycle.
     */
    public function attach(ContainerWidget $parent): void
    {
        $this->effect = new Effect(function () use ($parent): void {
            $value = $this->condition->get();

            if ($value === $this->lastValue) {
                return;
            }

            if ($value && ! $this->lastValue) {
                // false → true: create and add child
                $this->child = ($this->factory)();

                if ($this->child instanceof Loader) {
                    $loader = $this->child->mount();
                    $parent->add($loader);
                } else {
                    $parent->add($this->child);
                }
            } elseif (! $value && $this->lastValue) {
                // true → false: remove and dispose child
                if ($this->child instanceof Loader) {
                    $loader = $this->child->getLoader();
                    if ($loader !== null) {
                        $parent->remove($loader);
                    }
                    $this->child->unmount();
                } elseif ($this->child !== null) {
                    $parent->remove($this->child);
                }
                $this->child = null;
            }

            $this->lastValue = $value;
        });
    }

    /**
     * Dispose the Effect and remove the child if present.
     */
    public function detach(ContainerWidget $parent): void
    {
        if ($this->effect !== null) {
            $this->effect->dispose();
            $this->effect = null;
        }

        if ($this->child instanceof Loader) {
            $loader = $this->child->getLoader();
            if ($loader !== null) {
                $parent->remove($loader);
            }
            $this->child->unmount();
        } elseif ($this->child !== null) {
            $parent->remove($this->child);
        }

        $this->child = null;
        $this->lastValue = false;
    }

    /**
     * Get the current child widget, if mounted.
     */
    public function getChild(): ?AbstractWidget
    {
        return $this->child;
    }
}
