<?php

namespace Kosmokrator\Agent;

use Amp\CancelledException;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\RendererInterface;
use Psr\Log\LoggerInterface;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class AgentLoop
{
    private ConversationHistory $history;

    /** @var Tool[] */
    private array $tools = [];

    private int $maxToolRounds;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly RendererInterface $ui,
        private readonly LoggerInterface $log,
        int $maxToolRounds = 25,
        private readonly ?PermissionEvaluator $permissions = null,
    ) {
        $this->history = new ConversationHistory();
        $this->maxToolRounds = $maxToolRounds;
    }

    /**
     * @param Tool[] $tools
     */
    public function setTools(array $tools): void
    {
        $this->tools = $tools;
    }

    public function run(string $userInput): void
    {
        $this->log->debug('User input', ['input' => $userInput]);
        $this->history->addUser($userInput);

        $round = 0;
        $trimAttempts = 0;

        while ($round < $this->maxToolRounds) {
            $round++;

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

                if ($fullText !== '') {
                    $this->ui->streamChunk($fullText);
                }
            } catch (CancelledException $e) {
                $this->ui->clearThinking();
                $this->log->info('LLM request cancelled by user', ['round' => $round]);

                return;
            } catch (\Throwable $e) {
                $this->ui->clearThinking();

                // Context window overflow — trim oldest messages and retry
                if ($this->isContextOverflow($e) && $trimAttempts < 3 && $this->history->trimOldest()) {
                    $trimAttempts++;
                    $round--; // Don't count this as a tool round
                    $this->log->warning('Context overflow, trimmed oldest messages', ['attempt' => $trimAttempts]);

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
                $toolResults = $this->executeToolCalls($toolCalls);
                $this->history->addToolResults($toolResults);

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
            $this->ui->showStatus(
                $this->getModelName(),
                $tokensIn,
                $tokensOut,
                $this->estimateCost($tokensIn, $tokensOut),
            );

            return;
        }

        $this->ui->showError("Maximum tool rounds ({$this->maxToolRounds}) reached.");
    }

    public function history(): ConversationHistory
    {
        return $this->history;
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
                $action = $this->permissions->evaluate($toolCall->name, $toolCall->arguments());

                if ($action === PermissionAction::Deny) {
                    $output = "Permission denied: '{$toolCall->name}' is blocked by policy. Try a different approach.";
                    $this->log->info('Tool denied by policy', ['tool' => $toolCall->name]);
                    $this->ui->showToolResult($toolCall->name, $output, false);
                    $results[] = new ToolResult(
                        toolCallId: $toolCall->id,
                        toolName: $toolCall->name,
                        args: $toolCall->arguments(),
                        result: $output,
                    );

                    continue;
                }

                if ($action === PermissionAction::Ask) {
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
                }
            }

            $tool = $this->findTool($toolCall->name);

            if ($tool === null) {
                $output = "Tool '{$toolCall->name}' not found.";
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

    private function getModelName(): string
    {
        return $this->llm->getProvider() . '/' . $this->llm->getModel();
    }

    private function estimateCost(int $tokensIn, int $tokensOut): float
    {
        // Rough Claude Sonnet pricing: $3/M input, $15/M output
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
}
