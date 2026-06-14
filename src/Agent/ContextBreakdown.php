<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

readonly class ContextBreakdown
{
    /**
     * @param  ContextBucket[]  $buckets
     * @param  ContextBucket[]  $largestItems
     * @param  array<string, int|float|string|bool>  $budget
     * @param  array<string, mixed>  $cache
     */
    public function __construct(
        public string $model,
        public int $estimatedTokens,
        public int $contextWindow,
        public int $effectiveWindow,
        public array $budget,
        public array $buckets,
        public array $largestItems,
        public array $cache = [],
    ) {}

    /**
     * @return ContextBucket[]
     */
    public function topBuckets(int $limit = 8): array
    {
        $buckets = $this->buckets;
        usort($buckets, static fn (ContextBucket $a, ContextBucket $b): int => $b->tokens <=> $a->tokens);

        return array_slice($buckets, 0, $limit);
    }
}
