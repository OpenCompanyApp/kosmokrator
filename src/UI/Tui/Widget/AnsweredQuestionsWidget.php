<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

class AnsweredQuestionsWidget extends AbstractWidget
{
    /**
     * @param  array<array{question: string, answer: string, answered: bool, recommended: bool}>  $entries
     */
    public function __construct(
        private readonly array $entries,
    ) {}

    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $r = Theme::reset();
        $accent = Theme::accent();
        $white = Theme::white();
        $answerColor = Theme::info();
        $dim = Theme::dim();

        $answeredCount = count(array_filter($this->entries, static fn (array $entry): bool => $entry['answered']));

        $lines = [];
        $lines[] = AnsiUtils::truncateToWidth(
            "{$accent}› •{$r} {$dim}Questions {$answeredCount}/".count($this->entries)." answered{$r}",
            $columns
        );

        foreach ($this->entries as $index => $entry) {
            if ($index > 0) {
                $lines[] = '';
            }

            foreach ($this->wrapWithPrefix($entry['question'], '    • ', '      ', $columns) as $line) {
                $lines[] = AnsiUtils::truncateToWidth("{$white}{$line}{$r}", $columns);
            }

            $answer = $entry['answered']
                ? $entry['answer'].($entry['recommended'] ? ' (Recommended)' : '')
                : '(dismissed)';
            $color = $entry['answered'] ? $answerColor : $dim;

            foreach ($this->wrapWithPrefix($answer, '      ', '      ', $columns) as $line) {
                $lines[] = AnsiUtils::truncateToWidth("{$color}{$line}{$r}", $columns);
            }
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private function wrapWithPrefix(string $text, string $firstPrefix, string $restPrefix, int $columns): array
    {
        $wrapped = [];
        $current = '';
        $words = preg_split('/\s+/', trim($text)) ?: [];

        foreach ($words as $word) {
            $prefix = $current === '' && $wrapped === [] ? $firstPrefix : ($current === '' ? $restPrefix : '');
            $lineWidth = max(10, $columns - mb_strwidth($prefix));
            $candidate = $current === '' ? $word : "{$current} {$word}";

            if (mb_strwidth($candidate) > $lineWidth) {
                if ($current !== '') {
                    $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).$current;
                    $current = $word;

                    continue;
                }

                $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).mb_substr($word, 0, $lineWidth);
                $current = mb_substr($word, $lineWidth);

                continue;
            }

            $current = $candidate;
        }

        if ($current === '') {
            return [($wrapped === [] ? $firstPrefix : $restPrefix)];
        }

        $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).$current;

        return $wrapped;
    }
}
