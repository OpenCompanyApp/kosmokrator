<?php

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

class AsyncLlmClient implements LlmClientInterface
{
    private HttpClient $httpClient;

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private string $systemPrompt,
        private ?int $maxTokens = null,
        private int|float|null $temperature = null,
        private string $provider = 'z',
    ) {
        $this->httpClient = HttpClientBuilder::buildDefault();
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }

    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->mapMessages($messages),
        ];

        if (! empty($tools)) {
            $payload['tools'] = $this->mapTools($tools);
            $payload['tool_choice'] = 'auto';
        }

        if ($this->maxTokens !== null) {
            $payload['max_tokens'] = $this->maxTokens;
        }

        if ($this->temperature !== null) {
            $payload['temperature'] = $this->temperature;
        }

        $request = new Request($this->baseUrl . '/chat/completions', 'POST');
        $request->setHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));
        $request->setTransferTimeout(600);
        $request->setInactivityTimeout(300);

        // This suspends the fiber — Revolt event loop ticks freely
        $response = $this->httpClient->request($request, $cancellation);

        return $this->parseResponse($response, $cancellation);
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

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
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

    private function parseResponse(Response $response, ?Cancellation $cancellation): LlmResponse
    {
        $status = $response->getStatus();

        if ($status !== 200) {
            $body = $response->getBody()->buffer($cancellation);
            $error = json_decode($body, true);
            $message = $error['error']['message'] ?? $body;

            throw new \RuntimeException("API error ({$status}): {$message}");
        }

        // Non-blocking body read
        $body = $response->getBody()->buffer($cancellation);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $choice = $data['choices'][0] ?? [];
        $msg = $choice['message'] ?? [];
        $usage = $data['usage'] ?? [];

        $text = $msg['content'] ?? '';
        $rawToolCalls = $msg['tool_calls'] ?? [];
        $finishReason = $this->mapFinishReason($choice['finish_reason'] ?? '');

        $toolCalls = array_map(fn (array $tc): ToolCall => new ToolCall(
            id: $tc['id'],
            name: $tc['function']['name'],
            arguments: $tc['function']['arguments'] ?? '{}',
        ), $rawToolCalls);

        return new LlmResponse(
            text: $text,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
        );
    }

    /**
     * @param Message[] $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(array $messages): array
    {
        $mapped = [];

        // Prepend system prompt
        if ($this->systemPrompt !== '') {
            $mapped[] = ['role' => 'system', 'content' => $this->systemPrompt];
        }

        foreach ($messages as $message) {
            match ($message::class) {
                SystemMessage::class => $mapped[] = [
                    'role' => 'system',
                    'content' => $message->content,
                ],
                UserMessage::class => $mapped[] = [
                    'role' => 'user',
                    'content' => $message->text(),
                ],
                AssistantMessage::class => $this->mapAssistantMessage($message, $mapped),
                ToolResultMessage::class => $this->mapToolResultMessage($message, $mapped),
                default => throw new \InvalidArgumentException('Unsupported message type: ' . $message::class),
            };
        }

        return $mapped;
    }

    private function mapAssistantMessage(AssistantMessage $message, array &$mapped): void
    {
        $entry = [
            'role' => 'assistant',
            'content' => $message->content,
        ];

        // Include tool_calls so subsequent tool result messages are properly linked
        if (! empty($message->toolCalls)) {
            $entry['tool_calls'] = array_map(fn (ToolCall $tc) => [
                'id' => $tc->id,
                'type' => 'function',
                'function' => [
                    'name' => $tc->name,
                    'arguments' => is_string($tc->arguments) ? $tc->arguments : json_encode($tc->arguments),
                ],
            ], $message->toolCalls);
        }

        $mapped[] = $entry;
    }

    private function mapToolResultMessage(ToolResultMessage $message, array &$mapped): void
    {
        foreach ($message->toolResults as $result) {
            $mapped[] = [
                'role' => 'tool',
                'tool_call_id' => $result->toolCallId,
                'content' => is_string($result->result) ? $result->result : json_encode($result->result),
            ];
        }
    }

    /**
     * @param Tool[] $tools
     * @return array<int, array<string, mixed>>
     */
    private function mapTools(array $tools): array
    {
        return array_map(fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $tool->parametersAsArray(),
                    'required' => $tool->requiredParameters(),
                ],
            ],
        ], $tools);
    }

    private function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }
}
