<?php

namespace Kosmokrator\LLM;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;

readonly class LlmResponse
{
    /**
     * @param  ToolCall[]  $toolCalls
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
    ) {}
}
