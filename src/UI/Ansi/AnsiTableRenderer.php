<?php

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Renders markdown tables as box-drawing ANSI art for the ANSI rendering path.
 *
 * Part of the dual TUI/ANSI rendering layer. Also provides shared utilities
 * (visibleWidth) used by MarkdownToAnsi for column measurement.
 */
class AnsiTableRenderer
{
    /**
     * Renders a parsed markdown table as box-drawing ANSI art.
     *
     * @param  array{alignments: list<string|null>, head: list<list<string>>, body: list<list<string>>}  $table  Table structure with column alignments, header rows, and body rows
     * @param  string  $prefix  Left-margin prefix for each output line (default: 2 spaces)
     * @return string ANSI-escaped table string with trailing newline, or empty string if no rows
     */
    public function render(array $table, string $prefix = '  '): string
    {
        $alignments = $table['alignments'];
        $headRows = $table['head'];
        $bodyRows = $table['body'];
        $allRows = array_merge($headRows, $bodyRows);

        if ($allRows === []) {
            return '';
        }

        $colCount = count($alignments);
        $colWidths = array_fill(0, $colCount, 0);

        foreach ($allRows as $row) {
            foreach ($row as $i => $cell) {
                if ($i < $colCount) {
                    $colWidths[$i] = max($colWidths[$i], self::visibleWidth($cell));
                }
            }
        }

        // Minimum 3 chars per column, plus 2 for padding
        $colWidths = array_map(fn (int $w) => max($w, 3) + 2, $colWidths);

        $dim = Theme::dim();
        $r = Theme::reset();
        $bold = Theme::bold();
        $white = Theme::white();
        $text = Theme::text();
        $output = '';

        // Top border
        $output .= $prefix.$dim.'┌'.implode('┬', array_map(fn (int $w) => str_repeat('─', $w), $colWidths)).'┐'.$r."\n";

        // Header rows
        foreach ($headRows as $row) {
            $output .= $prefix.$dim.'│'.$r;
            foreach ($row as $i => $cell) {
                if ($i < $colCount) {
                    $padded = self::alignCell($cell, $colWidths[$i] - 2, $alignments[$i]);
                    $output .= ' '.$bold.$white.$padded.$r.' '.$dim.'│'.$r;
                }
            }
            $output .= "\n";
        }

        // Header separator
        if ($headRows !== []) {
            $output .= $prefix.$dim.'├'.implode('┼', array_map(fn (int $w) => str_repeat('─', $w), $colWidths)).'┤'.$r."\n";
        }

        // Body rows
        foreach ($bodyRows as $row) {
            $output .= $prefix.$dim.'│'.$r;
            foreach ($row as $i => $cell) {
                if ($i < $colCount) {
                    $padded = self::alignCell($cell, $colWidths[$i] - 2, $alignments[$i]);
                    $output .= ' '.$text.$padded.$r.' '.$dim.'│'.$r;
                }
            }
            $output .= "\n";
        }

        // Bottom border
        $output .= $prefix.$dim.'└'.implode('┴', array_map(fn (int $w) => str_repeat('─', $w), $colWidths)).'┘'.$r."\n";

        return $output;
    }

    /** Pads a cell string to the target width with ANSI-aware alignment (left, right, center). */
    private static function alignCell(string $text, int $width, ?string $align): string
    {
        $visible = self::visibleWidth($text);
        $pad = max(0, $width - $visible);

        return match ($align) {
            'right' => str_repeat(' ', $pad).$text,
            'center' => str_repeat(' ', (int) floor($pad / 2)).$text.str_repeat(' ', (int) ceil($pad / 2)),
            default => $text.str_repeat(' ', $pad),
        };
    }

    /**
     * Calculates the visible (non-ANSI) width of a string by stripping escape sequences.
     * Shared utility used across the ANSI rendering layer for column alignment and wrapping.
     *
     * @param  string  $text  String that may contain ANSI escape sequences (\033[...m)
     * @return int Visible character width using mb_strwidth
     */
    public static function visibleWidth(string $text): int
    {
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $text);

        return mb_strwidth($stripped);
    }
}
