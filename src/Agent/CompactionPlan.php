<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;

/**
 * Immutable value object representing the result of a context compaction.
 *
 * Contains the LLM-generated summary, the replacement message list (protected + summary + recent),
 * extracted memories, and token usage stats. Applied by ConversationHistory::applyCompactionPlan().
 */
final readonly class CompactionPlan
{
    /**
     * @param  int  $keepFromMessageIndex  Index in the original messages where preserved content starts
     * @param  int  $compactedMessageCount  Number of old messages that were summarized
     * @param  string  $summary  LLM-generated summary of the compacted messages
     * @param  Message[]  $replacementMessages  Full replacement message list to swap into ConversationHistory
     * @param  Message[]  $protectedMessages  Messages preserved verbatim during compaction
     * @param  array<int, array{type:string,title:string,content:string,memory_class?:string,pinned?:bool,expires_days?:int}>  $extractedMemories
     * @param  int  $tokensIn  Prompt tokens consumed by the compaction LLM call
     * @param  int  $tokensOut  Completion tokens from the compaction LLM call
     * @param  array<string, int|float|string|bool>  $stats  Optional stats from the compaction run
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

    /** Whether the plan contains no meaningful compaction (empty summary or zero compacted messages). */
    public function isEmpty(): bool
    {
        return $this->summary === '' || $this->compactedMessageCount <= 0;
    }
}
