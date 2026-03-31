<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Diff;

use Kosmokrator\UI\Ansi\KosmokratorTerminalTheme;
use Kosmokrator\UI\Theme;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;
use Tempest\Highlight\Highlighter;

/**
 * Renders unified diffs with hunks, line numbers, syntax highlighting,
 * and word-level change highlighting.
 *
 * Shared by TuiRenderer and AnsiRenderer — returns pre-formatted ANSI strings.
 */
final class DiffRenderer
{
    private const int CONTEXT_LINES = 3;

    private const string HUNK_SEPARATOR = '· · ✧ · ·';

    /** Skip word-level diff when >40% of tokens changed. */
    private const float WORD_DIFF_THRESHOLD = 0.4;

    private ?Highlighter $highlighter = null;

    /**
     * Render a unified diff as a single ANSI string (for TUI CollapsibleWidget).
     */
    public function render(string $old, string $new, string $path): string
    {
        $lines = $this->renderLines($old, $new, $path);

        return $lines !== [] ? implode("\n", $lines) : '';
    }

    /**
     * Render a unified diff as an array of ANSI-formatted lines (for ANSI renderer).
     *
     * @return string[]
     */
    public function renderLines(string $old, string $new, string $path): array
    {
        // Normalize line endings
        $old = str_replace("\r\n", "\n", $old);
        $new = str_replace("\r\n", "\n", $new);

        if ($old === $new) {
            return [];
        }

        // Pad old/new with file context so the diff naturally includes surrounding lines
        [$paddedOld, $paddedNew, $baseOffset] = $this->padWithFileContext($old, $new, $path);

        $differ = new Differ(new DiffOnlyOutputBuilder(''));
        $diffArray = $differ->diffToArray($paddedOld, $paddedNew);

        // Strip trailing newlines from each entry
        foreach ($diffArray as &$entry) {
            $entry[0] = rtrim($entry[0], "\r\n");
        }
        unset($entry);

        // Remove empty trailing entry caused by trailing newline in input
        while ($diffArray !== [] && end($diffArray)[0] === '' && end($diffArray)[1] === Differ::OLD) {
            array_pop($diffArray);
        }

        // Syntax highlight padded old and new as full blocks
        $language = KosmokratorTerminalTheme::detectLanguage($path);
        $oldHighlighted = explode("\n", $this->highlight($paddedOld, $language));
        $newHighlighted = explode("\n", $this->highlight($paddedNew, $language));

        // Map highlighted lines onto diff entries BEFORE building hunks
        // (buildHunks copies entries, so [2] must be set first)
        $oldIdx = 0;
        $newIdx = 0;
        foreach ($diffArray as &$entry) {
            if ($entry[1] === Differ::OLD) {
                $entry[2] = $oldHighlighted[$oldIdx] ?? $entry[0];
                $oldIdx++;
                $newIdx++;
            } elseif ($entry[1] === Differ::REMOVED) {
                $entry[2] = $oldHighlighted[$oldIdx] ?? $entry[0];
                $oldIdx++;
            } elseif ($entry[1] === Differ::ADDED) {
                $entry[2] = $newHighlighted[$newIdx] ?? $entry[0];
                $newIdx++;
            }
        }
        unset($entry);

        $hunks = $this->buildHunks($diffArray);
        if ($hunks === []) {
            return [];
        }

        // Compute gutter width from max line number
        $maxLineNum = $this->computeMaxLineNumber($hunks, $diffArray, $baseOffset);
        $gw = max(3, strlen((string) $maxLineNum));

        // Render hunks
        $r = Theme::reset();
        $dim = Theme::diffContext();
        $output = [];
        $totalAdded = 0;
        $totalRemoved = 0;

        foreach ($hunks as $hunkIdx => $hunk) {
            if ($hunkIdx > 0) {
                $pad = str_repeat(' ', $gw + 4);
                $sep = self::HUNK_SEPARATOR;
                $output[] = "{$dim}{$pad}{$sep}{$r}";
            }

            $oldLine = $hunk['oldStart'] + $baseOffset;
            $newLine = $hunk['newStart'] + $baseOffset;

            // Apply word-level diffs to paired lines within this hunk
            $hunkEntries = $this->applyWordDiffs($hunk['entries'], $diffArray);

            foreach ($hunkEntries as $entry) {
                $type = $entry[1];
                $highlighted = $entry[2] ?? $entry[0];

                if ($type === Differ::OLD) {
                    // Context line
                    $num = str_pad((string) $newLine, $gw, ' ', STR_PAD_LEFT);
                    $output[] = "{$dim}{$num}    {$highlighted}{$r}";
                    $oldLine++;
                    $newLine++;
                } elseif ($type === Differ::REMOVED) {
                    $num = str_pad((string) $oldLine, $gw, ' ', STR_PAD_LEFT);
                    $removeFg = Theme::diffRemove();
                    $removeBg = Theme::diffRemoveBg();
                    $output[] = "{$removeBg}{$removeFg}{$num} - {$r}{$removeBg} {$highlighted}{$r}";
                    $oldLine++;
                    $totalRemoved++;
                } elseif ($type === Differ::ADDED) {
                    $num = str_pad((string) $newLine, $gw, ' ', STR_PAD_LEFT);
                    $addFg = Theme::diffAdd();
                    $addBg = Theme::diffAddBg();
                    $output[] = "{$addBg}{$addFg}{$num} + {$r}{$addBg} {$highlighted}{$r}";
                    $newLine++;
                    $totalAdded++;
                }
            }
        }

        // Change summary
        $parts = [];
        if ($totalAdded > 0) {
            $parts[] = "{$totalAdded} ".($totalAdded === 1 ? 'addition' : 'additions');
        }
        if ($totalRemoved > 0) {
            $parts[] = "{$totalRemoved} ".($totalRemoved === 1 ? 'removal' : 'removals');
        }
        if ($parts !== []) {
            $pad = str_repeat(' ', $gw + 4);
            $output[] = "{$dim}{$pad}✧ ".implode(', ', $parts)."{$r}";
        }

        return $output;
    }

