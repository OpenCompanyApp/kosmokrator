<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Psr\Log\LoggerInterface;

class ContextCompactor
{
    private const DEFAULT_COMPACT_THRESHOLD_PERCENT = 60;

    private const COMPACTION_SYSTEM_PROMPT = 'You are a conversation summarizer. Summarize the conversation below for a continuation agent. Do not respond to questions in the conversation — only output the summary.';

    private const COMPACTION_USER_PROMPT = <<<'PROMPT'
Summarize this conversation segment. Use this structure:

## Goal
[What the user is trying to accomplish]

## Key Decisions
[Important technical choices, constraints, user preferences]

## Accomplished
[Work completed — specific file paths and changes]

## In Progress
[Current task and what remains]

## Relevant Files
[Files read, edited, or created]

<conversation>
%s
</conversation>
PROMPT;

    private const MEMORY_EXTRACTION_PROMPT = <<<'PROMPT'
Given this session summary, extract any durable knowledge useful across future sessions.
Categorize each as:
- project: facts about the codebase, architecture, patterns
- user: user preferences, workflow style, corrections they gave
- decision: technical decisions made and why

Only extract things NOT obvious from reading the code. Skip ephemeral task details.
Return a JSON array (empty if nothing worth remembering):

[{"type": "project", "title": "short label", "content": "the knowledge"}]
PROMPT;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly ModelCatalog $models,
        private readonly LoggerInterface $log,
        private int $compactThresholdPercent = self::DEFAULT_COMPACT_THRESHOLD_PERCENT,
    ) {
    }

    public function needsCompaction(int $promptTokens, string $model): bool
    {
        return $promptTokens >= $this->getThresholdTokens($model);
    }

    public function getThresholdTokens(string $model): int
    {
        $contextWindow = $this->models->contextWindow($model);

        return (int) ($contextWindow * $this->compactThresholdPercent / 100);
    }

    public function getCompactThresholdPercent(): int
    {
        return $this->compactThresholdPercent;
    }

    public function setCompactThresholdPercent(int $percent): void
    {
        $this->compactThresholdPercent = $percent;
    }

    /**
     * Summarize old messages in the conversation history.
     *
     * @return array{summary: string, tokens_in: int, tokens_out: int}
     */
    public function compact(ConversationHistory $history, int $keepRecent = 3): array
    {
        $messages = $history->messages();
        $total = count($messages);

        if ($total <= $keepRecent) {
            return ['summary' => '', 'tokens_in' => 0, 'tokens_out' => 0];
        }

        // Find the boundary: keep N recent user turns
        $keepFrom = $total;
        $turnsFound = 0;
        for ($i = $total - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof UserMessage) {
                $turnsFound++;
                if ($turnsFound >= $keepRecent) {
                    $keepFrom = $i;
                    break;
                }
            }
        }

        if ($keepFrom <= 0) {
            return ['summary' => '', 'tokens_in' => 0, 'tokens_out' => 0];
        }

        // Format old messages for summarization
        $oldMessages = array_slice($messages, 0, $keepFrom);
        $formatted = $this->formatMessages($oldMessages);

        $this->log->info('Compacting context', [
            'old_messages' => count($oldMessages),
            'kept_messages' => $total - $keepFrom,
        ]);

        // Call LLM for summarization — no tools, pure text
        $response = $this->llm->chat([
            new SystemMessage(self::COMPACTION_SYSTEM_PROMPT),
            new UserMessage(sprintf(self::COMPACTION_USER_PROMPT, $formatted)),
        ]);

        return [
            'summary' => trim($response->text),
            'tokens_in' => $response->promptTokens,
            'tokens_out' => $response->completionTokens,
        ];
    }

    /**
     * Extract durable memories from a compaction summary.
     *
     * @return array{memories: array<array{type: string, title: string, content: string}>, tokens_in: int, tokens_out: int}
     */
    public function extractMemories(string $summary): array
    {
        try {
            $response = $this->llm->chat([
                new SystemMessage(self::MEMORY_EXTRACTION_PROMPT),
                new UserMessage($summary),
            ]);

            $data = json_decode($response->text, true);
            $memories = [];
            if (is_array($data)) {
                $memories = array_values(array_filter($data, fn ($item) => isset($item['type'], $item['title'], $item['content'])
                    && in_array($item['type'], ['project', 'user', 'decision'], true)
                ));
            }

            return [
                'memories' => $memories,
                'tokens_in' => $response->promptTokens,
                'tokens_out' => $response->completionTokens,
            ];
        } catch (\Throwable $e) {
            $this->log->warning('Memory extraction failed', ['error' => $e->getMessage()]);

            return ['memories' => [], 'tokens_in' => 0, 'tokens_out' => 0];
        }
    }

    /**
     * @param \Prism\Prism\Contracts\Message[] $messages
     */
    private function formatMessages(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $lines[] = '[user]: ' . $this->truncate($message->content, 2000);
            } elseif ($message instanceof AssistantMessage) {
                if ($message->toolCalls !== []) {
                    foreach ($message->toolCalls as $tc) {
                        $args = $tc->arguments();
                        $argStr = $this->formatToolArgs($args);
                        $lines[] = "[assistant → tool_call]: {$tc->name}({$argStr})";
                    }
                }
                if ($message->content !== '') {
                    $lines[] = '[assistant]: ' . $this->truncate($message->content, 2000);
                }
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $tr) {
                    $result = is_string($tr->result) ? $tr->result : json_encode($tr->result);
                    $lines[] = '[tool_result]: ' . $this->truncate($result, 200);
                }
            } elseif ($message instanceof SystemMessage) {
                $lines[] = '[system]: ' . $this->truncate($message->content, 500);
            }
        }

        return implode("\n", $lines);
    }

    private function formatToolArgs(array $args): string
    {
        $parts = [];
        foreach ($args as $key => $value) {
            if (in_array($key, ['content', 'old_string', 'new_string'], true)) {
                $parts[] = "{$key}: [...]";
                continue;
            }
            $display = is_string($value) ? $value : json_encode($value);
            $parts[] = "{$key}: {$this->truncate($display, 100)}";
        }

        return implode(', ', $parts);
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . " [truncated — " . mb_strlen($text) . " chars]";
    }
}
