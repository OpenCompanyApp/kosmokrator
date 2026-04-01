<?php

namespace Kosmokrator\UI;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiRenderer;
use Kosmokrator\UI\Tui\TuiRenderer;
use Symfony\Component\Tui\Tui;

class UIManager implements RendererInterface
{
    private RendererInterface $renderer;

    public function __construct(string $preference = 'auto')
    {
        $this->renderer = $this->resolveRenderer($preference);
    }

    public function setTaskStore(TaskStore $store): void
    {
        $this->renderer->setTaskStore($store);
    }

    public function refreshTaskBar(): void
    {
        $this->renderer->refreshTaskBar();
    }

    public function getActiveRenderer(): string
    {
        return match (true) {
            $this->renderer instanceof TuiRenderer => 'tui',
            $this->renderer instanceof AnsiRenderer => 'ansi',
            default => get_class($this->renderer),
        };
    }

    public function initialize(): void
    {
        $this->renderer->initialize();
    }

    public function renderIntro(bool $animated): void
    {
        $this->renderer->renderIntro($animated);
    }

    public function prompt(): string
    {
        return $this->renderer->prompt();
    }

    public function showUserMessage(string $text): void
    {
        $this->renderer->showUserMessage($text);
    }

    public function setPhase(AgentPhase $phase): void
    {
        $this->renderer->setPhase($phase);
    }

    public function showThinking(): void
    {
        $this->renderer->showThinking();
    }

    public function clearThinking(): void
    {
        $this->renderer->clearThinking();
    }

    public function showCompacting(): void
    {
        $this->renderer->showCompacting();
    }

    public function clearCompacting(): void
    {
        $this->renderer->clearCompacting();
    }

    public function getCancellation(): ?Cancellation
    {
        return $this->renderer->getCancellation();
    }

    public function streamChunk(string $text): void
    {
        $this->renderer->streamChunk($text);
    }

    public function streamComplete(): void
    {
        $this->renderer->streamComplete();
    }

    public function showToolCall(string $name, array $args): void
    {
        $this->renderer->showToolCall($name, $args);
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $this->renderer->showToolResult($name, $output, $success);
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        return $this->renderer->askToolPermission($toolName, $args);
    }

    public function showAutoApproveIndicator(string $toolName): void
    {
        $this->renderer->showAutoApproveIndicator($toolName);
    }

    public function showToolExecuting(string $name): void
    {
        $this->renderer->showToolExecuting($name);
    }

    public function updateToolExecuting(string $output): void
    {
        $this->renderer->updateToolExecuting($output);
    }

    public function clearToolExecuting(): void
    {
        $this->renderer->clearToolExecuting();
    }

    public function clearConversation(): void
    {
        $this->renderer->clearConversation();
    }

    public function replayHistory(array $messages): void
    {
        $this->renderer->replayHistory($messages);
    }

    public function showNotice(string $message): void
    {
        $this->renderer->showNotice($message);
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->renderer->showMode($label, $color);
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->renderer->setPermissionMode($label, $color);
    }

    public function consumeQueuedMessage(): ?string
    {
        return $this->renderer->consumeQueuedMessage();
    }

    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        $this->renderer->setImmediateCommandHandler($handler);
    }

    public function showError(string $message): void
    {
        $this->renderer->showError($message);
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->renderer->showStatus($model, $tokensIn, $tokensOut, $cost, $maxContext);
    }

    public function showSettings(array $currentSettings): array
    {
        return $this->renderer->showSettings($currentSettings);
    }

    public function pickSession(array $items): ?string
    {
        return $this->renderer->pickSession($items);
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        return $this->renderer->approvePlan($currentPermissionMode);
    }

    public function askUser(string $question): string
    {
        return $this->renderer->askUser($question);
    }

    public function askChoice(string $question, array $choices): string
    {
        return $this->renderer->askChoice($question, $choices);
    }

    public function showSubagentStatus(array $stats): void
    {
        $this->renderer->showSubagentStatus($stats);
    }

    public function clearSubagentStatus(): void
    {
        $this->renderer->clearSubagentStatus();
    }

    public function showSubagentRunning(array $entries): void
    {
        $this->renderer->showSubagentRunning($entries);
    }

    public function showSubagentSpawn(array $entries): void
    {
        $this->renderer->showSubagentSpawn($entries);
    }

    public function showSubagentBatch(array $entries): void
    {
        $this->renderer->showSubagentBatch($entries);
    }

    public function refreshSubagentTree(array $tree): void
    {
        $this->renderer->refreshSubagentTree($tree);
    }

    public function setAgentTreeProvider(?\Closure $provider): void
    {
        $this->renderer->setAgentTreeProvider($provider);
    }

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        $this->renderer->showAgentsDashboard($summary, $allStats, $refresh);
    }

    public function teardown(): void
    {
        $this->renderer->teardown();
    }

    public function showWelcome(): void
    {
        $this->renderer->showWelcome();
    }

    public function seedMockSession(): void
    {
        if ($this->renderer instanceof AnsiRenderer) {
            $this->renderer->seedMockSession();
        }
    }

    public function playTheogony(): void
    {
        $this->renderer->playTheogony();
    }

    public function playPrometheus(): void
    {
        $this->renderer->playPrometheus();
    }

    private function resolveRenderer(string $preference): RendererInterface
    {
        if ($preference === 'ansi') {
            return new AnsiRenderer;
        }

        // Default: use TUI if available
        if (class_exists(Tui::class)) {
            return new TuiRenderer;
        }

        return new AnsiRenderer;
    }
}
