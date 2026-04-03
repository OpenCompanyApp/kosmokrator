<?php

namespace Kosmokrator\UI;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiRenderer;
use Kosmokrator\UI\Tui\TuiRenderer;
use Symfony\Component\Tui\Tui;

/**
 * Facade that selects and delegates to the active renderer implementation.
 *
 * Resolves 'auto', 'tui', or 'ansi' at construction time and proxies every
 * RendererInterface call to the chosen backend (TuiRenderer or AnsiRenderer).
 */
class UIManager implements RendererInterface
{
    /** @var RendererInterface The resolved backend renderer. */
    private RendererInterface $renderer;

    /**
     * @param string $preference Renderer preference: 'auto', 'tui', or 'ansi'
     */
    public function __construct(string $preference = 'auto')
    {
        $this->renderer = $this->resolveRenderer($preference);
    }

    /** {@inheritDoc} */
    public function setTaskStore(TaskStore $store): void
    {
        $this->renderer->setTaskStore($store);
    }

    /** {@inheritDoc} */
    public function refreshTaskBar(): void
    {
        $this->renderer->refreshTaskBar();
    }

    /**
     * Return which renderer backend is currently active.
     *
     * @return string 'tui', 'ansi', or the resolved class name
     */
    public function getActiveRenderer(): string
    {
        return match (true) {
            $this->renderer instanceof TuiRenderer => 'tui',
            $this->renderer instanceof AnsiRenderer => 'ansi',
            default => get_class($this->renderer),
        };
    }

    /** {@inheritDoc} */
    public function initialize(): void
    {
        $this->renderer->initialize();
    }

    /** {@inheritDoc} */
    public function renderIntro(bool $animated): void
    {
        $this->renderer->renderIntro($animated);
    }

    /** {@inheritDoc} */
    public function prompt(): string
    {
        return $this->renderer->prompt();
    }

    /** {@inheritDoc} */
    public function showUserMessage(string $text): void
    {
        $this->renderer->showUserMessage($text);
    }

    /** {@inheritDoc} */
    public function setPhase(AgentPhase $phase): void
    {
        $this->renderer->setPhase($phase);
    }

    /** {@inheritDoc} */
    public function showThinking(): void
    {
        $this->renderer->showThinking();
    }

    /** {@inheritDoc} */
    public function clearThinking(): void
    {
        $this->renderer->clearThinking();
    }

    /** {@inheritDoc} */
    public function showCompacting(): void
    {
        $this->renderer->showCompacting();
    }

    /** {@inheritDoc} */
    public function clearCompacting(): void
    {
        $this->renderer->clearCompacting();
    }

    /** {@inheritDoc} */
    public function getCancellation(): ?Cancellation
    {
        return $this->renderer->getCancellation();
    }

    /** {@inheritDoc} */
    public function streamChunk(string $text): void
    {
        $this->renderer->streamChunk($text);
    }

    /** {@inheritDoc} */
    public function streamComplete(): void
    {
        $this->renderer->streamComplete();
    }

    /** {@inheritDoc} */
    public function showToolCall(string $name, array $args): void
    {
        $this->renderer->showToolCall($name, $args);
    }

    /** {@inheritDoc} */
    public function showToolResult(string $name, string $output, bool $success): void
    {
        $this->renderer->showToolResult($name, $output, $success);
    }

    /** {@inheritDoc} */
    public function askToolPermission(string $toolName, array $args): string
    {
        return $this->renderer->askToolPermission($toolName, $args);
    }

    /** {@inheritDoc} */
    public function showAutoApproveIndicator(string $toolName): void
    {
        $this->renderer->showAutoApproveIndicator($toolName);
    }

    /** {@inheritDoc} */
    public function showToolExecuting(string $name): void
    {
        $this->renderer->showToolExecuting($name);
    }

    /** {@inheritDoc} */
    public function updateToolExecuting(string $output): void
    {
        $this->renderer->updateToolExecuting($output);
    }

    /** {@inheritDoc} */
    public function clearToolExecuting(): void
    {
        $this->renderer->clearToolExecuting();
    }

    /** {@inheritDoc} */
    public function clearConversation(): void
    {
        $this->renderer->clearConversation();
    }

