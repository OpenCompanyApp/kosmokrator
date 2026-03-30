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
use Kosmokrator\UI\RendererInterface;
use Psr\Log\LoggerInterface;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class AgentLoop
{
    private ConversationHistory $history;

    /** @var Tool[] Full set of tools from registry */
    private array $allTools = [];

    /** @var Tool[] Active tools (filtered by mode) */
    private array $tools = [];

    private int $maxToolRounds;

    private AgentMode $mode = AgentMode::Edit;

    private int $sessionTokensIn = 0;

    private int $sessionTokensOut = 0;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly RendererInterface $ui,
        private readonly LoggerInterface $log,
        private readonly string $baseSystemPrompt,
        int $maxToolRounds = 25,
        private readonly ?PermissionEvaluator $permissions = null,
        private readonly ?ModelCatalog $models = null,
        private readonly ?TaskStore $taskStore = null,
        private readonly ?SessionManager $sessionManager = null,
        private readonly ?ContextCompactor $compactor = null,
        private readonly ?OutputTruncator $truncator = null,
        private readonly ?ContextPruner $pruner = null,
        private readonly int $memoryWarningThreshold = 50 * 1024 * 1024,
    ) {
        $this->history = new ConversationHistory();
        $this->maxToolRounds = $maxToolRounds;
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

    /**
     * @param Tool[] $tools
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
        $this->llm->setSystemPrompt($this->baseSystemPrompt . $mode->systemPromptSuffix());
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

            if ($round > $this->maxToolRounds) {
                $this->log->warning('Max tool rounds reached', ['max' => $this->maxToolRounds]);
                $this->ui->showNotice("Reached maximum of {$this->maxToolRounds} tool rounds.");
                $this->history->addAssistant("Stopped: maximum tool rounds reached.");

                return;
            }

            $this->preFlightContextCheck();
            $this->refreshSystemPrompt();
            $this->ui->showThinking();

            try {
                $cancellation = $this->ui->getCancellation();
                $response = $this->llm->chat($this->history->messages(), $this->tools, $cancellation);
                $this->ui->clearThinking();
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
                $this->ui->clearThinking();
                $this->log->info('LLM request cancelled by user', ['round' => $round]);

                return;
            } catch (\Throwable $e) {
                $this->ui->clearThinking();

                // Context window overflow — compact or trim and retry
                if ($this->isContextOverflow($e) && $trimAttempts < 3) {
                    $trimAttempts++;
                    $round--;

                    if ($this->compactor !== null && $trimAttempts === 1) {
                        $this->performCompaction();
                    } else {
                        $this->history->trimOldest();
                    }

                    $this->log->warning('Context overflow, compacted/trimmed', ['attempt' => $trimAttempts]);

                    continue;
                }

                $this->log->error('LLM request failed', ['error' => $e->getMessage(), 'round' => $round]);
                $this->ui->showError($e->getMessage());
                $this->history->addAssistant('Error: ' . $e->getMessage());

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

    public function history(): ConversationHistory
    {
        return $this->history;
    }

    public function getSessionCost(): float
    {
        return $this->estimateCost($this->getModelName(), $this->sessionTokensIn, $this->sessionTokensOut);
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
     * @param ToolCall[] $toolCalls
     * @return ToolResult[]
     */
    private function executeToolCalls(array $toolCalls): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $this->log->info('Tool call', ['tool' => $toolCall->name, 'args' => $toolCall->arguments()]);
            $this->ui->showToolCall($toolCall->name, $toolCall->arguments());

            // Permission check — before tool lookup/execution
            if ($this->permissions !== null) {
                $permResult = $this->permissions->evaluate($toolCall->name, $toolCall->arguments());

                if ($permResult->action === PermissionAction::Deny) {
                    $output = $permResult->reason
                        ?? "Permission denied: '{$toolCall->name}' is blocked by policy.";
                    $output .= ' Try a different approach.';
                    $this->log->info('Tool denied by policy', ['tool' => $toolCall->name, 'reason' => $permResult->reason]);
                    $this->ui->showToolResult($toolCall->name, $output, false);
                    $results[] = new ToolResult(
                        toolCallId: $toolCall->id,
                        toolName: $toolCall->name,
                        args: $toolCall->arguments(),
                        result: $output,
                    );

                    continue;
                }

                if ($permResult->autoApproved) {
                    $this->ui->showAutoApproveIndicator($toolCall->name);
                }

                if ($permResult->action === PermissionAction::Ask) {
                    $decision = $this->ui->askToolPermission($toolCall->name, $toolCall->arguments());

                    if ($decision === 'deny') {
                        $output = "User denied permission for '{$toolCall->name}'. Try a different approach.";
                        $this->log->info('Tool denied by user', ['tool' => $toolCall->name]);
                        $this->ui->showToolResult($toolCall->name, $output, false);
                        $results[] = new ToolResult(
                            toolCallId: $toolCall->id,
                            toolName: $toolCall->name,
                            args: $toolCall->arguments(),
                            result: $output,
                        );

                        continue;
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
                }
            }

            $tool = $this->findTool($toolCall->name);

            if ($tool === null) {
                // Check if the tool exists but is blocked by mode
                $existsInAll = null !== $this->findToolInAll($toolCall->name);
                $output = $existsInAll
                    ? "Tool '{$toolCall->name}' is not available in {$this->mode->label()} mode. Switch to Edit mode to use write tools."
                    : "Tool '{$toolCall->name}' not found.";
                $this->ui->showToolResult($toolCall->name, $output, false);
                $results[] = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $output,
                );

                continue;
            }

            try {
                $output = $tool->handle(...$toolCall->arguments());
                $outputStr = is_string($output) ? $output : (string) $output;

                if ($this->truncator !== null) {
                    $outputStr = $this->truncator->truncate($outputStr, $toolCall->id);
                }

                $this->ui->showToolResult($toolCall->name, $outputStr, true);
                $results[] = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $outputStr,
                );
            } catch (\Throwable $e) {
                $this->log->error('Tool execution failed', ['tool' => $toolCall->name, 'error' => $e->getMessage()]);
                $error = "Error: {$e->getMessage()}";
                $this->ui->showToolResult($toolCall->name, $error, false);
                $results[] = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $error,
                );
            }
        }

        return $results;
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
        return $this->llm->getProvider() . '/' . $this->llm->getModel();
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

    private function persistMessage(\Prism\Prism\Contracts\Message $message, int $tokensIn = 0, int $tokensOut = 0): void
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
        $this->ui->showNotice('Compacting context...');

        try {
            $result = $this->compactor->compact($this->history);
            $summary = $result['summary'];

            // Track compaction LLM call cost
            $this->sessionTokensIn += $result['tokens_in'];
            $this->sessionTokensOut += $result['tokens_out'];

            if ($summary === '') {
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

            $this->ui->showNotice('Context compacted.');
            $this->log->info('Compaction complete', ['memories_extracted' => count($extraction['memories'])]);
        } catch (\Throwable $e) {
            $this->log->error('Compaction failed, falling back to trimOldest', ['error' => $e->getMessage()]);
            $this->history->trimOldest();
        }
    }

    private function refreshSystemPrompt(): void
    {
        $prompt = $this->baseSystemPrompt . $this->mode->systemPromptSuffix();

        if ($this->taskStore !== null && ! $this->taskStore->isEmpty()) {
            $prompt .= "\n\n## Current Tasks\n" . $this->taskStore->renderTree();
        }

        $this->llm->setSystemPrompt($prompt);
    }
}
