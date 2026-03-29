<?php

namespace Kosmokrator\LLM;

use Generator;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;

class PrismService
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $systemPrompt,
        private readonly ?int $maxTokens = null,
        private readonly int|float|null $temperature = null,
    ) {}

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @param Message[] $messages
     * @param Tool[] $tools
     * @return Generator<StreamEvent>
     */
    public function stream(array $messages, array $tools = []): Generator
    {
        return $this->buildRequest($messages, $tools)->asStream();
    }

    /**
     * Non-streaming fallback for providers that don't support streaming.
     *
     * @param Message[] $messages
     * @param Tool[] $tools
     */
    public function text(array $messages, array $tools = []): Response
    {
        return $this->buildRequest($messages, $tools)->asText();
    }

    public function supportsStreaming(): bool
    {
        // Providers known to NOT support streaming in Prism
        $nonStreamingProviders = ['z'];

        return ! in_array($this->provider, $nonStreamingProviders);
    }

    private function buildRequest(array $messages, array $tools = []): \Prism\Prism\Text\PendingRequest
    {
        $request = (new Prism)->text()
            ->using($this->provider, $this->model)
            ->withSystemPrompt($this->systemPrompt)
            ->withMessages($messages);

        if ($this->maxTokens !== null) {
            $request->withMaxTokens($this->maxTokens);
        }

        if ($this->temperature !== null) {
            $request->usingTemperature($this->temperature);
        }

        if (! empty($tools)) {
            $request->withTools($tools);
            $request->withMaxSteps(10);
        }

        return $request;
    }
}
