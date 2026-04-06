<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\Cancellation;
use Amp\CancelledException;
use Illuminate\Contracts\Events\Dispatcher;
use Kosmokrator\Agent\Event\ContextCompacted;
use Kosmokrator\Agent\Event\LlmResponseReceived;
use Kosmokrator\Agent\Event\MessagePersisted;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\MessageMapper;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ToolCallMapper;
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

/**
 * Core agent loop: sends conversation history to the LLM, executes tool calls via ToolExecutor,
 * and manages context through ContextManager. Delegates stuck detection to StuckDetector.
 * Used by AgentSession for interactive REPL and by SubagentFactory for headless execution.
 *
 * @see ToolExecutor
 * @see ContextManager
 * @see StuckDetector
 * @see AgentSession
 */
class AgentLoop
{
    private ConversationHistory $history;

    /** @var Tool[] Full set of tools from registry */
    private array $allTools = [];

    /** @var Tool[] Active tools (filtered by mode) */
    private array $tools = [];

    private AgentMode $mode = AgentMode::Edit;

    private SessionTokenTracker $tokens;

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
        private readonly ?ContextBudget $budget = null,
        private readonly ?ProtectedContextBuilder $protectedContextBuilder = null,
        private readonly int $memoryWarningThreshold = 50 * 1024 * 1024,
        private readonly ?Dispatcher $events = null,
        private readonly AgentTreeBuilder $treeBuilder = new AgentTreeBuilder,
    ) {
        $this->history = new ConversationHistory;
        $this->tokens = new SessionTokenTracker;
        $this->stuckDetector = new StuckDetector;
        $this->toolExecutor = new ToolExecutor($ui, $log, $permissions, $truncator, $treeBuilder);
        $this->contextManager = new ContextManager(
            $llm, $ui, $log, $baseSystemPrompt,
            $compactor, $pruner, $models, $sessionManager, $taskStore,
            $budget, $protectedContextBuilder,
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
            $this->tokens->tokensIn = $totals['tokens_in'];
            $this->tokens->tokensOut = $totals['tokens_out'];
        }
    }

    /** Set the subagent tree context (root for top-level, child for subagents). */
    public function setAgentContext(?AgentContext $context): void
    {
        $this->agentContext = $context;
    }

    /**
     * Whether background subagents are still running.
     * Used by the REPL to wait before collecting results.
     */
    public function hasRunningBackgroundAgents(): bool
    {
        if ($this->agentContext === null) {
            return false;
        }

        return $this->agentContext->orchestrator->hasRunningBackgroundAgents($this->agentContext->id);
    }

    /**
     * Whether completed background agents have uncollected results.
     * Used by the REPL to decide whether to auto-continue after all agents finish.
     */
    public function hasPendingBackgroundResults(): bool
    {
        if ($this->agentContext === null) {
            return false;
        }

        return $this->agentContext->orchestrator->hasPendingResults($this->agentContext->id);
    }

    /** Attach a stats collector for headless subagent token tracking. */
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

    /**
     * Run the interactive agent loop: send user input, execute tool calls, repeat until final response.
     * Handles context overflow via compaction/trimming and persists messages via SessionManager.
     *
     * @param  string  $userInput  The raw user message to process
     */
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

                // Yield to the event loop at the start of each iteration so
                // stdin input, TUI rendering, and cancellation signals are
                // processed even during long synchronous loops.
                \Amp\delay(0);

                [$compactIn, $compactOut] = $this->contextManager->preFlightCheck($this->history, $this->mode, $this->agentContext);
                $this->tokens->accumulate($compactIn, $compactOut);
                if ($compactIn > 0 || $compactOut > 0) {
                    $this->events?->dispatch(new ContextCompacted(0, $compactIn, $compactOut));
                }
                $this->injectPendingBackgroundResults();
                $this->injectQueuedUserMessages();
                $this->contextManager->refreshSystemPrompt($this->mode, $this->history, $this->agentContext);
                SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Thinking), $this->log);

                try {
                    $cancellation = $this->ui->getCancellation();
                    $responseData = $this->callLlm($cancellation);

                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Tools), $this->log);
                    $trimAttempts = 0;

                    $fullText = $responseData->text;
                    $toolCalls = $responseData->toolCalls;
                    $finishReason = $responseData->finishReason;
                    $tokensIn = $responseData->tokensIn;
                    $tokensOut = $responseData->tokensOut;
                    $cacheReadInputTokens = $responseData->cacheReadInputTokens;
                    $cacheWriteInputTokens = $responseData->cacheWriteInputTokens;

                    // Accumulate session-level token usage
                    $this->tokens->accumulate($tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens);

                    $this->events?->dispatch(new LlmResponseReceived(
                        $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens,
                        $this->contextManager->getModelName(),
                    ));

                    // Show reasoning as a single collapsed block (both streaming and non-streaming)
                    // Only show reasoning if it has meaningful content (skip trivial fragments)
                    if (mb_strlen(trim($responseData->reasoningContent)) > 40) {
                        SafeDisplay::call(fn () => $this->ui->showReasoningContent($responseData->reasoningContent), $this->log);
                    }

                    // For non-streaming path, display the full text; streaming already showed it
                    if ($fullText !== '' && ! $this->llm->supportsStreaming()) {
                        SafeDisplay::call(fn () => $this->ui->streamChunk($fullText), $this->log);
                    }
                } catch (CancelledException $e) {
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);
                    $this->log->info('LLM request cancelled by user', ['round' => $round]);

                    return;
                } catch (\RuntimeException $e) {
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);

                    // Context window overflow — compact or trim and retry
                    if ($this->handleContextOverflow($e, $trimAttempts)) {
                        $round--;

                        continue;
                    }

                    $this->log->error('LLM request failed', ['error' => $e->getMessage(), 'round' => $round]);
                    SafeDisplay::call(fn () => $this->ui->showError($e->getMessage()), $this->log);
                    $this->history->addAssistant('Error: '.ErrorSanitizer::sanitize($e->getMessage()));

                    return;
                } catch (\Throwable $e) {
                    $this->log->error('LLM request failed with unexpected exception', [
                        'exception' => get_class($e),
                        'error' => $e->getMessage(),
                        'round' => $round,
                    ]);
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);
                    SafeDisplay::call(fn () => $this->ui->showError('An unexpected error occurred.'), $this->log);
                    $this->history->addAssistant('Error: An unexpected error occurred.');

                    return;
                }

                if ($fullText !== '') {
                    SafeDisplay::call(fn () => $this->ui->streamComplete(), $this->log);
                }
                if (! empty($toolCalls) && $finishReason === FinishReason::ToolCalls) {
                    $this->history->addAssistant($fullText, $toolCalls);
                    $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())], $tokensIn, $tokensOut);

                    try {
                        $toolResults = $this->toolExecutor->executeToolCalls(
                            $toolCalls, $this->tools, $this->allTools,
                            $this->mode, $this->agentContext, $this->stats,
                        );
                    } catch (\Throwable $e) {
                        $toolResults = $this->handleToolExecutionError($e, $toolCalls, interactive: true);
                    }

                    $this->history->addToolResults($toolResults);
                    $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())]);

                    // Transition to Thinking early so the indicator appears immediately
                    // (the guard in setPhase prevents double-entry when the loop continues)
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Thinking), $this->log);

                    $this->injectPendingBackgroundResults();
                    $this->injectQueuedUserMessages();

                    // Yield so queued stdin events are processed before the
                    // next (potentially blocking) LLM call.
                    \Amp\delay(0);

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

                // Truncated response — max_tokens hit, continue to get more
                if ($finishReason === FinishReason::Length) {
                    $this->log->warning('LLM response truncated (max_tokens)', ['round' => $round]);
                    $this->history->addAssistant($fullText);
                    $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())], $tokensIn, $tokensOut);

                    // Ask the LLM to continue from where it left off
                    $this->history->addUser('Continue from where you left off. Do not repeat what you already said.');
                    SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Thinking), $this->log);

                    continue;
                }

                // No tool calls — final response
                $this->handleFinalResponse($fullText, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $round);

                SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);

                $modelName = $this->contextManager->getModelName();
                SafeDisplay::call(fn () => $this->ui->showStatus(
                    $this->formatStatusModelLabel($modelName),
                    $tokensIn,
                    $tokensOut,
                    $this->getSessionDisplayCost(),
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
     * Used by subagents — no interactive UI or session persistence.
     */
    public function runHeadless(string $task): string
    {
        $this->log->debug('Headless agent started', ['task' => mb_substr($task, 0, 100), 'depth' => $this->agentContext?->depth]);
        $this->history->addUser($task);

        $this->stuckDetector->reset();

        $round = 0;
        $trimAttempts = 0;

        try {
            while (true) {
                $round++;
                $this->stats?->touchActivity();

                $this->log->debug('Headless round start', [
                    'round' => $round,
                    'tokens_in' => $this->tokens->tokensIn,
                    'tokens_out' => $this->tokens->tokensOut,
                    'history_messages' => count($this->history->messages()),
                ]);

                [$compactIn, $compactOut] = $this->contextManager->headlessPreFlightCheck($this->history, $this->mode, $this->agentContext);
                $this->tokens->accumulate($compactIn, $compactOut);
                if ($compactIn > 0 || $compactOut > 0) {
                    $this->events?->dispatch(new ContextCompacted(0, $compactIn, $compactOut));
                }
                $this->injectPendingBackgroundResults();
                $this->contextManager->refreshSystemPrompt($this->mode, $this->history, $this->agentContext);

                try {
                    $cancellation = $this->ui->getCancellation();
                    $responseData = $this->callLlm($cancellation);
                    $trimAttempts = 0;

                    $fullText = $responseData->text;
                    $toolCalls = $responseData->toolCalls;
                    $finishReason = $responseData->finishReason;

                    $this->tokens->accumulate($responseData->tokensIn, $responseData->tokensOut, $responseData->cacheReadInputTokens, $responseData->cacheWriteInputTokens);
                    $this->stats?->addTokens($responseData->tokensIn, $responseData->tokensOut);
                    $this->stats?->touchActivity();

                    $this->events?->dispatch(new LlmResponseReceived(
                        $responseData->tokensIn, $responseData->tokensOut,
                        $responseData->cacheReadInputTokens, $responseData->cacheWriteInputTokens,
                        $this->contextManager->getModelName(),
                    ));

                    $this->log->debug('Headless LLM response', [
                        'round' => $round,
                        'finish_reason' => $finishReason->value,
                        'tool_calls' => count($toolCalls),
                        'text_length' => strlen($fullText),
                        'prompt_tokens' => $responseData->tokensIn,
                        'completion_tokens' => $responseData->tokensOut,
                    ]);
                } catch (CancelledException $e) {
                    $watchdogReason = $this->watchdogCancellationReason($e);

                    if ($watchdogReason !== null) {
                        $this->log->warning('Headless agent cancelled by watchdog', [
                            'round' => $round,
                            'reason' => $watchdogReason,
                        ]);
                        if ($this->stats !== null) {
                            $this->stats->error = $watchdogReason;
                        }

                        throw new \RuntimeException($watchdogReason, previous: $e);
                    }

                    $this->log->info('Headless agent cancelled', ['round' => $round]);

                    return '(cancelled)';
                } catch (\Throwable $e) {
                    if ($this->handleContextOverflow($e, $trimAttempts)) {
                        $round--;

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
                        $toolResults = $this->handleToolExecutionError($e, $toolCalls, interactive: false);
                    }

                    $this->history->addToolResults($toolResults);
                    $this->stats?->touchActivity();

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
                $this->handleFinalResponse($fullText, $responseData->tokensIn, $responseData->tokensOut, $responseData->cacheReadInputTokens, $responseData->cacheWriteInputTokens, $round);
                $this->stats?->touchActivity();

                return $fullText;
            }
        } finally {
            $this->log->debug('Headless agent exiting, resetting phase');
            SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);
        }
    }

    public function history(): ConversationHistory
    {
        return $this->history;
    }

    public function getSessionCost(): float
    {
        return $this->calculateSessionCost(display: false);
    }

    public function getSessionDisplayCost(): float
    {
        return $this->calculateSessionCost(display: true);
    }

    /** Compute session cost combining this loop's tokens with subagent orchestrator totals. */
    private function calculateSessionCost(bool $display): float
    {
        $tokensIn = $this->tokens->tokensIn;
        $tokensOut = $this->tokens->tokensOut;

        if ($this->agentContext !== null) {
            $sub = $this->agentContext->orchestrator->totalTokens();
            $tokensIn += $sub['in'];
            $tokensOut += $sub['out'];
        }

        return $display ? $this->estimateDisplayCost(
            $this->contextManager->getModelName(),
            $tokensIn,
            $tokensOut,
            $this->tokens->cacheReadInputTokens,
            $this->tokens->cacheWriteInputTokens,
        ) : $this->estimateCost(
            $this->contextManager->getModelName(),
            $tokensIn,
            $tokensOut,
            $this->tokens->cacheReadInputTokens,
            $this->tokens->cacheWriteInputTokens,
        );
    }

    public function getSessionTokensIn(): int
    {
        return $this->tokens->tokensIn;
    }

    public function getSessionTokensOut(): int
    {
        return $this->tokens->tokensOut;
    }

    private function watchdogCancellationReason(CancelledException $e): ?string
    {
        $previous = $e->getPrevious();
        if (! $previous instanceof \Throwable) {
            return null;
        }

        $message = $previous->getMessage();

        return str_starts_with($message, 'watchdog:') ? $message : null;
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
        $this->tokens->reset();
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

        return $this->treeBuilder->buildTree($this->agentContext->orchestrator);
    }

    private function estimateCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
    ): float {
        if ($this->models !== null) {
            return $this->models->estimateCost(
                $model,
                $tokensIn,
                $tokensOut,
                $cacheReadInputTokens,
                $cacheWriteInputTokens,
                $this->llm->getProvider(),
            );
        }

        // Fallback: Sonnet-like pricing
        return round(($tokensIn * 3 / 1_000_000) + ($tokensOut * 15 / 1_000_000), 4);
    }

    private function estimateDisplayCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
    ): float {
        if ($this->models !== null) {
            return $this->models->estimateDisplayCost(
                $model,
                $tokensIn,
                $tokensOut,
                $cacheReadInputTokens,
                $cacheWriteInputTokens,
                $this->llm->getProvider(),
            );
        }

        return $this->estimateCost($model, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens);
    }

    private function formatStatusModelLabel(string $modelName): string
    {
        return $modelName;
    }

    /** Invalidate tool caches after compaction rewrites history (tools may hold stale references). */
    private function resetToolCachesAfterCompaction(): void
    {
        foreach ($this->allTools as $tool) {
            if (method_exists($tool, 'resetCache')) {
                $tool->resetCache();
            }
        }
    }

    /**
     * Call the LLM using streaming or non-streaming path as appropriate.
     * Returns a normalized ResponseData regardless of which path was used.
     */
    private function callLlm(?Cancellation $cancellation): ResponseData
    {
        if ($this->llm->supportsStreaming()) {
            [$fullText, $toolCalls, $finishReason, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningContent] =
                $this->streamResponse($this->history->messages(), $this->tools, $cancellation);

            return new ResponseData($fullText, $toolCalls, $finishReason, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningContent);
        }

        $response = $this->llm->chat($this->history->messages(), $this->tools, $cancellation);

        return new ResponseData(
            $response->text,
            $response->toolCalls,
            $response->finishReason,
            $response->promptTokens,
            $response->completionTokens,
            $response->cacheReadInputTokens,
            $response->cacheWriteInputTokens,
            $response->reasoningContent,
        );
    }

    /**
     * Handle context overflow by compacting or trimming history.
     * Returns true if recovery succeeded and the loop should retry.
     */
    private function handleContextOverflow(\Throwable $e, int &$trimAttempts): bool
    {
        if (! $this->isContextOverflow($e) || $trimAttempts >= 3) {
            return false;
        }

        $trimAttempts++;
        $messagesBefore = count($this->history->messages());

        if ($this->compactor !== null && $trimAttempts === 1) {
            [$cIn, $cOut] = $this->contextManager->performCompaction($this->history, $this->mode, $this->agentContext);
            $this->tokens->accumulate($cIn, $cOut);
            if ($cIn > 0 || $cOut > 0) {
                $this->resetToolCachesAfterCompaction();
                $this->events?->dispatch(new ContextCompacted(0, $cIn, $cOut));
            }
        } else {
            $this->history->trimOldest();
        }

        $this->log->warning('Context overflow, compacted/trimmed', [
            'attempt' => $trimAttempts,
            'messages_before' => $messagesBefore,
            'messages_after' => count($this->history->messages()),
        ]);

        return true;
    }

    /**
     * Handle a tool execution error by logging, optionally displaying UI feedback,
     * and producing error results for each tool call.
     *
     * @param  ToolCall[]  $toolCalls
     * @return array<int, ToolResult>
     */
    private function handleToolExecutionError(\Throwable $e, array $toolCalls, bool $interactive): array
    {
        $this->log->error($interactive ? 'Tool execution failed' : 'Headless tool execution failed', ['error' => $e->getMessage()]);

        if ($interactive) {
            SafeDisplay::call(fn () => $this->ui->setPhase(AgentPhase::Idle), $this->log);
            SafeDisplay::call(fn () => $this->ui->showError('Tool execution error: '.$e->getMessage()), $this->log);
        }

        return array_map(
            fn (ToolCall $tc) => ToolCallMapper::toErrorResult($tc->id, $tc->name, $tc->arguments(), 'Error: '.ErrorSanitizer::sanitize($e->getMessage())),
            $toolCalls,
        );
    }

    /**
     * Handle the final LLM response: log completion, persist to history, and optionally compact.
     */
    private function handleFinalResponse(string $fullText, int $tokensIn, int $tokensOut, int $cacheRead, int $cacheWrite, int $round): void
    {
        $this->log->info('LLM response complete', [
            'model' => $this->contextManager->getModelName(),
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cache_read_input_tokens' => $cacheRead,
            'cache_write_input_tokens' => $cacheWrite,
            'rounds' => $round,
        ]);
        $this->history->addAssistant($fullText);
        $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())], $tokensIn, $tokensOut);

        if ($this->compactor !== null && $this->contextManager->shouldCompactHistory($this->history, $this->mode, $this->agentContext)) {
            [$cIn, $cOut] = $this->contextManager->performCompaction($this->history, $this->mode, $this->agentContext);
            $this->tokens->accumulate($cIn, $cOut);
            if ($cIn > 0 || $cOut > 0) {
                $this->resetToolCachesAfterCompaction();
                $this->events?->dispatch(new ContextCompacted(0, $cIn, $cOut));
            }
        }

        $this->logMemoryUsage();
    }

    /** Heuristic check: does this exception indicate the context window was exceeded? */
    private function isContextOverflow(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        // Check HTTP status codes commonly returned for context overflow
        if (preg_match('/\b(400|413)\b/', $message) && (
            str_contains($message, 'token')
            || str_contains($message, 'context')
            || str_contains($message, 'length')
            || str_contains($message, 'input')
            || str_contains($message, 'request')
        )) {
            return true;
        }

        return str_contains($message, 'max length')
            || str_contains($message, 'max tokens')
            || str_contains($message, 'context length')
            || str_contains($message, 'context_length_exceeded')
            || str_contains($message, 'context window')
            || str_contains($message, 'too many tokens')
            || str_contains($message, 'too long')
            || str_contains($message, 'token limit')
            || str_contains($message, 'input is too long')
            || str_contains($message, 'request too large')
            || str_contains($message, 'prompt is too long')
            || str_contains($message, 'reduced context')
            || str_contains($message, 'input_length_exceeded')
            || (str_contains($message, 'content filter')
                && str_contains($message, 'length'));
    }

    /**
     * Inject completed background subagent results into conversation history.
     */
    private function injectPendingBackgroundResults(): void
    {
        if ($this->agentContext === null) {
            return;
        }

        // Always prune completed agents at the start of each round so
        // await-mode agent stats don't accumulate and inflate the live tree.
        $this->agentContext->orchestrator->pruneCompleted();

        $results = $this->agentContext->orchestrator->collectPendingResults($this->agentContext->id);
        if (empty($results)) {
            return;
        }

        $parts = [];
        foreach ($results as $id => $result) {
            $stats = $this->agentContext->orchestrator->getStats($id);
            $type = $stats->agentType ?? 'agent';
            $tools = $stats->toolCalls ?? 0;
            $parts[] = "[Background {$type} agent '{$id}' completed ({$tools} tool calls)]:\n{$result}";
        }

        // Show completed background agents using the subagent batch display
        $batchEntries = [];
        foreach ($results as $id => $result) {
            $stats = $this->agentContext->orchestrator->getStats($id);
            $batchEntries[] = [
                'args' => [
                    'type' => $stats->agentType ?? 'explore',
                    'id' => $id,
                    'task' => $stats->task ?? '',
                    'mode' => 'background',
                ],
                'result' => $result,
                'success' => $stats->status !== 'failed',
            ];
        }
        $this->ui->showSubagentBatch($batchEntries);

        $this->history->addUser(implode("\n\n---\n\n", $parts));
        $this->persistMessage($this->history->messages()[array_key_last($this->history->messages())]);
        $this->log->debug('Injected background results', ['count' => count($results)]);
    }

    /**
     * Drain queued user messages from the UI and inject them into conversation history.
     * This allows follow-up messages typed during tool execution to be seen by the LLM
     * on the next API call within the same turn.
     */
    private function injectQueuedUserMessages(): void
    {
        while (($message = $this->ui->consumeQueuedMessage()) !== null) {
            $this->history->addUser($message);
            $lastMessage = $this->history->messages()[array_key_last($this->history->messages())];
            $this->persistMessage($lastMessage);
            $this->log->debug('Injected queued user message mid-turn', ['length' => strlen($message)]);
        }
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

    /** Persist a message to session storage if SessionManager is available. */
    private function persistMessage(Message $message, int $tokensIn = 0, int $tokensOut = 0): void
    {
        $this->sessionManager?->saveMessage($message, $tokensIn, $tokensOut);

        if ($this->sessionManager !== null) {
            $this->events?->dispatch(new MessagePersisted(
                MessageMapper::roleOf($message), $tokensIn, $tokensOut,
            ));
        }
    }

    /**
     * Stream an LLM response, rendering text deltas line-by-line as they arrive.
     *
     * Returns the assembled [fullText, toolCalls, finishReason, tokensIn, tokensOut,
     * cacheReadInputTokens, cacheWriteInputTokens, reasoningContent].
     *
     * @return array{string, ToolCall[], FinishReason, int, int, int, int, string}
     */
    private function streamResponse(array $messages, array $tools, ?Cancellation $cancellation): array
    {
        $fullText = '';
        $reasoningContent = '';
        $toolCalls = [];
        $tokensIn = 0;
        $tokensOut = 0;
        $cacheReadInputTokens = 0;
        $cacheWriteInputTokens = 0;
        $finishReason = FinishReason::Stop;

        // Buffer for line-by-line rendering: accumulate partial lines, flush on \n
        $lineBuffer = '';

        foreach ($this->llm->stream($messages, $tools, $cancellation) as $event) {
            if ($event->type === 'text_delta') {
                $fullText .= $event->delta;
                $lineBuffer .= $event->delta;

                // Flush line-by-line: yield complete lines to the renderer
                while (($nl = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $nl + 1);
                    $lineBuffer = substr($lineBuffer, $nl + 1);
                    SafeDisplay::call(fn () => $this->ui->streamChunk($line), $this->log);
                }
            } elseif ($event->type === 'thinking_delta') {
                // Accumulate reasoning silently — displayed once after stream completes
                $reasoningContent .= $event->delta;
            } elseif ($event->type === 'tool_call') {
                $toolCalls[] = new ToolCall(
                    id: $event->toolCall['id'],
                    name: $event->toolCall['name'],
                    arguments: $event->toolCall['arguments'],
                );
            } elseif ($event->type === 'stream_end') {
                $tokensIn = $event->usage['prompt_tokens'] ?? 0;
                $tokensOut = $event->usage['completion_tokens'] ?? 0;
                $cacheWriteInputTokens = $event->usage['cache_write_input_tokens'] ?? 0;
                $cacheReadInputTokens = $event->usage['cache_read_input_tokens'] ?? 0;
                if ($event->finishReason !== null) {
                    $finishReason = $event->finishReason;
                }
            }
        }

        // Flush remaining partial line
        if ($lineBuffer !== '') {
            SafeDisplay::call(fn () => $this->ui->streamChunk($lineBuffer), $this->log);
        }

        $this->log->debug('Stream response complete', [
            'text_length' => strlen($fullText),
            'tool_calls' => count($toolCalls),
            'finish_reason' => $finishReason->name,
        ]);

        return [$fullText, $toolCalls, $finishReason, $tokensIn, $tokensOut, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningContent];
    }

    public function performCompaction(): void
    {
        [$tokensIn, $tokensOut] = $this->contextManager->performCompaction($this->history);
        $this->tokens->accumulate($tokensIn, $tokensOut);
        if ($tokensIn > 0 || $tokensOut > 0) {
            $this->events?->dispatch(new ContextCompacted(0, $tokensIn, $tokensOut));
        }
    }
}
