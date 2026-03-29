<?php

namespace Kosmokrator\Agent;

use Kosmokrator\Agent\Event\ResponseCompleteEvent;
use Kosmokrator\Agent\Event\StreamChunkEvent;
use Kosmokrator\Agent\Event\ThinkingEvent;
use Kosmokrator\Agent\Event\ToolCallEvent;
use Kosmokrator\Agent\Event\ToolResultEvent;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\UI\RendererInterface;
use Psr\Log\LoggerInterface;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent as PrismToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent as PrismToolResultEvent;
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
        private readonly PrismService $llm,
        private readonly RendererInterface $ui,
        private readonly LoggerInterface $log,
        int $maxToolRounds = 25,
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

        while ($round < $this->maxToolRounds) {
            $round++;

            $this->ui->showThinking();

            $fullText = '';
            $toolCalls = [];
            $finishReason = FinishReason::Stop;
            $tokensIn = 0;
            $tokensOut = 0;

            try {
                if ($this->llm->supportsStreaming()) {
                    $stream = $this->llm->stream($this->history->messages(), $this->tools);

                    foreach ($stream as $event) {
                        match (true) {
                            $event instanceof TextDeltaEvent => $this->handleTextDelta($event, $fullText),
                            $event instanceof PrismToolCallEvent => $this->handleToolCall($event, $toolCalls),
                            $event instanceof PrismToolResultEvent => $this->handleToolResult($event),
                            $event instanceof StreamEndEvent => $this->handleStreamEnd($event, $finishReason, $tokensIn, $tokensOut),
                            $event instanceof ErrorEvent => $this->handleError($event),
                            default => null,
                        };
                    }
                } else {
                    // Non-streaming fallback
                    $response = $this->llm->text($this->history->messages(), $this->tools);
                    $fullText = $response->text;
                    $this->ui->streamChunk($fullText);
                    $finishReason = $response->finishReason;
                    $toolCalls = $response->toolCalls;
                    $tokensIn = $response->usage->promptTokens;
                    $tokensOut = $response->usage->completionTokens;
                }
            } catch (\Throwable $e) {
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

    private function handleTextDelta(TextDeltaEvent $event, string &$fullText): void
    {
        $fullText .= $event->delta;
        $this->ui->streamChunk($event->delta);
    }

    private function handleToolCall(PrismToolCallEvent $event, array &$toolCalls): void
    {
        $toolCalls[] = $event->toolCall;
    }

    private function handleToolResult(PrismToolResultEvent $event): void
    {
        // Prism handled a tool internally — display it
        $this->ui->showToolResult(
            $event->toolResult->toolName,
            is_string($event->toolResult->result) ? $event->toolResult->result : json_encode($event->toolResult->result),
            $event->success,
        );
    }

    private function handleStreamEnd(StreamEndEvent $event, FinishReason &$finishReason, int &$tokensIn, int &$tokensOut): void
    {
        $finishReason = $event->finishReason;

        if ($event->usage !== null) {
            $tokensIn = $event->usage->promptTokens;
            $tokensOut = $event->usage->completionTokens;
        }
    }

    private function handleError(ErrorEvent $event): void
    {
        $this->ui->showError("{$event->errorType}: {$event->message}");
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
}