    /**
     * Build hunks from a flat diff array. Groups changes with context,
     * merging hunks when gaps are < 2 * CONTEXT_LINES.
     *
     * @return array<int, array{entries: list<array>, oldStart: int, newStart: int}>
     */
    private function buildHunks(array $diff): array
    {
        // Phase 1: find change regions (contiguous ADDED/REMOVED)
        $changeRanges = [];
        $inChange = false;
        $changeStart = 0;

        foreach ($diff as $i => $entry) {
            $isChange = $entry[1] === Differ::ADDED || $entry[1] === Differ::REMOVED;
            if ($isChange && ! $inChange) {
                $inChange = true;
                $changeStart = $i;
            } elseif (! $isChange && $inChange) {
                $inChange = false;
                $changeRanges[] = [$changeStart, $i - 1];
            }
        }
        if ($inChange) {
            $changeRanges[] = [$changeStart, count($diff) - 1];
        }

        if ($changeRanges === []) {
            return [];
        }

        // Phase 2: expand by context and merge overlapping
        $merged = [];
        $lastEnd = count($diff) - 1;

        foreach ($changeRanges as [$start, $end]) {
            $ctxStart = max(0, $start - self::CONTEXT_LINES);
            $ctxEnd = min($lastEnd, $end + self::CONTEXT_LINES);

            if ($merged !== [] && $ctxStart <= $merged[count($merged) - 1][1] + 1) {
                $merged[count($merged) - 1][1] = $ctxEnd;
            } else {
                $merged[] = [$ctxStart, $ctxEnd];
            }
        }

        // Phase 3: build hunk objects
        $hunks = [];
        foreach ($merged as [$start, $end]) {
            $oldLine = 1;
            $newLine = 1;
            for ($i = 0; $i < $start; $i++) {
                if ($diff[$i][1] === Differ::OLD) {
                    $oldLine++;
                    $newLine++;
                } elseif ($diff[$i][1] === Differ::REMOVED) {
                    $oldLine++;
                } elseif ($diff[$i][1] === Differ::ADDED) {
                    $newLine++;
                }
            }

            $hunks[] = [
                'entries' => array_values(array_slice($diff, $start, $end - $start + 1)),
                'oldStart' => $oldLine,
                'newStart' => $newLine,
            ];
        }

        return $hunks;
    }

