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

    public function showCompacting(): void;

    public function clearCompacting(): void;

    public function getCancellation(): ?Cancellation;

    public function streamChunk(string $text): void;

    public function streamComplete(): void;

    public function showToolCall(string $name, array $args): void;

    public function showToolResult(string $name, string $output, bool $success): void;

    /**
     * Ask the user for permission to execute a tool.
     *
     * @return string 'allow', 'deny', 'always', 'guardian', or 'prometheus'
     */
    public function askToolPermission(string $toolName, array $args): string;

    /**
     * Show a dimmed auto-approve indicator after a tool call line.
     */
    public function showAutoApproveIndicator(string $toolName): void;

    public function showNotice(string $message): void;

    public function showMode(string $label, string $color = ''): void;

    /**
     * Set the current permission mode display for the status bar.
     */
    public function setPermissionMode(string $label, string $color): void;

    /**
     * Consume a message queued during thinking.
     * Returns null if no message was queued.
     */
    public function consumeQueuedMessage(): ?string;

    public function clearConversation(): void;

    /**
     * Replay resumed conversation history as a condensed visual summary.
     *
     * @param array<int, \Prism\Prism\Contracts\Message> $messages
     */
    public function replayHistory(array $messages): void;

    public function showError(string $message): void;

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void;

    /**
     * Show the settings panel and block until the user closes it.
     *
     * @param array<string, mixed> $currentSettings
     * @return array<string, string> Changed settings (id => new value)
     */
    public function showSettings(array $currentSettings): array;

    /**
     * Show an interactive session picker. Returns selected session ID or null.
     *
     * @param array<array{value: string, label: string, description?: string}> $items
     */
    public function pickSession(array $items): ?string;

    /**
     * Show the plan approval dialog after a plan-mode run completes.
     *
     * @return array{permission: string, context: string}|null Settings on accept, null on dismiss
     */
    public function approvePlan(string $currentPermissionMode): ?array;

    /**
     * Ask the user a free-text question mid-run. Blocks until they respond.
     */
    public function askUser(string $question): string;

    /**
     * Present multiple-choice options to the user. Each choice can have a detail
     * block (ASCII art / mockup) shown when that option is highlighted.
     * A "Dismiss" option is always appended. Returns selected label or 'dismissed'.
     *
     * @param array<array{label: string, detail: string|null}> $choices
     */
    public function askChoice(string $question, array $choices): string;

    public function teardown(): void;
}
