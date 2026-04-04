<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use OpenCompany\PrismRelay\Caching\PromptCachePlan;
use OpenCompany\PrismRelay\Capabilities\ProviderCapabilities;
use OpenCompany\PrismRelay\Reasoning\ReasoningStrategy;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use OpenCompany\PrismRelay\Relay;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Non-blocking HTTP client for OpenAI-compatible LLM providers using Amp's async runtime.
 *
 * Implements LlmClientInterface by sending raw HTTP requests via Amp\Http\Client,
 * bypassing the Prism SDK. Used by RetryableLlmClient for providers that support
 * the OpenAI chat/completions format directly.
 */
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
        'stepfun',
        'stepfun-plan',
    ];

    /**
     * @param  string  $apiKey  Provider API key
     * @param  string  $baseUrl  Base URL for the provider's chat completions endpoint
     * @param  string  $model  Model identifier (e.g. "gpt-4o", "deepseek-chat")
     * @param  string  $systemPrompt  System prompt prepended to every conversation
     */
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
        private string $reasoningEffort = 'off',
    ) {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->relay = $relay ?? new Relay;
    }

    /**
     * Whether this client can handle the given provider via the OpenAI-compatible protocol.
     *
     * @param  string  $provider  Provider identifier (e.g. "openai", "deepseek")
     */
    public static function supportsProvider(string $provider): bool
    {
        return in_array($provider, self::OPENAI_COMPATIBLE_PROVIDERS, true);
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }

    /**
     * Send a chat-completions request and return the parsed response.
     *
     * @param  Message[]  $messages  Conversation history as Prism Message objects
     * @param  Tool[]  $tools  Available tools for function calling
     * @param  Cancellation  $cancellation  Optional Amp cancellation token for aborting the request
     * @return LlmResponse Parsed response including text, tool calls, and token usage
     *
     * @throws RetryableHttpException On 429/5xx responses
     * @throws \RuntimeException On non-retryable HTTP errors
     */
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

        if ($this->reasoningEffort !== 'off') {
            $reasoningParams = ReasoningStrategy::requestParams($this->provider, $this->reasoningEffort);
            if ($reasoningParams !== []) {
                $payload = array_merge($payload, $reasoningParams);
            }
        }

        $request = new Request($this->baseUrl.'/chat/completions', 'POST');
        $request->setHeader('Authorization', 'Bearer '.$this->apiKey);
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $request->setTransferTimeout(600);
        $request->setInactivityTimeout(300);

        // This suspends the fiber — Revolt event loop ticks freely
        $this->relay->beforeRequest($this->provider, $this->model);
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

    public function getReasoningEffort(): string
    {
        return $this->reasoningEffort;
    }

    public function setReasoningEffort(string $effort): void
    {
        $this->reasoningEffort = $effort;
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

    /**
     * Decode the raw HTTP response into an LlmResponse value object.
     *
     * Handles error status codes by throwing RetryableHttpException (429/5xx) or RuntimeException.
     * Extracts token usage, tool calls, and cache/reasoning token details from the response body.
     */
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
        // Sanitize response body: strip invalid UTF-8 bytes that some providers return
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $choice = $data['choices'][0] ?? [];
        $msg = $choice['message'] ?? [];
        $usage = $data['usage'] ?? [];

        $text = $msg['content'] ?? '';
        $reasoningContent = ReasoningStrategy::extractReasoning($this->provider, $msg);
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
            reasoningContent: $reasoningContent,
        );
    }

    /**
     * Parse Retry-After header from the HTTP response (supports seconds, milliseconds, and HTTP-date formats).
     *
     * @return float|null Retry delay in seconds, capped at 300s, or null if no header present
     */
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
     * Build a PromptCachePlan that splits the system prompt for provider-specific prompt caching.
     *
     * @param  Message[]  $messages  Conversation history
     */
    private function buildPromptCachePlan(array $messages): PromptCachePlan
    {
        return $this->relay->planPromptCache(
            provider: $this->provider,
            model: $this->model,
            systemPrompts: PromptFrameBuilder::splitSystemPrompt($this->systemPrompt),
            messages: $messages,
        );
    }

    /**
     * Convert Prism Tool objects into the OpenAI function-calling wire format.
     *
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
                    'properties' => (object) $tool->parametersAsArray(),
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
