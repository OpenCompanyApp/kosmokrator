<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi\Handler;

use Kosmokrator\UI\Ansi\AnsiTableRenderer;
use Kosmokrator\UI\Theme;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;

/**
 * Tracks list nesting state (ordered/unordered, depth, counters)
 * during markdown AST traversal.
 */
final class ListTracker
{
    /** @var list<array{type: string, counter: int, start: int}> */
    private array $listStack = [];

    private bool $insideListItem = false;

    private bool $listItemNeedsBullet = false;

    /**
     * Current nesting depth (0 when not inside any list).
     */
    public function depth(): int
    {
        return count($this->listStack);
    }

    /**
     * Whether we are currently inside a list item.
     */
    public function isInsideItem(): bool
    {
        return $this->insideListItem;
    }

    /**
     * Whether the current list item still needs its bullet/number prefix.
     */
    public function needsBullet(): bool
    {
        return $this->listItemNeedsBullet;
    }

    /**
     * Reset all list tracking state.
     */
    public function reset(): void
    {
        $this->listStack = [];
        $this->insideListItem = false;
        $this->listItemNeedsBullet = false;
    }

    /**
     * Handle entering or leaving a list block.
     *
     * @param  ListBlock  $node  The list block node
     * @param  bool  $entering  Whether we are entering or leaving
     * @return string|null Trailing newline when the outermost list ends, null otherwise
     */
    public function handleListBlock(ListBlock $node, bool $entering): ?string
    {
        if ($entering) {
            $this->listStack[] = [
                'type' => $node->getListData()->type,
                'counter' => $node->getListData()->start ?? 1,
                'start' => $node->getListData()->start ?? 1,
            ];

            return null;
        }

        array_pop($this->listStack);

        if ($this->listStack === []) {
            return "\n";
        }

        return null;
    }

    /**
     * Handle entering or leaving a list item.
     *
     * @param  bool  $entering  Whether we are entering or leaving
     */
    public function handleListItem(bool $entering): void
    {
        if ($entering) {
            $this->insideListItem = true;
            $this->listItemNeedsBullet = true;
        } else {
            $this->insideListItem = false;
            $this->listItemNeedsBullet = false;
        }
    }

    /**
     * Render a paragraph within a list item, producing the bulleted/numbered output.
     *
     * @param  string  $inlineBuffer  The accumulated inline content for this paragraph
     * @param  string  $indent  Base indentation prefix
     * @param  int  $termWidth  Terminal width for line wrapping
     * @return string The rendered list item paragraph
     */
    public function flushListItemParagraph(string $inlineBuffer, string $indent, int $termWidth): string
    {
        if ($inlineBuffer === '') {
            return '';
        }

        $output = '';
        $listCtx = end($this->listStack);
        $depth = count($this->listStack);
        $bulletIndent = str_repeat('  ', $depth - 1);

        if ($this->listItemNeedsBullet) {
            if ($listCtx !== false) {
                if ($listCtx['type'] === ListBlock::TYPE_ORDERED) {
                    $bullet = $listCtx['counter'].'. ';
                    $this->listStack[array_key_last($this->listStack)]['counter']++;
                } else {
                    $bullet = $depth > 1 ? "\u{25E6} " : "\u{2022} ";
                }
            } else {
                $bullet = "\u{2022} ";
            }

            $continuationIndent = $indent.$bulletIndent.str_repeat(' ', mb_strlen($bullet));
            $contWidth = AnsiTableRenderer::visibleWidth($continuationIndent);
            $availableWidth = max(40, $termWidth - $contWidth - 2);
            $lines = self::wrapAnsiText($inlineBuffer, $availableWidth);

            $output .= $indent.$bulletIndent.Theme::dim().$bullet.Theme::reset().array_shift($lines).Theme::reset()."\n";
            foreach ($lines as $line) {
                $output .= $continuationIndent.$line.Theme::reset()."\n";
            }

            $this->listItemNeedsBullet = false;
        } else {
            // Continuation paragraph in same list item (loose list)
            $continuationIndent = $indent.$bulletIndent.'  ';
            $contWidth = AnsiTableRenderer::visibleWidth($continuationIndent);
            $availableWidth = max(40, $termWidth - $contWidth - 2);
            $lines = self::wrapAnsiText($inlineBuffer, $availableWidth);

            foreach ($lines as $line) {
                $output .= $continuationIndent.$line.Theme::reset()."\n";
            }
        }

        return $output;
    }

    /**
     * Word-wrap text that may contain ANSI escape codes.
     *
     * @param  string  $text  Text with possible ANSI sequences
     * @param  int  $width  Maximum visible width per line
     * @return list<string>
     */
    private static function wrapAnsiText(string $text, int $width): array
    {
        $words = preg_split('/(?<=\s)(?=\S)/', $text);
        $lines = [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = AnsiTableRenderer::visibleWidth($word);

            if ($currentWidth > 0 && $currentWidth + $wordWidth > $width) {
                $lines[] = rtrim($currentLine);
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                $currentLine .= $word;
                $currentWidth += $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $lines[] = rtrim($currentLine);
        }

        return $lines ?: [''];
    }
}
