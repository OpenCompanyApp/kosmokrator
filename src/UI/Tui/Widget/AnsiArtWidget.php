<?php

namespace Kosmokrator\UI\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Renders pre-formatted ANSI art text (e.g. ASCII banners) in the TUI. Used for splash screens and decorative headers.
 */
class AnsiArtWidget extends AbstractWidget
{
    public function __construct(
        /**
         * Raw ANSI text content to render (may contain escape sequences).
         */
        private string $text = '',
    ) {}

    /** Update the displayed ANSI art content and trigger a re-render. */
    public function setText(string $text): static
    {
        $this->text = $text;
        $this->invalidate();

        return $this;
    }

    /** Return the current raw ANSI art text. */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Render ANSI art lines, wrapping any line that exceeds the available columns.
     *
     * @return string[]
     */
    public function render(RenderContext $context): array
    {
        if ($this->text === '') {
            return [];
        }

        $lines = explode("\n", str_replace("\t", '   ', $this->text));
        $cols = $context->getColumns();
        $result = [];

        foreach ($lines as $line) {
            // Wrap long lines preserving ANSI escape sequences
            if (AnsiUtils::visibleWidth($line) > $cols) {
                foreach (TextWrapper::wrapTextWithAnsi($line, $cols) as $wrapped) {
                    $result[] = $wrapped;
                }
            } else {
                $result[] = $line;
            }
        }

        return $result ?: [''];
    }
}
