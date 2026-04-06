<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Immutable value object carrying the normalized result of an LLM call,
 * whether obtained via streaming or non-streaming path.
 *
 * @see AgentLoop::callLlm()
 */
final class ResponseData
{
    /**
     * @param  string  $text  Full assistant reply text
     * @param  ToolCall[]  $toolCalls  Tool calls requested by the model
     * @param  FinishReason  $finishReason  Why the model stopped generating
     * @param  int  $tokensIn  Input tokens billed
     * @param  int  $tokensOut  Output tokens billed
     * @param  int  $cacheReadInputTokens  Input tokens served from cache
     * @param  int  $cacheWriteInputTokens  Input tokens written to cache
     * @param  string  $reasoningContent  Extended-thinking reasoning text
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly FinishReason $finishReason,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly int $cacheReadInputTokens,
        public readonly int $cacheWriteInputTokens,
        public readonly string $reasoningContent,
    ) {}
}
