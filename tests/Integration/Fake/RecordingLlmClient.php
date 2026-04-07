<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Fake;

use Amp\Cancellation;
use Generator;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\LlmStreamingEvent;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;

/**
 * Fake LLM client that returns pre-queued responses and records all interactions.
 *
 * Usage:
 *   $llm = new RecordingLlmClient();
 *   $llm->queueResponse(new LlmResponse('Hello!', FinishReason::Stop, [], 100, 50));
 *   $llm->queueResponse(new LlmResponse('', FinishReason::ToolCalls, [$toolCall], 50, 20));
 *
 *   // After test:
 *   $this->assertCount(2, $llm->chatCalls);
 *   $this->assertSame('Hello!', $llm->chatCalls[0]->response->text);
 */
final class RecordingLlmClient implements LlmClientInterface
{
    private string $provider = 'test';

    private string $model = 'test-model';

    private int|float|null $temperature = null;

    private ?int $maxTokens = null;

    private string $reasoningEffort = 'medium';

    private string $systemPrompt = '';

    /** @var list<LlmResponse> */
    private array $responseQueue = [];

    private int $queueIndex = 0;

    /** @var list<array{messages: array<Message>, tools: array<Tool>, response: LlmResponse}> */
    public array $chatCalls = [];

    private ?\Throwable $chatException = null;

    /**
     * Queue a response to be returned on the next chat() call.
     * Responses are returned in FIFO order. If the queue is exhausted,
     * the last response is reused.
     */
    public function queueResponse(LlmResponse $response): self
    {
        $this->responseQueue[] = $response;

        return $this;
    }

    /**
     * Queue multiple responses at once.
     *
     * @param  list<LlmResponse>  $responses
     */
    public function queueResponses(array $responses): self
    {
        foreach ($responses as $response) {
            $this->responseQueue[] = $response;
        }

        return $this;
    }

    /**
     * Queue an exception to be thrown on the next chat() call.
     * After the exception is thrown, subsequent calls will use the response queue.
     */
    public function queueException(\Throwable $e): self
    {
        $this->chatException = $e;

        return $this;
    }

    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        if ($this->chatException !== null) {
            $e = $this->chatException;
            $this->chatException = null;

            throw $e;
        }

        $response = $this->dequeueResponse();

        $this->chatCalls[] = [
            'messages' => $messages,
            'tools' => $tools,
            'response' => $response,
        ];

        return $response;
    }

    public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): Generator
    {
        $response = $this->dequeueResponse();

        $this->chatCalls[] = [
            'messages' => $messages,
            'tools' => $tools,
            'response' => $response,
        ];

        // Yield text as a single delta, then stream_end
        if ($response->text !== '') {
            yield LlmStreamingEvent::textDelta($response->text);
        }

        foreach ($response->toolCalls as $toolCall) {
            yield LlmStreamingEvent::toolCall(
                $toolCall->id,
                $toolCall->name,
                is_string($toolCall->arguments) ? $toolCall->arguments : json_encode($toolCall->arguments, JSON_THROW_ON_ERROR),
            );
        }

        yield LlmStreamingEvent::streamEnd(
            $response->promptTokens,
            $response->completionTokens,
            $response->cacheWriteInputTokens,
            $response->cacheReadInputTokens,
            $response->thoughtTokens,
            $response->finishReason,
        );
    }

    public function supportsStreaming(): bool
    {
        return false;
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

    public function getReasoningEffort(): string
    {
        return $this->reasoningEffort;
    }

    public function setReasoningEffort(string $effort): void
    {
        $this->reasoningEffort = $effort;
    }

    /** Get the last system prompt that was set. */
    public function getLastSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /** Get the total number of chat() calls made. */
    public function getCallCount(): int
    {
        return count($this->chatCalls);
    }

    /** Get messages sent in a specific chat() call (0-indexed). */
    public function getMessagesForCall(int $index): array
    {
        return $this->chatCalls[$index]['messages'] ?? [];
    }

    /** Get the response returned by a specific chat() call (0-indexed). */
    public function getResponseForCall(int $index): ?LlmResponse
    {
        return $this->chatCalls[$index]['response'] ?? null;
    }

    private function dequeueResponse(): LlmResponse
    {
        if ($this->queueIndex < count($this->responseQueue)) {
            return $this->responseQueue[$this->queueIndex++];
        }

        // Reuse last response if queue is exhausted
        if (count($this->responseQueue) > 0) {
            return $this->responseQueue[count($this->responseQueue) - 1];
        }

        throw new \RuntimeException(
            'RecordingLlmClient: no responses queued. Call queueResponse() before chat().',
        );
    }
}
