<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Layout;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Flexible spacer that eats remaining space in a vertical layout.
 *
 * Use in VStack to push subsequent children to the bottom. Like SwiftUI's
 * `Spacer()` — it expands to fill available vertical space.
 */
final class Spacer extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $vertical = false;

    /**
     * Create a spacer that fills remaining vertical space.
     */
    public static function flex(): self
    {
        $s = new self;
        $s->vertical = true;

        return $s;
    }

    public function expandVertically(bool $expand): static
    {
        $this->vertical = $expand;

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->vertical;
    }

    public function render(RenderContext $context): array
    {
        $rows = $context->getRows();

        if ($rows <= 0) {
            return [];
        }

        return array_fill(0, $rows, '');
    }
}
