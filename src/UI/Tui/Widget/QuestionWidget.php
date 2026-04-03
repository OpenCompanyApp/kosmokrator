<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Simple bordered box that displays a question/prompt string to the user.
 * Used inline during agent output to render a titled question with word-wrapped text.
 */
class QuestionWidget extends AbstractWidget
{
    /**
     * @param  string  $question     The question text to display inside the box
     * @param  string  $title        Box header title (default: 'Question')
     * @param  string  $borderColor  ANSI escape for border color (falls back to Theme::borderAccent)
     * @param  string  $titleColor   ANSI escape for title color (falls back to Theme::accent)
     * @param  bool    $showBottom   Whether to render the bottom border line
     */
    public function __construct(
        private readonly string $question,
        private readonly string $title = 'Question',
        private readonly string $borderColor = '',
        private readonly string $titleColor = '',
        private readonly bool $showBottom = true,
    ) {}

    /**
     * @param  RenderContext  $context  Terminal dimensions
     * @return list<string>  ANSI-formatted bordered lines containing the question
     */
    public function render(RenderContext $context): array
    {
        $r = Theme::reset();
        $white = Theme::white();
        $border = $this->borderColor ?: Theme::borderAccent();
        $accent = $this->titleColor ?: Theme::accent();
        $columns = $context->getColumns();
        $inner = $columns - 4;

        $titleLabel = " {$this->title} ";
        $titleLen = mb_strwidth($titleLabel);
        $topFill = max(0, $inner - $titleLen - 1);

        $lines = [];
        $lines[] = AnsiUtils::truncateToWidth(
            "{$border}┌─{$accent}{$titleLabel}{$border}".str_repeat('─', $topFill)."┐{$r}",
            $columns
        );

        // Wrap question text to fit inside the box
        $maxTextWidth = max(10, $inner - 1);
        foreach ($this->wrapText($this->question, $maxTextWidth) as $line) {
            $padded = $line.str_repeat(' ', max(0, $inner - mb_strwidth($line)));
            $lines[] = AnsiUtils::truncateToWidth(
                "{$border}│{$r} {$white}{$padded}{$r} {$border}│{$r}",
                $columns
            );
        }

        if ($this->showBottom) {
            $lines[] = AnsiUtils::truncateToWidth(
                "{$border}└".str_repeat('─', $inner + 2)."┘{$r}",
                $columns
            );
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    /** Word-wrap text to fit within the given visible width. */
    private function wrapText(string $text, int $width): array
    {
        if (mb_strwidth($text) <= $width) {
            return [$text];
        }

        $lines = [];
        $words = explode(' ', $text);
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";
            if (mb_strwidth($candidate) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }
}
