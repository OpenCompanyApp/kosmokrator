<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Streaming;

/**
 * Rate-adaptive render throttle for streaming LLM responses.
 *
 * Prevents render queue buildup by accumulating incoming chunks and only
 * triggering a render when enough time has elapsed since the last one.
 *
 * The minimum delay adapts to measured render performance:
 *
 *   minDelay = max(BASE_DELAY_MS, lastRenderDurationMs × RENDER_MULTIPLIER)
 *
 * This ensures the TUI never falls behind during fast streaming — if a render
 * takes 10ms, the next render is delayed at least 20ms, giving the terminal
 * time to process the output and preventing frame accumulation.
 *
 * Usage:
 *   $throttler->start();                       // begin a new response
 *   $throttler->shouldRender($chunk) → bool    // check if it's time to render
 *   $throttler->recordRenderStart();           // before the render call
 *   $throttler->recordRenderEnd();             // after the render call
 *   $throttler->flushRemaining() → string      // get any remaining text
 *
 * @see ChunkedStringBuilder  Used internally for chunk accumulation
 */
final class StreamingThrottler
{
    /**
     * Minimum time between renders (~60fps cap).
     * Even if renders are instant, we never render more than ~60 times per second.
     */
    public const BASE_DELAY_MS = 16;

    /**
     * Multiplier applied to measured render time to compute the minimum delay.
     * A value of 2.0 means: "wait at least twice as long as the last render took."
     */
    public const RENDER_MULTIPLIER = 2.0;

    private ChunkedStringBuilder $accumulator;

    private float $lastRenderTimestampMs = 0.0;

    private float $lastRenderDurationMs = 0.0;

    private float $renderStartTimestampMs = 0.0;

    private bool $streaming = false;

    public function __construct()
    {
        $this->accumulator = new ChunkedStringBuilder();
    }

    /**
     * Begin a new streaming response.
     *
     * Resets all timing state and clears the chunk accumulator.
     * Call this when the first chunk of a new LLM response arrives.
     */
    public function start(): void
    {
        $this->accumulator->clear();
        $this->lastRenderTimestampMs = 0.0;
        $this->lastRenderDurationMs = 0.0;
        $this->renderStartTimestampMs = 0.0;
        $this->streaming = true;
    }

    /**
     * Feed a chunk into the throttler's accumulator.
     *
     * The chunk is stored but not yet "released" — call shouldRender()
     * to determine if enough time has elapsed for a render.
     */
    public function accumulate(string $chunk): void
    {
        $this->accumulator->append($chunk);
    }

    /**
     * Whether enough time has elapsed since the last render to justify a new one.
     *
     * The adaptive delay is computed as:
     *   minDelay = max(BASE_DELAY_MS, lastRenderDurationMs × RENDER_MULTIPLIER)
     *
     * On the first chunk of a response (lastRenderTimestampMs === 0.0),
     * this always returns true to ensure the initial content appears immediately.
     */
    public function shouldRender(): bool
    {
        if (!$this->streaming) {
            return true;
        }

        // First render of this response — always render immediately
        if ($this->lastRenderTimestampMs === 0.0) {
            return true;
        }

        $now = $this->timestampMs();
        $elapsed = $now - $this->lastRenderTimestampMs;

        $minDelay = max(
            self::BASE_DELAY_MS,
            $this->lastRenderDurationMs * self::RENDER_MULTIPLIER,
        );

        return $elapsed >= $minDelay;
    }

    /**
     * Record the start of a render cycle.
     *
     * Call this immediately before the render pipeline executes.
     * The measured duration feeds back into the adaptive delay calculation.
     */
    public function recordRenderStart(): void
    {
        $this->renderStartTimestampMs = $this->timestampMs();
    }

    /**
     * Record the end of a render cycle.
     *
     * Call this immediately after the render pipeline completes.
     * Updates the adaptive delay state for the next shouldRender() call.
     */
    public function recordRenderEnd(): void
    {
        $now = $this->timestampMs();
        $this->lastRenderDurationMs = $now - $this->renderStartTimestampMs;
        $this->lastRenderTimestampMs = $now;
    }

    /**
     * Force the next shouldRender() to return true.
     *
     * Use this when a significant state change occurs that warrants an
     * immediate render regardless of timing (e.g., widget type change
     * from MarkdownWidget to AnsiArtWidget).
     */
    public function forceNextRender(): void
    {
        $this->lastRenderDurationMs = 0.0;
        $this->lastRenderTimestampMs = 0.0;
    }

    /**
     * Flush all accumulated chunks and return them as a single string.
     *
     * Always call this on streamComplete() to ensure no text is lost.
     * After flushing, the accumulator is cleared for reuse.
     */
    public function flushRemaining(): string
    {
        $text = $this->accumulator->toString();
        $this->accumulator->clear();

        return $text;
    }

    /**
     * Get the accumulated text without clearing the accumulator.
     *
     * Useful when the caller needs to inspect accumulated content
     * (e.g., for ANSI escape detection) without consuming it.
     */
    public function peekAccumulated(): string
    {
        return $this->accumulator->toString();
    }

    /**
     * Whether there is any accumulated text waiting to be rendered.
     */
    public function hasPending(): bool
    {
        return !$this->accumulator->isEmpty();
    }

    /**
     * Get the number of accumulated chunks.
     */
    public function pendingChunkCount(): int
    {
        return $this->accumulator->chunkCount();
    }

    /**
     * Get the total byte length of accumulated text.
     */
    public function pendingByteLength(): int
    {
        return $this->accumulator->byteLength();
    }

    /**
     * End the streaming response and reset throttle state.
     *
     * Does NOT flush — call flushRemaining() first if there's pending text.
     */
    public function stop(): void
    {
        $this->streaming = false;
        $this->lastRenderTimestampMs = 0.0;
        $this->lastRenderDurationMs = 0.0;
    }

    /**
     * Get the measured duration of the last render cycle, in milliseconds.
     */
    public function getLastRenderDurationMs(): float
    {
        return $this->lastRenderDurationMs;
    }

    /**
     * Get the current adaptive minimum delay, in milliseconds.
     */
    public function getAdaptiveDelayMs(): float
    {
        return max(
            self::BASE_DELAY_MS,
            $this->lastRenderDurationMs * self::RENDER_MULTIPLIER,
        );
    }

    /**
     * High-resolution timestamp in milliseconds.
     */
    private function timestampMs(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
