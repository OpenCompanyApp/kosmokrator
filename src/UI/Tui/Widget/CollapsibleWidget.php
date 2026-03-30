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
        private ?int $previewWidth = null,
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
        $border = Theme::borderTask();

        $showLines = $this->expanded ? $contentLines : array_slice($contentLines, 0, self::PREVIEW_LINES);
        $charTruncated = false;

        // Character-level truncation for single-line content when collapsed
        if (! $this->expanded && $this->previewWidth !== null && count($contentLines) <= self::PREVIEW_LINES) {
            if (isset($showLines[0]) && mb_strlen($showLines[0]) > $this->previewWidth) {
                $showLines[0] = mb_substr($showLines[0], 0, $this->previewWidth) . '…';
                $charTruncated = true;
            }
        }

        $result = [];
        foreach ($showLines as $i => $line) {
            if ($i === 0) {
                $indented = "{$this->header} {$border}⏋{$r} {$line}";
            } else {
                $indented = '    ' . $line;
            }
            $result[] = AnsiUtils::visibleWidth($indented) > $cols
                ? AnsiUtils::truncateToWidth($indented, $cols, '')
                : $indented;
        }

        if (! $this->expanded) {
            $remaining = $total - self::PREVIEW_LINES;
            if ($remaining > 0) {
                $result[] = "    {$dim}⊛ +{$remaining} lines (ctrl+o to reveal){$r}";
            } elseif ($charTruncated) {
                $result[] = "    {$dim}⊛ (ctrl+o to reveal full command){$r}";
            }
        }

        // Safety clamp: ensure no line exceeds available width
        // (ANSI codes in content can cause visibleWidth edge cases)
        foreach ($result as $i => $line) {
            if (AnsiUtils::visibleWidth($line) > $cols) {
                $result[$i] = AnsiUtils::truncateToWidth($line, $cols, '');
            }
        }

        return $result;
    }
}
