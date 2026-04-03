<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Anti-corruption layer for Prism PHP message construction.
 *
 * Provides factory methods for creating Prism Message objects so the domain
 * layer does not directly depend on Prism constructors. If the Prism SDK
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
     * Determine the role string for a given Prism Message.
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
