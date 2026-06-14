<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Cache;

use Kosmokrator\Agent\CacheStats;

final class WebTransientCache
{
    /** @var array<string, array{value: mixed, generation: int}> */
    private array $entries = [];

    private int $generation = 0;

    private CacheStats $stats;

    public function __construct(
        private readonly int $keepTurns = 2,
        private readonly int $maxEntries = 128,
    ) {
        $this->stats = new CacheStats;
    }

    public function advanceTurn(): void
    {
        $this->generation++;
        $this->evictExpired();
    }

    public function has(string $key): bool
    {
        $this->evictExpired();

        $has = array_key_exists($key, $this->entries);
        $has ? $this->stats->hits++ : $this->stats->misses++;

        return $has;
    }

    public function get(string $key): mixed
    {
        $this->evictExpired();

        return $this->entries[$key]['value'] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->entries[$key] = [
            'value' => $value,
            'generation' => $this->generation,
        ];

        $this->evictOverflow();
        $this->stats->entries = count($this->entries);
    }

    public function remember(string $key, callable $resolver): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $resolver();
        $this->put($key, $value);

        return $value;
    }

    public function stats(): CacheStats
    {
        $stats = clone $this->stats;
        $stats->entries = count($this->entries);

        return $stats;
    }

    private function evictExpired(): void
    {
        foreach ($this->entries as $key => $entry) {
            if (($this->generation - $entry['generation']) > $this->keepTurns) {
                unset($this->entries[$key]);
                $this->stats->evictions++;
            }
        }
        $this->stats->entries = count($this->entries);
    }

    private function evictOverflow(): void
    {
        if (count($this->entries) <= $this->maxEntries) {
            return;
        }

        uasort($this->entries, static fn (array $a, array $b): int => $a['generation'] <=> $b['generation']);
        $overflow = count($this->entries) - $this->maxEntries;

        foreach (array_keys($this->entries) as $key) {
            unset($this->entries[$key]);
            $overflow--;
            $this->stats->evictions++;

            if ($overflow <= 0) {
                break;
            }
        }
        $this->stats->entries = count($this->entries);
    }
}
