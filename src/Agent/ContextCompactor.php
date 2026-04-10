<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\Cancellation;
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
 * Produces a CompactionPlan that replaces older messages with a structured summary
 * and extracted memories from the same LLM response.
 */
class ContextCompactor
{
    private const DEFAULT_COMPACT_THRESHOLD_PERCENT = 60;

    private const COMPACTION_SYSTEM_PROMPT = 'You are a conversation summarizer and memory extractor. Summarize the conversation below for a continuation agent. Do not respond to questions in the conversation. Return only valid JSON matching the requested schema.';

    private const COMPACTION_USER_PROMPT = <<<'PROMPT'
Summarize this conversation segment and extract useful cross-session memories in the same response.

Return a JSON object with this exact shape:

{
  "summary": "markdown summary using the required structure",
  "memories": [
    {
      "type": "project|user|decision",
      "title": "short label",
      "content": "memory text",
      "memory_class": "durable|working|priority",
      "pinned": false,
      "expires_days": 14
    }
  ]
}

Rules:

- The `summary` field must use this structure exactly:

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

- Only put cross-session-valuable items in `memories`.
- Prefer `memory_class: durable`.
- Use `working` only for unresolved task context worth keeping briefly.
- Use `expires_days` only with `working` memories.
- Skip anything obvious from reading the code.
- If nothing is worth saving, return an empty `memories` array.

<conversation>
%s
</conversation>
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
    public function compact(ConversationHistory $history, int $keepRecent = 3, ?Cancellation $cancellation = null): array
    {
        $plan = $this->buildPlan($history, keepRecent: $keepRecent, cancellation: $cancellation);

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
    public function buildPlan(ConversationHistory $history, array $protectedMessages = [], int $keepRecent = 3, ?Cancellation $cancellation = null): CompactionPlan
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
        ], cancellation: $cancellation);

        $parsed = $this->parseCompactionResponse($response->text);
        $summary = $parsed['summary'];
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
            extractedMemories: $parsed['memories'],
            tokensIn: $response->promptTokens,
            tokensOut: $response->completionTokens,
            stats: [
                'old_messages' => count($oldMessages),
                'kept_messages' => count($recent),
                'protected_messages' => count($protectedMessages),
                'extracted_memories' => count($parsed['memories']),
            ],
        );
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

    /**
     * @return array{summary:string,memories:array<int, array{type:string,title:string,content:string,memory_class?:string,pinned?:bool,expires_days?:int}>}
     */
    private function parseCompactionResponse(string $text): array
    {
        $fallback = ['summary' => trim($text), 'memories' => []];
        $data = json_decode($text, true);

        if (! is_array($data)) {
            return $fallback;
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        $memories = [];
        $rawMemories = $data['memories'] ?? [];
        if (is_array($rawMemories)) {
            foreach ($rawMemories as $item) {
                if (! is_array($item) || ! isset($item['type'], $item['title'], $item['content'])) {
                    continue;
                }

                $type = (string) $item['type'];
                if (! in_array($type, ['project', 'user', 'decision'], true)) {
                    continue;
                }

                $memoryClass = (string) ($item['memory_class'] ?? 'durable');
                if (! in_array($memoryClass, ['priority', 'working', 'durable'], true)) {
                    $memoryClass = 'durable';
                }

                $title = trim((string) $item['title']);
                $content = trim((string) $item['content']);
                if ($title === '' || $content === '') {
                    continue;
                }

                $memory = [
                    'type' => $type,
                    'title' => $title,
                    'content' => $content,
                    'memory_class' => $memoryClass,
                    'pinned' => (bool) ($item['pinned'] ?? false),
                ];

                if ($memoryClass === 'working' && isset($item['expires_days']) && is_numeric((string) $item['expires_days'])) {
                    $memory['expires_days'] = max(1, min(30, (int) $item['expires_days']));
                }

                $memories[] = $memory;
            }
        }

        if ($summary === '') {
            $summary = $this->fallbackSummaryFromParsedResponse($memories);
        }

        return [
            'summary' => $summary,
            'memories' => $memories,
        ];
    }

    /**
     * @param  array<int, array{type:string,title:string,content:string,memory_class?:string,pinned?:bool,expires_days?:int}>  $memories
     */
    private function fallbackSummaryFromParsedResponse(array $memories): string
    {
        $decisionTitles = array_values(array_map(
            fn (array $memory): string => $memory['title'],
            array_filter($memories, fn (array $memory): bool => $memory['type'] === 'decision')
        ));

        $projectTitles = array_values(array_map(
            fn (array $memory): string => $memory['title'],
            array_filter($memories, fn (array $memory): bool => $memory['type'] === 'project')
        ));

        $workingTitles = array_values(array_map(
            fn (array $memory): string => $memory['title'],
            array_filter($memories, fn (array $memory): bool => ($memory['memory_class'] ?? 'durable') === 'working')
        ));

        $lines = [
            '## Goal',
            '[Compaction summary unavailable]',
            '',
            '## Key Decisions',
            $decisionTitles !== [] ? '- '.implode("\n- ", array_slice($decisionTitles, 0, 3)) : '[No decisions extracted]',
            '',
            '## Accomplished',
            $projectTitles !== [] ? '- '.implode("\n- ", array_slice($projectTitles, 0, 3)) : '[Summary unavailable]',
            '',
            '## In Progress',
            $workingTitles !== [] ? '- '.implode("\n- ", array_slice($workingTitles, 0, 3)) : '[No active work extracted]',
            '',
            '## Relevant Files',
            '[Unknown]',
        ];

        return implode("\n", $lines);
    }
}
