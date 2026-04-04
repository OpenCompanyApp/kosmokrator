<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\ToolCallMapper;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\AgentTreeBuilder;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\SafeDisplay;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Psr\Log\LoggerInterface;

use function Amp\async;

/**
 * Executes tool calls with permission checking and concurrent partitioning.
 *
 * Stateless service — all varying state (tools, mode, agent context) is
 * passed per-call. Handles permission evaluation, tool lookup, concurrent
 * execution grouping, and all tool-related UI rendering.
 */
final class ToolExecutor
{
    private const ASK_TOOLS = ['ask_user', 'ask_choice'];

    /**
     * @param  RendererInterface  $ui  Terminal UI renderer for tool call/result display
     * @param  LoggerInterface  $log  Logger for tool execution events
     * @param  PermissionEvaluator|null  $permissions  Permission policy evaluator (null = no restrictions)
     * @param  OutputTruncator|null  $truncator  Truncates oversized tool output to fit context
     */
    public function __construct(
        private readonly RendererInterface $ui,
        private readonly LoggerInterface $log,
        private readonly ?PermissionEvaluator $permissions,
        private readonly ?OutputTruncator $truncator,
        private readonly AgentTreeBuilder $treeBuilder = new AgentTreeBuilder,
    ) {}

    /**
     * Execute tool calls in three phases: permission check, execution, result collection.
     *
     * @param  ToolCall[]  $toolCalls  Tool calls from the LLM response
     * @param  Tool[]  $tools  Active tools (filtered by mode)
     * @param  Tool[]  $allTools  Full tool set (for "exists but not in mode" messages)
     * @param  AgentMode  $mode  Current agent mode (for mode-specific guards)
     * @param  ?AgentContext  $agentContext  For subagent tree building in batch display
     * @param  ?SubagentStats  $stats  For incrementing tool call counters
     * @return ToolResult[]
     */
    public function executeToolCalls(
        array $toolCalls,
        array $tools,
        array $allTools,
        AgentMode $mode,
        ?AgentContext $agentContext,
        ?SubagentStats $stats,
    ): array {
        // Phase 1: Permission checks & tool lookup
        $approved = [];
        $denied = [];
        $autoApproved = [];
        $seenAskTool = false;

        foreach ($toolCalls as $toolCall) {
            $this->log->info('Tool call', ['tool' => $toolCall->name, 'args' => $toolCall->arguments()]);

            if ($this->isAskTool($toolCall->name)) {
                if ($seenAskTool) {
                    $output = 'Only one interactive question may be asked per response. Use a single ask_user or ask_choice call, wait for the answer, then continue.';
                    SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $toolCall->arguments()), $this->log);
                    SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);
                    $denied[$toolCall->id] = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output);

