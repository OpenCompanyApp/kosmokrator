<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

/**
 * A single event yielded by the streaming LLM client.
 *
 * Abstracts over provider-specific streaming events (SSE chunks, Prism StreamEvents)
 * into a uniform shape consumed by AgentLoop for incremental rendering.
 */
final class LlmStreamingEvent
{
    /**
     * @param  string  $type  Event type: 'text_delta', 'thinking_delta', 'tool_call', 'stream_end'
     * @param  string  $delta  Incremental text (for text_delta and thinking_delta)
     * @param  array<string, mixed>  $toolCall  Tool call data (for tool_call events)
     * @param  array<string, mixed>  $usage  Token usage (for stream_end events)
     * @param  FinishReason|null  $finishReason  Finish reason (for stream_end events)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $delta = '',
        public readonly array $toolCall = [],
        public readonly array $usage = [],
        public readonly ?\Prism\Prism\Enums\FinishReason $finishReason = null,
    ) {}

    public static function textDelta(string $text): self
    {
        return new self('text_delta', delta: $text);
    }

    public static function thinkingDelta(string $text): self
    {
        return new self('thinking_delta', delta: $text);
    }

    public static function toolCall(string $id, string $name, string $arguments): self
    {
        return new self('tool_call', toolCall: ['id' => $id, 'name' => $name, 'arguments' => $arguments]);
    }

    public static function streamEnd(int $promptTokens, int $completionTokens, int $cacheWrite = 0, int $cacheRead = 0, int $thoughtTokens = 0, ?\Prism\Prism\Enums\FinishReason $finishReason = null): self
    {
        return new self(
            'stream_end',
            usage: [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cache_write_input_tokens' => $cacheWrite,
                'cache_read_input_tokens' => $cacheRead,
                'thought_tokens' => $thoughtTokens,
            ],
            finishReason: $finishReason,
        );
    }
}
