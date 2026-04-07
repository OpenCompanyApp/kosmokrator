<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Widget;

use KosmoKrator\UI\Tui\Performance\CompactableWidgetInterface;
use KosmoKrator\UI\Tui\Streaming\StreamingMarkdownBuffer;
use KosmoKrator\UI\Tui\Streaming\StreamingThrottler;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Streaming-optimized markdown widget that wraps StreamingMarkdownBuffer.
 *
 * Drop-in replacement for MarkdownWidget during streaming. Uses prefix-caching
 * to avoid O(n²) re-renders on every chunk. On streamComplete(), call freeze()
 * to finalize the buffer — the widget then returns the buffer's cached output
 * from render().
 *
 * For the first response after streamComplete(), the full text is available via
 * getText() so that it can be handed off to a regular MarkdownWidget for the
 * final high-fidelity render (Tempest highlighting, style resolution, etc.).
 *
 * @see StreamingMarkdownBuffer  The prefix-caching buffer engine
 * @see StreamingThrottler       Rate-adaptive render throttle
 */
final class StreamingMarkdownWidget extends AbstractWidget implements CompactableWidgetInterface
{
    private StreamingMarkdownBuffer $buffer;

    private ?StreamingThrottler $throttler;

    private bool $frozen = false;

    /** @var list<string>|null Cached rendered lines after freeze() */
    private ?array $frozenLines = null;

    /** Full raw text retained for getText() and compaction summary */
    private string $fullText = '';

    private bool $compacted = false;

    private ?string $compactedSummary = null;

    private int $estimatedHeight = 0;

    public function __construct(
        ?StreamingThrottler $throttler = null,
        ?StreamingMarkdownBuffer $buffer = null,
    ) {
        $this->buffer = $buffer ?? new StreamingMarkdownBuffer();
        $this->throttler = $throttler;
    }

    /**
     * Append streaming text to the buffer.
     *
     * Only triggers a render if the throttler allows it. Returns the
     * rendered lines (frozen + active) for immediate display.
     *
     * @return list<string>|null Rendered lines, or null if throttled
     */
    public function appendText(string $text): ?array
    {
        if ($this->frozen) {
            return $this->frozenLines;
        }

        $this->fullText .= $text;

        if ($this->throttler !== null) {
            $this->throttler->accumulate($text);

            if (!$this->throttler->shouldRender()) {
                return null;
            }

            $this->throttler->recordRenderStart();
        }

        $columns = 80; // Will be overridden by render()
        $lines = $this->buffer->append($text, $columns);

        if ($this->throttler !== null) {
            $this->throttler->recordRenderEnd();
        }

        return $lines;
    }

    /**
     * Set the full text (replaces current content).
     *
     * Used for drop-in compatibility with MarkdownWidget::setText().
     */
    public function setText(string $text): static
    {
        if ($this->frozen) {
            return $this;
        }

        $this->fullText = $text;
        $this->buffer->reset();

        // Re-feed text through buffer
        if ($text !== '') {
            $this->buffer->append($text, 80);
        }

        $this->invalidate();

        return $this;
    }

    /**
     * Get the full raw markdown text accumulated so far.
     */
    public function getText(): string
    {
        return $this->fullText;
    }

    /**
     * Freeze the buffer on stream completion.
     *
     * After this call, all content is cached and render() returns the
     * frozen output. No further appendText() calls are processed.
     *
     * @param int $columns Terminal width for final render
     */
    public function freeze(int $columns = 80): void
    {
        if ($this->frozen) {
            return;
        }

        // Flush any remaining throttled text
        if ($this->throttler !== null) {
            $remaining = $this->throttler->flushRemaining();
            if ($remaining !== '') {
                $this->fullText .= $remaining;
                $this->buffer->append($remaining, $columns);
            }
            $this->throttler->stop();
        }

        $this->frozenLines = $this->buffer->finalize($columns);
        $this->frozen = true;
        $this->estimatedHeight = count($this->frozenLines);
        $this->invalidate();
    }

    /**
     * Render the widget — returns frozen lines if frozen, otherwise live renders.
     */
    public function render(RenderContext $context): array
    {
        if ($this->compacted && $this->compactedSummary !== null) {
            $dim = "\x1b[38;5;240m";
            $r = "\x1b[0m";

            return ["  {$dim}⊛ {$this->compactedSummary}{$r}"];
        }

        if ($this->frozen && $this->frozenLines !== null) {
            return $this->frozenLines;
        }

        $columns = $context->getColumns();
        $lines = $this->buffer->getLines();
        $this->estimatedHeight = count($lines);

        return $lines;
    }

    /**
     * Whether the buffer has been frozen (streaming complete).
     */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Get the underlying buffer (for advanced usage).
     */
    public function getBuffer(): StreamingMarkdownBuffer
    {
        return $this->buffer;
    }

    /**
     * Get the throttler (for external timing coordination).
     */
    public function getThrottler(): ?StreamingThrottler
    {
        return $this->throttler;
    }

    /**
     * Get the estimated rendered height in terminal lines.
     */
    public function getEstimatedLineCount(): int
    {
        return $this->estimatedHeight;
    }

    // ── CompactableWidgetInterface ──────────────────────────────────────

    public function compact(): void
    {
        if ($this->compacted) {
            return;
        }

        // Generate a one-line summary from the first line of content
        $firstLine = '';
        if ($this->fullText !== '') {
            $lines = explode("\n", trim($this->fullText), 2);
            $firstLine = trim($lines[0]);
        }

        $this->compactedSummary = $firstLine !== ''
            ? mb_substr($firstLine, 0, 80) . (mb_strlen($firstLine) > 80 ? '…' : '')
            : '(markdown response)';

        $this->compacted = true;

        // Free the expensive content
        $this->frozenLines = null;
        $this->fullText = '';
        $this->buffer->reset();
    }

    public function isCompacted(): bool
    {
        return $this->compacted;
    }

    public function getSummaryLine(): string
    {
        return $this->compactedSummary ?? '(markdown response)';
    }

    public function getEstimatedHeight(): int
    {
        return $this->estimatedHeight;
    }
}
