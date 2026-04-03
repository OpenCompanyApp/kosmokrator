<?php

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Generator;
use OpenCompany\PrismRelay\Capabilities\ProviderCapabilities;
use OpenCompany\PrismRelay\Relay;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;

/**
 * LLM client backed by the Prism PHP SDK for providers with native Prism drivers.
 *
 * Implements LlmClientInterface using Prism's text() and asStream() APIs.
 * Handles prompt caching via PromptFrameBuilder and Relay. Used alongside AsyncLlmClient,
 * which handles OpenAI-compatible providers via raw HTTP.
 */
class PrismService implements LlmClientInterface
{
    private readonly Relay $relay;

    private string $reasoningEffort = 'off';

    public function __construct(
        private string $provider,
        private string $model,
        private string $systemPrompt,
        private ?int $maxTokens = null,
        private int|float|null $temperature = null,
        ?Relay $relay = null,
        private readonly ?RelayRegistry $registry = null,
    ) {
        $this->relay = $relay ?? new Relay;
    }

    /**
     * @param string $prompt Full system prompt to prepend to every conversation
     */
    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function getTemperature(): int|float|null
    {
        return $this->temperature;
    }

    public function setTemperature(int|float|null $temperature): void
    {
        $this->temperature = $temperature;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function setMaxTokens(?int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    public function getReasoningEffort(): string
    {
        return $this->reasoningEffort ?? 'off';
    }

    public function setReasoningEffort(string $effort): void
    {
        $this->reasoningEffort = $effort;
    }

    /**
     * Delegate to text() and reshape the Prism Response into our generic LlmResponse.
     *
     * @param  Message[]    $messages     Conversation history
     * @param  Tool[]       $tools        Available tools for function calling
     * @param  Cancellation $cancellation Unused — kept for interface compatibility
     */
    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        $response = $this->text($messages, $tools);

        return new LlmResponse(
            text: $response->text,
            finishReason: $response->finishReason,
            toolCalls: $response->toolCalls,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            cacheWriteInputTokens: $response->usage->cacheWriteInputTokens ?? 0,
            cacheReadInputTokens: $response->usage->cacheReadInputTokens ?? 0,
            thoughtTokens: $response->usage->thoughtTokens ?? 0,
        );
    }

    /**
     * Yield streaming events from the provider for real-time token display.
     *
     * Wraps the Prism asStream() generator to apply relay normalization (beforeRequest,
     * normalizeError) consistently across streaming and non-streaming paths.
     *
     * @param  Message[]  $messages
     * @param  Tool[]     $tools
     * @return Generator<StreamEvent>
     */
    public function stream(array $messages, array $tools = []): Generator
    {
        return (function () use ($messages, $tools): Generator {
            $this->relay->beforeRequest($this->provider, $this->model);

            try {
                yield from $this->buildRequest($messages, $tools)->asStream();
            } catch (\Throwable $e) {
                throw $this->relay->normalizeError($e, $this->provider, $this->model);
            }
        })();
    }

    /**
     * Non-streaming request returning a full Prism Response object.
     *
     * Unlike chat(), this returns the raw Prism Response for callers that need
     * the full Prism value objects rather than the generic LlmResponse DTO.
     *
     * @param  Message[]  $messages
     * @param  Tool[]     $tools
     */
    public function text(array $messages, array $tools = []): Response
    {
        $this->relay->beforeRequest($this->provider, $this->model);

        try {
            $response = $this->buildRequest($messages, $tools)->asText();
        } catch (\Throwable $e) {
            throw $this->relay->normalizeError($e, $this->provider, $this->model);
        }

        return $this->relay->normalizeResponse($this->provider, $this->model, $response);
    }

    /**
     * Whether the current provider supports SSE streaming via Prism.
     * Uses RelayProviderRegistry when available, otherwise falls back to ProviderCapabilities.
     */
    public function supportsStreaming(): bool
    {
        if ($this->registry !== null) {
            return $this->registry->capabilities($this->provider)['streaming'];
        }

        return ProviderCapabilities::for($this->provider, $this->registry)->supportsStreaming();
    }

    /**
     * Build a pending Prism text request with prompt caching, tools, and provider options.
     *
     * @param  Message[] $messages Conversation history
     * @param  Tool[]    $tools    Available tools for function calling
     */
    private function buildRequest(array $messages, array $tools = []): PendingRequest
    {
        $cachePlan = $this->relay->planPromptCache(
            provider: $this->provider,
            model: $this->model,
            systemPrompts: PromptFrameBuilder::splitSystemPrompt($this->systemPrompt),
            messages: $messages,
        );

        $request = (new Prism)->text()
            ->using($this->provider, $this->model)
            ->withSystemPrompts($cachePlan->systemPrompts)
            ->withMessages($cachePlan->messages)
            ->withProviderOptions($cachePlan->providerOptions);

        if ($this->maxTokens !== null) {
            $request->withMaxTokens($this->maxTokens);
        }

        if ($this->temperature !== null && $this->supportsTemperature()) {
            $request->usingTemperature($this->temperature);
        }

        if (! empty($tools)) {
            $request->withTools($tools);
        }

        return $request;
    }

    /**
     * Check whether the current provider supports temperature configuration.
     * Uses RelayProviderRegistry when available, otherwise falls back to ProviderCapabilities.
     */
    private function supportsTemperature(): bool
    {
        if ($this->registry !== null) {
            return $this->registry->capabilities($this->provider)['temperature'];
        }

        return ProviderCapabilities::for($this->provider, $this->registry)->supportsTemperature();
    }
}