    /**
     * Apply word-level diffs to paired removed/added lines within a hunk.
     * Returns the entries with enhanced highlighted text for paired lines.
     *
     * @return list<array>
     */
    private function applyWordDiffs(array $entries, array $fullDiff): array
    {
        $result = [];
        $i = 0;
        $count = count($entries);

        while ($i < $count) {
            // Collect consecutive REMOVED entries
            $removedStart = $i;
            while ($i < $count && $entries[$i][1] === Differ::REMOVED) {
                $i++;
            }
            $removedCount = $i - $removedStart;

            // Collect consecutive ADDED entries
            $addedStart = $i;
            while ($i < $count && $entries[$i][1] === Differ::ADDED) {
                $i++;
            }
            $addedCount = $i - $addedStart;

            if ($removedCount > 0 && $addedCount > 0) {
                // Pair them up for word-level diff
                $pairCount = min($removedCount, $addedCount);
                for ($p = 0; $p < $pairCount; $p++) {
                    $rEntry = $entries[$removedStart + $p];
                    $aEntry = $entries[$addedStart + $p];

                    [$enhancedOld, $enhancedNew] = $this->wordDiffPair(
                        $rEntry[0], $aEntry[0],
                        $rEntry[2] ?? $rEntry[0], $aEntry[2] ?? $aEntry[0],
                    );

                    $rEntry[2] = $enhancedOld;
                    $aEntry[2] = $enhancedNew;
                    $result[] = $rEntry;
                    $result[] = $aEntry;
                }

                // Unpaired remainder
                for ($p = $pairCount; $p < $removedCount; $p++) {
                    $result[] = $entries[$removedStart + $p];
                }
                for ($p = $pairCount; $p < $addedCount; $p++) {
                    $result[] = $entries[$addedStart + $p];
                }
            } else {
                // No pairing — emit as-is
                for ($p = $removedStart; $p < $removedStart + $removedCount; $p++) {
                    $result[] = $entries[$p];
                }
                for ($p = $addedStart; $p < $addedStart + $addedCount; $p++) {
                    $result[] = $entries[$p];
                }
            }

            // Emit any non-change entries (context)
            while ($i < $count && $entries[$i][1] === Differ::OLD) {
                $result[] = $entries[$i];
                $i++;
            }
        }

        return $result;
    }

    /**
     * Compute word-level diff for a paired removed/added line.
     * Returns [enhancedOldHighlighted, enhancedNewHighlighted].
     *
     * @return array{string, string}
     */
    private function wordDiffPair(string $oldPlain, string $newPlain, string $oldHighlighted, string $newHighlighted): array
    {
        $oldTokens = $this->tokenize($oldPlain);
        $newTokens = $this->tokenize($newPlain);

        if ($oldTokens === [] && $newTokens === []) {
            return [$oldHighlighted, $newHighlighted];
        }

        $differ = new Differ(new DiffOnlyOutputBuilder(''));
        $tokenDiff = $differ->diffToArray(
            implode("\n", $oldTokens)."\n",
            implode("\n", $newTokens)."\n",
        );

        // Count changed tokens to check threshold
        $totalTokens = max(count($oldTokens), count($newTokens), 1);
        $changedTokens = 0;
        foreach ($tokenDiff as $td) {
            if ($td[1] !== Differ::OLD) {
                $changedTokens++;
            }
        }
        if ($changedTokens / $totalTokens > self::WORD_DIFF_THRESHOLD) {
            return [$oldHighlighted, $newHighlighted];
        }

        // Build character ranges of changed tokens
        $oldRanges = [];
        $newRanges = [];
        $oldPos = 0;
        $newPos = 0;

        foreach ($tokenDiff as $td) {
            $token = rtrim($td[0], "\r\n");
            $len = mb_strlen($token);

            if ($td[1] === Differ::OLD) {
                $oldPos += $len;
                $newPos += $len;
            } elseif ($td[1] === Differ::REMOVED) {
                if ($len > 0) {
                    $oldRanges[] = [$oldPos, $oldPos + $len];
                }
                $oldPos += $len;
            } elseif ($td[1] === Differ::ADDED) {
                if ($len > 0) {
                    $newRanges[] = [$newPos, $newPos + $len];
                }
                $newPos += $len;
            }
        }

        $enhancedOld = $this->injectStrongBg(
            $oldHighlighted, $oldRanges,
            Theme::diffRemoveBgStrong(), Theme::diffRemoveBg(),
        );
        $enhancedNew = $this->injectStrongBg(
            $newHighlighted, $newRanges,
            Theme::diffAddBgStrong(), Theme::diffAddBg(),
        );

        return [$enhancedOld, $enhancedNew];
    }

