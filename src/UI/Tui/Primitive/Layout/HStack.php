<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Layout;

use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Horizontal stack of children.
 *
 * Wraps ContainerWidget with Direction::Horizontal. Provides a SwiftUI-style
 * factory method for declarative layout composition.
 */
final class HStack
{
    /**
     * Create a horizontal container with the given children.
     *
     * @param  list<AbstractWidget>  $children
     * @param  list<string>  $classes  CSS-style class names for stylesheet rules
     */
    public static function make(
        int $gap = 0,
        array $children = [],
        array $classes = [],
    ): ContainerWidget {
        $row = new ContainerWidget;
        $row->setStyle(new Style(direction: Direction::Horizontal, gap: $gap));

        foreach ($classes as $class) {
            $row->addStyleClass($class);
        }

        foreach ($children as $child) {
            $row->add($child);
        }

        return $row;
    }
}
