<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiAnimation;
use Kosmokrator\UI\RendererInterface;

final class AcpRenderer implements RendererInterface
{
    private ?string $sessionId = null;

    private ?DeferredCancellation $cancellation = null;

    /** @var list<string> */
    private array $textChunks = [];

    /** @var array<string, list<string>> */
    private array $pendingToolIdsByName = [];

    private int $toolCounter = 0;

    private bool $cancelled = false;

    public function __construct(
        private readonly AcpConnection $connection,
    ) {}

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function beginTurn(): void
    {
        $this->textChunks = [];
        $this->pendingToolIdsByName = [];
        $this->cancelled = false;
        $this->cancellation = new DeferredCancellation;
    }

    public function endTurn(): void
    {
        $this->cancellation = null;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->cancellation?->cancel();
    }

    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    public function collectedText(): string
    {
        return implode('', $this->textChunks);
    }

    public function initialize(): void {}

    public function renderIntro(bool $animated): void {}

    public function prompt(): string
    {
        return '';
    }

    public function showUserMessage(string $text): void
    {
        $this->update('user_message_chunk', [
            'content' => ['type' => 'text', 'text' => $text],
        ]);
    }

    public function setPhase(AgentPhase $phase): void {}

    public function showThinking(): void {}

    public function clearThinking(): void {}

    public function showCompacting(): void
    {
        $this->showNotice('Compacting context');
    }

    public function clearCompacting(): void {}

    public function getCancellation(): ?Cancellation
    {
        return $this->cancellation?->getCancellation();
    }

    public function showReasoningContent(string $content): void
    {
        $this->update('agent_thought_chunk', [
            'content' => ['type' => 'text', 'text' => $content],
        ]);
    }

    public function streamChunk(string $text): void
    {
        $this->textChunks[] = $text;
        $this->update('agent_message_chunk', [
            'content' => ['type' => 'text', 'text' => $text],
        ]);
    }

    public function streamComplete(): void {}

    public function showError(string $message): void
    {
        $this->update('agent_message_chunk', [
            'content' => ['type' => 'text', 'text' => "Error: {$message}"],
        ]);
    }

    public function showNotice(string $message): void
    {
        $this->update('agent_thought_chunk', [
            'content' => ['type' => 'text', 'text' => $message],
        ]);
    }

    public function showMode(string $label, string $color = ''): void {}

    public function setPermissionMode(string $label, string $color): void {}

