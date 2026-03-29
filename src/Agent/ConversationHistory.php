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
