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

    private ?string $runId = null;

    private ?DeferredCancellation $cancellation = null;

    /** @var list<string> */
    private array $textChunks = [];

    /** @var array<string, list<string>> */
    private array $pendingToolIdsByName = [];

    private int $toolCounter = 0;

    private int $runCounter = 0;

    private bool $cancelled = false;

    /** @var null|\Closure(): array<string, mixed> */
    private ?\Closure $agentTreeProvider = null;

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
        $this->runId = 'run_'.(++$this->runCounter);
        $this->kosmo('session_update', ['status' => 'started']);
    }

    public function endTurn(): void
    {
        $this->kosmo('session_update', [
            'status' => $this->cancelled ? 'cancelled' : 'completed',
            'text' => $this->collectedText(),
        ]);
        $this->cancellation = null;
        $this->runId = null;
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
        $this->kosmo('session_update', ['role' => 'user', 'text' => $text]);
    }

    public function setPhase(AgentPhase $phase): void
    {
        $this->kosmo('phase_changed', ['phase' => $phase->value]);
    }

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
        $this->kosmo('thinking_delta', ['text' => $content, 'delta' => $content]);
    }

    public function streamChunk(string $text): void
    {
        $this->textChunks[] = $text;
        $this->update('agent_message_chunk', [
            'content' => ['type' => 'text', 'text' => $text],
        ]);
        $this->kosmo('text_delta', ['text' => $text, 'delta' => $text]);
    }

    public function streamComplete(): void {}

    public function showError(string $message): void
    {
        $this->update('agent_message_chunk', [
            'content' => ['type' => 'text', 'text' => "Error: {$message}"],
        ]);
        $this->kosmo('error', ['message' => $message]);
    }

    public function showNotice(string $message): void
    {
        $this->update('agent_thought_chunk', [
            'content' => ['type' => 'text', 'text' => $message],
        ]);
        $this->kosmo('status_updated', ['message' => $message]);
    }

    public function showMode(string $label, string $color = ''): void {}

    public function setPermissionMode(string $label, string $color): void {}

    public function showCurrentMode(string $modeId): void
    {
        $this->update('current_mode_update', [
            'currentModeId' => $modeId,
        ]);
        $this->kosmo('runtime_changed', ['mode' => $modeId]);
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->update('usage_update', [
            'used' => $tokensIn,
            'size' => $maxContext,
            'cost' => ['amount' => $cost, 'currency' => 'USD'],
        ]);
        $this->kosmo('usage_updated', [
            'model' => $model,
            'tokensIn' => $tokensIn,
            'tokensOut' => $tokensOut,
            'cost' => $cost,
            'maxContext' => $maxContext,
        ]);
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $this->kosmo('runtime_changed', [
            'provider' => $provider,
            'model' => $model,
            'maxContext' => $maxContext,
        ]);
    }

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
        $this->kosmo('tool_started', [
            'toolCallId' => $id,
            'tool' => $name,
            'name' => $name,
            'args' => $args,
            'kind' => $this->toolKind($name),
            'title' => $this->formatToolTitle($name, $args),
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
        $this->kosmo('tool_completed', [
            'toolCallId' => $id,
            'tool' => $name,
            'name' => $name,
            'output' => $output,
            'success' => $success,
            'status' => $success ? 'completed' : 'failed',
        ]);
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        $pending = $this->pendingToolIdsByName[$toolName] ?? [];
        $toolCallId = ($pending !== [] ? end($pending) : false) ?: 'permission_'.$this->toolCounter++;
        $toolCall = [
            'toolCallId' => $toolCallId,
            'title' => $this->formatToolTitle($toolName, $args),
            'kind' => $this->toolKind($toolName),
            'status' => 'pending',
            'rawInput' => (object) $args,
        ];
        $options = [
            ['optionId' => 'allow_once', 'name' => 'Allow once', 'kind' => 'allow_once'],
            ['optionId' => 'allow_always', 'name' => 'Allow always', 'kind' => 'allow_always'],
            ['optionId' => 'reject_once', 'name' => 'Reject', 'kind' => 'reject_once'],
            ['optionId' => 'reject_always', 'name' => 'Reject always', 'kind' => 'reject_always'],
        ];

        $this->kosmo('permission_requested', [
            'toolCallId' => $toolCallId,
            'tool' => $toolName,
            'name' => $toolName,
            'args' => $args,
            'toolCall' => $toolCall,
            'options' => $options,
        ]);

        $response = $this->connection->request('session/request_permission', [
            'sessionId' => $this->sessionId,
            'toolCall' => $toolCall,
            'options' => $options,
        ]);

        $outcome = $response['outcome'] ?? [];
        if (! is_array($outcome) || ($outcome['outcome'] ?? '') !== 'selected') {
            if (is_array($outcome) && ($outcome['outcome'] ?? '') === 'cancelled') {
                $this->cancel();
            }

            $this->kosmo('permission_resolved', [
                'toolCallId' => $toolCallId,
                'tool' => $toolName,
                'decision' => 'deny',
                'outcome' => $outcome,
            ]);

            return 'deny';
        }

        $decision = match ($outcome['optionId'] ?? '') {
            'allow_always' => 'always',
            'allow_once' => 'allow',
            default => 'deny',
        };
        $this->kosmo('permission_resolved', [
            'toolCallId' => $toolCallId,
            'tool' => $toolName,
            'decision' => $decision,
            'outcome' => $outcome,
        ]);

        return $decision;
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
            $this->kosmo('tool_progress', [
                'toolCallId' => $id,
                'tool' => $name,
                'name' => $name,
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

    public function showSubagentStatus(array $stats): void
    {
        $this->kosmo('subagent_status', ['stats' => $stats]);
    }

    public function clearSubagentStatus(): void
    {
        $this->kosmo('subagent_status', ['stats' => []]);
    }

    public function showSubagentRunning(array $entries): void
    {
        $this->kosmo('subagent_running', ['entries' => $entries]);
    }

    public function showSubagentSpawn(array $entries): void
    {
        $this->kosmo('subagent_spawned', ['entries' => $entries]);
    }

    public function showSubagentBatch(array $entries): void
    {
        $this->kosmo('subagent_completed', ['entries' => $entries]);
    }

    public function refreshSubagentTree(array $tree): void
    {
        $this->kosmo('subagent_tree', ['tree' => $tree]);
    }

    public function setAgentTreeProvider(?\Closure $provider): void
    {
        $this->agentTreeProvider = $provider;
    }

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        $tree = null;
        if ($refresh !== null) {
            $refresh();
        }
        if ($this->agentTreeProvider !== null) {
            $candidate = ($this->agentTreeProvider)();
            $tree = is_array($candidate) ? $candidate : null;
        }

        $this->kosmo('subagent_dashboard', array_filter([
            'summary' => $summary,
            'stats' => $allStats,
            'tree' => $tree,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function emitKosmokratorEvent(string $type, array $fields = []): void
    {
        $this->kosmo($type, $fields);
    }

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

    /**
     * @param  array<string, mixed>  $fields
     */
    private function kosmo(string $type, array $fields = []): void
    {
        if ($this->sessionId === null) {
            return;
        }

        $this->connection->notify(
            'kosmokrator/'.$type,
            AcpKosmokratorProtocol::event($this->sessionId, $this->runId, $type, $fields),
        );
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