                    continue;
                }

                $seenAskTool = true;
            }

            [$permDenied, $wasAutoApproved] = $this->checkPermission($toolCall);
            if ($permDenied !== null) {
                $denied[$toolCall->id] = $permDenied;

                continue;
            }

            $tool = $this->findTool($toolCall->name, $tools);
            if ($tool === null) {
                $existsInAll = $this->findTool($toolCall->name, $allTools) !== null;
                $output = $existsInAll
                    ? "Tool '{$toolCall->name}' is not available in {$mode->label()} mode. Switch to Edit mode to use write tools."
                    : "Tool '{$toolCall->name}' not found.";
                SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $toolCall->arguments()), $this->log);
                SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);
                $denied[$toolCall->id] = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output);

                continue;
            }

            // Read-only mode shell write-guard
            if (($mode === AgentMode::Ask || $mode === AgentMode::Plan) && $this->isReadOnlyShellTool($tool->name())) {
                $cmd = $this->commandLikeInput($toolCall);
                if ($this->permissions?->isMutativeCommand($cmd)) {
                    $output = "Command blocked in {$mode->label()} mode (read-only). Switch to Edit mode for write operations.";
                    SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $toolCall->arguments()), $this->log);
                    SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);
                    $denied[$toolCall->id] = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output);

                    continue;
                }
            }

            $approved[] = [$toolCall, $tool];
            $autoApproved[$toolCall->id] = $wasAutoApproved;
        }

        // Show subagent spawn indicators before execution starts
        $subagentSpawns = [];
        foreach ($approved as [$tc, $_]) {
            if ($tc->name === 'subagent') {
                $subagentSpawns[] = ['args' => $tc->arguments(), 'id' => $tc->id];
            }
        }
        if ($subagentSpawns !== []) {
            SafeDisplay::call(fn () => $this->ui->showSubagentSpawn($subagentSpawns), $this->log);
            SafeDisplay::call(fn () => $this->ui->showSubagentRunning($subagentSpawns), $this->log);
        }

        // Phase 2: Execute with live feedback — show header + spinner before, result after
        $results = [];
        $subagentBatch = [];
        $groups = $this->partitionConcurrentGroups($approved);

        // Build lookup: toolCall id → [toolCall, wasAutoApproved]
        $approvedById = [];
        foreach ($approved as [$tc, $t]) {
            $approvedById[$tc->id] = [$tc, $t, $autoApproved[$tc->id] ?? false];
        }

        foreach ($groups as $group) {
            if (count($group) === 1) {
                [$toolCall, $tool] = $group[0];

                if ($toolCall->name !== 'subagent') {
                    // Show header + spinner before execution
                    SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $toolCall->arguments()), $this->log);
                    if ($autoApproved[$toolCall->id] ?? false) {
                        SafeDisplay::call(fn () => $this->ui->showAutoApproveIndicator($toolCall->name), $this->log);
                    }
                    SafeDisplay::call(fn () => $this->ui->showToolExecuting($toolCall->name), $this->log);

                    // Wire bash streaming callback
                    if ($toolCall->name === 'bash' && $tool instanceof BashTool) {
                        $tool->progressCallback = fn (string $chunk) => SafeDisplay::call(
                            fn () => $this->ui->updateToolExecuting($chunk), $this->log
                        );
                    }
                }

                try {
                    $result = $this->executeSingleTool($toolCall, $tool, $stats, $mode);
                } finally {
                    if ($toolCall->name === 'bash' && $tool instanceof BashTool) {
                        $tool->progressCallback = null;
                    }
                }

                if ($toolCall->name !== 'subagent') {
                    SafeDisplay::call(fn () => $this->ui->clearToolExecuting(), $this->log);
                }

                $this->collectResult($toolCall, $result, $agentContext, $subagentBatch, $results);
            } else {
                // Concurrent group: launch all, then show header+result pairs in order
                $hasNonSubagent = false;
                foreach ($group as [$tc, $_]) {
                    if ($tc->name !== 'subagent') {
                        $hasNonSubagent = true;
                        break;
                    }
                }
                if ($hasNonSubagent) {
                    SafeDisplay::call(fn () => $this->ui->showToolExecuting('concurrent'), $this->log);
                }

                // Launch all futures concurrently
                $futures = [];
                foreach ($group as [$toolCall, $tool]) {
                    $futures[$toolCall->id] = async(fn () => $this->executeSingleTool($toolCall, $tool, $stats, $mode));
                }

                // Await and display each header+result pair in original order
                foreach ($group as [$toolCall, $tool]) {
                    $outcome = $futures[$toolCall->id]->await();

                    if ($toolCall->name !== 'subagent') {
                        SafeDisplay::call(fn () => $this->ui->clearToolExecuting(), $this->log);
                        SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $toolCall->arguments()), $this->log);
                    }

                    $this->collectResult($toolCall, $outcome, $agentContext, $subagentBatch, $results);
                }
            }
        }

        // Add denied results in original order
        foreach ($toolCalls as $toolCall) {
            if (isset($denied[$toolCall->id])) {
                $results[] = $denied[$toolCall->id];
            }
        }

        // Flush remaining subagent batch
        if ($subagentBatch !== []) {
            SafeDisplay::call(fn () => $this->ui->showSubagentBatch($subagentBatch), $this->log);

            // Yield to the event loop so background agent fibers can start and
            // acquire concurrency slots in parallel, rather than being starved
            // until the parent's next async operation (e.g. LLM HTTP request).
            \Amp\delay(0);
        }

        return $results;
    }

    /**
     * Check permission for a tool call and handle UI for denied/asked cases.
     *
     * @return array{?ToolResult, bool} [denied result or null, was auto-approved]
     */
    private function checkPermission(ToolCall $toolCall): array
    {
        if ($this->permissions === null) {
            return [null, false];
        }

        $permResult = $this->permissions->evaluate($toolCall->name, $toolCall->arguments());

        if ($permResult->action === PermissionAction::Deny) {
            $output = ($permResult->reason ?? "Permission denied: '{$toolCall->name}' is blocked by policy.")
                .' Try a different approach.';
            $this->log->info('Tool denied by policy', ['tool' => $toolCall->name, 'reason' => $permResult->reason]);
            SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $toolCall->arguments()), $this->log);
            SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);

            return [ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output), false];
        }

        if ($permResult->action === PermissionAction::Ask) {
            SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $toolCall->arguments()), $this->log);
            $decision = $this->ui->askToolPermission($toolCall->name, $toolCall->arguments());

            if ($decision === 'deny') {
                $output = "User denied permission for '{$toolCall->name}'. Try a different approach.";
                $this->log->info('Tool denied by user', ['tool' => $toolCall->name]);
                SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);

                return [ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output), false];
            }

            if ($decision === 'always') {
                $this->permissions->grantSession($toolCall->name);
            }
            if ($decision === 'guardian') {
                $this->permissions->setPermissionMode(PermissionMode::Guardian);
                SafeDisplay::call(fn () => $this->ui->setPermissionMode(PermissionMode::Guardian->statusLabel(), PermissionMode::Guardian->color()), $this->log);
            }
            if ($decision === 'prometheus') {
                $this->permissions->setPermissionMode(PermissionMode::Prometheus);
                SafeDisplay::call(fn () => $this->ui->setPermissionMode(PermissionMode::Prometheus->statusLabel(), PermissionMode::Prometheus->color()), $this->log);
            }

            return [null, false];
        }

        return [null, $permResult->autoApproved];
    }

    /**
     * Execute a single tool call with error handling and output truncation.
     */
    private function executeSingleTool(ToolCall $toolCall, Tool $tool, ?SubagentStats $stats, AgentMode $mode): ToolResult
    {
        try {
            $args = $toolCall->arguments();
            if ($toolCall->name === 'shell_start') {
                $args['read_only'] = $mode !== AgentMode::Edit;
            }

            $output = $tool->handle(...$args);
            $stats?->incrementToolCalls();
            $outputStr = ToolCallMapper::normalizeToolOutput($output);

            if ($this->truncator !== null) {
                $outputStr = $this->truncator->truncate($outputStr, $toolCall->id);
            }

            $this->log->debug('Tool execution complete', [
                'tool' => $toolCall->name,
                'output_length' => strlen($outputStr),
            ]);

            return ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $outputStr);
        } catch (\Throwable $e) {
            $this->log->error('Tool execution failed', ['tool' => $toolCall->name, 'error' => $e->getMessage()]);

            return ToolCallMapper::toErrorResult($toolCall->id, $toolCall->name, $toolCall->arguments(), "Error: {$e->getMessage()}");
        }
    }

    /**
     * Partition approved tool calls into groups that can execute concurrently.
     *
     * Calls within a group have no file-path conflicts. Groups run sequentially.
     * Conservative: if any write conflict or bash+write mix exists, falls back to fully sequential.
     *
     * @param  array<array{ToolCall, Tool}>  $approved
     * @return array<array<array{ToolCall, Tool}>>
     */
    private function partitionConcurrentGroups(array $approved): array
    {
        if (count($approved) <= 1) {
            return [$approved];
        }

        foreach ($approved as [$toolCall, $_]) {
            if ($this->isAskTool($toolCall->name)) {
                return array_map(fn ($item) => [$item], $approved);
            }
            if (str_starts_with($toolCall->name, 'shell_')) {
                return array_map(fn ($item) => [$item], $approved);
            }
        }

        $writeTools = ['file_write', 'file_edit', 'apply_patch'];
        $writePaths = [];
        $hasBash = false;
        $hasWrites = false;

        foreach ($approved as $i => [$toolCall, $tool]) {
            $name = $toolCall->name;
            $path = $toolCall->arguments()['path'] ?? null;

            if ($path !== null && in_array($name, $writeTools, true)) {
                $resolved = realpath($path) ?: $path;
                $writePaths[$resolved][] = $i;
                $hasWrites = true;
            }
            if ($name === 'apply_patch') {
                $hasWrites = true;
            }
            if ($name === 'bash' || str_starts_with($name, 'shell_')) {
                $hasBash = true;
            }
        }

        // Conservative: bash + write tools can't run concurrently
        if ($hasBash && $hasWrites) {
            return array_map(fn ($item) => [$item], $approved);
        }

        // Check for write-write or read-write conflicts on same path
        foreach ($writePaths as $writePath => $writeIndices) {
            if (count($writeIndices) > 1) {
                return array_map(fn ($item) => [$item], $approved);
            }

            foreach ($approved as $j => [$tc, $_]) {
                if (in_array($j, $writeIndices, true)) {
                    continue;
                }
                $readPath = $tc->arguments()['path'] ?? null;
                if ($readPath !== null) {
                    $resolvedRead = realpath($readPath) ?: $readPath;
                    if ($resolvedRead === $writePath) {
                        return array_map(fn ($item) => [$item], $approved);
                    }
                }
            }
        }

        // No conflicts — everything in one concurrent group
        return [$approved];
    }

    /**
     * Collect a tool result into the appropriate batch (subagent) or show it directly.
     *
     * @param  array<int, array{args: array, result: string, success: bool, children?: array, stats?: mixed}>  $subagentBatch
     * @param  ToolResult[]  $results
     */
    private function collectResult(
        ToolCall $toolCall,
        ToolResult $result,
        ?AgentContext $agentContext,
        array &$subagentBatch,
        array &$results,
    ): void {
        $success = ! str_starts_with($result->result, 'Error:');

        if ($toolCall->name === 'subagent') {
            $agentId = $toolCall->arguments()['id'] ?? '';
            $orchestrator = $agentContext?->orchestrator;
            $subagentBatch[] = [
                'args' => $toolCall->arguments(),
                'result' => (string) $result->result,
                'success' => $success,
                'children' => $orchestrator !== null ? $this->treeBuilder->buildSubtree($orchestrator, $agentId) : [],
                'stats' => $orchestrator?->getStats($agentId),
            ];
            $results[] = $result;

            return;
        }

        // Flush any buffered subagents before showing non-subagent result
        if ($subagentBatch !== []) {
            SafeDisplay::call(fn () => $this->ui->showSubagentBatch($subagentBatch), $this->log);
            $subagentBatch = [];
        }

        SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $result->result, $success), $this->log);
        $results[] = $result;
    }

    /**
     * Find a tool by name in the given tool array.
     *
     * @param  Tool[]  $tools
     */
    private function findTool(string $name, array $tools): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** Whether the tool is an interactive question tool (ask_user / ask_choice). */
    private function isAskTool(string $name): bool
    {
        return in_array($name, self::ASK_TOOLS, true);
    }

    /** Whether the tool can execute shell commands that might be mutative. */
    private function isReadOnlyShellTool(string $name): bool
    {
        return in_array($name, ['bash', 'shell_start', 'shell_write'], true);
    }

    /** Extract the command string from a tool call's arguments (handles both 'command' and 'input' keys). */
    private function commandLikeInput(ToolCall $toolCall): string
    {
        return (string) ($toolCall->arguments()['command'] ?? $toolCall->arguments()['input'] ?? '');
    }
}
