<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\Theme;

/**
 * Main TUI renderer implementing the full-screen terminal interface.
 *
 * Thin coordinator that delegates to sub-renderers aligned with the sub-interface
 * boundaries: TuiCoreRenderer, TuiToolRenderer, TuiConversationRenderer.
 * Dialog methods delegate to TuiModalManager (via core), subagent methods
 * delegate to SubagentDisplayManager (via core).
 */
class TuiRenderer implements RendererInterface
{
    private TuiCoreRenderer $core;

    private TuiToolRenderer $tool;

    private TuiConversationRenderer $conversation;

    public function __construct()
    {
        $this->core = new TuiCoreRenderer;
        $this->tool = new TuiToolRenderer($this->core);
        $this->conversation = new TuiConversationRenderer($this->core, $this->tool);

        // Wire the discovery batch finalizer so core->streamChunk can finalize
        $this->core->setDiscoveryBatchFinalizer($this->tool->finalizeDiscoveryBatch(...));
    }

    // ── CoreRendererInterface ───────────────────────────────────────────

    public function setTaskStore(TaskStore $store): void
    {
        $this->core->setTaskStore($store);
    }

    public function refreshTaskBar(): void
    {
        $this->core->refreshTaskBar();
    }

    public function initialize(): void
    {
        $this->core->initialize();
    }

    public function renderIntro(bool $animated): void
    {
        $this->core->renderIntro($animated);
    }

    public function prompt(): string
    {
        return $this->core->prompt();
    }

    public function showUserMessage(string $text): void
    {
        $this->core->showUserMessage($text);
    }

    public function setPhase(AgentPhase $phase): void
    {
        $this->core->setPhase($phase);
    }

    public function showThinking(): void
    {
        $this->core->showThinking();
    }

    public function clearThinking(): void
    {
        $this->core->clearThinking();
    }

    public function showCompacting(): void
    {
        $this->core->showCompacting();
    }

    public function clearCompacting(): void
    {
        $this->core->clearCompacting();
    }

    public function getCancellation(): ?Cancellation
    {
        return $this->core->getCancellation();
    }

    public function showReasoningContent(string $content): void
    {
        $this->core->showReasoningContent($content);
    }

    public function streamChunk(string $text): void
    {
        $this->core->streamChunk($text);
    }

    public function streamComplete(): void
    {
        $this->core->streamComplete();
    }

    public function showError(string $message): void
    {
        $this->core->showError($message);
    }

    public function showNotice(string $message): void
    {
        $this->core->showNotice($message);
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->core->showMode($label, $color);
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->core->setPermissionMode($label, $color);
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->core->showStatus($model, $tokensIn, $tokensOut, $cost, $maxContext);
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $this->core->refreshRuntimeSelection($provider, $model, $maxContext);
    }

