<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Scores and ranks memories for relevance before injection.
 * Considers memory class (priority > durable > working), type, pinned status,
 * and term overlap with the current query. Used by MemoryInjector to pick the
 * most relevant subset.
 */
final class MemorySelector
{
    /**
     * @param  array<int, array<string, mixed>>  $memories  Candidate memories from the store
     * @param  string|null  $query  Free-text query to boost relevance scoring
     * @param  int  $limit  Maximum number of memories to return
     * @return array<int, array<string, mixed>> Top-scoring memories, sorted descending
     */
    public function select(array $memories, ?string $query, int $limit = 6): array
    {
        if ($memories === []) {
            return [];
        }

        $terms = $this->terms($query);

        usort($memories, function (array $a, array $b) use ($terms): int {
            $scoreA = $this->score($a, $terms);
            $scoreB = $this->score($b, $terms);

            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? ''));
        });

        return array_slice($memories, 0, max(0, $limit));
    }

    /**
     * Compute a relevance score for a single memory based on class, type, pinned status, and query term overlap.
     *
     * @param  string[]  $terms  Lowercased query terms extracted by terms()
     */
    private function score(array $memory, array $terms): int
    {
        $score = 0;
        $type = (string) ($memory['type'] ?? '');
        $class = (string) ($memory['memory_class'] ?? 'durable');
        $title = mb_strtolower((string) ($memory['title'] ?? ''));
        $content = mb_strtolower((string) ($memory['content'] ?? ''));

        $score += match ($class) {
            'priority' => 80,
            'durable' => 50,
            'working' => 20,
            default => 10,
        };

        $score += match ($type) {
            'decision' => 35,
            'project' => 30,
            'user' => 25,
            'compaction' => 10,
            default => 5,
        };

        if ((int) ($memory['pinned'] ?? 0) === 1) {
            $score += 40;
        }

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            if (str_contains($title, $term)) {
                $score += 30;
            }
            if (str_contains($content, $term)) {
                $score += 15;
            }
        }

        return $score;
    }

    /**
     * @return string[]
     */
    private function terms(?string $query): array
    {
        if ($query === null || trim($query) === '') {
            return [];
        }

        $parts = preg_split('/\s+/', mb_strtolower(trim($query))) ?: [];

        return array_values(array_filter($parts, fn (string $part) => mb_strlen($part) >= 3));
    }
}
