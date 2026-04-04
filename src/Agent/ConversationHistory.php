<?php

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\ToolCallMapper;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Manages the ordered list of messages exchanged between user, assistant, and tools.
 *
 * Provides convenience builders for appending typed messages, compaction strategies
 * (compact, trimOldest, applyCompactionPlan), and surgical pruning of tool results
 * to stay within the limits defined by ContextBudget.
 *
 * @see ContextBudget For the thresholds that trigger compaction
 * @see CompactionPlan For LLM-produced compaction results
 */
class ConversationHistory
{
    /** @var array<int, Message> */
    private array $messages = [];

    /**
     * Append a user message to the history.
     *
     * @param  string  $content  The user's raw text input
     */
    public function addUser(string $content): void
    {
        $this->messages[] = new UserMessage($content);
    }

    /**
     * Append an assistant message, optionally carrying tool calls.
     *
     * @param  string  $content  The assistant's text response
     * @param  array  $toolCalls  Structured tool-call payloads from the LLM
     */
    public function addAssistant(string $content, array $toolCalls = []): void
    {
        $this->messages[] = new AssistantMessage($content, $toolCalls);
    }

    /**
     * Append a tool-result message wrapping one or more tool outputs.
     *
     * @param  ToolResult[]  $results  Results returned by executed tools
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
        // Scan backwards so we keep the most recent assistant message
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
     *
     * @param  string  $summary  Compacted summary text, inserted as a SystemMessage
     * @param  int  $keepRecent  Number of recent user turns to preserve after the summary
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

        // Prepend summary as a SystemMessage so the LLM treats it as context, not a turn
        $recent = array_slice($this->messages, $keepFrom);
        $this->messages = [new SystemMessage($summary), ...$recent];
    }

    /**
     * Replace the entire message list with the contents of a CompactionPlan.
     *
     * @param  CompactionPlan  $plan  The compaction result to apply
     */
    public function applyCompactionPlan(CompactionPlan $plan): void
    {
        if ($plan->isEmpty()) {
            return;
        }

        $this->messages = $plan->replacementMessages;
    }

    /**
     * Find the message index where the Nth-most-recent user turn begins.
     *
     * @param  int  $keepRecent  Number of recent user turns to count back
     * @return int Index of the oldest UserMessage to keep
     */
    public function findKeepBoundary(int $keepRecent = 3): int
    {
        return self::findKeepBoundaryInMessages($this->messages, $keepRecent);
    }

    /**
     * Stateless version of findKeepBoundary() that works on any message array.
     *
     * @param  Message[]  $messages
     * @param  int  $keepRecent  Number of recent user turns to count back
     * @return int Index of the oldest UserMessage in the keep window
     */
    public static function findKeepBoundaryInMessages(array $messages, int $keepRecent = 3): int
    {
        $total = count($messages);
        $keepFrom = $total;
        $turnsFound = 0;

        // Walk backwards counting UserMessages to find the turn boundary
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
     * Collect the text of the most recent user turns for a quick context snippet.
     *
     * @param  int  $turns  Number of user turns to include
     * @param  int  $maxChars  Maximum character length of the result
     * @return string Concatenated user messages, truncated to maxChars
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

        // Reverse to restore chronological order
        $text = implode("\n", array_reverse($chunks));

        return mb_strlen($text) <= $maxChars ? $text : mb_substr($text, 0, $maxChars);
    }

    /**
     * Replace specific tool results with a placeholder string.
     *
     * @param  array<array{int, int, int}>  $targets  [[messageIndex, resultIndex, tokensSaved], ...]
     * @param  string  $placeholder  Text to substitute into each targeted result
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

            $results[$resultIdx] = ToolCallMapper::withReplacedContent($results[$resultIdx], $placeholder);
            $this->messages[$msgIdx] = new ToolResultMessage($results);
        }
    }

    /**
     * Replace specific tool results, each with its own placeholder string.
     *
     * @param  array<array{0:int,1:int,2:string}>  $targets  [[messageIndex, resultIndex, placeholder], ...]
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

            $results[$resultIdx] = ToolCallMapper::withReplacedContent($results[$resultIdx], $placeholder);
            $this->messages[$msgIdx] = new ToolResultMessage($results);
        }
    }

    /**
     * Replace a single tool result with a custom placeholder string.
     *
     * @param  int  $messageIndex  Index of the ToolResultMessage in the history
     * @param  int  $resultIndex  Index of the individual result within that message
     * @param  string  $placeholder  Replacement text
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

        $results[$resultIndex] = ToolCallMapper::withReplacedContent($results[$resultIndex], $placeholder);
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

        return $removed > 0;
    }
}
