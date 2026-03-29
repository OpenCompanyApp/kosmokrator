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
}
