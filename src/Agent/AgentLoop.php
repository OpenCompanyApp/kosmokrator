<?php

namespace Kosmokrator\Agent;

use Amp\CancelledException;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\AgentTreeBuilder;
use Kosmokrator\UI\RendererInterface;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;
use Psr\Log\LoggerInterface;

use function Amp\async;

class AgentLoop
{
    private ConversationHistory $history;

    /** @var Tool[] Full set of tools from registry */
    private array $allTools = [];

    /** @var Tool[] Active tools (filtered by mode) */
    private array $tools = [];

    private AgentMode $mode = AgentMode::Edit;

    private int $sessionTokensIn = 0;

    private int $sessionTokensOut = 0;

    private ?AgentContext $agentContext = null;

    private ?SubagentStats $stats = null;

    /** @var string[] Rolling window of tool call signatures for stuck detection */
    private array $toolCallWindow = [];

    private int $stuckEscalation = 0;

    private int $turnsSinceEscalation = 0;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly RendererInterface $ui,
        private readonly LoggerInterface $log,
        private readonly string $baseSystemPrompt,
        private readonly ?PermissionEvaluator $permissions = null,
        private readonly ?ModelCatalog $models = null,
        private readonly ?TaskStore $taskStore = null,
        private readonly ?SessionManager $sessionManager = null,
        private readonly ?ContextCompactor $compactor = null,
        private readonly ?OutputTruncator $truncator = null,
        private readonly ?ContextPruner $pruner = null,
        private readonly ?ToolResultDeduplicator $deduplicator = null,
        private readonly int $memoryWarningThreshold = 50 * 1024 * 1024,
    ) {
        $this->history = new ConversationHistory;
    }

    /**
     * Replace history with a pre-loaded one (for session resume).
     */
    public function setHistory(ConversationHistory $history): void
    {
        $this->history = $history;

        // Restore cumulative token totals from persisted messages
        if ($this->sessionManager !== null) {
            $totals = $this->sessionManager->getSessionTokenTotals();
            $this->sessionTokensIn = $totals['tokens_in'];
            $this->sessionTokensOut = $totals['tokens_out'];
        }
    }

    public function setAgentContext(?AgentContext $context): void
    {
        $this->agentContext = $context;
    }

    public function setStats(?SubagentStats $stats): void
    {
        $this->stats = $stats;
    }

    /**
     * @param  Tool[]  $tools
     */
    public function setTools(array $tools): void
    {
        $this->allTools = $tools;
        $this->applyModeFilter();
    }

    public function setMode(AgentMode $mode): void
    {
        $this->mode = $mode;
        $this->applyModeFilter();
        $this->llm->setSystemPrompt($this->baseSystemPrompt.$mode->systemPromptSuffix());
    }

    public function getMode(): AgentMode
    {
        return $this->mode;
    }

    private function applyModeFilter(): void
    {
        $allowed = $this->mode->allowedTools();
        $this->tools = array_values(array_filter(
            $this->allTools,
            fn (Tool $tool) => in_array($tool->name(), $allowed, true),
        ));
    }

    public function run(string $userInput): void
    {
        $this->log->debug('User input', ['input' => $userInput]);
        $this->history->addUser($userInput);
        $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())]);

        $round = 0;
        $trimAttempts = 0;

        while (true) {
            $round++;

            $this->preFlightContextCheck();
            $this->refreshSystemPrompt();
            $this->injectPendingBackgroundResults();
            $this->ui->setPhase(AgentPhase::Thinking);

            try {
                $cancellation = $this->ui->getCancellation();
                $response = $this->llm->chat($this->history->messages(), $this->tools, $cancellation);
                $this->ui->setPhase(AgentPhase::Tools);
                $trimAttempts = 0;

                $fullText = $response->text;
                $toolCalls = $response->toolCalls;
                $finishReason = $response->finishReason;
                $tokensIn = $response->promptTokens;
                $tokensOut = $response->completionTokens;

                // Accumulate session-level token usage
                $this->sessionTokensIn += $tokensIn;
                $this->sessionTokensOut += $tokensOut;

                if ($fullText !== '') {
                    $this->ui->streamChunk($fullText);
                }
            } catch (CancelledException $e) {
                $this->ui->setPhase(AgentPhase::Idle);
                $this->log->info('LLM request cancelled by user', ['round' => $round]);

                return;
            } catch (\Throwable $e) {
                $this->ui->setPhase(AgentPhase::Idle);

                // Context window overflow — compact or trim and retry
                if ($this->isContextOverflow($e) && $trimAttempts < 3) {
                    $trimAttempts++;
                    $round--;
                    $messagesBefore = count($this->history->messages());

                    if ($this->compactor !== null && $trimAttempts === 1) {
                        $this->performCompaction();
                    } else {
                        $this->history->trimOldest();
                    }

                    $this->log->warning('Context overflow, compacted/trimmed', [
                        'attempt' => $trimAttempts,
                        'messages_before' => $messagesBefore,
                        'messages_after' => count($this->history->messages()),
                    ]);

                    continue;
                }

                $this->log->error('LLM request failed', ['error' => $e->getMessage(), 'round' => $round]);
                $this->ui->showError($e->getMessage());
                $this->history->addAssistant('Error: '.$e->getMessage());

                return;
            }

            if ($fullText !== '') {
                $this->ui->streamComplete();
            }

            // If there were tool calls, execute them and loop
            if (! empty($toolCalls) && $finishReason === FinishReason::ToolCalls) {
                $this->history->addAssistant($fullText, $toolCalls);
                $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())], $tokensIn, $tokensOut);
                $toolResults = $this->executeToolCalls($toolCalls);
                $this->history->addToolResults($toolResults);
                $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())]);

                $this->injectPendingBackgroundResults();

                // Deduplicate superseded tool results (cheap, no LLM call)
                if ($this->deduplicator !== null) {
                    $deduped = $this->deduplicator->deduplicate($this->history);
                    if ($deduped > 0) {
                        $this->log->debug('Deduplicated tool results', ['superseded' => $deduped]);
                    }
                }

                // Prune old tool results (cheap, no LLM call)
                if ($this->pruner !== null) {
                    $saved = $this->pruner->prune($this->history);
                    if ($saved > 0) {
                        $this->log->debug('Pruned old tool results', ['tokens_saved' => $saved]);
                    }
                }

                $this->logMemoryUsage();

                continue;
            }

            // No tool calls — final response
            $this->log->info('LLM response complete', [
                'model' => $this->getModelName(),
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'rounds' => $round,
            ]);
            $this->history->addAssistant($fullText);
            $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())], $tokensIn, $tokensOut);
            // Auto-compaction check
            if ($this->compactor !== null && $this->compactor->needsCompaction($tokensIn, $this->getModelName())) {
                $this->performCompaction();
            }

            $this->logMemoryUsage();

            $this->ui->setPhase(AgentPhase::Idle);

            $modelName = $this->getModelName();
            $this->ui->showStatus(
                $modelName,
                $tokensIn,
                $tokensOut,
                $this->getSessionCost(),
                $this->getContextWindow($modelName),
            );

            return;
        }

        // Unreachable — loop exits via return
    }

    /**
     * Run the agent headlessly on a single task until completion.
     * Returns the final assistant response text.
     *
     * Used by subagents — no interactive UI, no session persistence,
     * no compaction, no dynamic system prompt refresh.
     */
    public function runHeadless(string $task): string
    {
        $this->log->debug('Headless agent started', ['task' => mb_substr($task, 0, 100), 'depth' => $this->agentContext?->depth]);
        $this->history->addUser($task);

        $this->toolCallWindow = [];
        $this->stuckEscalation = 0;
        $this->turnsSinceEscalation = 0;

        $round = 0;
        $trimAttempts = 0;

        while (true) {
            $round++;

            $this->log->debug('Headless round start', [
                'round' => $round,
                'tokens_in' => $this->sessionTokensIn,
                'tokens_out' => $this->sessionTokensOut,
                'history_messages' => count($this->history->messages()),
            ]);

            $this->headlessPreFlightCheck();
            $this->injectPendingBackgroundResults();

            try {
                $cancellation = $this->ui->getCancellation();
                $response = $this->llm->chat($this->history->messages(), $this->tools, $cancellation);
                $trimAttempts = 0;

                $fullText = $response->text;
                $toolCalls = $response->toolCalls;
                $finishReason = $response->finishReason;

                $this->sessionTokensIn += $response->promptTokens;
                $this->sessionTokensOut += $response->completionTokens;
                $this->stats?->addTokens($response->promptTokens, $response->completionTokens);

                $this->log->debug('Headless LLM response', [
                    'round' => $round,
                    'finish_reason' => $finishReason->value,
                    'tool_calls' => count($toolCalls),
                    'text_length' => strlen($fullText),
                    'prompt_tokens' => $response->promptTokens,
                    'completion_tokens' => $response->completionTokens,
                ]);
            } catch (CancelledException) {
                $this->log->info('Headless agent cancelled', ['round' => $round]);

                return '(cancelled)';
            } catch (\Throwable $e) {
                if ($this->isContextOverflow($e) && $trimAttempts < 3) {
                    $trimAttempts++;
                    $round--;
                    $messagesBefore = count($this->history->messages());
                    $this->history->trimOldest();
                    $this->log->warning('Headless context overflow, trimmed', [
                        'attempt' => $trimAttempts,
                        'messages_before' => $messagesBefore,
                        'messages_after' => count($this->history->messages()),
                    ]);

                    continue;
                }

                $this->log->error('Headless agent error', ['error' => $e->getMessage(), 'round' => $round]);

                return 'Error: '.$e->getMessage();
            }

            if (! empty($toolCalls) && $finishReason === FinishReason::ToolCalls) {
                $this->history->addAssistant($fullText, $toolCalls);
                $toolResults = $this->executeToolCalls($toolCalls);
                $this->history->addToolResults($toolResults);

                $this->injectPendingBackgroundResults();

                if ($this->deduplicator !== null) {
                    $this->deduplicator->deduplicate($this->history);
                }
                if ($this->pruner !== null) {
                    $this->pruner->prune($this->history);
                }

                // Stuck detection: check for repetitive tool call patterns
                $stuckState = $this->checkStuckState($toolCalls);

                if ($stuckState === 'force_return') {
                    $this->log->warning('Headless agent force-returned', [
                        'round' => $round,
                        'escalation' => $this->stuckEscalation,
                        'window' => $this->toolCallWindow,
                    ]);
                    if ($this->stats !== null) {
                        $this->stats->error = 'forced return: agent did not converge';
                    }
                    $lastText = $fullText !== '' ? $fullText : $this->extractLastAssistantText();

                    return $lastText."\n\n(forced return: agent did not converge after repeated nudges)";
                }
                if ($stuckState === 'nudge') {
                    $this->history->addUser('[SYSTEM] You appear to be repeating the same actions. Consolidate your findings and return a final response.');
                    $this->log->info('Stuck nudge injected', [
                        'round' => $round,
                        'window' => $this->toolCallWindow,
                        'escalation' => $this->stuckEscalation,
                    ]);
                }
                if ($stuckState === 'final_notice') {
                    $this->history->addUser('[SYSTEM] FINAL NOTICE: You are still looping. Return your findings NOW. Do NOT make any more tool calls.');
                    $this->log->warning('Stuck final notice injected', [
                        'round' => $round,
                        'window' => $this->toolCallWindow,
                        'escalation' => $this->stuckEscalation,
                    ]);
                }

                continue;
            }

            // Final response
            $this->log->info('Headless agent complete', [
                'rounds' => $round,
                'total_tokens_in' => $this->sessionTokensIn,
                'total_tokens_out' => $this->sessionTokensOut,
                'response_length' => strlen($fullText),
            ]);
            $this->history->addAssistant($fullText);

            return $fullText;
        }
    }

    /**
     * Check if the agent is stuck in a repetitive tool call loop.
     *
     * @param  ToolCall[]  $toolCalls
     * @return string 'ok'|'nudge'|'final_notice'|'force_return'
     */
    private function checkStuckState(array $toolCalls): string
    {
        // Build signatures and add to rolling window
        foreach ($toolCalls as $tc) {
            $this->toolCallWindow[] = $tc->name.':'.md5(json_encode($tc->arguments()));
        }
        $this->toolCallWindow = array_slice($this->toolCallWindow, -8);

        // Check if the latest call's signature appears 3+ times in the window
        // This focuses on active repetition — old repeats the agent has moved past don't trigger
        $latestSig = end($this->toolCallWindow);
        $latestCount = count(array_filter($this->toolCallWindow, fn ($s) => $s === $latestSig));
        $isStuck = $latestCount >= 3;

        if (! $isStuck) {
            if ($this->stuckEscalation > 0) {
                $this->stuckEscalation = 0;
                $this->turnsSinceEscalation = 0;
            }

            return 'ok';
        }

        // First detection → nudge
        if ($this->stuckEscalation === 0) {
            $this->stuckEscalation = 1;
            $this->turnsSinceEscalation = 0;

            return 'nudge';
        }

        $this->turnsSinceEscalation++;

        // Second escalation after 2 turns
        if ($this->stuckEscalation === 1 && $this->turnsSinceEscalation >= 2) {
            $this->stuckEscalation = 2;
            $this->turnsSinceEscalation = 0;

            return 'final_notice';
        }

        // Force return after 2 more turns
        if ($this->stuckEscalation >= 2 && $this->turnsSinceEscalation >= 2) {
            return 'force_return';
        }

        return 'ok';
    }

    private function extractLastAssistantText(): string
    {
        $messages = $this->history->messages();

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->content !== '') {
                return $messages[$i]->content;
            }
        }

        return '(no response generated)';
    }

    public function history(): ConversationHistory
    {
        return $this->history;
    }

    public function getSessionCost(): float
    {
        $tokensIn = $this->sessionTokensIn;
        $tokensOut = $this->sessionTokensOut;

        if ($this->agentContext !== null) {
            $sub = $this->agentContext->orchestrator->totalTokens();
            $tokensIn += $sub['in'];
            $tokensOut += $sub['out'];
        }

        return $this->estimateCost($this->getModelName(), $tokensIn, $tokensOut);
    }

    public function getSessionTokensIn(): int
    {
        return $this->sessionTokensIn;
    }

    public function getSessionTokensOut(): int
    {
        return $this->sessionTokensOut;
    }

    public function getPruner(): ?ContextPruner
    {
        return $this->pruner;
    }

    public function getCompactor(): ?ContextCompactor
    {
        return $this->compactor;
    }

    /**
     * Reset cost accumulators (used by /reset when starting a new session).
     */
    public function resetSessionCost(): void
    {
        $this->sessionTokensIn = 0;
        $this->sessionTokensOut = 0;
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @return ToolResult[]
     */
    private function executeToolCalls(array $toolCalls): array
    {
        // Phase 1: Permission checks + tool lookup (sequential — may prompt user)
        $approved = [];     // [[ToolCall, Tool], ...]
        $autoApproved = []; // id → bool
        $denied = [];       // id → ToolResult

        foreach ($toolCalls as $toolCall) {
            $this->log->info('Tool call', ['tool' => $toolCall->name, 'args' => $toolCall->arguments()]);

            // checkPermission shows tool call header + handles user prompts for denied/asked
            [$permDenied, $wasAutoApproved] = $this->checkPermission($toolCall);
            if ($permDenied !== null) {
                $denied[$toolCall->id] = $permDenied;

                continue;
            }

            $tool = $this->findTool($toolCall->name);
            if ($tool === null) {
                $existsInAll = $this->findToolInAll($toolCall->name) !== null;
                $output = $existsInAll
                    ? "Tool '{$toolCall->name}' is not available in {$this->mode->label()} mode. Switch to Edit mode to use write tools."
                    : "Tool '{$toolCall->name}' not found.";
                $this->ui->showToolCall($toolCall->name, $toolCall->arguments());
                $this->ui->showToolResult($toolCall->name, $output, false);
                $denied[$toolCall->id] = new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments(), $output);

                continue;
            }

            // Ask-mode bash write-guard
            if ($this->mode === AgentMode::Ask && $tool->name() === 'bash') {
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
                $outcomes[$toolCall->id] = $this->executeSingleTool($toolCall, $tool);
            } else {
                $futures = [];
                foreach ($group as [$toolCall, $tool]) {
                    $futures[$toolCall->id] = async(fn () => $this->executeSingleTool($toolCall, $tool));
                }
                // Await all futures — executeSingleTool has try/catch so these won't throw
                foreach ($futures as $id => $future) {
                    $outcomes[$id] = $future->await();
                }
            }
        }

        // Phase 3: Collect results in original order + UI display
        // Denied/not-found calls already displayed header+result in phase 1.
        // Approved calls: show header + auto-approve indicator + result together.
        // Subagent calls are batched for grouped display.
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
                    $orchestrator = $this->agentContext?->orchestrator;
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
     * Build the full live agent tree from orchestrator stats.
     *
     * Delegates to AgentTreeBuilder — kept as a public method because
     * AgentCommand wires it as the TUI tree provider.
     *
     * @return array<int, array{id: string, type: string, task: string, status: string, elapsed: float, success: bool, error: ?string, children: array}>
     */
    public function buildLiveAgentTree(): array
    {
        if ($this->agentContext === null) {
            return [];
        }

        return AgentTreeBuilder::buildTree($this->agentContext->orchestrator);
    }

    /**
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

    private function executeSingleTool(ToolCall $toolCall, Tool $tool): ToolResult
    {
        try {
            $output = $tool->handle(...$toolCall->arguments());
            $this->stats?->incrementToolCalls();
            $outputStr = match (true) {
                is_string($output) => $output,
                $output instanceof ToolOutput => $output->output,
                $output instanceof ToolError => $output->error,
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
     * Calls within a group have no file-path conflicts. Groups run sequentially.
     *
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
            // Multiple writes to same file
            if (count($writeIndices) > 1) {
                return array_map(fn ($item) => [$item], $approved);
            }

            // Any other call touching the same path
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

    private function findTool(string $name): ?Tool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    private function findToolInAll(string $name): ?Tool
    {
        foreach ($this->allTools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    private function getModelName(): string
    {
        return $this->llm->getProvider().'/'.$this->llm->getModel();
    }

    private function estimateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        if ($this->models !== null) {
            return $this->models->estimateCost($model, $tokensIn, $tokensOut);
        }

        // Fallback: Sonnet-like pricing
        return round(($tokensIn * 3 / 1_000_000) + ($tokensOut * 15 / 1_000_000), 4);
    }

    private function getContextWindow(string $model): int
    {
        if ($this->models !== null) {
            return $this->models->contextWindow($model);
        }

        return 200_000;
    }

    private function isContextOverflow(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'max length')
            || str_contains($message, 'max tokens')
            || str_contains($message, 'context length')
            || str_contains($message, 'too long')
            || str_contains($message, 'token limit');
    }

    private function preFlightContextCheck(): void
    {
        if ($this->compactor === null && $this->pruner === null) {
            return;
        }

        $estimated = TokenEstimator::estimateMessages($this->history->messages());
        $modelName = $this->getModelName();

        // Use compactor's configurable threshold; fall back to 80% for pruner-only mode
        if ($this->compactor !== null) {
            $threshold = $this->compactor->getThresholdTokens($modelName);
        } else {
            $threshold = (int) ($this->getContextWindow($modelName) * 0.8);
        }

        if ($estimated < $threshold) {
            return;
        }

        $this->log->info('Pre-flight context check: estimated tokens exceed threshold', [
            'estimated' => $estimated,
            'threshold' => $threshold,
        ]);

        if ($this->compactor !== null) {
            $this->performCompaction();
        } else {
            $this->history->trimOldest();
        }
    }

    /**
     * Simplified pre-flight check for headless mode — only trim, never compact.
     */
    private function headlessPreFlightCheck(): void
    {
        if ($this->pruner === null) {
            return;
        }

        $estimated = TokenEstimator::estimateMessages($this->history->messages());
        $threshold = (int) ($this->getContextWindow($this->getModelName()) * 0.8);

        if ($estimated >= $threshold) {
            $messagesBefore = count($this->history->messages());
            $this->history->trimOldest();
            $this->log->info('Headless pre-flight trim', [
                'estimated_tokens' => $estimated,
                'threshold' => $threshold,
                'messages_before' => $messagesBefore,
                'messages_after' => count($this->history->messages()),
            ]);
        }
    }

    /**
     * Inject completed background subagent results into conversation history.
     */
    private function injectPendingBackgroundResults(): void
    {
        if ($this->agentContext === null) {
            return;
        }

        $results = $this->agentContext->orchestrator->collectPendingResults($this->agentContext->id);
        if (empty($results)) {
            return;
        }

        $parts = [];
        foreach ($results as $id => $result) {
            $stats = $this->agentContext->orchestrator->getStats($id);
            $type = $stats?->agentType ?? 'agent';
            $tools = $stats?->toolCalls ?? 0;
            $parts[] = "[Background {$type} agent '{$id}' completed ({$tools} tool calls)]:\n{$result}";
        }

        // Show completed background agents using the subagent batch display
        $batchEntries = [];
        foreach ($results as $id => $result) {
            $stats = $this->agentContext->orchestrator->getStats($id);
            $batchEntries[] = [
                'args' => [
                    'type' => $stats?->agentType ?? 'explore',
                    'id' => $id,
                    'task' => $stats?->task ?? '',
                    'mode' => 'background',
                ],
                'result' => $result,
                'success' => $stats?->status !== 'failed',
            ];
        }
        $this->ui->showSubagentBatch($batchEntries);

        $this->history->addUser(implode("\n\n---\n\n", $parts));
        $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())]);
        $this->log->debug('Injected background results', ['count' => count($results)]);
    }

    private function logMemoryUsage(): void
    {
        $usage = memory_get_usage(true);
        $usageMB = round($usage / 1024 / 1024, 1);

        $this->log->debug('Memory usage', ['mb' => $usageMB]);

        if ($usage > $this->memoryWarningThreshold) {
            $this->log->warning('High memory usage', [
                'usage_mb' => $usageMB,
                'threshold_mb' => round($this->memoryWarningThreshold / 1024 / 1024, 1),
            ]);
        }
    }

    private function persistMessage(Message $message, int $tokensIn = 0, int $tokensOut = 0): void
    {
        $this->sessionManager?->saveMessage($message, $tokensIn, $tokensOut);
    }

    public function performCompaction(): void
    {
        if ($this->compactor === null) {
            $this->log->warning('Compaction requested but no compactor configured');

            return;
        }

        $this->log->info('Starting context compaction');
        $this->ui->showCompacting();

        try {
            $result = $this->compactor->compact($this->history);
            $summary = $result['summary'];

            // Track compaction LLM call cost
            $this->sessionTokensIn += $result['tokens_in'];
            $this->sessionTokensOut += $result['tokens_out'];

            if ($summary === '') {
                $this->ui->clearCompacting();
                $this->ui->showNotice('Nothing to compact.');

                return;
            }

            // Persist compaction to database
            $this->sessionManager?->persistCompaction($summary);

            // In-memory: replace old messages with summary
            $this->history->compact($summary);

            // Save compaction summary as memory
            if ($this->sessionManager !== null) {
                $title = mb_substr($summary, 0, 80);
                $this->sessionManager->addMemory('compaction', $title, $summary);
            }

            // Extract durable memories from summary (best-effort)
            $extraction = $this->compactor->extractMemories($summary);

            // Track memory extraction LLM call cost
            $this->sessionTokensIn += $extraction['tokens_in'];
            $this->sessionTokensOut += $extraction['tokens_out'];

            if ($this->sessionManager !== null) {
                foreach ($extraction['memories'] as $item) {
                    $this->sessionManager->addMemory($item['type'], $item['title'], $item['content']);
                }
            }

            $this->ui->clearCompacting();
            $this->ui->showNotice('Context compacted.');
            $this->log->info('Compaction complete', [
                'memories_extracted' => count($extraction['memories']),
                'messages_after' => count($this->history->messages()),
                'compaction_tokens_in' => $result['tokens_in'],
                'compaction_tokens_out' => $result['tokens_out'],
                'summary_length' => strlen($summary),
            ]);
        } catch (\Throwable $e) {
            $this->ui->clearCompacting();
            $messagesBefore = count($this->history->messages());
            $this->history->trimOldest();
            $this->log->error('Compaction failed, falling back to trimOldest', [
                'error' => $e->getMessage(),
                'messages_before' => $messagesBefore,
                'messages_after' => count($this->history->messages()),
            ]);
        }
    }

    private function refreshSystemPrompt(): void
    {
        $prompt = $this->baseSystemPrompt.$this->mode->systemPromptSuffix();

        if ($this->taskStore !== null && ! $this->taskStore->isEmpty()) {
            $prompt .= "\n\n## Current Tasks\n".$this->taskStore->renderTree();
        }

        $this->llm->setSystemPrompt($prompt);
    }
}
