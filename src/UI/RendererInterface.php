<?php

namespace Kosmokrator\UI;

use Amp\Cancellation;

interface RendererInterface
{
    public function initialize(): void;

    public function renderIntro(bool $animated): void;

    public function prompt(): string;

    public function showUserMessage(string $text): void;

    public function showThinking(): void;

    public function clearThinking(): void;

    public function getCancellation(): ?Cancellation;

    public function streamChunk(string $text): void;

    public function streamComplete(): void;

    public function showToolCall(string $name, array $args): void;

    public function showToolResult(string $name, string $output, bool $success): void;

    /**
     * Ask the user for permission to execute a tool.
     *
     * @return string 'allow', 'deny', or 'always'
     */
    public function askToolPermission(string $toolName, array $args): string;

    public function showNotice(string $message): void;

    /**
     * Consume a message queued during thinking.
     * Returns null if no message was queued.
     */
    public function consumeQueuedMessage(): ?string;

    public function showError(string $message): void;

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost): void;

    public function teardown(): void;
}
