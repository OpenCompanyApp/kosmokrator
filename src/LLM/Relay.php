<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Messages\AssistantMessage;
use Kosmokrator\LLM\ValueObjects\Messages\SystemMessage;
use Kosmokrator\LLM\ValueObjects\Messages\ToolResultMessage;
use Kosmokrator\LLM\ValueObjects\Messages\UserMessage;
use Kosmokrator\LLM\ValueObjects\ToolCall;

class Relay
{
    public function beforeRequest(string $provider, string $model): void {}

    public function normalizeError(\Throwable $e, string $provider, string $model): \Throwable
    {
        if ($e instanceof ProviderError) {
            return $e;
        }

        $retryable = $e instanceof RetryableHttpException
            || preg_match('/\b(429|5\d{2})\b/', $e->getMessage()) === 1;

        return new ProviderError($e->getMessage(), $provider, $model, $retryable, previous: $e);
    }

    /**
     * @param  list<SystemMessage>  $systemPrompts
     * @param  list<Message>  $messages
     * @param  list<mixed>  $tools
     */
    public function planPromptCache(string $provider, string $model, array $systemPrompts, array $messages, array $tools = []): PromptCachePlan
    {
        return PromptCachePlanner::plan($provider, $systemPrompts, $messages, tools: $tools);
    }

    /**
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function mapOpenAiCompatibleMessages(string $provider, array $messages): array
    {
        $mapped = [];
        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                $mapped[] = ['role' => 'system', 'content' => $message->content];
            } elseif ($message instanceof UserMessage) {
                $mapped[] = ['role' => 'user', 'content' => $message->content];
            } elseif ($message instanceof AssistantMessage) {
                $entry = ['role' => 'assistant', 'content' => $message->content];
                if ($message->toolCalls !== []) {
                    $entry['tool_calls'] = array_map(static fn (ToolCall $toolCall): array => [
                        'id' => $toolCall->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolCall->name,
                            'arguments' => is_string($toolCall->arguments)
                                ? $toolCall->arguments
                                : json_encode($toolCall->arguments, JSON_THROW_ON_ERROR),
                        ],
                    ], $message->toolCalls);
                }
                $mapped[] = $entry;
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $mapped[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result->toolCallId,
                        'content' => is_string($result->result) ? $result->result : json_encode($result->result, JSON_THROW_ON_ERROR),
                    ];
                }
            }
        }

        return $mapped;
    }
}
