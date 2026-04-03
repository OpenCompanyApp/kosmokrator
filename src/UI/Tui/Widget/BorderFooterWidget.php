<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Renders a bottom border line (└─…─┘) used to visually close a widget section.
 * Typically placed as the last child in a vertical layout.
 */
class BorderFooterWidget extends AbstractWidget
{
    public function __construct(
        /**
         * ANSI colour code for the border; falls back to the theme default when empty.
         */
        private readonly string $borderColor = '',
    ) {}

    /** Render a single bottom-border line spanning the full terminal width. */
    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $border = $this->borderColor ?: Theme::borderAccent();
        $inner = $context->getColumns() - 4;

        return ["{$border}└".str_repeat('─', $inner + 2)."┘{$r}"];
    }
}