    public function showCurrentMode(string $modeId): void
    {
        $this->update('current_mode_update', [
            'currentModeId' => $modeId,
        ]);
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->update('usage_update', [
            'used' => $tokensIn,
            'size' => $maxContext,
            'cost' => ['amount' => $cost, 'currency' => 'USD'],
        ]);
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void {}

    public function consumeQueuedMessage(): ?string
    {
        return null;
    }

    public function setImmediateCommandHandler(?\Closure $handler): void {}

    public function teardown(): void {}

    public function showWelcome(): void {}

    public function setTaskStore(TaskStore $store): void {}

    public function refreshTaskBar(): void {}

    public function playTheogony(): void {}

    public function playPrometheus(): void {}

    public function playUnleash(): void {}

    public function playAnimation(AnsiAnimation $animation): void {}

    public function setSkillCompletions(array $completions): void {}

    public function showToolCall(string $name, array $args): void
    {
        $id = 'tool_'.$this->toolCounter++;
        $this->pendingToolIdsByName[$name][] = $id;

        $this->update('tool_call', [
            'toolCallId' => $id,
            'title' => $this->formatToolTitle($name, $args),
            'kind' => $this->toolKind($name),
            'status' => 'pending',
            'rawInput' => (object) $args,
            'locations' => $this->toolLocations($name, $args),
        ]);
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $id = $this->shiftToolId($name) ?? 'tool_'.$this->toolCounter++;

        $content = [
            [
                'type' => 'content',
                'content' => ['type' => 'text', 'text' => $output],
            ],
        ];

        $this->update('tool_call_update', [
            'toolCallId' => $id,
            'status' => $success ? 'completed' : 'failed',
            'content' => $content,
            'rawOutput' => ['output' => $output, 'success' => $success],
        ]);
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        $pending = $this->pendingToolIdsByName[$toolName] ?? [];
        $toolCallId = ($pending !== [] ? end($pending) : false) ?: 'permission_'.$this->toolCounter++;
        $response = $this->connection->request('session/request_permission', [
            'sessionId' => $this->sessionId,
            'toolCall' => [
                'toolCallId' => $toolCallId,
                'title' => $this->formatToolTitle($toolName, $args),
                'kind' => $this->toolKind($toolName),
                'status' => 'pending',
                'rawInput' => (object) $args,
            ],
            'options' => [
                ['optionId' => 'allow_once', 'name' => 'Allow once', 'kind' => 'allow_once'],
                ['optionId' => 'allow_always', 'name' => 'Allow always', 'kind' => 'allow_always'],
                ['optionId' => 'reject_once', 'name' => 'Reject', 'kind' => 'reject_once'],
                ['optionId' => 'reject_always', 'name' => 'Reject always', 'kind' => 'reject_always'],
            ],
        ]);

        $outcome = $response['outcome'] ?? [];
        if (! is_array($outcome) || ($outcome['outcome'] ?? '') !== 'selected') {
            if (is_array($outcome) && ($outcome['outcome'] ?? '') === 'cancelled') {
                $this->cancel();
            }

            return 'deny';
        }

        return match ($outcome['optionId'] ?? '') {
            'allow_always' => 'always',
            'allow_once' => 'allow',
            default => 'deny',
        };
    }

    public function showAutoApproveIndicator(string $toolName): void {}

    public function showToolExecuting(string $name): void
    {
        if ($name === 'concurrent') {
            return;
        }

        $id = end($this->pendingToolIdsByName[$name]) ?: null;
        if (is_string($id)) {
            $this->update('tool_call_update', [
                'toolCallId' => $id,
                'status' => 'in_progress',
            ]);
        }
    }

    public function updateToolExecuting(string $output): void {}

    public function clearToolExecuting(): void {}

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

    public function clearConversation(): void {}

    public function replayHistory(array $messages): void {}

    public function showSubagentStatus(array $stats): void {}

    public function clearSubagentStatus(): void {}

    public function showSubagentRunning(array $entries): void {}

    public function showSubagentSpawn(array $entries): void {}

    public function showSubagentBatch(array $entries): void {}

    public function refreshSubagentTree(array $tree): void {}

    public function setAgentTreeProvider(?\Closure $provider): void {}

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void {}

    /**
     * @param  array<string, mixed>  $fields
     */
    private function update(string $type, array $fields): void
    {
        if ($this->sessionId === null) {
            return;
        }

        $this->connection->notify('session/update', [
            'sessionId' => $this->sessionId,
            'update' => ['sessionUpdate' => $type] + $fields,
        ]);
    }

    private function shiftToolId(string $name): ?string
    {
        if (($this->pendingToolIdsByName[$name] ?? []) === []) {
            return null;
        }

        $id = array_shift($this->pendingToolIdsByName[$name]);
        if ($this->pendingToolIdsByName[$name] === []) {
            unset($this->pendingToolIdsByName[$name]);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function formatToolTitle(string $name, array $args): string
    {
        return match ($name) {
            'file_read' => 'Read '.($args['path'] ?? 'file'),
            'file_write' => 'Write '.($args['path'] ?? 'file'),
            'file_edit', 'apply_patch' => 'Edit '.($args['path'] ?? 'files'),
            'grep' => 'Search '.($args['pattern'] ?? ''),
            'glob' => 'Find files',
            'bash' => 'Run '.mb_substr((string) ($args['command'] ?? 'command'), 0, 80),
            default => $name,
        };
    }

    private function toolKind(string $name): string
    {
        return match ($name) {
            'file_read', 'session_read', 'memory_search', 'task_get', 'task_list' => 'read',
            'file_write', 'file_edit', 'apply_patch', 'task_update', 'task_create', 'memory_save' => 'edit',
            'grep', 'glob', 'session_search' => 'search',
            'bash', 'shell_start', 'shell_write', 'shell_read', 'shell_kill', 'execute_lua', 'subagent' => 'execute',
            default => 'other',
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array{path: string}>
     */
    private function toolLocations(string $name, array $args): array
    {
        $path = match ($name) {
            'file_read', 'file_write', 'file_edit' => $args['path'] ?? null,
            'grep', 'glob' => $args['path'] ?? null,
            default => null,
        };

        return is_string($path) && $path !== '' ? [['path' => $path]] : [];
    }
}
