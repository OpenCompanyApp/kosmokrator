<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Streaming;

/**
 * Rope-like string builder that avoids O(n²) reallocation during streaming.
 *
 * Chunks are collected in an array (O(1) amortized push) and only materialized
 * into a single string on demand via toString(). This eliminates the per-chunk
 * concatenation chain that allocates growing intermediate strings.
 *
 * Designed for reuse across streaming responses — call clear() between responses
 * instead of creating a new instance.
 *
 * @see StreamingMarkdownBuffer  Uses ChunkedStringBuilder for the active region
 * @see StreamingThrottler       Accumulates throttled chunks via this builder
 */
final class ChunkedStringBuilder
{
    /** @var list<string> */
    private array $chunks = [];

    private int $byteLength = 0;

    /**
     * Append a chunk. O(1) amortized — pushes to the internal array.
     * Empty chunks are silently ignored.
     *
     * @return $this
     */
    public function append(string $chunk): self
    {
        if ($chunk !== '') {
            $this->chunks[] = $chunk;
            $this->byteLength += \strlen($chunk);
        }

        return $this;
    }

    /**
     * Materialize the full string. O(n) where n = total byte length.
     *
     * Optimized for the common cases:
     * - No chunks → returns ''
     * - Single chunk → returns it directly (no implode overhead)
     * - Multiple chunks → implode (single allocation)
     */
    public function toString(): string
    {
        return match (\count($this->chunks)) {
            0 => '',
            1 => $this->chunks[0],
            default => implode('', $this->chunks),
        };
    }

    /**
     * Get the total byte length without materializing the full string.
     */
    public function byteLength(): int
    {
        return $this->byteLength;
    }

    /**
     * Get the number of accumulated chunks.
     */
    public function chunkCount(): int
    {
        return \count($this->chunks);
    }

    /**
     * Whether the builder contains any data.
     */
    public function isEmpty(): bool
    {
        return $this->chunks === [];
    }

    /**
     * Extract the last N bytes without materializing the full string.
     *
     * Used by the streaming window to examine the tail of accumulated content
     * (e.g., to detect block boundaries or markdown syntax transitions).
     *
     * Walks chunks in reverse, accumulating until the requested byte budget
     * is satisfied. O(min(chunks, bytes/avg_chunk_size)) in the worst case.
     */
    public function tail(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        if ($this->byteLength <= $bytes) {
            return $this->toString();
        }

        $result = '';
        $remaining = $bytes;

        for ($i = \count($this->chunks) - 1; $i >= 0 && $remaining > 0; $i--) {
            $chunk = $this->chunks[$i];
            $chunkLen = \strlen($chunk);

            if ($chunkLen <= $remaining) {
                $result = $chunk . $result;
                $remaining -= $chunkLen;
            } else {
                $result = substr($chunk, -$remaining) . $result;
                $remaining = 0;
            }
        }

        return $result;
    }

    /**
     * Get the last chunk that was appended, or empty string if none.
     *
     * Useful for quick syntax detection on the latest streaming token.
     */
    public function lastChunk(): string
    {
        if ($this->chunks === []) {
            return '';
        }

        return $this->chunks[\count($this->chunks) - 1];
    }

    /**
     * Compact adjacent small chunks into a single chunk.
     *
     * Call periodically to prevent unbounded chunk array growth.
     * After compact(), chunkCount() === 1 and byteLength() is unchanged.
     *
     * @param int $threshold Only compact if chunk count exceeds this value
     */
    public function compact(int $threshold = 64): void
    {
        if (\count($this->chunks) < $threshold) {
            return;
        }

        $this->chunks = [$this->toString()];
        // byteLength is unchanged — implode produces the same total length
    }

    /**
     * Clear all chunks and reset state for reuse.
     *
     * The builder instance is retained, avoiding repeated allocation/deallocation
     * across streaming responses. PHP's GC can reclaim the old array.
     */
    public function clear(): void
    {
        $this->chunks = [];
        $this->byteLength = 0;
    }
}
