<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\Tool;
use Kosmokrator\LLM\ValueObjects\Messages\AssistantMessage;
use Kosmokrator\LLM\ValueObjects\Messages\SystemMessage;
use Kosmokrator\LLM\ValueObjects\Messages\ToolResultMessage;
use Kosmokrator\LLM\ValueObjects\Messages\UserMessage;

final class ContextAnalyzer
{
    public function __construct(
        private readonly TokenCounterInterface $counter = new ApproximateTokenCounter,
    ) {}

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, int|float|string|bool>  $budget
     * @param  array<string, mixed>  $cache
     */
    public function analyze(string $model, string $systemPrompt, array $messages, array $tools, array $budget, array $cache = []): ContextBreakdown
    {
        $buckets = $this->promptBuckets($systemPrompt);
        $toolSchemaTokens = $this->counter->countTools($tools);
        if ($toolSchemaTokens > 0) {
            $buckets[] = new ContextBucket('tool_schema', $toolSchemaTokens, 'tools');
        }

        $messageBuckets = [
            'messages:user' => 0,
            'messages:assistant' => 0,
            'messages:system' => 0,
            'tool_calls' => 0,
        ];
        $toolBuckets = [];
        $largestItems = [];

        foreach ($messages as $index => $message) {
            $tokens = $this->counter->countMessage($message);
            if ($message instanceof UserMessage) {
                $messageBuckets['messages:user'] += $tokens;
                $largestItems[] = $this->item('message:user', $tokens, $index, preview: $message->content);
            } elseif ($message instanceof AssistantMessage) {
                $messageBuckets['messages:assistant'] += $tokens;
                $messageBuckets['tool_calls'] += $this->countToolCalls($message);
                $largestItems[] = $this->item('message:assistant', $tokens, $index, preview: $message->content);
            } elseif ($message instanceof SystemMessage) {
                $name = str_contains($message->content, 'Compacted Conversation Summary') ? 'compaction_summary' : 'messages:system';
                $messageBuckets[$name] = ($messageBuckets[$name] ?? 0) + $tokens;
                $largestItems[] = $this->item($name, $tokens, $index, preview: $message->content);
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $resultText = is_string($result->result)
                        ? $result->result
                        : json_encode($result->result, JSON_INVALID_UTF8_SUBSTITUTE);
                    $resultTokens = $this->counter->countText((string) $resultText, 'tool:result');
                    $bucketName = 'tool:'.$result->toolName;
                    $toolBuckets[$bucketName] = ($toolBuckets[$bucketName] ?? 0) + $resultTokens;
                    $largestItems[] = $this->item($bucketName, $resultTokens, $index, $result->toolName, $result->args['path'] ?? null, (string) $resultText);
                }
            }
        }

        foreach ($messageBuckets as $name => $tokens) {
            if ($tokens > 0) {
                $buckets[] = new ContextBucket($name, $tokens, 'history');
            }
        }
        foreach ($toolBuckets as $name => $tokens) {
            $buckets[] = new ContextBucket($name, $tokens, 'history');
        }

        usort($largestItems, static fn (ContextBucket $a, ContextBucket $b): int => $b->tokens <=> $a->tokens);
        $largestItems = array_slice($largestItems, 0, 10);

        $estimated = array_sum(array_map(static fn (ContextBucket $bucket): int => $bucket->tokens, $buckets));
        $contextWindow = (int) ($budget['context_window'] ?? 0);
        $effectiveWindow = (int) ($budget['effective_window'] ?? $contextWindow);

        return new ContextBreakdown($model, $estimated, $contextWindow, $effectiveWindow, $budget, $buckets, $largestItems, $cache);
    }

    /**
     * @return ContextBucket[]
     */
    private function promptBuckets(string $prompt): array
    {
        $sections = [
            'memory' => "\n\n## Memories",
            'mode' => "\n\n# Operational Mode:",
            'parent_brief' => "\n\n## Parent Brief\n",
            'task_tree' => "\n\n## Current Tasks\n",
        ];

        $offsets = [];
        foreach ($sections as $name => $marker) {
            $offset = strpos($prompt, $marker);
            if ($offset !== false) {
                $offsets[$name] = $offset;
            }
        }

        asort($offsets);
        $buckets = [];
        $cursor = 0;
        foreach ($offsets as $name => $offset) {
            if ($offset > $cursor) {
                $label = $cursor === 0 ? 'stable_system' : 'prompt_misc';
                $this->addPromptBucket($buckets, $label, substr($prompt, $cursor, $offset - $cursor));
            }
            $cursor = $offset;
            $nextOffsets = array_filter($offsets, static fn (int $other): bool => $other > $offset);
            $end = $nextOffsets === [] ? strlen($prompt) : min($nextOffsets);
            $this->addPromptBucket($buckets, $name, substr($prompt, $offset, $end - $offset));
            $cursor = $end;
        }

        if ($offsets === []) {
            $this->addPromptBucket($buckets, 'stable_system', $prompt);
        } elseif ($cursor < strlen($prompt)) {
            $this->addPromptBucket($buckets, 'prompt_misc', substr($prompt, $cursor));
        }

        return $buckets;
    }

    /**
     * @param  ContextBucket[]  $buckets
     */
    private function addPromptBucket(array &$buckets, string $name, string $content): void
    {
        $tokens = $this->counter->countText($content, 'prompt:'.$name);
        if ($tokens > 0) {
            $buckets[] = new ContextBucket($name, $tokens, 'system', preview: trim(mb_substr($content, 0, 160)));
        }
    }

    private function countToolCalls(AssistantMessage $message): int
    {
        $tokens = 0;
        foreach ($message->toolCalls as $toolCall) {
            $tokens += $this->counter->countText($toolCall->name.json_encode($toolCall->arguments, JSON_INVALID_UTF8_SUBSTITUTE), 'tool:call');
        }

        return $tokens;
    }

    private function item(string $name, int $tokens, int $messageIndex, ?string $toolName = null, ?string $path = null, ?string $preview = null): ContextBucket
    {
        $preview = $preview !== null ? trim((string) preg_replace('/\s+/', ' ', $preview)) : null;
        if ($preview !== null && mb_strlen($preview) > 160) {
            $preview = mb_substr($preview, 0, 157).'...';
        }

        return new ContextBucket($name, $tokens, 'history', $messageIndex, $toolName, $path, $preview);
    }
}
