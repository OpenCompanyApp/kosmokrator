<?php

namespace Kosmokrator\LLM;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Immutable value object carrying the result of a single LLM chat-completion call.
 *
 * Created by AsyncLlmClient and PrismService after parsing the raw provider response.
 * Consumed by the agent loop, cost tracking (ModelCatalog), and TUI display.
 */
readonly class LlmResponse
{
    /**
     * @param  string  $text  Assistant reply text (empty when tool_calls is non-empty)
     * @param  FinishReason  $finishReason  Why the model stopped generating
     * @param  ToolCall[]  $toolCalls  Requested function calls from the model
     * @param  int  $promptTokens  Input tokens billed for this request
     * @param  int  $completionTokens  Output tokens billed for this request
     * @param  int  $cacheWriteInputTokens  Input tokens written to provider cache (often discounted)
     * @param  int  $cacheReadInputTokens  Input tokens served from provider cache (often discounted)
     * @param  int  $thoughtTokens  Reasoning/thinking tokens used by extended-thinking models
     * @param  string  $reasoningContent  Raw reasoning/thinking text returned by extended-thinking models
     */
    public function __construct(
        public string $text,
        public FinishReason $finishReason,
        public array $toolCalls,
        public int $promptTokens,
        public int $completionTokens,
        public int $cacheWriteInputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $thoughtTokens = 0,
        public string $reasoningContent = '',
    ) {}
}
