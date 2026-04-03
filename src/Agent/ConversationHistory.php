<?php

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Manages the conversation message list: adding, compacting, pruning, and restoring messages.
 *
 * Wraps an array of Prism Message objects (UserMessage, AssistantMessage, ToolResultMessage, SystemMessage).
 * Supports compaction (replace old messages with a summary), pruning (replace tool results with placeholders),
 * superseding (mark cached reads as stale), and trimming (drop oldest turns as a last resort).
 */
class ConversationHistory
{
    /** @var array<int, Message> */
    private array $messages = [];

    /** Append a user message to the conversation. */
    public function addUser(string $content): void
    {
        $this->messages[] = new UserMessage($content);
    }

    /** Append an assistant message, optionally with tool calls. */
    public function addAssistant(string $content, array $toolCalls = []): void
    {
        $this->messages[] = new AssistantMessage($content, $toolCalls);
    }

    /**
     * @param  ToolResult[]  $results
     */
    public function addToolResults(array $results): void
    {
        $this->messages[] = new ToolResultMessage($results);
    }

    /**
     * Add a pre-built message (used when restoring from persistence).
     */
    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return array<int, Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    /**
     * Clear all messages except the last assistant message (the plan).
     * Gives maximum context space while preserving the plan text.
     */
    public function clearKeepingLast(): void
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i] instanceof AssistantMessage) {
                $this->messages = [$this->messages[$i]];

                return;
            }
        }

        // No assistant message found — clear everything
        $this->messages = [];
    }

    /**
     * Replace old messages with a summary, keeping the most recent turns.
     */
    public function compact(string $summary, int $keepRecent = 3): void
    {
        $total = count($this->messages);
        if ($total <= $keepRecent) {
            return;
        }

        $keepFrom = $this->findKeepBoundary($keepRecent);

        if ($keepFrom <= 0 || $keepFrom >= $total) {
            return;
        }

        $recent = array_slice($this->messages, $keepFrom);
        $this->messages = [new SystemMessage($summary), ...$recent];
    }

    /**
     * Replace the entire message list with the plan's pre-built replacement messages.
     */
    public function applyCompactionPlan(CompactionPlan $plan): void
    {
        if ($plan->isEmpty()) {
            return;
        }

        $this->messages = $plan->replacementMessages;
    }

    /**
     * Find the message index where the Nth-to-last user turn starts (used by ContextCompactor).
     */
    public function findKeepBoundary(int $keepRecent = 3): int
    {
        return self::findKeepBoundaryInMessages($this->messages, $keepRecent);
    }

    /**
     * Find the message index where the Nth-from-last user turn starts.
     * Used by compaction to determine which messages to summarize vs. keep.
     *
     * @param  Message[]  $messages  Flat message array to scan
     * @param  int  $keepRecent  Number of recent user turns to locate
     */
    public static function findKeepBoundaryInMessages(array $messages, int $keepRecent = 3): int
    {
        $total = count($messages);
        $keepFrom = $total;
        $turnsFound = 0;

        for ($i = $total - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof UserMessage) {
                $turnsFound++;
                if ($turnsFound >= $keepRecent) {
                    $keepFrom = $i;
                    break;
                }
            }
        }

        return $keepFrom;
    }

    /**
     * Collect the text of the last N user messages for memory-relevance queries.
     *
     * @param  int  $turns  Number of user turns to include
     * @param  int  $maxChars  Hard character limit on the concatenated result
     * @return string Concatenated user text (oldest first), truncated to $maxChars
     */
    public function latestUserContext(int $turns = 3, int $maxChars = 1200): string
    {
        $chunks = [];
        $userTurns = 0;

        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if (! $this->messages[$i] instanceof UserMessage) {
                continue;
            }

            $chunks[] = $this->messages[$i]->content;
            $userTurns++;

            if ($userTurns >= $turns) {
                break;
            }
        }

        if ($chunks === []) {
            return '';
        }

        $text = implode("\n", array_reverse($chunks));

        return mb_strlen($text) <= $maxChars ? $text : mb_substr($text, 0, $maxChars);
    }

    /**
     * Replace specific tool results with a placeholder string.
     *
     * @param  array<array{int, int, int}>  $targets  [[messageIndex, resultIndex, tokensSaved], ...]
     */
    public function pruneToolResults(array $targets, string $placeholder): void
    {
        foreach ($targets as [$msgIdx, $resultIdx, $_]) {
            $msg = $this->messages[$msgIdx] ?? null;
            if (! $msg instanceof ToolResultMessage) {
                continue;
            }

            $results = $msg->toolResults;
            if (! isset($results[$resultIdx])) {
                continue;
            }

            $old = $results[$resultIdx];
            $results[$resultIdx] = new ToolResult(
                toolCallId: $old->toolCallId,
                toolName: $old->toolName,
                args: $old->args,
                result: $placeholder,
            );

            $this->messages[$msgIdx] = new ToolResultMessage($results);
        }
    }

    /**
     * @param  array<array{0:int,1:int,2:string}>  $targets
     */
    public function pruneToolResultsWithPlaceholders(array $targets): void
    {
        foreach ($targets as [$msgIdx, $resultIdx, $placeholder]) {
            $msg = $this->messages[$msgIdx] ?? null;
            if (! $msg instanceof ToolResultMessage) {
                continue;
            }

            $results = $msg->toolResults;
            if (! isset($results[$resultIdx])) {
                continue;
            }

            $old = $results[$resultIdx];
            $results[$resultIdx] = new ToolResult(
                toolCallId: $old->toolCallId,
                toolName: $old->toolName,
                args: $old->args,
                result: $placeholder,
            );

            $this->messages[$msgIdx] = new ToolResultMessage($results);
        }
    }

    /**
     * Replace a single tool result with a custom placeholder string.
     */
    public function supersedeToolResult(int $messageIndex, int $resultIndex, string $placeholder): void
    {
        $msg = $this->messages[$messageIndex] ?? null;
        if (! $msg instanceof ToolResultMessage) {
            return;
        }

        $results = $msg->toolResults;
        if (! isset($results[$resultIndex])) {
            return;
        }

        $old = $results[$resultIndex];
        $results[$resultIndex] = new ToolResult(
            toolCallId: $old->toolCallId,
            toolName: $old->toolName,
            args: $old->args,
            result: $placeholder,
        );

        $this->messages[$messageIndex] = new ToolResultMessage($results);
    }

    /**
     * Remove the oldest complete turn from history (user message + all
     * assistant/tool messages up to the next user message).
     * Returns false if there aren't enough messages to trim.
     */
    public function trimOldest(): bool
    {
        // Need at least 3 messages to trim (keep at least the latest user message)
        if (count($this->messages) < 3) {
            return false;
        }

        // Skip leading SystemMessages (compaction summaries) — preserve them
        $startIdx = 0;
        while ($startIdx < count($this->messages) && $this->messages[$startIdx] instanceof SystemMessage) {
            $startIdx++;
        }

        if ($startIdx >= count($this->messages) - 1) {
            return false;
        }

        // Drop from the first non-system message until the next UserMessage (turn boundary)
        $removed = 0;
        array_splice($this->messages, $startIdx, 1);
        $removed++;

        while ($startIdx < count($this->messages) - 1 && ! ($this->messages[$startIdx] instanceof UserMessage)) {
            array_splice($this->messages, $startIdx, 1);
            $removed++;
        }

        return $removed !== 0;
    }
}
