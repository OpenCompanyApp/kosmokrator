<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Collection;

use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\Display\Loader;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Conditional widget — shows/hides a child based on a Signal<bool>.
 *
 * SwiftUI's `if showDetail { DetailView() }` equivalent.
 * When the signal transitions true→false, the child widget is removed
 * from its parent container. When false→true, it's re-created and added.
 *
 * The factory callback is called lazily — only when the condition becomes true.
 * For Loader children, it calls mount()/unmount() on the Loader primitive
 * to manage the CancellableLoaderWidget lifecycle.
 *
 * Usage:
 *   When::show($state->hasThinkingLoaderSignal(),
 *       fn () => Loader::of($state->thinkingPhraseSignal(), $state->breathColorSignal())
 *   )
 */
final class When
{
    /**
     * Create a conditional widget binding.
     *
     * Wires an Effect that watches the condition signal and manages the child
     * lifecycle inside the given parent container.
     *
     * @param  Signal<bool>  $condition  Signal that controls visibility
     * @param  \Closure(): AbstractWidget  $factory  Creates the child widget when condition is true
     */
    public static function show(Signal $condition, \Closure $factory): WhenBinding
    {
        return new WhenBinding($condition, $factory);
    }
}
