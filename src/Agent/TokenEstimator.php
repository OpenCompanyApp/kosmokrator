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
 * Rough character-based token counter for estimating context usage.
 *
 * Uses a fixed chars-per-token ratio (≈4) to avoid pulling in a real
 * tokenizer. Good enough for budget-threshold checks against ContextBudget.
 * Not suitable for exact billing or API parameter sizing.
 *
 * @see ContextBudget Which consumes these estimates for threshold decisions
 */
class TokenEstimator
{
    /** Approximate characters per token for English/code text. */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Estimate the token count for a plain-text string.
     *
     * @param  string  $text  Raw text to estimate
     * @return int Estimated token count (always ≥ 0)
     */
    public static function estimate(string $text): int
    {
        return max(0, (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN));
    }

    /**
     * Estimate tokens consumed by a single Prism message.
     *
     * @param  Message  $message  Any supported Prism message type
     * @return int Estimated token count for this message
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
     * Sum token estimates across an array of messages.
     *
     * @param  Message[]  $messages  Ordered message list (e.g. from ConversationHistory)
     * @return int Total estimated tokens
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
     * Estimate tokens for a set of tool calls (name + serialized arguments).
     *
     * @param  ToolCall[]  $toolCalls
     */
    private static function estimateToolCalls(array $toolCalls): int
    {
        $total = 0;
        foreach ($toolCalls as $tc) {
            $total += self::estimate($tc->name.json_encode($tc->arguments(), JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return $total;
    }

    /**
     * Estimate tokens for tool result payloads.
     *
     * @param  ToolResult[]  $toolResults
     */
    private static function estimateToolResults(array $toolResults): int
    {
        $total = 0;
        foreach ($toolResults as $tr) {
            // Result may be a structured value; JSON-encode for estimation
            $result = is_string($tr->result) ? $tr->result : json_encode($tr->result, JSON_INVALID_UTF8_SUBSTITUTE);
            $total += self::estimate($result);
        }

        return $total;
    }
}
