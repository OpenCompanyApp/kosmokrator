<?php

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class ConversationHistory
{
    /** @var array<int, \Prism\Prism\Contracts\Message> */
    private array $messages = [];

    public function addUser(string $content): void
    {
        $this->messages[] = new UserMessage($content);
    }

    public function addAssistant(string $content, array $toolCalls = []): void
    {
        $this->messages[] = new AssistantMessage($content, $toolCalls);
    }

    /**
     * @param ToolResult[] $results
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
     * @return array<int, \Prism\Prism\Contracts\Message>
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
     * Replace old messages with a summary, keeping the most recent turns.
     */
    public function compact(string $summary, int $keepRecent = 3): void
    {
        $total = count($this->messages);
        if ($total <= $keepRecent) {
            return;
        }

        // Count recent complete turns from the end
        $keepFrom = $total;
        $turnsFound = 0;
        for ($i = $total - 1; $i >= 0; $i--) {
            if ($this->messages[$i] instanceof UserMessage) {
                $turnsFound++;
                if ($turnsFound >= $keepRecent) {
                    $keepFrom = $i;
                    break;
                }
            }
        }

        if ($keepFrom <= 0) {
            return;
        }

        $recent = array_slice($this->messages, $keepFrom);
        $this->messages = [new SystemMessage($summary), ...$recent];
    }

    /**
     * Replace specific tool results with a placeholder string.
     *
     * @param array<array{int, int, int}> $targets [[messageIndex, resultIndex, tokensSaved], ...]
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

        // Drop the first message (should be a UserMessage)
        array_shift($this->messages);
        $removed = 1;

        // Keep dropping until we hit the next UserMessage (turn boundary)
        while (count($this->messages) > 1 && ! ($this->messages[0] instanceof UserMessage)) {
            array_shift($this->messages);
            $removed++;
        }

        $this->messages = array_values($this->messages);

        return $removed > 0;
    }
}
