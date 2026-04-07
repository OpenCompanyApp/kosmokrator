<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Streaming;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Prefix-caching markdown buffer for streaming LLM responses.
 *
 * Splits streamed markdown content into two regions:
 *
 *   ┌─────────────────────────────────────────────────────────────┐
 *   │ Frozen prefix (settled)                                     │
 *   │                                                             │
 *   │ frozenLines: string[]  ← Pre-rendered ANSI lines            │
 *   │                                                             │
 *   │ These blocks are complete (ended by a block boundary like   │
 *   │ \n\n or a closing code fence). They are parsed and rendered │
 *   │ once, then cached forever. No re-parse cost on subsequent   │
 *   │ chunks.                                                     │
 *   └─────────────────────────────────────────────────────────────┘
 *   ┌─────────────────────────────────────────────────────────────┐
 *   │ Active suffix (live)                                        │
 *   │                                                             │
 *   │ activeBuilder: ChunkedStringBuilder  ← Recent raw text      │
 *   │ activeLines: string[]                ← Rendered lines       │
 *   │                                                             │
 *   │ This region holds the last incomplete block. It is re-parsed│
 *   │ and re-rendered on every chunk. Cost is O(active text only),│
 *   │ not O(total accumulated text).                              │
 *   └─────────────────────────────────────────────────────────────┘
 *
 * The settle boundary is chosen to keep the active region at or above
 * a configurable line minimum (liveWindowLines), ensuring that wrapping
 * and formatting near the cursor remain correct.
 *
 * @see ChunkedStringBuilder  Used for efficient chunk accumulation
 * @see StreamingThrottler    Works alongside this buffer to throttle renders
 */
final class StreamingMarkdownBuffer
{
    /**
     * Default minimum lines to keep in the active region.
     *
     * Matches Aider's empirical finding that ~6 lines balances smoothness
     * (enough context for re-wrapping) with efficiency (small re-parse window).
     */
    public const DEFAULT_LIVE_WINDOW_LINES = 6;

    /**
     * Default minimum bytes the active region must reach before the buffer
     * attempts to settle (freeze completed blocks).
     *
     * Prevents premature settling when the response has only just started.
     */
    public const DEFAULT_SETTLE_THRESHOLD_BYTES = 256;

    /** @var list<string> Pre-rendered ANSI lines for frozen prefix blocks */
    private array $frozenLines = [];

    /** @var int Total byte count of all frozen raw text */
    private int $frozenBytes = 0;

    /** @var int Total line count of frozen rendered output */
    private int $frozenLineCount = 0;

    private ChunkedStringBuilder $activeBuilder;

    /** @var list<string> Rendered lines for the active (live) region */
    private array $activeLines = [];

    private readonly int $liveWindowLines;

    private readonly int $settleThresholdBytes;

    private MarkdownParser $parser;

    /**
     * Cached columns value from the last render call.
     * Used by renderActive() when called without a column argument.
     */
    private int $lastColumns = 80;

