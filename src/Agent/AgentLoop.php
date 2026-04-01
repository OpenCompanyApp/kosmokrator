<?php

namespace Kosmokrator\Agent;

use Amp\CancelledException;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\AgentTreeBuilder;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\SafeDisplay;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Psr\Log\LoggerInterface;

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

    private readonly StuckDetector $stuckDetector;

    private readonly ToolExecutor $toolExecutor;

    private readonly ContextManager $contextManager;

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
        $this->stuckDetector = new StuckDetector;
        $this->toolExecutor = new ToolExecutor($ui, $log, $permissions, $truncator);
        $this->contextManager = new ContextManager(
            $llm, $ui, $log, $baseSystemPrompt,
            $compactor, $pruner, $models, $sessionManager, $taskStore,
        );
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

        try {
            while (true) {
                $round++;

                [$compactIn, $compactOut] = $this->contextManager->preFlightCheck($this->history);
                $this->sessionTokensIn += $compactIn;
                $this->sessionTokensOut += $compactOut;
                $this->contextManager->refreshSystemPrompt($this->mode);
                $this->injectPendingBackgroundResults();
                SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Thinking), $this->log);

                try {
                    $cancellation = $this->ui->getCancellation();
                    $response = $this->llm->chat($this->history->messages(), $this->tools, $cancellation);
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Tools), $this->log);
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
                        SafeDisplay::call(fn () => $this->ui->streamChunk($fullText), $this->log);
                    }
                } catch (CancelledException $e) {
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);
                    $this->log->info('LLM request cancelled by user', ['round' => $round]);

                    return;
                } catch (\Throwable $e) {
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);

                    // Context window overflow — compact or trim and retry
                    if ($this->isContextOverflow($e) && $trimAttempts < 3) {
                        $trimAttempts++;
                        $round--;
                        $messagesBefore = count($this->history->messages());

                        if ($this->compactor !== null && $trimAttempts === 1) {
                            [$cIn, $cOut] = $this->contextManager->performCompaction($this->history);
                            $this->sessionTokensIn += $cIn;
                            $this->sessionTokensOut += $cOut;
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
                    SafeDisplay::call(fn () => $this->ui->showError($e->getMessage()), $this->log);
                    $this->history->addAssistant('Error: '.$e->getMessage());

                    return;
                }

                if ($fullText !== '') {
                    SafeDisplay::call(fn () => $this->ui->streamComplete(), $this->log);
                }

                // If there were tool calls, execute them and loop
                if (! empty($toolCalls) && $finishReason === FinishReason::ToolCalls) {
                    $this->history->addAssistant($fullText, $toolCalls);
                    $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())], $tokensIn, $tokensOut);

                    try {
                        $toolResults = $this->toolExecutor->executeToolCalls(
                            $toolCalls, $this->tools, $this->allTools,
                            $this->mode, $this->agentContext, $this->stats,
                        );
                    } catch (\Throwable $e) {
                        $this->log->error('Tool execution failed', ['error' => $e->getMessage()]);
                        SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);
                        SafeDisplay::call(fn () => $this->ui->showError('Tool execution error: '.$e->getMessage()), $this->log);
                        $toolResults = array_map(
                            fn (ToolCall $tc) => new ToolResult($tc->id, $tc->name, $tc->arguments(), 'Error: '.$e->getMessage()),
                            $toolCalls,
                        );
                    }

                    $this->history->addToolResults($toolResults);
                    $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())]);

                    // Transition to Thinking early so the indicator appears immediately
                    // (the guard in setPhase prevents double-entry when the loop continues)
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Thinking), $this->log);

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
                    'model' => $this->contextManager->getModelName(),
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'rounds' => $round,
                ]);
                $this->history->addAssistant($fullText);
                $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())], $tokensIn, $tokensOut);
                // Auto-compaction check
                $modelName = $this->contextManager->getModelName();
                if ($this->compactor !== null && $this->compactor->needsCompaction($tokensIn, $modelName)) {
                    [$cIn, $cOut] = $this->contextManager->performCompaction($this->history);
                    $this->sessionTokensIn += $cIn;
                    $this->sessionTokensOut += $cOut;
                }

                $this->logMemoryUsage();

                SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);

                SafeDisplay::call(fn () => $this->ui->showStatus(
                    $modelName,
                    $tokensIn,
                    $tokensOut,
                    $this->getSessionCost(),
                    $this->contextManager->getContextWindow(),
                ), $this->log);

                return;
            }

            // Unreachable — loop exits via return
        } finally {
            // Guarantee phase resets to Idle when run() exits (idempotent if already Idle)
            SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);
        }
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

        $this->stuckDetector->reset();

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

            $this->contextManager->headlessPreFlightCheck($this->history);
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

                try {
                    $toolResults = $this->toolExecutor->executeToolCalls(
                        $toolCalls, $this->tools, $this->allTools,
                        $this->mode, $this->agentContext, $this->stats,
                    );
                } catch (\Throwable $e) {
                    $this->log->error('Headless tool execution failed', ['error' => $e->getMessage()]);
                    $toolResults = array_map(
                        fn (ToolCall $tc) => new ToolResult($tc->id, $tc->name, $tc->arguments(), 'Error: '.$e->getMessage()),
                        $toolCalls,
                    );
                }

                $this->history->addToolResults($toolResults);

                $this->injectPendingBackgroundResults();

                if ($this->deduplicator !== null) {
                    $this->deduplicator->deduplicate($this->history);
                }
                if ($this->pruner !== null) {
                    $this->pruner->prune($this->history);
                }

                // Stuck detection: check for repetitive tool call patterns
                $stuckState = $this->stuckDetector->check($toolCalls);

                if ($stuckState === 'force_return') {
                    $this->log->warning('Headless agent force-returned', [
                        'round' => $round,
                        'escalation' => $this->stuckDetector->getEscalation(),
                        'window' => $this->stuckDetector->getWindow(),
                    ]);
                    if ($this->stats !== null) {
                        $this->stats->error = 'forced return: agent did not converge';
                    }
                    $lastText = $fullText !== '' ? $fullText : $this->stuckDetector->extractLastAssistantText($this->history);

                    return $lastText."\n\n(forced return: agent did not converge after repeated nudges)";
                }
                if ($stuckState === 'nudge') {
                    $this->history->addUser('[SYSTEM] You appear to be repeating the same actions. Consolidate your findings and return a final response.');
                    $this->log->info('Stuck nudge injected', [
                        'round' => $round,
                        'window' => $this->stuckDetector->getWindow(),
                        'escalation' => $this->stuckDetector->getEscalation(),
                    ]);
                }
                if ($stuckState === 'final_notice') {
                    $this->history->addUser('[SYSTEM] FINAL NOTICE: You are still looping. Return your findings NOW. Do NOT make any more tool calls.');
                    $this->log->warning('Stuck final notice injected', [
                        'round' => $round,
                        'window' => $this->stuckDetector->getWindow(),
                        'escalation' => $this->stuckDetector->getEscalation(),
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

        return $this->estimateCost($this->contextManager->getModelName(), $tokensIn, $tokensOut);
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
        return $this->contextManager->getPruner();
    }

    public function getCompactor(): ?ContextCompactor
    {
        return $this->contextManager->getCompactor();
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

    private function estimateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        if ($this->models !== null) {
            return $this->models->estimateCost($model, $tokensIn, $tokensOut);
        }

        // Fallback: Sonnet-like pricing
        return round(($tokensIn * 3 / 1_000_000) + ($tokensOut * 15 / 1_000_000), 4);
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

        // Free memory for completed agents whose results we just consumed
        $this->agentContext->orchestrator->pruneCompleted();
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
        [$tokensIn, $tokensOut] = $this->contextManager->performCompaction($this->history);
        $this->sessionTokensIn += $tokensIn;
        $this->sessionTokensOut += $tokensOut;
    }
}
