<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;

/**
 * Immutable result of a context-compaction operation.
 *
 * Produced by the compaction engine (LLM-based or heuristic) and consumed by
 * ConversationHistory::applyCompactionPlan(). Carries the replacement messages,
 * any extracted memories, and token-usage stats from the compaction LLM call.
 *
 * @see ConversationHistory::applyCompactionPlan()
 */
final readonly class CompactionPlan
{
    /**
     * @param  int  $keepFromMessageIndex  Index of the first message kept from the original history
     * @param  int  $compactedMessageCount  Number of messages that were summarized/replaced
     * @param  string  $summary  Human-readable summary of the compacted conversation segment
     * @param  Message[]  $replacementMessages  Full set of messages that replace the old history
     * @param  Message[]  $protectedMessages  Messages that were excluded from compaction
     * @param  array<int, array{type:string,title:string,content:string,memory_class?:string,pinned?:bool,expires_days?:int}>  $extractedMemories  Facts extracted during compaction for persistent storage
     * @param  int  $tokensIn  Prompt tokens consumed by the compaction LLM call
     * @param  int  $tokensOut  Completion tokens produced by the compaction LLM call
     * @param  array<string, int|float|string|bool>  $stats  Arbitrary compaction statistics
     */
    public function __construct(
        public int $keepFromMessageIndex,
        public int $compactedMessageCount,
        public string $summary,
        public array $replacementMessages,
        public array $protectedMessages = [],
        public array $extractedMemories = [],
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public array $stats = [],
    ) {}

    /**
     * Whether this plan contains no meaningful compaction result.
     *
     * @return bool True when the summary is empty or no messages were compacted
     */
    public function isEmpty(): bool
    {
        return $this->summary === '' || $this->compactedMessageCount <= 0;
    }
}