    public function consumeQueuedMessage(): ?string
    {
        return $this->core->consumeQueuedMessage();
    }

    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        $this->core->setImmediateCommandHandler($handler);
    }

    public function teardown(): void
    {
        $this->core->teardown();
    }

    public function showWelcome(): void
    {
        $this->core->showWelcome();
    }

    public function playTheogony(): void
    {
        $this->core->playTheogony();
    }

    public function playPrometheus(): void
    {
        $this->core->playPrometheus();
    }

    public function playUnleash(): void
    {
        $this->core->playUnleash();
    }

    // ── ToolRendererInterface ───────────────────────────────────────────

    public function showToolCall(string $name, array $args): void
    {
        $this->tool->showToolCall($name, $args);
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $this->tool->showToolResult($name, $output, $success);
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        return $this->tool->askToolPermission($toolName, $args);
    }

    public function showAutoApproveIndicator(string $toolName): void
    {
        $this->tool->showAutoApproveIndicator($toolName);
    }

    public function showToolExecuting(string $name): void
    {
        $this->tool->showToolExecuting($name);
    }

    public function updateToolExecuting(string $output): void
    {
        $this->tool->updateToolExecuting($output);
    }

    public function clearToolExecuting(): void
    {
        $this->tool->clearToolExecuting();
    }

    // ── DialogRendererInterface ─────────────────────────────────────────

    public function showSettings(array $currentSettings): array
    {
        $result = $this->core->getModalManager()->showSettings($currentSettings);
        $this->core->bindInputHandlers();
        $this->core->getTui()->setFocus($this->core->getInput());
        $this->core->forceRender();

        return $result;
    }

    public function pickSession(array $items): ?string
    {
        return $this->core->getModalManager()->pickSession($items);
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        return $this->core->getModalManager()->approvePlan($currentPermissionMode);
    }

    public function askUser(string $question): string
    {
        $answer = $this->core->getModalManager()->askUser($question);
        $trimmed = trim($answer);

        $this->core->queueQuestionRecap(
            question: $question,
            answer: $trimmed,
            answered: $trimmed !== '',
        );

        return $answer;
    }

    public function askChoice(string $question, array $choices): string
    {
        $result = $this->core->getModalManager()->askChoice($question, $choices);
        $selected = $this->findChoice($choices, $result);

        $this->core->queueQuestionRecap(
            question: $question,
            answer: $result === 'dismissed' ? '' : $result,
            answered: $result !== 'dismissed',
            recommended: (bool) ($selected['recommended'] ?? false),
        );

        return $result;
    }

    // ── ConversationRendererInterface ───────────────────────────────────

    public function clearConversation(): void
    {
        $this->conversation->clearConversation();
    }

    public function replayHistory(array $messages): void
    {
        $this->conversation->replayHistory($messages);
    }

    // ── SubagentRendererInterface ───────────────────────────────────────

    public function showSubagentStatus(array $stats): void
    {
        if (empty($stats)) {
            return;
        }

        $running = count(array_filter($stats, fn ($s) => $s->status === 'running'));
        $done = count(array_filter($stats, fn ($s) => $s->status === 'done'));
        $total = count($stats);

        $lines = ["{$running} running, {$done}/{$total} finished"];

        foreach ($stats as $s) {
            $icon = match ($s->status) {
                'done' => '✓',
                'running' => '●',
                'failed' => '✗',
                'waiting' => '◌',
                'retrying' => '↻',
                default => '○',
            };
            $task = mb_substr($s->task, 0, 50);
            $type = ucfirst($s->agentType);
            $lines[] = "  {$icon} {$type} \"{$task}\" · {$s->toolCalls} tools";
        }

        $this->core->addConversationWidget(new \Symfony\Component\Tui\Widget\TextWidget(implode("\n", $lines)));
    }

    public function clearSubagentStatus(): void
    {
        // TUI: status is part of conversation flow, nothing to actively clear
    }

    public function showSubagentRunning(array $entries): void
    {
        $this->core->getSubagentDisplay()->showRunning($entries);
    }

    public function setAgentTreeProvider(?\Closure $provider): void
    {
        $this->core->getSubagentDisplay()->setTreeProvider($provider);
    }

    public function refreshSubagentTree(array $tree): void
    {
        $this->core->getSubagentDisplay()->refreshTree($tree);
    }

    public function showSubagentSpawn(array $entries): void
    {
        $this->core->getSubagentDisplay()->showSpawn($entries);
    }

    public function showSubagentBatch(array $entries): void
    {
        $this->core->getSubagentDisplay()->showBatch($entries);
    }

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        $this->core->getModalManager()->showAgentsDashboard($summary, $allStats, $refresh);
    }

    // ── Private helpers ─────────────────────────────────────────────────

    /**
     * @param  array<array{label: string, detail: string|null, recommended?: bool}>  $choices
     * @return array{label: string, detail: string|null, recommended?: bool}|null
     */
    private function findChoice(array $choices, string $label): ?array
    {
        foreach ($choices as $choice) {
            if ($choice['label'] === $label) {
                return $choice;
            }
        }

        return null;
    }
}
