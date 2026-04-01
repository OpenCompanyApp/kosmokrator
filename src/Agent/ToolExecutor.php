<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\AgentTreeBuilder;
use Kosmokrator\UI\RendererInterface;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
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
    public function __construct(
        private readonly RendererInterface $ui,
        private readonly LoggerInterface $log,
        private readonly ?PermissionEvaluator $permissions,
        private readonly ?OutputTruncator $truncator,
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

        foreach ($toolCalls as $toolCall) {
            $this->log->info('Tool call', ['tool' => $toolCall->name, 'args' => $toolCall->arguments()]);

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
                $this->ui->showToolCall($toolCall->name, $toolCall->arguments());
                $this->ui->showToolResult($toolCall->name, $output, false);
                $denied[$toolCall->id] = new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output);

                continue;
            }

            // Ask-mode bash write-guard
            if ($mode === AgentMode::Ask && $tool->name() === 'bash') {
                $cmd = $toolCall->arguments()['command'] ?? '';
                if ($this->permissions?->isMutativeCommand($cmd)) {
                    $output = 'Command blocked in Ask mode (read-only). Switch to Edit mode for write operations.';
                    $this->ui->showToolCall($toolCall->name, $toolCall->arguments());
                    $this->ui->showToolResult($toolCall->name, $output, false);
                    $denied[$toolCall->id] = new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output);

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
            $this->ui->showSubagentSpawn($subagentSpawns);
            $this->ui->showSubagentRunning($subagentSpawns);
        }

        // Phase 2: Execute approved calls (concurrent within safe groups, sequential across)
        $outcomes = [];
        $groups = $this->partitionConcurrentGroups($approved);

        foreach ($groups as $group) {
            if (count($group) === 1) {
                [$toolCall, $tool] = $group[0];
                $outcomes[$toolCall->id] = $this->executeSingleTool($toolCall, $tool, $stats);
            } else {
                $futures = [];
                foreach ($group as [$toolCall, $tool]) {
                    $futures[$toolCall->id] = async(fn () => $this->executeSingleTool($toolCall, $tool, $stats));
                }
                foreach ($futures as $id => $future) {
                    $outcomes[$id] = $future->await();
                }
            }
        }

        // Phase 3: Collect results in original order + UI display
        $results = [];
        $subagentBatch = [];

        foreach ($toolCalls as $toolCall) {
            if (isset($denied[$toolCall->id])) {
                $results[] = $denied[$toolCall->id];

                continue;
            }
            if (isset($outcomes[$toolCall->id])) {
                $result = $outcomes[$toolCall->id];
                $success = ! str_starts_with($result->result, 'Error:');

                if ($toolCall->name === 'subagent') {
                    $agentId = $toolCall->arguments()['id'] ?? '';
                    $orchestrator = $agentContext?->orchestrator;
                    $subagentBatch[] = [
                        'args' => $toolCall->arguments(),
                        'result' => $result->result,
                        'success' => $success,
                        'children' => $orchestrator !== null ? AgentTreeBuilder::buildSubtree($orchestrator, $agentId) : [],
                        'stats' => $orchestrator?->getStats($agentId),
                    ];
                    $results[] = $result;

                    continue;
                }

                // Flush any buffered subagents before showing non-subagent tool
                if ($subagentBatch !== []) {
                    $this->ui->showSubagentBatch($subagentBatch);
                    $subagentBatch = [];
                }

                $this->ui->showToolCall($toolCall->name, $toolCall->arguments());
                if ($autoApproved[$toolCall->id] ?? false) {
                    $this->ui->showAutoApproveIndicator($toolCall->name);
                }
                $this->ui->showToolResult($toolCall->name, $result->result, $success);
                $results[] = $result;
            }
        }

        // Flush remaining subagent batch
        if ($subagentBatch !== []) {
            $this->ui->showSubagentBatch($subagentBatch);
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
            $this->ui->showToolCall($toolCall->name, $toolCall->arguments());
            $this->ui->showToolResult($toolCall->name, $output, false);

            return [new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output), false];
        }

        if ($permResult->action === PermissionAction::Ask) {
            $this->ui->showToolCall($toolCall->name, $toolCall->arguments());
            $decision = $this->ui->askToolPermission($toolCall->name, $toolCall->arguments());

            if ($decision === 'deny') {
                $output = "User denied permission for '{$toolCall->name}'. Try a different approach.";
                $this->log->info('Tool denied by user', ['tool' => $toolCall->name]);
                $this->ui->showToolResult($toolCall->name, $output, false);

                return [new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output), false];
            }

            if ($decision === 'always') {
                $this->permissions->grantSession($toolCall->name);
            }
            if ($decision === 'guardian') {
                $this->permissions->setPermissionMode(PermissionMode::Guardian);
                $this->ui->setPermissionMode(PermissionMode::Guardian->statusLabel(), PermissionMode::Guardian->color());
            }
            if ($decision === 'prometheus') {
                $this->permissions->setPermissionMode(PermissionMode::Prometheus);
                $this->ui->setPermissionMode(PermissionMode::Prometheus->statusLabel(), PermissionMode::Prometheus->color());
            }

            return [null, false];
        }

        return [null, $permResult->autoApproved];
    }

    /**
     * Execute a single tool call with error handling and output truncation.
     */
    private function executeSingleTool(ToolCall $toolCall, Tool $tool, ?SubagentStats $stats): ToolResult
    {
        try {
            $output = $tool->handle(...$toolCall->arguments());
            $stats?->incrementToolCalls();
            $outputStr = match (true) {
                is_string($output) => $output,
                $output instanceof ToolOutput => $output->output,
                $output instanceof ToolError => "Error: {$output->message}",
                default => (string) $output,
            };

            if ($this->truncator !== null) {
                $outputStr = $this->truncator->truncate($outputStr, $toolCall->id);
            }

            $this->log->debug('Tool execution complete', [
                'tool' => $toolCall->name,
                'output_length' => strlen($outputStr),
            ]);

            return new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $outputStr);
        } catch (\Throwable $e) {
            $this->log->error('Tool execution failed', ['tool' => $toolCall->name, 'error' => $e->getMessage()]);

            return new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), "Error: {$e->getMessage()}");
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

        $writeTools = ['file_write', 'file_edit'];
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
            if ($name === 'bash') {
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
}