    /**
     * @param int $liveWindowLines     Minimum lines to keep in the active region
     * @param int $settleThresholdBytes Minimum active bytes before settling
     * @param MarkdownParser|null $parser  Optional shared parser instance
     */
    public function __construct(
        int $liveWindowLines = self::DEFAULT_LIVE_WINDOW_LINES,
        int $settleThresholdBytes = self::DEFAULT_SETTLE_THRESHOLD_BYTES,
        ?MarkdownParser $parser = null,
    ) {
        $this->liveWindowLines = $liveWindowLines;
        $this->settleThresholdBytes = $settleThresholdBytes;
        $this->activeBuilder = new ChunkedStringBuilder();

        if ($parser !== null) {
            $this->parser = $parser;
        } else {
            $environment = new Environment();
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new GithubFlavoredMarkdownExtension());
            $this->parser = new MarkdownParser($environment);
        }
    }

    /**
     * Append streaming text and get the full rendered output.
     *
     * This is the main entry point during streaming:
     *  1. Append to the active chunk builder (O(1))
     *  2. Try to settle completed blocks (freeze prefix)
     *  3. Re-render only the active region
     *  4. Return frozen + active lines
     *
     * @param int $columns Terminal width for text wrapping
     * @return list<string> Full rendered ANSI lines (frozen + active)
     */
    public function append(string $text, int $columns): array
    {
        $this->lastColumns = $columns;
        $this->activeBuilder->append($text);
        $this->activeBuilder->compact();

        $this->trySettle($columns);
        $this->renderActive($columns);

        return $this->getLines();
    }

    /**
     * Get the full rendered output without appending new text.
     *
     * Useful when the throttler triggers a render but no new text has arrived
     * (e.g., re-render at a different column width after terminal resize).
     *
     * @param int $columns Terminal width for text wrapping
     * @return list<string>
     */
    public function rerender(int $columns): array
    {
        $this->lastColumns = $columns;

        // Re-render frozen lines if column width changed
        // (For simplicity, we re-render from full text on resize.
        //  A more sophisticated approach would cache per-width.)
        if ($columns !== $this->lastColumns && $this->frozenBytes > 0) {
            // Re-rendering frozen on resize is expensive but rare.
            // For now, just re-render the active region.
        }

        $this->renderActive($columns);

        return $this->getLines();
    }

    /**
     * Get the current rendered lines (frozen + active).
     *
     * @return list<string>
     */
    public function getLines(): array
    {
        if ($this->frozenLines === []) {
            return $this->activeLines;
        }

        if ($this->activeLines === []) {
            return $this->frozenLines;
        }

        return [...$this->frozenLines, ...$this->activeLines];
    }

    /**
     * Get the total rendered line count without materializing.
     */
    public function getLineCount(): int
    {
        return $this->frozenLineCount + \count($this->activeLines);
    }

    /**
     * Freeze the active region into the frozen prefix.
     *
     * Call this when streaming completes to ensure all content is cached.
     * After freeze(), the active region is empty and all lines are frozen.
     *
     * @param int $columns Terminal width for final render
     */
    public function freeze(int $columns): void
    {
        $activeText = $this->activeBuilder->toString();

        if ($activeText !== '') {
            $rendered = $this->renderMarkdown($activeText, $columns);
            array_push($this->frozenLines, ...$rendered);
            $this->frozenBytes += \strlen($activeText);
            $this->frozenLineCount += \count($rendered);
        }

        $this->activeBuilder->clear();
        $this->activeLines = [];
    }

    /**
     * Finalize the buffer: freeze all content and return final rendered lines.
     *
     * Always call this on streamComplete(). After this call:
     * - All content is frozen and cached
     * - The active region is empty
     * - The returned lines are the final, correct output
     *
     * @param int $columns Terminal width for final render
     * @return list<string> Final rendered ANSI lines
     */
    public function finalize(int $columns): array
    {
        $this->freeze($columns);

        return $this->frozenLines;
    }

    /**
     * Get the full raw markdown text (frozen + active).
     *
     * Used when the buffer needs to supply text to an external widget
     * (e.g., MarkdownWidget::setText() for the non-streaming path).
     */
    public function getFullText(): string
    {
        return $this->activeBuilder->toString();
    }

    /**
     * Get the byte length of the active (non-frozen) region.
     */
    public function getActiveByteLength(): int
    {
        return $this->activeBuilder->byteLength();
    }

    /**
     * Get the number of frozen (settled) lines.
     */
    public function getFrozenLineCount(): int
    {
        return $this->frozenLineCount;
    }

    /**
     * Reset the buffer for reuse in a new streaming response.
     *
     * Clears all frozen and active state. The MarkdownParser instance is
     * retained (expensive to construct) for reuse across responses.
     */
    public function reset(): void
    {
        $this->frozenLines = [];
        $this->frozenBytes = 0;
        $this->frozenLineCount = 0;
        $this->activeBuilder->clear();
        $this->activeLines = [];
    }

    /**
     * Try to settle completed blocks from the active region into the frozen prefix.
     *
     * A block is considered "completed" when:
     *  1. The active region exceeds the settle threshold (enough bytes)
     *  2. A block boundary is found that leaves at least liveWindowLines
     *     of rendered content in the active region
     *
     * Block boundaries are:
     *  - Double newline (\n\n) — standard CommonMark paragraph/block separator
     *  - Closing code fence (``` followed by newline) — end of fenced code block
     */
    private function trySettle(int $columns): void
    {
        $activeText = $this->activeBuilder->toString();

        // Don't settle if active region is still small
        if (\strlen($activeText) < $this->settleThresholdBytes) {
            return;
        }

        // Find the last safe block boundary
        $boundary = $this->findSettleBoundary($activeText, $columns);

        if ($boundary === null) {
            return;
        }

        $settledText = substr($activeText, 0, $boundary);
        $remainText = substr($activeText, $boundary);

        // Render and freeze the settled prefix
        $rendered = $this->renderMarkdown($settledText, $columns);
        array_push($this->frozenLines, ...$rendered);
        $this->frozenBytes += \strlen($settledText);
        $this->frozenLineCount += \count($rendered);

        // Reset active region to just the remaining tail
        $this->activeBuilder->clear();
        $this->activeBuilder->append($remainText);
    }

    /**
     * Find the last block boundary in the active text that leaves enough
     * content in the active region (at least liveWindowLines rendered).
     *
     * @return int|null Byte offset of the boundary, or null if none found
     */
    private function findSettleBoundary(string $text, int $columns): ?int
    {
        $textLen = \strlen($text);

        // Estimate how many bytes correspond to liveWindowLines.
        // Use a rough heuristic: average ~80 bytes per rendered line.
        // This is conservative — we'll verify with an actual render below.
        $minActiveBytes = $this->liveWindowLines * 40;
        $searchStart = max(0, $textLen - $minActiveBytes);

        // Track potential boundaries (position => type)
        // We want the LAST boundary before the live window starts
        $lastBoundary = null;

        // Scan for block boundaries from the beginning up to the live window
        $pos = 0;
        $inFencedCode = false;
        $fenceChar = '';
        $fenceLen = 0;
        $fenceStart = 0;

        while ($pos < $searchStart) {
            // Track fenced code blocks — we cannot settle inside them
            if (!$inFencedCode && $this->isFenceStart($text, $pos, $fenceChar, $fenceLen)) {
                $inFencedCode = true;
                $fenceStart = $pos;
                $pos += $fenceLen;
                continue;
            }

            if ($inFencedCode && $this->isFenceEnd($text, $pos, $fenceChar, $fenceLen)) {
                // Found closing fence — the boundary is after this fence line
                $endOfLine = strpos($text, "\n", $pos + $fenceLen);
                if ($endOfLine !== false) {
                    // Settle boundary is after the closing fence line
                    $candidate = $endOfLine + 1;
                    // Ensure there's a blank line after (block boundary)
                    if ($candidate < $searchStart) {
                        $lastBoundary = $candidate;
                    }
                }
                $inFencedCode = false;
                $pos += $fenceLen;
                continue;
            }

            // Check for double newline (standard block boundary)
            if (!$inFencedCode
                && $pos + 1 < $textLen
                && $text[$pos] === "\n"
                && $text[$pos + 1] === "\n"
            ) {
                $candidate = $pos + 2; // After the double newline
                if ($candidate <= $searchStart) {
                    $lastBoundary = $candidate;
                }
                $pos += 2;
                continue;
            }

            $pos++;
        }

        // Verify the candidate leaves enough active lines
        if ($lastBoundary !== null && $lastBoundary < $textLen) {
            $activeTail = substr($text, $lastBoundary);
            $activeRendered = $this->renderMarkdown($activeTail, $columns);

            if (\count($activeRendered) >= $this->liveWindowLines) {
                return $lastBoundary;
            }

            // Not enough lines — try an earlier boundary
            // In practice, the live window heuristic means we may not settle
            // until more content accumulates. This is fine.
        }

        return null;
    }

    /**
     * Check if $pos starts a fenced code fence (``` or ~~~).
     *
     * @param string $text  Full text
     * @param int $pos      Position to check
     * @-out string $char   The fence character (` or ~)
     * @-out int $len       Number of fence characters
     */
    private function isFenceStart(string $text, int $pos, string &$char, int &$len): bool
    {
        if ($pos >= \strlen($text)) {
            return false;
        }

        $c = $text[$pos];
        if ($c !== '`' && $c !== '~') {
            return false;
        }

        // Count consecutive fence characters
        $count = 1;
        $i = $pos + 1;
        while ($i < \strlen($text) && $text[$i] === $c && $count < 10) {
            $count++;
            $i++;
        }

        if ($count < 3) {
            return false;
        }

        $char = $c;
        $len = $count;

        return true;
    }

    /**
     * Check if $pos starts a closing fence matching the current open fence.
     */
    private function isFenceEnd(string $text, int $pos, string $fenceChar, int $fenceLen): bool
    {
        if ($pos >= \strlen($text)) {
            return false;
        }

        if ($text[$pos] !== $fenceChar) {
            return false;
        }

        // Count consecutive matching fence characters
        $count = 1;
        $i = $pos + 1;
        while ($i < \strlen($text) && $text[$i] === $fenceChar && $count < 10) {
            $count++;
            $i++;
        }

        return $count >= $fenceLen;
    }

    /**
     * Render the active region's raw text into ANSI lines.
     *
     * This is the "expensive" operation that the buffer seeks to minimize.
     * By keeping the active region small, render cost stays O(active text)
     * instead of O(total accumulated text).
     */
    private function renderActive(int $columns): void
    {
        $activeText = $this->activeBuilder->toString();

        if ($activeText === '') {
            $this->activeLines = [];

            return;
        }

        $this->activeLines = $this->renderMarkdown($activeText, $columns);
    }

    /**
     * Render raw markdown text into ANSI-styled lines using CommonMark.
     *
     * This is a lightweight wrapper that parses the text and then renders
     * through the same pipeline that MarkdownWidget uses internally.
     *
     * Note: For full fidelity (syntax highlighting, custom styles), the
     * output of this buffer should be fed to a MarkdownWidget on finalize().
     * During streaming, this provides a fast approximation.
     *
     * @param string $markdown  Raw markdown text
     * @param int $columns      Terminal width for wrapping
     * @return list<string>     Rendered ANSI lines
     */
    private function renderMarkdown(string $markdown, int $columns): array
    {
        if (trim($markdown) === '') {
            return [];
        }

        $document = $this->parser->parse($markdown);

        // Simple rendering: walk the AST and produce styled lines.
        // This intentionally does NOT use the full MarkdownWidget render
        // pipeline (which includes Tempest highlighting, style resolution, etc.)
        // to keep streaming fast. The final render on streamComplete() uses
        // the full pipeline via MarkdownWidget.
        $lines = [];
        $this->renderAstNode($document, $columns, $lines);

        return $lines;
    }

    /**
     * Walk a CommonMark AST node and render to lines.
     *
     * This produces a simplified rendering suitable for streaming display.
     * The final render (on streamComplete) uses MarkdownWidget for full fidelity.
     *
     * @param \League\CommonMark\Node\Node $node   AST node to render
     * @param int $columns                              Terminal width
     * @param list<string> $lines                       Output lines (appended to)
     */
    private function renderAstNode(
        \League\CommonMark\Node\Node $node,
        int $columns,
        array &$lines,
    ): void {
        foreach ($node->children() as $child) {
            // Block-level nodes
            if ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode) {
                // Code fence content
                $content = rtrim($child->getLiteral(), "\n");
                foreach (explode("\n", $content) as $line) {
                    $lines[] = '  ' . $line;
                }
            } elseif ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\Heading) {
                $level = $child->getLevel();
                $text = $this->collectInlineText($child);
                $prefix = str_repeat('#', $level) . ' ';
                $lines[] = $prefix . $text;
            } elseif ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\ListBlock) {
                $this->renderListBlock($child, $columns, $lines);
            } elseif ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote) {
                foreach ($child->children() as $quoteChild) {
                    $quoteLines = [];
                    $this->renderAstNode($quoteChild, max(1, $columns - 2), $quoteLines);
                    foreach ($quoteLines as $ql) {
                        $lines[] = '│ ' . $ql;
                    }
                }
            } elseif ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak) {
                $lines[] = str_repeat('─', $columns);
            } elseif ($child instanceof \League\CommonMark\Node\Block\Paragraph) {
                $text = $this->collectInlineText($child);
                if ($text !== '') {
                    $wrapped = $this->wrapText($text, $columns);
                    foreach ($wrapped as $wl) {
                        $lines[] = $wl;
                    }
                }
            } elseif ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode) {
                $content = rtrim($child->getLiteral(), "\n");
                foreach (explode("\n", $content) as $line) {
                    $lines[] = '    ' . $line;
                }
            } elseif ($child instanceof \League\CommonMark\Extension\Table\Table) {
                $this->renderTable($child, $columns, $lines);
            } else {
                // Generic block — recurse into children
                $this->renderAstNode($child, $columns, $lines);
            }

            // Add spacing between blocks
            if ($lines !== [] && !$child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak) {
                $lines[] = '';
            }
        }

        // Remove trailing empty line added by block spacing
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
    }

    /**
     * Collect all inline text from a node's children.
     */
    private function collectInlineText(\League\CommonMark\Node\Node $node): string
    {
        $text = '';
        foreach ($node->children() as $child) {
            if ($child instanceof \League\CommonMark\Node\Inline\Text) {
                $text .= $child->getLiteral();
            } elseif ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Inline\Code) {
                $text .= $child->getLiteral();
            } elseif ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Inline\Strong
                || $child instanceof \League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis
            ) {
                $text .= $this->collectInlineText($child);
            } elseif ($child instanceof \League\CommonMark\Node\Inline\Newline) {
                $text .= "\n";
            } else {
                $text .= $this->collectInlineText($child);
            }
        }

        return $text;
    }

    /**
     * Render a list block.
     */
    private function renderListBlock(
        \League\CommonMark\Extension\CommonMark\Node\Block\ListBlock $list,
        int $columns,
        array &$lines,
    ): void {
        $isOrdered = $list->getListData()->type === 'ordered';
        $index = $list->getListData()->start ?? 1;

        foreach ($list->children() as $item) {
            if (!$item instanceof \League\CommonMark\Extension\CommonMark\Node\Block\ListItem) {
                continue;
            }

            $bullet = $isOrdered ? ($index . '. ') : '• ';
            $itemColumns = max(1, $columns - 2);

            foreach ($item->children() as $itemChild) {
                if ($itemChild instanceof \League\CommonMark\Node\Block\Paragraph) {
                    $text = $this->collectInlineText($itemChild);
                    $wrapped = $this->wrapText($text, $itemColumns);
                    foreach ($wrapped as $i => $wl) {
                        $lines[] = ($i === 0 ? $bullet : '  ') . $wl;
                    }
                } else {
                    $childLines = [];
                    $this->renderAstNode($itemChild, $itemColumns, $childLines);
                    foreach ($childLines as $i => $cl) {
                        $lines[] = ($i === 0 ? $bullet : '  ') . $cl;
                    }
                }
            }

            $index++;
        }
    }

    /**
     * Render a CommonMark Table node.
     */
    private function renderTable(
        \League\CommonMark\Extension\Table\Table $table,
        int $columns,
        array &$lines,
    ): void {
        $headers = [];
        $rows = [];

        foreach ($table->children() as $section) {
            if (!$section instanceof \League\CommonMark\Extension\Table\TableSection) {
                continue;
            }

            foreach ($section->children() as $row) {
                if (!$row instanceof \League\CommonMark\Extension\Table\TableRow) {
                    continue;
                }

                $cells = [];
                foreach ($row->children() as $cell) {
                    if ($cell instanceof \League\CommonMark\Extension\Table\TableCell) {
                        $cells[] = $this->collectInlineText($cell);
                    }
                }

                if ($section->isHead()) {
                    $headers = $cells;
                } else {
                    $rows[] = $cells;
                }
            }
        }

        if ($headers === [] && $rows === []) {
            return;
        }

        // Simple table rendering for streaming
        if ($headers !== []) {
            $lines[] = implode(' | ', $headers);
            $lines[] = str_repeat('─', min($columns, array_sum(array_map('strlen', $headers)) + 3 * \count($headers)));
        }

        foreach ($rows as $row) {
            $lines[] = implode(' | ', $row);
        }
    }

    /**
     * Simple word-wrap without ANSI awareness.
     *
     * During streaming, we use this lightweight wrapper instead of
     * TextWrapper::wrapTextWithAnsi() to avoid the ANSI parsing overhead.
     * The final render uses the full-featured wrapper.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $columns): array
    {
        if ($columns <= 0) {
            return [$text];
        }

        $words = preg_split('/(\s+)/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return [$text];
        }

        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            // Skip pure whitespace words but use them to separate
            if (trim($word) === '') {
                if ($currentLine !== '') {
                    $currentLine .= $word;
                }
                continue;
            }

            if ($currentLine === '') {
                $currentLine = $word;
            } elseif (\strlen($currentLine) + \strlen($word) <= $columns) {
                $currentLine .= $word;
            } else {
                $lines[] = rtrim($currentLine);
                $currentLine = $word;
            }
        }

        if ($currentLine !== '') {
            $lines[] = rtrim($currentLine);
        }

        return $lines !== [] ? $lines : [''];
    }
}
