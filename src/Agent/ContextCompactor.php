<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Psr\Log\LoggerInterface;

/**
 * Summarizes old conversation turns via LLM to keep the context window within budget.
 *
 * Used by the agent loop (see Agent class) when needsCompaction() returns true.
 * Produces a CompactionPlan that replaces older messages with a structured summary,
 * and can extract durable knowledge into memories via extractMemories().
 */
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
        private readonly ?ContextBudget $budget = null,
    ) {}

    /**
     * Check whether the current prompt size exceeds the compaction threshold for the given model.
     *
     * @param  int  $promptTokens  Current token count of the prompt to be sent
     * @param  string  $model  Model identifier used to look up context window size
     */
    public function needsCompaction(int $promptTokens, string $model): bool
    {
        return $promptTokens >= $this->getThresholdTokens($model);
    }

    /**
     * Calculate the token count at which compaction should trigger.
     *
     * Uses the lower of the percentage-based threshold and the ContextBudget limit (if provided).
     *
     * @param  string  $model  Model identifier used to look up context window size
     * @return int Token count threshold
     */
    public function getThresholdTokens(string $model): int
    {
        $contextWindow = $this->models->contextWindow($model);
        $percentThreshold = (int) ($contextWindow * $this->compactThresholdPercent / 100);

        if ($this->budget === null) {
            return $percentThreshold;
        }

        return min($percentThreshold, $this->budget->autoCompactThreshold($model));
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
     * @param  ConversationHistory  $history  Full conversation to compact
     * @param  int  $keepRecent  Number of most-recent messages to preserve unchanged
     * @return array{summary: string, tokens_in: int, tokens_out: int} Compaction result with LLM token usage
     */
    public function compact(ConversationHistory $history, int $keepRecent = 3): array
    {
        $plan = $this->buildPlan($history, keepRecent: $keepRecent);

        return [
            'summary' => $plan->summary,
            'tokens_in' => $plan->tokensIn,
            'tokens_out' => $plan->tokensOut,
        ];
    }

    /**
     * Build a CompactionPlan describing how to replace old messages with a summary.
     *
     * @param  ConversationHistory  $history  Full conversation to compact
     * @param  Message[]  $protectedMessages  Messages that must appear at the top of the replacement list
     * @param  int  $keepRecent  Number of most-recent messages to preserve unchanged
     * @return CompactionPlan Plan with replacement messages, summary, and token usage
     */
    public function buildPlan(ConversationHistory $history, array $protectedMessages = [], int $keepRecent = 3): CompactionPlan
    {
        $messages = $history->messages();
        $total = count($messages);

        if ($total <= $keepRecent) {
            return new CompactionPlan($total, 0, '', $messages);
        }

        $keepFrom = ConversationHistory::findKeepBoundaryInMessages($messages, $keepRecent);

        if ($keepFrom <= 0) {
            return new CompactionPlan($keepFrom, 0, '', $messages);
        }

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

        $summary = trim($response->text);
        $recent = array_slice($messages, $keepFrom);
        // Rebuild message list: protected → summary → recent
        $replacement = [...$protectedMessages];
        if ($summary !== '') {
            $replacement[] = new SystemMessage($summary);
        }
        $replacement = [...$replacement, ...$recent];

        return new CompactionPlan(
            keepFromMessageIndex: $keepFrom,
            compactedMessageCount: $keepFrom,
            summary: $summary,
            replacementMessages: $replacement,
            protectedMessages: $protectedMessages,
            tokensIn: $response->promptTokens,
            tokensOut: $response->completionTokens,
            stats: [
                'old_messages' => count($oldMessages),
                'kept_messages' => count($recent),
                'protected_messages' => count($protectedMessages),
            ],
        );
    }

    /**
     * Extract durable memories from a compaction summary.
     *
     * @param  string  $summary  The compaction summary produced by compact() or buildPlan()
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
                // Only keep well-formed items with a recognized type
                $memories = array_values(array_filter($data, fn ($item) => isset($item['type'], $item['title'], $item['content'])
                    && in_array($item['type'], ['project', 'user', 'decision'], true)
                ));
                // Apply defaults for optional fields
                $memories = array_map(function (array $item): array {
                    $item['memory_class'] = $item['memory_class'] ?? 'durable';
                    $item['pinned'] = (bool) ($item['pinned'] ?? false);

                    return $item;
                }, $memories);
            }

            return [
                'memories' => $memories,
                'tokens_in' => $response->promptTokens,
                'tokens_out' => $response->completionTokens,
            ];
        } catch (\Throwable $e) {
            // Fail gracefully — memory extraction is best-effort, never fatal
            $this->log->warning('Memory extraction failed', ['error' => $e->getMessage()]);

            return ['memories' => [], 'tokens_in' => 0, 'tokens_out' => 0];
        }
    }

    /**
     * Cap formatted output at ~100K chars (~25K tokens) to prevent memory blowup
     * on very long conversations. Older messages are dropped first.
     */
    private const MAX_FORMAT_CHARS = 100_000;

    /**
     * Render an array of Message objects into a plain-text transcript for the LLM.
     *
     * Each message type is labeled with its role. Output is capped at MAX_FORMAT_CHARS.
     *
     * @param  Message[]  $messages
     */
    private function formatMessages(array $messages): string
    {
        $lines = [];
        $totalChars = 0;

        foreach ($messages as $message) {
            $newLines = [];

            if ($message instanceof UserMessage) {
                $newLines[] = '[user]: '.$this->truncate($message->content, 2000);
            } elseif ($message instanceof AssistantMessage) {
                if ($message->toolCalls !== []) {
                    foreach ($message->toolCalls as $tc) {
                        $args = $tc->arguments();
                        $argStr = $this->formatToolArgs($args);
                        $newLines[] = "[assistant → tool_call]: {$tc->name}({$argStr})";
                    }
                }
                if ($message->content !== '') {
                    $newLines[] = '[assistant]: '.$this->truncate($message->content, 2000);
                }
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $tr) {
                    $result = is_string($tr->result) ? $tr->result : json_encode($tr->result, JSON_INVALID_UTF8_SUBSTITUTE);
                    $newLines[] = '[tool_result]: '.$this->truncate($result, 200);
                }
            } elseif ($message instanceof SystemMessage) {
                $newLines[] = '[system]: '.$this->truncate($message->content, 500);
            }

            foreach ($newLines as $line) {
                $totalChars += strlen($line) + 1;
                if ($totalChars > self::MAX_FORMAT_CHARS) {
                    $lines[] = '[... older messages truncated for compaction]';

                    return implode("\n", $lines);
                }
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format tool-call arguments as a compact key: value string, eliding large content fields.
     */
    private function formatToolArgs(array $args): string
    {
        $parts = [];
        foreach ($args as $key => $value) {
            // Omit potentially large content payloads from the transcript
            if (in_array($key, ['content', 'old_string', 'new_string'], true)) {
                $parts[] = "{$key}: [...]";

                continue;
            }
            $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
            $parts[] = "{$key}: {$this->truncate($display, 100)}";
        }

        return implode(', ', $parts);
    }

    /**
     * Truncate text to $maxLength characters, appending a length hint when truncated.
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).' [truncated — '.mb_strlen($text).' chars]';
    }
}
