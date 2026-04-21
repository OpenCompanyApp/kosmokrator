<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Cache;

final class WebTransientCache
{
    /** @var array<string, array{value: mixed, generation: int}> */
    private array $entries = [];

    private int $generation = 0;

    public function __construct(
        private readonly int $keepTurns = 2,
        private readonly int $maxEntries = 128,
    ) {}

    public function advanceTurn(): void
    {
        $this->generation++;
        $this->evictExpired();
    }

    public function has(string $key): bool
    {
        $this->evictExpired();

        return array_key_exists($key, $this->entries);
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

    private function evictExpired(): void
    {
        foreach ($this->entries as $key => $entry) {
            if (($this->generation - $entry['generation']) > $this->keepTurns) {
                unset($this->entries[$key]);
            }
        }
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

            if ($overflow <= 0) {
                break;
            }
        }
    }
}