    /** {@inheritDoc} */
    public function replayHistory(array $messages): void
    {
        $this->renderer->replayHistory($messages);
    }

    /** {@inheritDoc} */
    public function showNotice(string $message): void
    {
        $this->renderer->showNotice($message);
    }

    /** {@inheritDoc} */
    public function showMode(string $label, string $color = ''): void
    {
        $this->renderer->showMode($label, $color);
    }

    /** {@inheritDoc} */
    public function setPermissionMode(string $label, string $color): void
    {
        $this->renderer->setPermissionMode($label, $color);
    }

    /** {@inheritDoc} */
    public function consumeQueuedMessage(): ?string
    {
        return $this->renderer->consumeQueuedMessage();
    }

    /** {@inheritDoc} */
    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        $this->renderer->setImmediateCommandHandler($handler);
    }

    /** {@inheritDoc} */
    public function showError(string $message): void
    {
        $this->renderer->showError($message);
    }

    /** {@inheritDoc} */
    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->renderer->showStatus($model, $tokensIn, $tokensOut, $cost, $maxContext);
    }

    /** {@inheritDoc} */
    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $this->renderer->refreshRuntimeSelection($provider, $model, $maxContext);
    }

    /** {@inheritDoc} */
    public function showSettings(array $currentSettings): array
    {
        return $this->renderer->showSettings($currentSettings);
    }

    /** {@inheritDoc} */
    public function pickSession(array $items): ?string
    {
        return $this->renderer->pickSession($items);
    }

    /** {@inheritDoc} */
    public function approvePlan(string $currentPermissionMode): ?array
    {
        return $this->renderer->approvePlan($currentPermissionMode);
    }

    /** {@inheritDoc} */
    public function askUser(string $question): string
    {
        return $this->renderer->askUser($question);
    }

    /** {@inheritDoc} */
    public function askChoice(string $question, array $choices): string
    {
        return $this->renderer->askChoice($question, $choices);
    }

    /** {@inheritDoc} */
    public function showSubagentStatus(array $stats): void
    {
        $this->renderer->showSubagentStatus($stats);
    }

    /** {@inheritDoc} */
    public function clearSubagentStatus(): void
    {
        $this->renderer->clearSubagentStatus();
    }

    /** {@inheritDoc} */
    public function showSubagentRunning(array $entries): void
    {
        $this->renderer->showSubagentRunning($entries);
    }

    /** {@inheritDoc} */
    public function showSubagentSpawn(array $entries): void
    {
        $this->renderer->showSubagentSpawn($entries);
    }

    /** {@inheritDoc} */
    public function showSubagentBatch(array $entries): void
    {
        $this->renderer->showSubagentBatch($entries);
    }

    /** {@inheritDoc} */
    public function refreshSubagentTree(array $tree): void
    {
        $this->renderer->refreshSubagentTree($tree);
    }

    /** {@inheritDoc} */
    public function setAgentTreeProvider(?\Closure $provider): void
    {
        $this->renderer->setAgentTreeProvider($provider);
    }

    /** {@inheritDoc} */
    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        $this->renderer->showAgentsDashboard($summary, $allStats, $refresh);
    }

    /** {@inheritDoc} */
    public function teardown(): void
    {
        $this->renderer->teardown();
    }

    /** {@inheritDoc} */
    public function showWelcome(): void
    {
        $this->renderer->showWelcome();
    }

    /**
     * Seed a mock session for testing (AnsiRenderer only).
     *
     * This method only exists on AnsiRenderer; it is a development/testing hook
     * and is not part of RendererInterface.
     */
    public function seedMockSession(): void
    {
        if ($this->renderer instanceof AnsiRenderer) {
            $this->renderer->seedMockSession();
        }
    }

    /** {@inheritDoc} */
    public function playTheogony(): void
    {
        $this->renderer->playTheogony();
    }

    /** {@inheritDoc} */
    public function playPrometheus(): void
    {
        $this->renderer->playPrometheus();
    }

    /**
     * Resolve the preference string to a concrete renderer instance.
     *
     * @param string $preference 'auto', 'tui', or 'ansi'
     * @return RendererInterface The resolved renderer
     */
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
