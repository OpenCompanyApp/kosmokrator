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

    public function getTemperature(): int|float|null;

    public function setTemperature(int|float|null $temperature): void;

    public function getMaxTokens(): ?int;

    public function setMaxTokens(?int $maxTokens): void;
}
