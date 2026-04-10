<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Collection;

use Athanor\Signal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Keyed list widget derived from a Signal<list<T>>.
 *
 * SwiftUI's `ForEach(items) { item in Row(item) }` equivalent.
 * Reads a signal of items and maintains a keyed child widget list.
 * When items are added/removed/reordered, children are created/removed/moved.
 *
 * Usage:
 *   ReactiveList::of(
 *       $state->activeDiscoveryItemsSignal(),
 *       keyFn: fn(array $item) => $item['id'],
 *       builderFn: fn(array $item) => new DiscoveryRowWidget($item),
 *   )
 */
final class ReactiveList
{
    /**
     * Create a keyed list binding.
     *
     * @param  Signal<list<mixed>>  $itemsSignal  Signal holding the current items
     * @param  callable(mixed): string  $keyFn  Extracts a stable key from each item
     * @param  callable(mixed): AbstractWidget  $builderFn  Creates a widget for each item
     */
    public static function of(Signal $itemsSignal, callable $keyFn, callable $builderFn): ReactiveListBinding
    {
        return new ReactiveListBinding($itemsSignal, $keyFn, $builderFn);
    }
}
