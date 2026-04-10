<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Collection;

use Athanor\Effect;
use Athanor\Signal;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Binding object returned by ReactiveList::of().
 *
 * Maintains a keyed map of child widgets. On each signal change, diffs
 * the new list against the previous state and adds/removes children
 * from the parent container as needed.
 */
final class ReactiveListBinding
{
    /** @var array<string, AbstractWidget> Keyed child widgets currently mounted */
    private array $children = [];

    /** @var list<string> Keys in current order */
    private array $keyOrder = [];

    private ?Effect $effect = null;

    /**
     * @param  Signal<list<mixed>>  $itemsSignal
     * @param  \Closure(mixed): string  $keyFn
     * @param  \Closure(mixed): AbstractWidget  $builderFn
     */
    public function __construct(
        private readonly Signal $itemsSignal,
        private readonly \Closure $keyFn,
        private readonly \Closure $builderFn,
    ) {}

    /**
     * Attach this list binding to a parent container.
     *
     * Creates an Effect that watches the items signal. On each change,
     * reconciles the child widget list against the new items.
     */
    public function attach(ContainerWidget $parent): void
    {
        $this->effect = new Effect(function () use ($parent): void {
            $items = $this->itemsSignal->get();

            /** @var list<string> $newKeys */
            $newKeys = [];
            /** @var array<string, mixed> $newItemsByKey */
            $newItemsByKey = [];

            foreach ($items as $item) {
                $key = ($this->keyFn)($item);
                $newKeys[] = $key;
                $newItemsByKey[$key] = $item;
            }

            // Remove children whose keys are gone
            $removedKeys = array_diff($this->keyOrder, $newKeys);
            foreach ($removedKeys as $key) {
                if (isset($this->children[$key])) {
                    $parent->remove($this->children[$key]);
                    unset($this->children[$key]);
                }
            }

            // Add new children
            $addedKeys = array_diff($newKeys, $this->keyOrder);
            foreach ($addedKeys as $key) {
                $widget = ($this->builderFn)($newItemsByKey[$key]);
                $this->children[$key] = $widget;
                $parent->add($widget);
            }

            $this->keyOrder = $newKeys;
        });
    }

    /**
     * Dispose the Effect and remove all children.
     */
    public function detach(ContainerWidget $parent): void
    {
        if ($this->effect !== null) {
            $this->effect->dispose();
            $this->effect = null;
        }

        foreach ($this->children as $widget) {
            $parent->remove($widget);
        }
        $this->children = [];
        $this->keyOrder = [];
    }

    /**
     * Get the current keyed children map.
     *
     * @return array<string, AbstractWidget>
     */
    public function getChildren(): array
    {
        return $this->children;
    }
}
