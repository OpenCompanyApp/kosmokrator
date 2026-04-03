<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;

/**
 * No-op renderer for headless subagents.
 * All display methods are silent. Permission prompts auto-approve.
 * Passes through the parent's cancellation token so Ctrl+C cascades.
 */
class NullRenderer implements RendererInterface
{
    /**
     * @param  (\Closure(): ?Cancellation)|Cancellation|null  $cancellation  Lazy closure or direct token
     */
    public function __construct(
        private readonly \Closure|Cancellation|null $cancellation = null,
    ) {}

    public function initialize(): void {}

    public function renderIntro(bool $animated): void {}

    public function prompt(): string
    {
        return '';
    }

    public function showUserMessage(string $text): void {}

    public function setPhase(AgentPhase $phase): void {}

    public function showThinking(): void {}

    public function clearThinking(): void {}

    public function showCompacting(): void {}

    public function clearCompacting(): void {}

    public function getCancellation(): ?Cancellation
    {
        if ($this->cancellation instanceof \Closure) {
            return ($this->cancellation)();
        }

        return $this->cancellation;
    }

    public function streamChunk(string $text): void {}

    public function streamComplete(): void {}

    public function showToolCall(string $name, array $args): void {}

    public function showToolResult(string $name, string $output, bool $success): void {}

    public function askToolPermission(string $toolName, array $args): string
    {
        return 'allow';
    }

    public function showAutoApproveIndicator(string $toolName): void {}

    public function showToolExecuting(string $name): void {}

    public function updateToolExecuting(string $output): void {}

    public function clearToolExecuting(): void {}

    public function showNotice(string $message): void {}

    public function showMode(string $label, string $color = ''): void {}

    public function setPermissionMode(string $label, string $color): void {}

    public function consumeQueuedMessage(): ?string
    {
        return null;
    }

    public function setImmediateCommandHandler(?\Closure $handler): void {}

    public function clearConversation(): void {}

    public function replayHistory(array $messages): void {}

    public function showError(string $message): void {}

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void {}

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void {}

    public function showSettings(array $currentSettings): array
    {
        return [];
    }

    public function pickSession(array $items): ?string
    {
        return null;
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        return null;
    }

    public function askUser(string $question): string
    {
        return '';
    }

    public function askChoice(string $question, array $choices): string
    {
        return 'dismissed';
    }

    public function showSubagentStatus(array $stats): void {}

    public function clearSubagentStatus(): void {}

    public function showSubagentRunning(array $entries): void {}

    public function showSubagentSpawn(array $entries): void {}

    public function showSubagentBatch(array $entries): void {}

    public function refreshSubagentTree(array $tree): void {}

    public function setAgentTreeProvider(?\Closure $provider): void {}

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void {}

    public function teardown(): void {}

    public function showWelcome(): void {}

    public function setTaskStore(TaskStore $store): void {}

    public function refreshTaskBar(): void {}

    public function playTheogony(): void {}

    public function playPrometheus(): void {}
}
