<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Layout;

use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Vertical stack of children.
 *
 * Wraps ContainerWidget with Direction::Vertical. Provides a SwiftUI-style
 * factory method for declarative layout composition.
 */
final class VStack
{
    /**
     * Create a vertical container with the given children.
     *
     * @param  list<AbstractWidget>  $children
     * @param  list<string>  $classes  CSS-style class names for stylesheet rules
     */
    public static function make(
        int $gap = 0,
        array $children = [],
        array $classes = [],
        bool $expandVertically = false,
    ): ContainerWidget {
        $col = new ContainerWidget;
        $col->setStyle(new Style(direction: Direction::Vertical, gap: $gap));

        foreach ($classes as $class) {
            $col->addStyleClass($class);
        }

        if ($expandVertically) {
            $col->expandVertically(true);
        }

        foreach ($children as $child) {
            $col->add($child);
        }

        return $col;
    }
}
