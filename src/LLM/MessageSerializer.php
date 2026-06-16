<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Messages\AssistantMessage;
use Kosmokrator\LLM\ValueObjects\Messages\SystemMessage;
use Kosmokrator\LLM\ValueObjects\Messages\ToolResultMessage;
use Kosmokrator\LLM\ValueObjects\Messages\UserMessage;
use Kosmokrator\LLM\ValueObjects\ToolCall;
use Kosmokrator\LLM\ValueObjects\ToolResult;

/**
 * Anti-corruption layer for native message serialization/deserialization.
 *
 * Centralizes the conversion between native Message objects and the plain-array
 * storage format used by MessageRepository. If native value objects change its message
 * structure, only this class needs updating.
 */
final class MessageSerializer
{
    /**
     * Decompose a native Message into a storable tuple.
     *
     * @return array{role: string, content: ?string, toolCalls: ?ToolCall[], toolResults: ?ToolResult[]}
     */
    public function decompose(Message $message): array
    {
        return match (true) {
            $message instanceof UserMessage => [
                'role' => 'user',
                'content' => $message->content,
                'toolCalls' => null,
                'toolResults' => null,
            ],
            $message instanceof AssistantMessage => [
                'role' => 'assistant',
                'content' => $message->content,
                'toolCalls' => $message->toolCalls !== [] ? $message->toolCalls : null,
                'toolResults' => null,
            ],
            $message instanceof ToolResultMessage => [
                'role' => 'tool_result',
                'content' => null,
                'toolCalls' => null,
                'toolResults' => $message->toolResults,
            ],
            $message instanceof SystemMessage => [
                'role' => 'system',
                'content' => $message->content,
                'toolCalls' => null,
                'toolResults' => null,
            ],
            default => [
                'role' => 'unknown',
                'content' => null,
                'toolCalls' => null,
                'toolResults' => null,
            ],
        };
    }

    /**
     * Serialize ToolCall objects to plain arrays suitable for JSON encoding.
     *
     * @param  ToolCall[]  $toolCalls
     * @return array<int, array{id: string, name: string, arguments: array<string, mixed>}>
     */
    public function serializeToolCalls(array $toolCalls): array
    {
        return array_map(fn (ToolCall $tc) => [
            'id' => $tc->id,
            'name' => $tc->name,
            'arguments' => ToolCallMapper::safeArguments($tc),
        ], $toolCalls);
    }

    /**
     * Serialize ToolResult objects to plain arrays suitable for JSON encoding.
     *
     * @param  ToolResult[]  $toolResults
     * @return array<int, array{toolCallId: string, toolName: string, args: array<string, mixed>, result: int|float|string|array|null}>
     */
    public function serializeToolResults(array $toolResults): array
    {
        return array_map(fn (ToolResult $tr) => [
            'toolCallId' => $tr->toolCallId,
            'toolName' => $tr->toolName,
            'args' => $tr->args,
            'result' => $tr->result,
        ], $toolResults);
    }

    /**
     * Reconstruct a native Message from a database row.
     *
     * @param  array<string, mixed>  $row  Database row with role, content, tool_calls, tool_results columns
     */
    public function deserializeMessage(array $row): ?Message
    {
        return match ($row['role']) {
            'user' => new UserMessage($row['content'] ?? ''),
            'assistant' => new AssistantMessage(
                content: $row['content'] ?? '',
                toolCalls: $row['tool_calls'] ? $this->deserializeToolCalls($row['tool_calls']) : [],
            ),
            'tool_result' => $row['tool_results']
                ? new ToolResultMessage($this->deserializeToolResults($row['tool_results']))
                : null,
            'system' => new SystemMessage($row['content'] ?? ''),
            default => null,
        };
    }

    /**
     * Parse a JSON string back into ToolCall objects.
     *
     * @return ToolCall[]
     */
    public function deserializeToolCalls(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }

        return array_map(fn (array $tc) => new ToolCall(
            id: $tc['id'],
            name: $tc['name'],
            arguments: $tc['arguments'],
        ), $data);
    }

    /**
     * Parse a JSON string back into ToolResult objects.
     *
     * @return ToolResult[]
     */
    public function deserializeToolResults(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }

        return array_map(fn (array $tr) => new ToolResult(
            toolCallId: $tr['toolCallId'],
            toolName: $tr['toolName'] ?? '',
            args: $tr['args'] ?? [],
            result: $tr['result'],
        ), $data);
    }
}
