<?php

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use OpenCompany\PrismRelay\Relay;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use OpenCompany\PrismRelay\Capabilities\ProviderCapabilities;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use Prism\Prism\ValueObjects\ToolCall;

class AsyncLlmClient implements LlmClientInterface
{
    private HttpClient $httpClient;

    private readonly Relay $relay;

    /** @var list<string> */
    private const OPENAI_COMPATIBLE_PROVIDERS = [
        'openai',
        'deepseek',
        'groq',
        'mistral',
        'xai',
        'openrouter',
        'perplexity',
        'ollama',
        'z',
        'z-api',
        'kimi',
        'kimi-coding',
        'mimo',
        'mimo-api',
    ];

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private string $systemPrompt,
        private ?int $maxTokens = null,
        private int|float|null $temperature = null,
        private string $provider = 'z',
        ?Relay $relay = null,
        private readonly ?RelayRegistry $registry = null,
    ) {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->relay = $relay ?? new Relay;
    }

    public static function supportsProvider(string $provider): bool
    {
        return in_array($provider, self::OPENAI_COMPATIBLE_PROVIDERS, true);
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }

    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        $cachePlan = $this->buildPromptCachePlan($messages);

        $allMessages = [...$cachePlan->systemPrompts, ...$cachePlan->messages];

        $payload = [
            'model' => $this->model,
            'messages' => $this->relay->mapOpenAiCompatibleMessages($this->provider, $allMessages),
        ];

        if ($cachePlan->providerOptions !== []) {
            $payload = array_merge($payload, $cachePlan->providerOptions);
        }

        if (! empty($tools)) {
            $payload['tools'] = $this->mapTools($tools);
            $payload['tool_choice'] = 'auto';
        }

        if ($this->maxTokens !== null) {
            $payload['max_tokens'] = $this->maxTokens;
        }

        if ($this->temperature !== null && $this->supportsTemperature()) {
            $payload['temperature'] = $this->temperature;
        }

        $request = new Request($this->baseUrl.'/chat/completions', 'POST');
        $request->setHeader('Authorization', 'Bearer '.$this->apiKey);
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));
        $request->setTransferTimeout(600);
        $request->setInactivityTimeout(300);

        // This suspends the fiber — Revolt event loop ticks freely
        $this->relay->beforeRequest($this->provider, $this->model);
        $response = $this->httpClient->request($request, $cancellation);

        return $this->parseResponse($response, $cancellation);
    }

    /**
     * @param  Message[]  $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(array $messages): array
    {
        $allMessages = $messages;

        if (trim($this->systemPrompt) !== '') {
            array_unshift($allMessages, new SystemMessage($this->systemPrompt));
        }

        return $this->relay->mapOpenAiCompatibleMessages($this->provider, $allMessages);
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

    private function supportsTemperature(): bool
    {
        if ($this->registry !== null) {
            return $this->registry->capabilities($this->provider)['temperature'];
        }

        return ProviderCapabilities::for($this->provider, $this->registry)->supportsTemperature();
    }

    private function parseResponse(Response $response, ?Cancellation $cancellation): LlmResponse
    {
        $status = $response->getStatus();

        if ($status !== 200) {
            $body = $response->getBody()->buffer($cancellation);
            $error = json_decode($body, true);
            $message = $error['error']['message'] ?? $body;

            if ($status === 429 || $status >= 500) {
                throw $this->relay->normalizeError(
                    new RetryableHttpException(
                        $status,
                        "API error ({$status}): {$message}",
                        $this->parseRetryAfter($response),
                    ),
                    $this->provider,
                    $this->model,
                );
            }

            throw $this->relay->normalizeError(
                new \RuntimeException("API error ({$status}): {$message}"),
                $this->provider,
                $this->model,
            );
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
            arguments: self::sanitizeJson($tc['function']['arguments'] ?? '{}'),
        ), $rawToolCalls);

        return new LlmResponse(
            text: $text,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
            cacheWriteInputTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            cacheReadInputTokens: (int) (($usage['prompt_tokens_details']['cached_tokens'] ?? null)
                ?? ($usage['input_tokens_details']['cached_tokens'] ?? null)
                ?? 0),
            thoughtTokens: (int) (($usage['completion_tokens_details']['reasoning_tokens'] ?? null)
                ?? ($usage['output_tokens_details']['reasoning_tokens'] ?? null)
                ?? 0),
        );
    }

    private function parseRetryAfter(Response $response): ?float
    {
        // Millisecond header (used by Anthropic, OpenAI)
        $ms = $response->getHeader('retry-after-ms');
        if ($ms !== null) {
            $parsed = (float) $ms;
            if ($parsed > 0) {
                return min($parsed / 1000.0, 300.0);
            }
        }

        // Standard retry-after: seconds or HTTP-date
        $header = $response->getHeader('retry-after');
        if ($header !== null) {
            if (is_numeric($header)) {
                return min(max((float) $header, 0.0), 300.0);
            }

            $timestamp = strtotime($header);
            if ($timestamp !== false) {
                return min(max((float) ($timestamp - time()), 0.0), 300.0);
            }
        }

        return null;
    }

    /**
     * @param  Message[]  $messages
     * @return array<int, array<string, mixed>>
     */
    private function buildPromptCachePlan(array $messages): \OpenCompany\PrismRelay\Caching\PromptCachePlan
    {
        return $this->relay->planPromptCache(
            provider: $this->provider,
            model: $this->model,
            systemPrompts: PromptFrameBuilder::splitSystemPrompt($this->systemPrompt),
            messages: $messages,
        );
    }

    /**
     * @param  Tool[]  $tools
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

    /**
     * Strip control characters (U+0000–U+001F except whitespace) from a JSON
     * string so json_decode() won't fail with JSON_ERROR_CTRL_CHAR.
     */
    private static function sanitizeJson(string $json): string
    {
        // preg_replace is faster than character-by-character; \x00-\x08,
        // \x0B (vertical tab), \x0C (form feed), \x0E-\x1F are the
        // non-whitespace C0 controls that trigger the error.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $json);
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
