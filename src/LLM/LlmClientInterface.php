<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Generator;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;

/**
 * Contract for LLM chat clients in the KosmoKrator LLM layer.
 *
 * Implemented by PrismService (via Prism SDK) and AsyncLlmClient (raw HTTP).
 * Decorated by RetryableLlmClient for automatic retry on transient failures.
 */
interface LlmClientInterface
{
    /**
     * Send a chat-completion request to the LLM provider.
     *
     * @param  Message[]  $messages  Conversation history as Prism Message objects
     * @param  Tool[]  $tools  Available tools for function calling
     * @param  Cancellation  $cancellation  Optional Amp cancellation token
     * @return LlmResponse Parsed response with text, tool calls, and usage data
     */
    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse;

    /**
     * Stream a chat-completion request, yielding incremental events.
     *
     * Returns a Generator that yields LlmStreamingEvent objects as they arrive
     * from the provider. The final event is always a 'stream_end' with usage data.
     * Callers that don't need streaming should use chat() instead.
     *
     * @param  Message[]  $messages  Conversation history as Prism Message objects
     * @param  Tool[]  $tools  Available tools for function calling
     * @param  Cancellation  $cancellation  Optional Amp cancellation token
     * @return Generator<LlmStreamingEvent>
     */
    public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): Generator;

    /**
     * Whether this client supports streaming for the current provider.
     */
    public function supportsStreaming(): bool;

    public function setSystemPrompt(string $prompt): void;

    public function getProvider(): string;

    /** @param string $provider Provider identifier to switch to (e.g. "anthropic", "openai") */
    public function setProvider(string $provider): void;

    public function getModel(): string;

    public function setModel(string $model): void;

    public function getTemperature(): int|float|null;

    public function setTemperature(int|float|null $temperature): void;

    public function getMaxTokens(): ?int;

    public function setMaxTokens(?int $maxTokens): void;

    public function getReasoningEffort(): string;

    public function setReasoningEffort(string $effort): void;
}
