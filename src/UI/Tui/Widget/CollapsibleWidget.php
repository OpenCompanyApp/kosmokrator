<?php

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

class CollapsibleWidget extends AbstractWidget
{
    private const PREVIEW_LINES = 3;

    private bool $expanded = false;
    private string $content;

    /**
     * @param string $header     Status line (e.g. "✓")
     * @param string $content    Full content to show when expanded
     * @param int    $lineCount  Total line count for the badge
     */
    public function __construct(
        private string $header,
        string $content,
        private int $lineCount,
    ) {
        // Normalize tabs — TUI renderer expands them but visibleWidth may not
        $this->content = str_replace("\t", '   ', $content);
    }

    public function toggle(): void
    {
        $this->expanded = !$this->expanded;
        $this->invalidate();
    }

    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $cols = $context->getColumns();
        $contentLines = explode("\n", $this->content);
        $total = count($contentLines);
        $border = Theme::rgb(128, 100, 40);

        $result = [AnsiUtils::truncateToWidth($this->header, $cols)];

        $showLines = $this->expanded ? $contentLines : array_slice($contentLines, 0, self::PREVIEW_LINES);
        foreach ($showLines as $i => $line) {
            $prefix = $i === 0 ? "{$border}  ⏋{$r} " : '    ';
            $indented = $prefix . $line;
            $result[] = AnsiUtils::visibleWidth($indented) > $cols
                ? AnsiUtils::truncateToWidth($indented, $cols, '')
                : $indented;
        }

        if (!$this->expanded) {
            $remaining = $total - self::PREVIEW_LINES;
            if ($remaining > 0) {
                $result[] = "    {$dim}⊛ +{$remaining} lines (ctrl+o to reveal){$r}";
            }
        }

        return $result;
    }
}
