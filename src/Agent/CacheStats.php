<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

final class CacheStats
{
    public int $hits = 0;

    public int $misses = 0;

    public int $evictions = 0;

    public int $resets = 0;

    public int $entries = 0;

    /**
     * @return array{hits:int,misses:int,evictions:int,resets:int,entries:int}
     */
    public function toArray(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'evictions' => $this->evictions,
            'resets' => $this->resets,
            'entries' => $this->entries,
        ];
    }
}
