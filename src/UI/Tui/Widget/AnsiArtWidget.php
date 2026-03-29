<?php

namespace Kosmokrator\UI\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

class AnsiArtWidget extends AbstractWidget
{
    public function __construct(
        private string $text = '',
    ) {}

    public function setText(string $text): static
    {
        $this->text = $text;
        $this->invalidate();

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return string[]
     */
    public function render(RenderContext $context): array
    {
        if ('' === $this->text) {
            return [];
        }

        $lines = explode("\n", str_replace("\t", '   ', $this->text));
        $cols = $context->getColumns();
        $result = [];

        foreach ($lines as $line) {
            $result[] = AnsiUtils::visibleWidth($line) > $cols
                ? AnsiUtils::truncateToWidth($line, $cols, '')
                : $line;
        }

        return $result ?: [''];
    }
}
