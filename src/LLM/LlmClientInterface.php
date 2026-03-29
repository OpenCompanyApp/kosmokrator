<?php

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;

interface LlmClientInterface
{
    /**
     * @param Message[] $messages
     * @param Tool[] $tools
     */
    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse;

    public function setSystemPrompt(string $prompt): void;

    public function getProvider(): string;

    public function getModel(): string;
}
