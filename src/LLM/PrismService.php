<?php

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Generator;
use OpenCompany\PrismRelay\Capabilities\ProviderCapabilities;
use OpenCompany\PrismRelay\Relay;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;

class PrismService implements LlmClientInterface
{
    private readonly Relay $relay;

    public function __construct(
        private string $provider,
        private string $model,
        private string $systemPrompt,
        private ?int $maxTokens = null,
        private int|float|null $temperature = null,
        ?Relay $relay = null,
    ) {
        $this->relay = $relay ?? new Relay;
    }

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
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
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
     * Non-streaming fallback for providers that don't support streaming.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
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

    public function supportsStreaming(): bool
    {
        // Providers known to NOT support streaming in Prism
        $nonStreamingProviders = ['z'];

        return ! in_array($this->provider, $nonStreamingProviders);
    }

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

    private function supportsTemperature(): bool
    {
        return ProviderCapabilities::for($this->provider)->supportsTemperature();
    }
}