    /**
     * Tokenize a line into words, preserving whitespace as tokens.
     *
     * @return string[]
     */
    private function tokenize(string $line): array
    {
        $tokens = preg_split('/(\s+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $tokens !== false ? $tokens : [$line];
    }

    /**
     * Inject strong background ANSI codes into a highlighted string
     * at specific visible character positions.
     *
     * @param  array<array{int, int}>  $ranges  Character ranges [start, end) in visible positions
     */
    private function injectStrongBg(string $highlighted, array $ranges, string $strongBg, string $normalBg): string
    {
        if ($ranges === []) {
            return $highlighted;
        }

        $result = '';
        $visiblePos = 0;
        $rangeIdx = 0;
        $inStrong = false;
        $len = strlen($highlighted);
        $i = 0;

        while ($i < $len) {
            // Skip ANSI escape sequences
            if ($highlighted[$i] === "\033") {
                $escEnd = strpos($highlighted, 'm', $i);
                if ($escEnd !== false) {
                    $result .= substr($highlighted, $i, $escEnd - $i + 1);
                    $i = $escEnd + 1;

                    continue;
                }
            }

            // Check if entering a strong range
            if (! $inStrong && $rangeIdx < count($ranges) && $visiblePos === $ranges[$rangeIdx][0]) {
                $result .= $strongBg;
                $inStrong = true;
            }

            // Check if leaving a strong range
            if ($inStrong && $visiblePos === $ranges[$rangeIdx][1]) {
                $result .= $normalBg;
                $inStrong = false;
                $rangeIdx++;
            }

            // Handle multi-byte UTF-8 characters
            $byte = ord($highlighted[$i]);
            if ($byte < 0x80) {
                $charLen = 1;
            } elseif ($byte < 0xE0) {
                $charLen = 2;
            } elseif ($byte < 0xF0) {
                $charLen = 3;
            } else {
                $charLen = 4;
            }

            $result .= substr($highlighted, $i, $charLen);
            $visiblePos++;
            $i += $charLen;
        }

        return $result;
    }

    /**
     * Pad old/new with surrounding file context so the diff naturally includes
     * context lines. Returns [paddedOld, paddedNew, baseLineOffset].
     *
     * @return array{string, string, int}
     */
    private function padWithFileContext(string $old, string $new, string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [$old, $new, 0];
        }

        $fileContent = @file_get_contents($path);
        if ($fileContent === false) {
            return [$old, $new, 0];
        }

        $pos = strpos($fileContent, $new);
        if ($pos === false) {
            return [$old, $new, 0];
        }

        $fileLines = explode("\n", $fileContent);
        $startLine = substr_count($fileContent, "\n", 0, $pos);
        $newLineCount = count(explode("\n", $new));
        $endLine = $startLine + $newLineCount - 1;

        // Extract margin lines
        $marginBefore = [];
        for ($i = max(0, $startLine - self::CONTEXT_LINES); $i < $startLine; $i++) {
            $marginBefore[] = $fileLines[$i];
        }

        $marginAfter = [];
        for ($i = $endLine + 1, $max = min(count($fileLines) - 1, $endLine + self::CONTEXT_LINES); $i <= $max; $i++) {
            $marginAfter[] = $fileLines[$i];
        }

        $before = $marginBefore !== [] ? implode("\n", $marginBefore)."\n" : '';
        $after = $marginAfter !== [] ? "\n".implode("\n", $marginAfter) : '';
        $baseOffset = max(0, $startLine - count($marginBefore));

        return [
            $before.$old.$after,
            $before.$new.$after,
            $baseOffset,
        ];
    }

    /**
     * Compute the maximum line number across all hunks for gutter width.
     */
    private function computeMaxLineNumber(array $hunks, array $diff, int $baseOffset): int
    {
        $maxOld = 1;
        $maxNew = 1;

        foreach ($diff as $entry) {
            if ($entry[1] === Differ::OLD) {
                $maxOld++;
                $maxNew++;
            } elseif ($entry[1] === Differ::REMOVED) {
                $maxOld++;
            } elseif ($entry[1] === Differ::ADDED) {
                $maxNew++;
            }
        }

        return max($maxOld, $maxNew) + $baseOffset;
    }

    private function highlight(string $code, string $language): string
    {
        if ($language === '') {
            return $code;
        }

        try {
            return $this->getHighlighter()->parse($code, $language);
        } catch (\Throwable) {
            return $code;
        }
    }

    private function getHighlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter(new KosmokratorTerminalTheme);
    }
}
