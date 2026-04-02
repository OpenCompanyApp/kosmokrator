<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;

final readonly class CompactionPlan
{
    /**
     * @param  Message[]  $replacementMessages
     * @param  Message[]  $protectedMessages
     * @param  array<int, array{type:string,title:string,content:string,memory_class?:string,pinned?:bool,expires_days?:int}>  $extractedMemories
     * @param  array<string, int|float|string|bool>  $stats
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

    public function isEmpty(): bool
    {
        return $this->summary === '' || $this->compactedMessageCount <= 0;
    }
}
