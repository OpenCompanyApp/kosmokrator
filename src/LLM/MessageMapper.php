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
 * Anti-corruption layer for native message construction.
 *
 * Provides factory methods for creating native Message objects so the domain
 * layer does not directly depend on message constructors. If the native HTTP
 * changes constructor signatures, only this class needs updating.
 */
final class MessageMapper
{
    public static function userMessage(string $content): UserMessage
    {
        return new UserMessage($content);
    }

    /**
     * @param  ToolCall[]  $toolCalls
     */
    public static function assistantMessage(string $content, array $toolCalls = []): AssistantMessage
    {
        return new AssistantMessage($content, $toolCalls);
    }

    public static function systemMessage(string $content): SystemMessage
    {
        return new SystemMessage($content);
    }

    /**
     * @param  ToolResult[]  $results
     */
    public static function toolResultMessage(array $results): ToolResultMessage
    {
        return new ToolResultMessage($results);
    }

    /**
     * Determine the role string for a given native Message.
     */
    public static function roleOf(Message $message): string
    {
        return match (true) {
            $message instanceof UserMessage => 'user',
            $message instanceof AssistantMessage => 'assistant',
            $message instanceof ToolResultMessage => 'tool',
            $message instanceof SystemMessage => 'system',
            default => 'unknown',
        };
    }

    /**
     * Check if a message is of a specific role type.
     *
     * @param  class-string<Message>  $type
     */
    public static function isType(Message $message, string $type): bool
    {
        return $message instanceof $type;
    }
}
