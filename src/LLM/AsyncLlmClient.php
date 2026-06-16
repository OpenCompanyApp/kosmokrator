<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Kosmokrator\LLM\Codex\CodexOAuthService;
use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\Enums\FinishReason;
use Kosmokrator\LLM\ValueObjects\Messages\AssistantMessage;
use Kosmokrator\LLM\ValueObjects\Messages\SystemMessage;
use Kosmokrator\LLM\ValueObjects\Messages\ToolResultMessage;
use Kosmokrator\LLM\ValueObjects\Messages\UserMessage;
use Kosmokrator\LLM\ValueObjects\ToolCall;
use Kosmokrator\LLM\ValueObjects\ToolResult;

/**
 * Non-blocking HTTP client for OpenAI-compatible LLM providers using Amp's async runtime.
 *
 * Implements LlmClientInterface by sending raw HTTP requests via Amp\Http\Client,
 * bypassing the native HTTP. Used by RetryableLlmClient for providers that support
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

    /** @var list<string> */
    private const NATIVE_PROVIDERS = [
        'anthropic',
        'gemini',
        'minimax',
        'minimax-cn',
        'codex',
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
        private readonly ?RelayProviderRegistry $registry = null,
        private readonly ?CodexOAuthService $codexOAuth = null,
        private string $reasoningEffort = 'max',
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
        return in_array($provider, self::OPENAI_COMPATIBLE_PROVIDERS, true)
            || in_array($provider, self::NATIVE_PROVIDERS, true);
    }

    public function supportsStreaming(): bool
    {
        if ($this->registry !== null) {
            return $this->registry->capabilities($this->provider)['streaming'];
        }

        return ProviderCapabilities::defaults()[$this->provider]['streaming'] ?? true;
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }

    /**
     * Send a chat-completions request and return the parsed response.
     *
     * @param  Message[]  $messages  Conversation history as native Message objects
     * @param  Tool[]  $tools  Available tools for function calling
     * @param  Cancellation  $cancellation  Optional Amp cancellation token for aborting the request
     * @return LlmResponse Parsed response including text, tool calls, and token usage
     *
     * @throws RetryableHttpException On 429/5xx responses
     * @throws \RuntimeException On non-retryable HTTP errors
     */
    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        if ($this->wireProtocol() === 'codex') {
            return $this->collectStreamingResponse($messages, $tools, $cancellation);
        }

        $payload = $this->buildPayload($messages, $tools, streaming: false);
        $request = $this->buildRequest($payload, streaming: false);

        // This suspends the fiber — Revolt event loop ticks freely
        $this->relay->beforeRequest($this->provider, $this->model);
        $response = $this->httpClient->request($request, $cancellation);

        return $this->parseResponse($response, $cancellation);
    }

    /** @param Message[] $messages @param Tool[] $tools */
    private function collectStreamingResponse(array $messages, array $tools, ?Cancellation $cancellation): LlmResponse
    {
        $text = '';
        $reasoning = '';
        $toolCalls = [];
        $usage = [];
        $finishReason = FinishReason::Stop;

        foreach ($this->stream($messages, $tools, $cancellation) as $event) {
            if ($event->type === 'text_delta') {
                $text .= $event->delta;
            } elseif ($event->type === 'thinking_delta') {
                $reasoning .= $event->delta;
            } elseif ($event->type === 'tool_call') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($event->toolCall['id'] ?? ''),
                    name: (string) ($event->toolCall['name'] ?? ''),
                    arguments: (string) ($event->toolCall['arguments'] ?? '{}'),
                );
            } elseif ($event->type === 'stream_end') {
                $usage = $event->usage;
                $finishReason = $event->finishReason ?? $finishReason;
            }
        }

        return new LlmResponse(
            text: $text,
            finishReason: $toolCalls !== [] ? FinishReason::ToolCalls : $finishReason,
            toolCalls: $toolCalls,
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            cacheWriteInputTokens: (int) ($usage['cache_write_input_tokens'] ?? 0),
            cacheReadInputTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            thoughtTokens: (int) ($usage['thought_tokens'] ?? 0),
            reasoningContent: $reasoning,
        );
    }

    /**
     * Stream a chat-completion request via SSE, yielding incremental events.
     *
     * Sends the same payload as chat() but with stream=true, then reads the
     * SSE response line-by-line, yielding LlmStreamingEvent for each delta.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return \Generator<int, LlmStreamingEvent>
     */
    public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): \Generator
    {
        if ($this->wireProtocol() === 'anthropic') {
            yield from $this->streamAnthropic($messages, $tools, $cancellation);

            return;
        }

        if ($this->wireProtocol() === 'gemini') {
            yield from $this->streamGemini($messages, $tools, $cancellation);

            return;
        }

        if ($this->wireProtocol() === 'codex') {
            yield from $this->streamCodex($messages, $tools, $cancellation);

            return;
        }

        $payload = $this->buildPayload($messages, $tools, streaming: true);
        $request = $this->buildRequest($payload, streaming: true);

        $this->relay->beforeRequest($this->provider, $this->model);
        $response = $this->httpClient->request($request, $cancellation);

        $this->guardResponseStatus($response, $cancellation);

        // Parse SSE stream line-by-line
        $toolCallBuffers = [];
        $reasoningBuffer = '';
        $promptTokens = 0;
        $completionTokens = 0;
        $cacheWriteInputTokens = 0;
        $cacheReadInputTokens = 0;
        $thoughtTokens = 0;
        $lastFinishReason = null;

        $body = $response->getBody();
        $buffer = '';

        while (true) {
            $chunk = $body->read($cancellation);
            if ($chunk === null) {
                break;
            }

            $buffer .= $chunk;

            // Process complete lines from the buffer
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                $line = rtrim($line, "\r");

                // SSE lines starting with "data: " contain JSON payload
                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = trim(substr($line, 6));

                // End of stream
                if ($data === '[DONE]') {
                    // Flush any remaining reasoning content
                    if ($reasoningBuffer !== '') {
                        yield LlmStreamingEvent::thinkingDelta($reasoningBuffer);
                        $reasoningBuffer = '';
                    }

                    // Flush any accumulated tool calls (may have been missed if
                    // finish_reason arrived in a usage-only chunk without choices)
                    foreach ($toolCallBuffers as $tc) {
                        yield LlmStreamingEvent::toolCall(
                            $tc['id'],
                            $tc['name'],
                            self::sanitizeJson($tc['arguments'] ?: '{}'),
                        );
                    }

                    // Infer finish reason from accumulated state
                    $inferredReason = $toolCallBuffers !== []
                        ? FinishReason::ToolCalls
                        : ($lastFinishReason ?? FinishReason::Stop);

                    yield LlmStreamingEvent::streamEnd(
                        $promptTokens,
                        $completionTokens,
                        $cacheWriteInputTokens,
                        $cacheReadInputTokens,
                        $thoughtTokens,
                        $inferredReason,
                    );

                    return;
                }

                $json = json_decode($data, true);

                // Usage-only chunks (no choices) — extract usage data but don't skip
                if ($json !== null && ! isset($json['choices'][0])) {
                    if (isset($json['usage'])) {
                        $usage = $json['usage'];
                        $promptTokens = $usage['prompt_tokens'] ?? $promptTokens;
                        $completionTokens = $usage['completion_tokens'] ?? $completionTokens;
                        $cacheWriteInputTokens = (int) ($usage['cache_creation_input_tokens'] ?? $cacheWriteInputTokens);
                        $cacheReadInputTokens = (int) (($usage['prompt_tokens_details']['cached_tokens'] ?? null)
                            ?? ($usage['input_tokens_details']['cached_tokens'] ?? null)
                            ?? $cacheReadInputTokens);
                        $thoughtTokens = (int) (($usage['completion_tokens_details']['reasoning_tokens'] ?? null)
                            ?? ($usage['output_tokens_details']['reasoning_tokens'] ?? null)
                            ?? $thoughtTokens);
                    }

                    continue;
                }

                if ($json === null) {
                    continue;
                }

                $choice = $json['choices'][0];
                $delta = $choice['delta'] ?? [];

                // Extract usage from final chunk (some providers include it in the last SSE event)
                if (isset($json['usage'])) {
                    $usage = $json['usage'];
                    $promptTokens = $usage['prompt_tokens'] ?? $promptTokens;
                    $completionTokens = $usage['completion_tokens'] ?? $completionTokens;
                    $cacheWriteInputTokens = (int) ($usage['cache_creation_input_tokens'] ?? $cacheWriteInputTokens);
                    $cacheReadInputTokens = (int) (($usage['prompt_tokens_details']['cached_tokens'] ?? null)
                        ?? ($usage['input_tokens_details']['cached_tokens'] ?? null)
                        ?? $cacheReadInputTokens);
                    $thoughtTokens = (int) (($usage['completion_tokens_details']['reasoning_tokens'] ?? null)
                        ?? ($usage['output_tokens_details']['reasoning_tokens'] ?? null)
                        ?? $thoughtTokens);
                }

                // Text delta
                $content = $delta['content'] ?? null;
                if ($content !== null && $content !== '') {
                    // Flush accumulated reasoning before text starts
                    if ($reasoningBuffer !== '') {
                        yield LlmStreamingEvent::thinkingDelta($reasoningBuffer);
                        $reasoningBuffer = '';
                    }

                    yield LlmStreamingEvent::textDelta($content);
                }

                // Reasoning/thinking delta
                $reasoningDelta = (string) ($delta['reasoning_content'] ?? $delta['reasoning'] ?? '');
                if ($reasoningDelta !== '') {
                    $reasoningBuffer .= $reasoningDelta;
                    // Flush reasoning in line-based chunks for smoother display
                    while (($nl = strpos($reasoningBuffer, "\n")) !== false) {
                        yield LlmStreamingEvent::thinkingDelta(substr($reasoningBuffer, 0, $nl + 1));
                        $reasoningBuffer = substr($reasoningBuffer, $nl + 1);
                    }
                }

                // Tool call deltas
                $toolCallDeltas = $delta['tool_calls'] ?? [];
                foreach ($toolCallDeltas as $tc) {
                    $idx = $tc['index'] ?? 0;
                    if (! isset($toolCallBuffers[$idx])) {
                        $toolCallBuffers[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                    }
                    if (isset($tc['id'])) {
                        $toolCallBuffers[$idx]['id'] = $tc['id'];
                    }
                    if (isset($tc['function']['name'])) {
                        $toolCallBuffers[$idx]['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $toolCallBuffers[$idx]['arguments'] .= $tc['function']['arguments'];
                    }
                }

                // If finish_reason is set, yield tool calls and end
                $finishReason = $choice['finish_reason'] ?? null;
                if ($finishReason !== null && $finishReason !== '' && $finishReason !== 'null') {
                    $lastFinishReason = $this->mapFinishReason($finishReason);
                    // Flush any remaining reasoning content
                    if ($reasoningBuffer !== '') {
                        yield LlmStreamingEvent::thinkingDelta($reasoningBuffer);
                        $reasoningBuffer = '';
                    }

                    // Yield completed tool calls
                    foreach ($toolCallBuffers as $tc) {
                        yield LlmStreamingEvent::toolCall(
                            $tc['id'],
                            $tc['name'],
                            self::sanitizeJson($tc['arguments'] ?: '{}'),
                        );
                    }

                    yield LlmStreamingEvent::streamEnd(
                        $promptTokens,
                        $completionTokens,
                        $cacheWriteInputTokens,
                        $cacheReadInputTokens,
                        $thoughtTokens,
                        $lastFinishReason,
                    );

                    return;
                }
            }
        }

        // Stream ended without [DONE] — process any remaining buffer data
        if ($buffer !== '') {
            $line = rtrim($buffer, "\r\n");
            if (str_starts_with($line, 'data: ')) {
                $data = trim(substr($line, 6));
                if ($data !== '[DONE]') {
                    $json = json_decode($data, true);
                    if ($json !== null && isset($json['choices'][0])) {
                        $fr = $json['choices'][0]['finish_reason'] ?? null;
                        if ($fr !== null && $fr !== '' && $fr !== 'null') {
                            $lastFinishReason = $this->mapFinishReason($fr);
                        }
                    }
                }
            }
        }

        // Yield end with whatever we have
        if ($reasoningBuffer !== '') {
            yield LlmStreamingEvent::thinkingDelta($reasoningBuffer);
        }

        foreach ($toolCallBuffers as $tc) {
            yield LlmStreamingEvent::toolCall(
                $tc['id'],
                $tc['name'],
                self::sanitizeJson($tc['arguments'] ?: '{}'),
            );
        }

        $inferredReason = $toolCallBuffers !== []
            ? FinishReason::ToolCalls
            : ($lastFinishReason ?? FinishReason::Stop);

        yield LlmStreamingEvent::streamEnd(
            $promptTokens,
            $completionTokens,
            $cacheWriteInputTokens,
            $cacheReadInputTokens,
            $thoughtTokens,
            $inferredReason,
        );
    }

    /** @param Message[] $messages @param Tool[] $tools */
    private function streamAnthropic(array $messages, array $tools, ?Cancellation $cancellation): \Generator
    {
        $response = $this->httpClient->request($this->buildRequest(
            $this->buildPayload($messages, $tools, streaming: true),
            streaming: true,
        ), $cancellation);
        $this->guardResponseStatus($response, $cancellation);

        $toolBuffers = [];
        $currentIndex = null;
        $promptTokens = 0;
        $completionTokens = 0;
        $cacheWrite = 0;
        $cacheRead = 0;
        $finishReason = FinishReason::Stop;

        foreach ($this->sseJsonEvents($response, $cancellation) as $event) {
            $type = (string) ($event['type'] ?? '');
            if ($type === 'message_start') {
                $usage = $event['message']['usage'] ?? [];
                $promptTokens = (int) ($usage['input_tokens'] ?? $promptTokens);
                $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? $cacheWrite);
                $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $cacheRead);
            } elseif ($type === 'content_block_start') {
                $currentIndex = (int) ($event['index'] ?? 0);
                $block = $event['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $toolBuffers[$currentIndex] = [
                        'id' => (string) ($block['id'] ?? ''),
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => '',
                    ];
                }
            } elseif ($type === 'content_block_delta') {
                $delta = $event['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta' && isset($delta['text'])) {
                    yield LlmStreamingEvent::textDelta((string) $delta['text']);
                } elseif (in_array($delta['type'] ?? '', ['thinking_delta', 'signature_delta'], true) && isset($delta['thinking'])) {
                    yield LlmStreamingEvent::thinkingDelta((string) $delta['thinking']);
                } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                    $index = (int) ($event['index'] ?? $currentIndex ?? 0);
                    $toolBuffers[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                    $toolBuffers[$index]['arguments'] .= (string) ($delta['partial_json'] ?? '');
                }
            } elseif ($type === 'message_delta') {
                $usage = $event['usage'] ?? [];
                $completionTokens = (int) ($usage['output_tokens'] ?? $completionTokens);
                $finishReason = $this->mapFinishReason((string) ($event['delta']['stop_reason'] ?? ''));
            } elseif ($type === 'message_stop') {
                foreach ($toolBuffers as $tool) {
                    yield LlmStreamingEvent::toolCall($tool['id'], $tool['name'], self::sanitizeJson($tool['arguments'] ?: '{}'));
                }
                yield LlmStreamingEvent::streamEnd($promptTokens, $completionTokens, $cacheWrite, $cacheRead, finishReason: $finishReason);

                return;
            }
        }

        foreach ($toolBuffers as $tool) {
            yield LlmStreamingEvent::toolCall($tool['id'], $tool['name'], self::sanitizeJson($tool['arguments'] ?: '{}'));
        }
        yield LlmStreamingEvent::streamEnd($promptTokens, $completionTokens, $cacheWrite, $cacheRead, finishReason: $finishReason);
    }

    /** @param Message[] $messages @param Tool[] $tools */
    private function streamGemini(array $messages, array $tools, ?Cancellation $cancellation): \Generator
    {
        $response = $this->httpClient->request($this->buildRequest(
            $this->buildPayload($messages, $tools, streaming: true),
            streaming: true,
        ), $cancellation);
        $this->guardResponseStatus($response, $cancellation);

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheRead = 0;
        $thoughtTokens = 0;
        $toolCalls = [];
        $finishReason = FinishReason::Stop;

        foreach ($this->sseJsonEvents($response, $cancellation) as $event) {
            foreach (($event['candidates'] ?? []) as $candidate) {
                foreach (($candidate['content']['parts'] ?? []) as $index => $part) {
                    if (isset($part['text'])) {
                        yield LlmStreamingEvent::textDelta((string) $part['text']);
                    }
                    if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                        $call = $part['functionCall'];
                        $toolCalls[] = [
                            'id' => 'gemini_call_'.$index,
                            'name' => (string) ($call['name'] ?? ''),
                            'arguments' => json_encode($call['args'] ?? [], JSON_THROW_ON_ERROR),
                        ];
                    }
                }
                if (isset($candidate['finishReason'])) {
                    $finishReason = $this->mapFinishReason((string) $candidate['finishReason']);
                }
            }
            if (isset($event['usageMetadata'])) {
                $usage = $event['usageMetadata'];
                $promptTokens = (int) ($usage['promptTokenCount'] ?? $promptTokens);
                $completionTokens = (int) ($usage['candidatesTokenCount'] ?? $completionTokens);
                $cacheRead = (int) ($usage['cachedContentTokenCount'] ?? $cacheRead);
                $thoughtTokens = (int) ($usage['thoughtsTokenCount'] ?? $thoughtTokens);
            }
        }

        foreach ($toolCalls as $tool) {
            yield LlmStreamingEvent::toolCall($tool['id'], $tool['name'], $tool['arguments']);
        }
        yield LlmStreamingEvent::streamEnd($promptTokens, $completionTokens, cacheRead: $cacheRead, thoughtTokens: $thoughtTokens, finishReason: $finishReason);
    }

    /** @param Message[] $messages @param Tool[] $tools */
    private function streamCodex(array $messages, array $tools, ?Cancellation $cancellation): \Generator
    {
        $response = $this->httpClient->request($this->buildRequest(
            $this->buildPayload($messages, $tools, streaming: true),
            streaming: true,
        ), $cancellation);
        $this->guardResponseStatus($response, $cancellation);

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheRead = 0;
        $thoughtTokens = 0;
        $toolCalls = [];

        foreach ($this->sseJsonEvents($response, $cancellation) as $event) {
            $type = (string) ($event['type'] ?? '');
            if (in_array($type, ['response.output_text.delta', 'response.output_text.annotation.added'], true) && isset($event['delta'])) {
                yield LlmStreamingEvent::textDelta((string) $event['delta']);
            } elseif (str_contains($type, 'reasoning') && isset($event['delta'])) {
                yield LlmStreamingEvent::thinkingDelta((string) $event['delta']);
            } elseif ($type === 'response.output_item.done' && isset($event['item']) && is_array($event['item'])) {
                $item = $event['item'];
                if (in_array($item['type'] ?? '', ['function_call', 'tool_call'], true)) {
                    $toolCalls[] = [
                        'id' => (string) ($item['call_id'] ?? $item['id'] ?? 'codex_call_'.count($toolCalls)),
                        'name' => (string) ($item['name'] ?? ''),
                        'arguments' => self::sanitizeJson((string) ($item['arguments'] ?? '{}')),
                    ];
                }
            } elseif ($type === 'response.completed') {
                $responseData = $event['response'] ?? [];
                $usage = $responseData['usage'] ?? [];
                $promptTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? $promptTokens);
                $completionTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? $completionTokens);
                $cacheRead = (int) ($usage['input_tokens_details']['cached_tokens'] ?? $cacheRead);
                $thoughtTokens = (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? $thoughtTokens);
            }
        }

        foreach ($toolCalls as $tool) {
            yield LlmStreamingEvent::toolCall($tool['id'], $tool['name'], $tool['arguments']);
        }
        yield LlmStreamingEvent::streamEnd(
            $promptTokens,
            $completionTokens,
            cacheRead: $cacheRead,
            thoughtTokens: $thoughtTokens,
            finishReason: $toolCalls !== [] ? FinishReason::ToolCalls : FinishReason::Stop,
        );
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function sseJsonEvents(Response $response, ?Cancellation $cancellation): \Generator
    {
        $buffer = '';
        while (true) {
            $chunk = $response->getBody()->read($cancellation);
            if ($chunk === null) {
                break;
            }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }
                $json = json_decode($data, true);
                if (is_array($json)) {
                    yield $json;
                }
            }
        }
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

        return ProviderCapabilities::defaults()[$this->provider]['temperature'] ?? true;
    }

    /**
     * Check whether the current provider supports stream_options.include_usage.
     * Ollama and some local model servers hang when this is sent.
     */
    private function supportsStreamUsage(): bool
    {
        if ($this->registry !== null) {
            return $this->registry->capabilities($this->provider)['stream_usage'] ?? true;
        }

        return ProviderCapabilities::defaults()[$this->provider]['stream_usage'] ?? true;
    }

    /**
     * Build the JSON payload for a chat/completions request.
     *
     * Consolidates model, messages, tools, max_tokens, temperature, reasoning params,
     * provider options, and streaming flags into a single array.
     *
     * @param  Message[]  $messages  Conversation history
     * @param  Tool[]  $tools  Available tools
     * @param  bool  $streaming  Whether to enable SSE streaming
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $tools, bool $streaming): array
    {
        return match ($this->wireProtocol()) {
            'anthropic' => $this->buildAnthropicPayload($messages, $tools, $streaming),
            'gemini' => $this->buildGeminiPayload($messages, $tools, $streaming),
            'codex' => $this->buildCodexPayload($messages, $tools, $streaming),
            default => $this->buildOpenAiCompatiblePayload($messages, $tools, $streaming),
        };
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    private function buildOpenAiCompatiblePayload(array $messages, array $tools, bool $streaming): array
    {
        $mappedTools = $tools !== [] ? $this->mapTools($tools) : [];
        $cachePlan = $this->buildPromptCachePlan($messages, $mappedTools);
        $allMessages = [...$cachePlan->systemPrompts, ...$cachePlan->messages];

        $payload = [
            'model' => $this->requestModel(),
            'messages' => $this->relay->mapOpenAiCompatibleMessages($this->provider, $allMessages),
        ];

        if ($streaming) {
            $payload['stream'] = true;

            // Request usage data in stream — not supported by all providers (Ollama hangs)
            if ($this->supportsStreamUsage()) {
                $payload['stream_options'] = ['include_usage' => true];
            }
        }

        if ($cachePlan->providerOptions !== []) {
            $payload = array_merge($payload, $cachePlan->providerOptions);
        }

        if ($cachePlan->tools !== []) {
            $payload['tools'] = $cachePlan->tools;
            $payload['tool_choice'] = 'auto';
        }

        if ($this->maxTokens !== null) {
            $payload['max_tokens'] = $this->maxTokens;
        }

        if ($this->temperature !== null && $this->supportsTemperature()) {
            $payload['temperature'] = $this->temperature;
        }

        $reasoningParams = ReasoningStrategy::requestParams($this->provider, $this->reasoningEffort);
        if ($reasoningParams !== []) {
            $payload = array_merge($payload, $reasoningParams);
        }

        return $payload;
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    private function buildAnthropicPayload(array $messages, array $tools, bool $streaming): array
    {
        $cachePlan = $this->buildPromptCachePlan($messages, $tools);
        $payload = [
            'model' => $this->requestModel(),
            'system' => $this->mapAnthropicSystem($cachePlan->systemPrompts),
            'messages' => $this->mapAnthropicMessages($cachePlan->messages),
            'max_tokens' => $this->maxTokens ?? 8192,
        ];

        if ($streaming) {
            $payload['stream'] = true;
        }

        if ($cachePlan->tools !== []) {
            $payload['tools'] = array_map(fn (Tool $tool): array => $this->mapAnthropicTool($tool), $cachePlan->tools);
            $payload['tool_choice'] = ['type' => 'auto'];
        }

        if ($this->temperature !== null && $this->supportsTemperature()) {
            $payload['temperature'] = $this->temperature;
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    private function buildGeminiPayload(array $messages, array $tools, bool $streaming): array
    {
        $cachePlan = $this->buildPromptCachePlan($messages, $tools);
        $payload = $this->mapGeminiMessages($cachePlan->messages, $cachePlan->systemPrompts);

        $generationConfig = [];
        if ($this->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $this->maxTokens;
        }
        if ($this->temperature !== null && $this->supportsTemperature()) {
            $generationConfig['temperature'] = $this->temperature;
        }
        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        if ($cachePlan->tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (Tool $tool): array => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => (object) $this->mapGeminiSchemaProperties($tool->parametersAsArray()),
                        'required' => $tool->requiredParameters(),
                    ],
                ], $cachePlan->tools),
            ]];
        }

        return $payload;
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    private function buildCodexPayload(array $messages, array $tools, bool $streaming): array
    {
        $mappedTools = $tools !== [] ? $this->mapTools($tools) : [];
        $cachePlan = $this->buildPromptCachePlan($messages, $mappedTools);
        $input = array_values(array_filter(
            $this->relay->mapOpenAiCompatibleMessages($this->provider, $cachePlan->messages),
            static fn (array $message): bool => ($message['role'] ?? null) !== 'system',
        ));
        $instructions = implode("\n\n", array_map(
            static fn (SystemMessage $message): string => $message->content,
            $cachePlan->systemPrompts,
        ));

        $payload = [
            'model' => $this->requestModel(),
            'instructions' => $instructions !== '' ? $instructions : 'You are a helpful assistant.',
            'input' => $input,
            'stream' => true,
            'store' => false,
        ];

        if ($this->maxTokens !== null) {
            $payload['max_output_tokens'] = $this->maxTokens;
        }

        if ($cachePlan->tools !== []) {
            $payload['tools'] = $this->sanitizeCodexTools($cachePlan->tools);
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * Build an HTTP request for the chat/completions endpoint with standard headers and timeouts.
     *
     * @param  array<string, mixed>  $payload  JSON-serializable request body
     */
    private function buildRequest(array $payload, bool $streaming): Request
    {
        $request = new Request($this->endpointUrl($streaming), 'POST');
        if ($this->wireProtocol() === 'codex') {
            $token = $this->codexOAuth?->getAccessToken();
            if ($token === null || $token === '') {
                throw new \RuntimeException('Codex not authenticated. Run `kosmo codex:login`.');
            }

            $request->setHeader('Authorization', 'Bearer '.$token);
            $accountId = $this->codexOAuth->getAccountId();
            if ($accountId !== null && $accountId !== '') {
                $request->setHeader('ChatGPT-Account-Id', $accountId);
            }
            $request->setHeader('originator', 'kosmokrator');
            $request->setHeader('User-Agent', 'kosmokrator');
        } elseif ($this->wireProtocol() === 'gemini') {
            // Gemini uses the API key query parameter.
        } elseif ($this->wireProtocol() === 'anthropic') {
            $request->setHeader('anthropic-version', '2023-06-01');
            $request->setHeader('anthropic-beta', 'prompt-caching-2024-07-31');
            $request->setHeader('x-api-key', $this->apiKey);
        } else {
            $request->setHeader('Authorization', 'Bearer '.$this->apiKey);
        }

        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $request->setTransferTimeout(600);
        $request->setInactivityTimeout(300);

        return $request;
    }

    private function endpointUrl(bool $streaming): string
    {
        $base = rtrim($this->baseUrl, '/');

        return match ($this->wireProtocol()) {
            'anthropic' => $base.'/messages',
            'gemini' => sprintf(
                '%s/models/%s:%s?%s',
                $base,
                rawurlencode($this->model),
                $streaming ? 'streamGenerateContent' : 'generateContent',
                http_build_query(array_filter(['key' => $this->apiKey, 'alt' => $streaming ? 'sse' : null])),
            ),
            'codex' => $base.'/responses',
            default => $base.'/chat/completions',
        };
    }

    private function requestModel(): string
    {
        return $this->model;
    }

    private function wireProtocol(): string
    {
        $driver = $this->registry?->driver($this->provider) ?? $this->provider;

        return match ($driver) {
            'anthropic', 'anthropic-compatible', 'minimax', 'minimax-cn' => 'anthropic',
            'gemini' => 'gemini',
            'codex' => 'codex',
            default => 'openai',
        };
    }

    /** @param list<SystemMessage> $systemPrompts */
    private function mapAnthropicSystem(array $systemPrompts): array
    {
        return array_map(static fn (SystemMessage $message): array => array_filter([
            'type' => 'text',
            'text' => $message->content,
            'cache_control' => $message->providerOptions('cacheType') !== null
                ? ['type' => $message->providerOptions('cacheType')]
                : null,
        ]), $systemPrompts);
    }

    /** @param list<Message> $messages */
    private function mapAnthropicMessages(array $messages): array
    {
        $mapped = [];
        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                continue;
            }

            if ($message instanceof UserMessage) {
                $mapped[] = [
                    'role' => 'user',
                    'content' => [array_filter([
                        'type' => 'text',
                        'text' => $message->text(),
                        'cache_control' => $message->providerOptions('cacheType') !== null
                            ? ['type' => $message->providerOptions('cacheType')]
                            : null,
                    ])],
                ];
            } elseif ($message instanceof AssistantMessage) {
                $content = [];
                if ($message->content !== '') {
                    $content[] = array_filter([
                        'type' => 'text',
                        'text' => $message->content,
                        'cache_control' => $message->providerOptions('cacheType') !== null
                            ? ['type' => $message->providerOptions('cacheType')]
                            : null,
                    ]);
                }
                foreach ($message->toolCalls as $toolCall) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall->id,
                        'name' => $toolCall->name,
                        'input' => $toolCall->arguments(),
                    ];
                }
                $mapped[] = ['role' => 'assistant', 'content' => $content];
            } elseif ($message instanceof ToolResultMessage) {
                $total = count($message->toolResults);
                $mapped[] = [
                    'role' => 'user',
                    'content' => array_map(function (ToolResult $result, int $index) use ($message, $total): array {
                        return array_filter([
                            'type' => 'tool_result',
                            'tool_use_id' => $result->toolCallId,
                            'content' => is_string($result->result) ? $result->result : json_encode($result->result, JSON_THROW_ON_ERROR),
                            'cache_control' => $index === $total - 1 && $message->providerOptions('cacheType') !== null
                                ? ['type' => $message->providerOptions('cacheType')]
                                : null,
                        ]);
                    }, $message->toolResults, array_keys($message->toolResults)),
                ];
            }
        }

        return $mapped;
    }

    private function mapAnthropicTool(Tool $tool): array
    {
        return array_filter([
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) $tool->parametersAsArray(),
                'required' => $tool->requiredParameters(),
            ],
            'cache_control' => $tool->providerOptions('cacheType') !== null
                ? ['type' => $tool->providerOptions('cacheType')]
                : null,
        ]);
    }

    /**
     * @param  list<Message>  $messages
     * @param  list<SystemMessage>  $systemPrompts
     * @return array<string, mixed>
     */
    private function mapGeminiMessages(array $messages, array $systemPrompts): array
    {
        $payload = ['contents' => []];
        foreach ($systemPrompts as $systemPrompt) {
            if (! isset($payload['systemInstruction'])) {
                $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt->content]]];
            }
        }

        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                $payload['systemInstruction'] ??= ['parts' => [['text' => $message->content]]];
            } elseif ($message instanceof UserMessage) {
                $payload['contents'][] = ['role' => 'user', 'parts' => [['text' => $message->text()]]];
            } elseif ($message instanceof AssistantMessage) {
                $parts = [];
                if ($message->content !== '') {
                    $parts[] = ['text' => $message->content];
                }
                foreach ($message->toolCalls as $toolCall) {
                    $parts[] = ['functionCall' => ['name' => $toolCall->name, 'args' => $toolCall->arguments()]];
                }
                $payload['contents'][] = ['role' => 'model', 'parts' => $parts];
            } elseif ($message instanceof ToolResultMessage) {
                $parts = [];
                foreach ($message->toolResults as $result) {
                    $parts[] = [
                        'functionResponse' => [
                            'name' => $result->toolName,
                            'response' => [
                                'name' => $result->toolName,
                                'content' => is_string($result->result) ? $result->result : json_encode($result->result, JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ];
                }
                $payload['contents'][] = ['role' => 'user', 'parts' => $parts];
            }
        }

        return $payload;
    }

    /** @param array<string, array<string, mixed>> $properties */
    private function mapGeminiSchemaProperties(array $properties): array
    {
        return array_map(function (array $schema): array {
            $mapped = $schema;
            if (isset($mapped['type']) && is_string($mapped['type'])) {
                $mapped['type'] = strtoupper($mapped['type']);
                if ($mapped['type'] === 'OBJECT' && isset($mapped['properties']) && is_array($mapped['properties'])) {
                    $mapped['properties'] = $this->mapGeminiSchemaProperties($mapped['properties']);
                }
            }

            return $mapped;
        }, $properties);
    }

    /** @param list<array<string, mixed>> $tools */
    private function sanitizeCodexTools(array $tools): array
    {
        return array_map(static function (array $tool): array {
            unset($tool['cache_control']);
            if (isset($tool['function']) && is_array($tool['function'])) {
                unset($tool['function']['strict']);
            }

            return $tool;
        }, $tools);
    }

    /**
     * Throw on non-200 HTTP status, using RetryableHttpException for 429/5xx.
     *
     * Shared by both stream() and parseResponse() to avoid duplicating error handling.
     *
     * @throws RetryableHttpException On 429/5xx responses
     * @throws \RuntimeException On non-retryable HTTP errors
     */
    private function guardResponseStatus(Response $response, ?Cancellation $cancellation): void
    {
        $status = $response->getStatus();

        if ($status === 200) {
            return;
        }

        $body = $response->getBody()->buffer($cancellation);
        $message = $this->extractErrorMessage($body);

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

    private function extractErrorMessage(string $body): string
    {
        $error = json_decode($body, true);
        $message = is_array($error) ? ($error['error']['message'] ?? null) : null;

        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        $text = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\b(bearer\s+)[\w.\-]+/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/\b(sk-[a-zA-Z0-9]{8,})\b/', '[REDACTED_KEY]', $text) ?? $text;
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return 'Provider returned a non-JSON error response.';
        }

        if (mb_strlen($text) > 200) {
            return mb_substr($text, 0, 200).'...';
        }

        return $text;
    }

    /**
     * Decode the raw HTTP response into an LlmResponse value object.
     *
     * Guards against error status codes, then extracts token usage, tool calls,
     * and cache/reasoning token details from the response body.
     */
    private function parseResponse(Response $response, ?Cancellation $cancellation): LlmResponse
    {
        $this->guardResponseStatus($response, $cancellation);

        // Non-blocking body read
        $body = $response->getBody()->buffer($cancellation);
        // Sanitize response body: strip invalid UTF-8 bytes that some providers return
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return match ($this->wireProtocol()) {
            'anthropic' => $this->parseAnthropicResponse($data),
            'gemini' => $this->parseGeminiResponse($data),
            'codex' => $this->parseCodexResponse($data),
            default => $this->parseOpenAiResponse($data),
        };
    }

    /** @param array<string, mixed> $data */
    private function parseOpenAiResponse(array $data): LlmResponse
    {
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

    /** @param array<string, mixed> $data */
    private function parseAnthropicResponse(array $data): LlmResponse
    {
        $text = '';
        $reasoningContent = '';
        $toolCalls = [];
        foreach (($data['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'text') {
                $text .= (string) ($part['text'] ?? '');
            } elseif (in_array($part['type'] ?? '', ['thinking', 'redacted_thinking'], true)) {
                $reasoningContent .= (string) ($part['thinking'] ?? $part['data'] ?? '');
            } elseif (($part['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($part['id'] ?? ''),
                    name: (string) ($part['name'] ?? ''),
                    arguments: json_encode($part['input'] ?? [], JSON_THROW_ON_ERROR),
                );
            }
        }

        $usage = $data['usage'] ?? [];

        return new LlmResponse(
            text: $text,
            finishReason: $this->mapFinishReason((string) ($data['stop_reason'] ?? '')),
            toolCalls: $toolCalls,
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheWriteInputTokens: (int) (($usage['cache_creation_input_tokens'] ?? null) ?? 0),
            cacheReadInputTokens: (int) (($usage['cache_read_input_tokens'] ?? null) ?? 0),
            reasoningContent: $reasoningContent,
        );
    }

    /** @param array<string, mixed> $data */
    private function parseGeminiResponse(array $data): LlmResponse
    {
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        $text = '';
        $toolCalls = [];
        foreach ($parts as $index => $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $call = $part['functionCall'];
                $toolCalls[] = new ToolCall(
                    id: 'gemini_call_'.$index,
                    name: (string) ($call['name'] ?? ''),
                    arguments: json_encode($call['args'] ?? [], JSON_THROW_ON_ERROR),
                );
            }
        }
        $usage = $data['usageMetadata'] ?? [];

        return new LlmResponse(
            text: $text,
            finishReason: $this->mapFinishReason((string) ($candidate['finishReason'] ?? '')),
            toolCalls: $toolCalls,
            promptTokens: (int) ($usage['promptTokenCount'] ?? 0),
            completionTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            cacheReadInputTokens: (int) ($usage['cachedContentTokenCount'] ?? 0),
            thoughtTokens: (int) ($usage['thoughtsTokenCount'] ?? 0),
        );
    }

    /** @param array<string, mixed> $data */
    private function parseCodexResponse(array $data): LlmResponse
    {
        $text = '';
        $toolCalls = [];
        foreach (($data['output'] ?? []) as $index => $item) {
            if (($item['type'] ?? '') === 'message') {
                foreach (($item['content'] ?? []) as $part) {
                    if (in_array($part['type'] ?? '', ['output_text', 'text'], true)) {
                        $text .= (string) ($part['text'] ?? '');
                    }
                }
            } elseif (in_array($item['type'] ?? '', ['function_call', 'tool_call'], true)) {
                $toolCalls[] = new ToolCall(
                    id: (string) ($item['call_id'] ?? $item['id'] ?? 'codex_call_'.$index),
                    name: (string) ($item['name'] ?? ''),
                    arguments: self::sanitizeJson((string) ($item['arguments'] ?? '{}')),
                );
            }
        }
        $usage = $data['usage'] ?? [];

        return new LlmResponse(
            text: $text,
            finishReason: $toolCalls !== [] ? FinishReason::ToolCalls : FinishReason::Stop,
            toolCalls: $toolCalls,
            promptTokens: (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
            cacheReadInputTokens: (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            thoughtTokens: (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
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
    private function buildPromptCachePlan(array $messages, array $tools = []): PromptCachePlan
    {
        return $this->relay->planPromptCache(
            provider: $this->provider,
            model: $this->model,
            systemPrompts: PromptFrameBuilder::splitSystemPrompt($this->systemPrompt),
            messages: $messages,
            tools: $tools,
        );
    }

    /**
     * Convert LLM Tool objects into the OpenAI function-calling wire format.
     *
     * @param  Tool[]  $tools
     * @return array<int, array<string, mixed>>
     */
    private function mapTools(array $tools): array
    {
        return array_map(fn (Tool $tool): array => array_filter([
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
            'cache_control' => $tool->providerOptions('cacheType') !== null
                ? ['type' => $tool->providerOptions('cacheType')]
                : null,
        ]), $tools);
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
