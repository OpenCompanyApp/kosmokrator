<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Rough token estimation using a 4-chars-per-token heuristic.
 *
 * Used by ContextManager, ContextPruner, and OutputTruncator for fast local estimates
 * without requiring an external tokenizer. Assumes ~4 characters per token (reasonable for English/code).
 */
class TokenEstimator
{
    private const CHARS_PER_TOKEN = 4;

    /**
     * Estimate token count for a plain string using the chars-per-token ratio.
     */
    public static function estimate(string $text): int
    {
        return max(0, (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN));
    }

    /**
     * Estimate token count for a single Prism Message (dispatches by type).
     */
    public static function estimateMessage(Message $message): int
    {
        return match (true) {
            $message instanceof UserMessage => self::estimate($message->content),
            $message instanceof AssistantMessage => self::estimate($message->content)
                + self::estimateToolCalls($message->toolCalls),
            $message instanceof ToolResultMessage => self::estimateToolResults($message->toolResults),
            $message instanceof SystemMessage => self::estimate($message->content),
            default => 0,
        };
    }

    /**
     * Sum token estimates across an array of Prism Messages.
     *
     * @param  Message[]  $messages
     */
    public static function estimateMessages(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $total += self::estimateMessage($message);
        }

        return $total;
    }

    /**
     * Estimate tokens consumed by serialized tool call names + arguments.
     *
     * @param  ToolCall[]  $toolCalls
     */
    private static function estimateToolCalls(array $toolCalls): int
    {
        $total = 0;
        foreach ($toolCalls as $tc) {
            $total += self::estimate($tc->name.json_encode($tc->arguments()));
        }

        return $total;
    }

    /**
     * Estimate tokens consumed by serialized tool results.
     *
     * @param  ToolResult[]  $toolResults
     */
    private static function estimateToolResults(array $toolResults): int
    {
        $total = 0;
        foreach ($toolResults as $tr) {
            $result = is_string($tr->result) ? $tr->result : json_encode($tr->result);
            $total += self::estimate($result);
        }

        return $total;
    }
}
