<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Primitive\Display;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Visual separator — horizontal line or inline pipe.
 *
 * Non-reactive. Pure decorative widget for layout composition.
 */
final class Sep extends AbstractWidget
{
    private string $content;

    private function __construct(string $content)
    {
        $this->content = $content;
    }

    /**
     * Dim dot separator: ` · `
     */
    public static function pipe(): self
    {
        return new self(Theme::dim().'·'.Theme::reset());
    }

    /**
     * Full-width horizontal line with the given character.
     */
    public static function line(string $char = '─'): self
    {
        return new self($char);
    }

    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();

        if ($cols <= 0) {
            return [];
        }

        return [str_repeat($this->content, $cols)];
    }
}
