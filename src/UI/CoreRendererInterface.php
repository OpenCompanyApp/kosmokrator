<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;

/**
 * Core lifecycle and display methods for the rendering layer.
 */
interface CoreRendererInterface
{
    public function initialize(): void;

    public function renderIntro(bool $animated): void;

    public function prompt(): string;

    public function showUserMessage(string $text): void;

    public function setPhase(AgentPhase $phase): void;

    public function showThinking(): void;

    public function clearThinking(): void;

    public function showCompacting(): void;

    public function clearCompacting(): void;

    public function getCancellation(): ?Cancellation;

    public function streamChunk(string $text): void;

    public function streamComplete(): void;

    public function showError(string $message): void;

    public function showNotice(string $message): void;

    public function showMode(string $label, string $color = ''): void;

    /**
     * Set the current permission mode display for the status bar.
     */
    public function setPermissionMode(string $label, string $color): void;

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void;

    /**
     * Consume a message queued during thinking.
     * Returns null if no message was queued.
     */
    public function consumeQueuedMessage(): ?string;

    /**
     * Set a handler for immediate slash commands during agent execution.
     * The closure receives the raw input and returns true if handled.
     *
     * @param  (\Closure(string): bool)|null  $handler
     */
    public function setImmediateCommandHandler(?\Closure $handler): void;

    public function teardown(): void;

    public function showWelcome(): void;

    public function setTaskStore(TaskStore $store): void;

    public function refreshTaskBar(): void;

    public function playTheogony(): void;

    public function playPrometheus(): void;
}
