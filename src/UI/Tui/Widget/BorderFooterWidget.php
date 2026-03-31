<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

class BorderFooterWidget extends AbstractWidget
{
    public function __construct(
        private readonly string $borderColor = '',
    ) {}

    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $border = $this->borderColor ?: Theme::borderAccent();
        $inner = $context->getColumns() - 4;

        return ["{$border}└".str_repeat('─', $inner + 2)."┘{$r}"];
    }
}
