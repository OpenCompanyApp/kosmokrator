<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\Cancellation;
use Amp\CancelledException;
use Kosmokrator\LLM\ToolCallMapper;
use Kosmokrator\Tool\Coding\FileReadTool;
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
        $deferredAsk = [];
        $seenAskTool = false;

        foreach ($toolCalls as $toolCall) {
            $decodedCall = ToolCallMapper::tryExtractCall($toolCall);
            $args = $decodedCall['args'];

            if ($decodedCall['argumentsError'] !== null) {
                $output = "Invalid tool call arguments (malformed JSON): {$decodedCall['argumentsError']}. Please retry with valid JSON arguments.";
                $this->log->warning('Malformed tool call arguments', ['tool' => $toolCall->name, 'error' => $decodedCall['argumentsError']]);
                SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, []), $this->log);
                SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);
                $denied[$toolCall->id] = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, [], $output);

                continue;
            }

            if ($toolCall->name === 'subagent') {
                $args = $this->normalizeSubagentArgs($args, $agentContext);
            }

            $this->log->info('Tool call', ['tool' => $toolCall->name, 'args' => $args]);

            if ($this->isAskTool($toolCall->name)) {
                if ($seenAskTool) {
                    $output = 'Only one interactive question may be asked per response. Use a single ask_user or ask_choice call, wait for the answer, then continue.';
                    SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $args), $this->log);
                    SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);
                    $denied[$toolCall->id] = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $args, $output);

                    continue;
                }

                $seenAskTool = true;
            }

            [$permDenied, $wasAutoApproved, $shouldAskLater] = $this->checkPermission($toolCall, $args, deferAsk: true);
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
                SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $args), $this->log);
                SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);
                $denied[$toolCall->id] = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $args, $output);

                continue;
            }

            // Read-only mode shell write-guard
            if (($mode === AgentMode::Ask || $mode === AgentMode::Plan) && $this->isReadOnlyShellTool($tool->name())) {
                $cmd = $this->commandLikeInput($args);
                if ($this->permissions?->isMutativeCommand($cmd)) {
                    $output = "Command blocked in {$mode->label()} mode (read-only). Switch to Edit mode for write operations.";
                    SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $args), $this->log);
                    SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);
                    $denied[$toolCall->id] = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $args, $output);

                    continue;
                }
            }

            if ($shouldAskLater) {
                $deferredAsk[] = [$toolCall, $tool, $args];

                continue;
            }

            $approved[] = [$toolCall, $tool, $args];
            $autoApproved[$toolCall->id] = $wasAutoApproved;
        }

        // Show subagent spawn indicators before execution starts
        $subagentSpawns = [];
        foreach ($approved as [$tc, $_, $args]) {
            if ($tc->name === 'subagent') {
                $subagentSpawns[] = ['args' => $args, 'id' => $tc->id];
            }
        }
        if ($subagentSpawns !== []) {
            SafeDisplay::call(fn () => $this->ui->showSubagentSpawn($subagentSpawns), $this->log);
            SafeDisplay::call(fn () => $this->ui->showSubagentRunning($subagentSpawns), $this->log);
        }

        // Phase 2: Execute with live feedback — show header + spinner before, result after
        $cancellation = $this->ui->getCancellation();
        $results = [];
        $subagentBatch = [];
        $groups = $this->partitionConcurrentGroups($approved);

        // Build lookup: toolCall id → [toolCall, wasAutoApproved]
        $approvedById = [];
        foreach ($approved as [$tc, $t, $args]) {
            $approvedById[$tc->id] = [$tc, $t, $args, $autoApproved[$tc->id] ?? false];
        }

        foreach ($groups as $group) {
            if (count($group) === 1) {
                [$toolCall, $tool, $args] = $group[0];

                if ($toolCall->name !== 'subagent') {
                    // Show header + spinner before execution
                    SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $args), $this->log);
                    if ($autoApproved[$toolCall->id] ?? false) {
                        SafeDisplay::call(fn () => $this->ui->showAutoApproveIndicator($toolCall->name), $this->log);
                    }
                    SafeDisplay::call(fn () => $this->ui->showToolExecuting($toolCall->name), $this->log);
                }

                $result = $this->executeSingleTool($toolCall, $args, $tool, $stats, $mode, $cancellation);
                $agentContext?->orchestrator->persistStats($stats);

                if ($toolCall->name !== 'subagent') {
                    SafeDisplay::call(fn () => $this->ui->clearToolExecuting(), $this->log);
                }

                $this->collectResult($toolCall, $args, $result, $agentContext, $subagentBatch, $results);
            } else {
                // Concurrent group: launch all, then show header+result pairs in order
                $hasNonSubagent = false;
                foreach ($group as [$tc, $_, $_args]) {
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
                foreach ($group as [$toolCall, $tool, $args]) {
                    $futures[$toolCall->id] = async(fn () => $this->executeSingleTool($toolCall, $args, $tool, $stats, $mode, $cancellation));
                }

                // Await and display each header+result pair in original order
                try {
                    foreach ($group as [$toolCall, $tool, $args]) {
                        $outcome = $futures[$toolCall->id]->await($cancellation);
                        $agentContext?->orchestrator->persistStats($stats);

                        if ($toolCall->name !== 'subagent') {
                            SafeDisplay::call(fn () => $this->ui->clearToolExecuting(), $this->log);
                            SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $args), $this->log);
                        }

                        $this->collectResult($toolCall, $args, $outcome, $agentContext, $subagentBatch, $results);
                    }
                } catch (\Throwable $e) {
                    foreach ($futures as $future) {
                        $future->ignore();
                    }

                    throw $e;
                }
            }
        }

        foreach ($deferredAsk as [$toolCall, $tool, $args]) {
            [$permDenied] = $this->checkPermission($toolCall, $args);
            if ($permDenied !== null) {
                $denied[$toolCall->id] = $permDenied;

                continue;
            }

            if ($toolCall->name !== 'subagent') {
                SafeDisplay::call(fn () => $this->ui->showToolExecuting($toolCall->name), $this->log);
            }

            $result = $this->executeSingleTool($toolCall, $args, $tool, $stats, $mode, $cancellation);
            $agentContext?->orchestrator->persistStats($stats);

            if ($toolCall->name !== 'subagent') {
                SafeDisplay::call(fn () => $this->ui->clearToolExecuting(), $this->log);
            }

            $this->collectResult($toolCall, $args, $result, $agentContext, $subagentBatch, $results);
        }

        // Merge approved and denied results in original tool call order
        $approvedById = [];
        foreach ($results as $r) {
            $approvedById[$r->toolCallId] = $r;
        }
        $orderedResults = [];
        foreach ($toolCalls as $toolCall) {
            if (isset($approvedById[$toolCall->id])) {
                $orderedResults[] = $approvedById[$toolCall->id];
            } elseif (isset($denied[$toolCall->id])) {
                $orderedResults[] = $denied[$toolCall->id];
            }
        }
        $results = $orderedResults;

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
     * @return array{?ToolResult, bool, bool} [denied result or null, was auto-approved, should ask later]
     */
    private function checkPermission(ToolCall $toolCall, array $args, bool $deferAsk = false): array
    {
        if ($this->permissions === null) {
            return [null, false, false];
        }

        $permResult = $this->permissions->evaluate($toolCall->name, $args);

        if ($permResult->action === PermissionAction::Deny) {
            $output = ($permResult->reason ?? "Permission denied: '{$toolCall->name}' is blocked by policy.")
                .' Try a different approach.';
            $this->log->info('Tool denied by policy', ['tool' => $toolCall->name, 'reason' => $permResult->reason]);
            SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $args), $this->log);
            SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);

            return [ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $args, $output), false, false];
        }

        if ($permResult->action === PermissionAction::Ask) {
            if ($deferAsk) {
                return [null, false, true];
            }

            SafeDisplay::call(fn () => $this->ui->showToolCall($toolCall->name, $args), $this->log);
            $decision = $this->ui->askToolPermission($toolCall->name, $args);

            if ($decision === 'deny') {
                $output = "User denied permission for '{$toolCall->name}'. Try a different approach.";
                $this->log->info('Tool denied by user', ['tool' => $toolCall->name]);
                SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, $output, false), $this->log);

                return [ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $args, $output), false, false];
            }

            if ($decision === 'always') {
                $this->permissions->grantSession($toolCall->name, $args);
            }
            if ($decision === 'guardian') {
                $this->permissions->setPermissionMode(PermissionMode::Guardian);
                SafeDisplay::call(fn () => $this->ui->setPermissionMode(PermissionMode::Guardian->statusLabel(), PermissionMode::Guardian->color()), $this->log);
            }
            if ($decision === 'prometheus') {
                $this->permissions->setPermissionMode(PermissionMode::Prometheus);
                SafeDisplay::call(fn () => $this->ui->setPermissionMode(PermissionMode::Prometheus->statusLabel(), PermissionMode::Prometheus->color()), $this->log);
            }

            return [null, false, false];
        }

        return [null, $permResult->autoApproved, false];
    }

    /**
     * Execute a single tool call with error handling and output truncation.
     */
    private function executeSingleTool(
        ToolCall $toolCall,
        array $args,
        Tool $tool,
        ?SubagentStats $stats,
        AgentMode $mode,
        ?Cancellation $cancellation = null,
    ): ToolResult {
        try {
            $cancellation?->throwIfRequested();
            \Amp\delay(0, cancellation: $cancellation);

            if ($toolCall->name === 'shell_start') {
                $args['read_only'] = $mode !== AgentMode::Edit;
            }
            if ($toolCall->name === 'execute_lua') {
                $args['_agent_mode'] = $mode->value;
            }

            $stats?->markTool($toolCall->name);
            try {
                $output = $tool->handle(...$args);
                \Amp\delay(0, cancellation: $cancellation);
                $cancellation?->throwIfRequested();
                $stats?->incrementToolCalls();
                $outputStr = ToolCallMapper::normalizeToolOutput($output);

                if ($this->truncator !== null) {
                    $outputStr = $this->truncator->truncate($outputStr, $toolCall->id);
                }

                $this->log->debug('Tool execution complete', [
                    'tool' => $toolCall->name,
                    'output_length' => strlen($outputStr),
                ]);

                $result = ToolCallMapper::toToolResult($toolCall->id, $toolCall->name, $args, $outputStr);
                if ($this->isMutativeFileTool($toolCall->name) && ! ToolCallMapper::isErrorResult($result)) {
                    FileReadTool::resetGlobalCache();
                }
            } finally {
                $stats?->clearCurrentTool();
            }

            return $result;
        } catch (CancelledException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $this->log->error('Tool execution failed', ['tool' => $toolCall->name, 'error' => $e->getMessage()]);

            return ToolCallMapper::toErrorResult($toolCall->id, $toolCall->name, $args, 'Error: '.ErrorSanitizer::sanitize($e->getMessage()));
        } catch (\Throwable $e) {
            $this->log->error('Tool execution failed with unexpected exception', [
                'tool' => $toolCall->name,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return ToolCallMapper::toErrorResult($toolCall->id, $toolCall->name, $args, 'Error: '.ErrorSanitizer::sanitize($e->getMessage()));
        }
    }

    /**
     * Partition approved tool calls into groups that can execute concurrently.
     *
     * Calls within a group have no file-path conflicts. Groups run sequentially.
     * Conservative: if any write conflict or bash+write mix exists, falls back to fully sequential.
     *
     * @param  array<array{ToolCall, Tool, array<string, mixed>}>  $approved
     * @return array<array<array{ToolCall, Tool, array<string, mixed>}>>
     */
    private function partitionConcurrentGroups(array $approved): array
    {
        if (count($approved) <= 1) {
            if ($approved === []) {
                return [];
            }

            return [$approved];
        }

        foreach ($approved as [$toolCall, $_, $_args]) {
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

        foreach ($approved as $i => [$toolCall, $tool, $args]) {
            $name = $toolCall->name;
            $path = $args['path'] ?? null;

            if ($path !== null && in_array($name, $writeTools, true)) {
                $resolved = realpath($path) ?: $path;
                $writePaths[$resolved][] = $i;
                $hasWrites = true;
            }
            if ($name === 'apply_patch') {
                $hasWrites = true;
                // Extract file paths from the patch argument for conflict detection
                $patchContent = $args['patch'] ?? '';
                if (is_string($patchContent) && $patchContent !== '') {
                    // Match paths from lines like: "Update File: path/to/file" or "*** Begin Patch\nAdd File: path"
                    if (preg_match_all('/(?:Update File|Add File|Delete File|File):\s*(\S+)/i', $patchContent, $matches)) {
                        foreach ($matches[1] as $extractedPath) {
                            $resolved = realpath($extractedPath) ?: $extractedPath;
                            $writePaths[$resolved][] = $i;
                        }
                    }
                }
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

            foreach ($approved as $j => [$tc, $_, $readArgs]) {
                if (in_array($j, $writeIndices, true)) {
                    continue;
                }
                $readPath = $readArgs['path'] ?? null;
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
        array $args,
        ToolResult $result,
        ?AgentContext $agentContext,
        array &$subagentBatch,
        array &$results,
    ): void {
        $success = ! ToolCallMapper::isErrorResult($result);

        if ($toolCall->name === 'subagent') {
            $agentId = $args['id'] ?? '';
            $orchestrator = $agentContext?->orchestrator;
            $subagentBatch[] = [
                'args' => $args,
                'result' => ToolCallMapper::cleanErrorResult($result),
                'success' => $success,
                'children' => $orchestrator !== null ? $this->treeBuilder->buildSubtree($orchestrator, $agentId) : [],
                'stats' => $orchestrator?->getStats($agentId),
            ];
            $results[] = ToolCallMapper::isErrorResult($result)
                ? ToolCallMapper::withReplacedContent($result, ToolCallMapper::cleanErrorResult($result))
                : $result;

            return;
        }

        // Flush any buffered subagents before showing non-subagent result
        if ($subagentBatch !== []) {
            SafeDisplay::call(fn () => $this->ui->showSubagentBatch($subagentBatch), $this->log);
            $subagentBatch = [];
        }

        SafeDisplay::call(fn () => $this->ui->showToolResult($toolCall->name, ToolCallMapper::cleanErrorResult($result), $success), $this->log);
        $results[] = ToolCallMapper::isErrorResult($result)
            ? ToolCallMapper::withReplacedContent($result, ToolCallMapper::cleanErrorResult($result))
            : $result;
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

    /**
     * Assign final subagent IDs before UI spawn/running display and execution.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function normalizeSubagentArgs(array $args, ?AgentContext $agentContext): array
    {
        $orchestrator = $agentContext?->orchestrator;
        if ($orchestrator === null) {
            return $args;
        }

        if (isset($args['agents']) && is_array($args['agents']) && $args['agents'] !== []) {
            foreach ($args['agents'] as $i => $spec) {
                if (! is_array($spec)) {
                    continue;
                }

                if (! isset($spec['id']) || $spec['id'] === '') {
                    $spec['id'] = $orchestrator->generateId();
                }

                $args['agents'][$i] = $spec;
            }

            return $args;
        }

        if (isset($args['task']) && trim((string) $args['task']) !== '' && (! isset($args['id']) || $args['id'] === '')) {
            $args['id'] = $orchestrator->generateId();
        }

        return $args;
    }

    /** Whether the tool is an interactive question tool (ask_user / ask_choice). */
    private function isAskTool(string $name): bool
    {
        return in_array($name, self::ASK_TOOLS, true);
    }

    /** Whether the tool can execute shell commands that might be mutative. */
    private function isReadOnlyShellTool(string $name): bool
    {
        return in_array($name, ['bash', 'shell_start', 'shell_write', 'shell_kill'], true);
    }

    private function isMutativeFileTool(string $name): bool
    {
        return in_array($name, ['file_write', 'file_edit', 'apply_patch'], true);
    }

    /** Extract the command string from a tool call's arguments (handles both 'command' and 'input' keys). */
    private function commandLikeInput(array $args): string
    {
        return (string) ($args['command'] ?? $args['input'] ?? '');
    }
}
