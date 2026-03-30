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

class TokenEstimator
{
    private const CHARS_PER_TOKEN = 4;

    public static function estimate(string $text): int
    {
        return max(0, (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN));
    }

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
     * @param Message[] $messages
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
     * @param ToolCall[] $toolCalls
     */
    private static function estimateToolCalls(array $toolCalls): int
    {
        $total = 0;
        foreach ($toolCalls as $tc) {
            $total += self::estimate($tc->name . json_encode($tc->arguments()));
        }

        return $total;
    }

    /**
     * @param ToolResult[] $toolResults
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
