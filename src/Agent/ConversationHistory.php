<?php

namespace Kosmokrator\Agent;

use Prism\Prism\ValueObjects\Messages\AssistantMessage;
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
     * @return array<int, \Prism\Prism\Contracts\Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    /**
     * Remove the oldest user + assistant/tool turn from history.
     * Returns false if there aren't enough messages to trim.
     */
    public function trimOldest(): bool
    {
        // Need at least 3 messages to trim (keep at least the latest user message)
        if (count($this->messages) < 3) {
            return false;
        }

        // Drop messages from the front until we've removed a complete user turn
        // (user message + any following assistant/tool messages before the next user message)
        $removed = 0;
        while (count($this->messages) > 1) {
            $first = $this->messages[0];
            array_shift($this->messages);
            $removed++;

            // Stop after removing the assistant reply (complete turn removed)
            if ($first instanceof AssistantMessage && $removed > 1) {
                break;
            }
            // Also stop if next message is a new user message (turn boundary)
            if ($removed > 1 && isset($this->messages[0]) && $this->messages[0] instanceof UserMessage) {
                break;
            }
        }

        $this->messages = array_values($this->messages);

        return $removed > 0;
    }
}
