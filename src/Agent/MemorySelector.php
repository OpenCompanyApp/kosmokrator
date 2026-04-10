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

        $normalizedQuery = $this->normalizeQuery($query);
        $terms = $this->terms($query);

        usort($memories, function (array $a, array $b) use ($terms, $normalizedQuery): int {
            $scoreA = $this->score($a, $terms, $normalizedQuery);
            $scoreB = $this->score($b, $terms, $normalizedQuery);

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
    private function score(array $memory, array $terms, string $normalizedQuery): int
    {
        $score = 0;
        $type = (string) ($memory['type'] ?? '');
        $class = (string) ($memory['memory_class'] ?? 'durable');
        $title = mb_strtolower((string) ($memory['title'] ?? ''));
        $content = mb_strtolower((string) ($memory['content'] ?? ''));
        $referenceTime = $this->referenceTimestamp($memory);

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

        if ($normalizedQuery !== '') {
            if ($title === $normalizedQuery) {
                $score += 70;
            } elseif (str_contains($title, $normalizedQuery)) {
                $score += 45;
            }

            if (str_contains($content, $normalizedQuery)) {
                $score += 20;
            }
        }

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $identifierLike = $this->isIdentifierLike($term);
            if (str_contains($title, $term)) {
                $score += $identifierLike ? 42 : 30;
            }
            if (str_contains($content, $term)) {
                $score += $identifierLike ? 24 : 15;
            }
        }

        if ($type === 'decision') {
            $score += $this->decisionRecencyBoost($referenceTime);
        }

        if ($class === 'working') {
            $score -= $this->staleWorkingPenalty(
                $this->timestamp((string) ($memory['last_surfaced_at'] ?? '')) ?? $referenceTime,
            );
        }

        return $score;
    }

    /**
     * @return string[]
     */
    private function terms(?string $query): array
    {
        $normalized = $this->normalizeQuery($query);
        if ($normalized === '') {
            return [];
        }

        preg_match_all('/[[:alnum:]_\\.\\/-]+/u', $normalized, $matches);
        $parts = $matches[0] ?? [];

        return array_values(array_filter($parts, function (string $part): bool {
            if ($part === '') {
                return false;
            }

            return $this->isIdentifierLike($part) || mb_strlen($part) >= 3;
        }));
    }

    private function normalizeQuery(?string $query): string
    {
        if ($query === null) {
            return '';
        }

        return mb_strtolower(trim($query));
    }

    private function isIdentifierLike(string $term): bool
    {
        return strpbrk($term, '/._-') !== false;
    }

    private function referenceTimestamp(array $memory): ?int
    {
        return $this->timestamp((string) ($memory['updated_at'] ?? ''))
            ?? $this->timestamp((string) ($memory['created_at'] ?? ''));
    }

    private function timestamp(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function decisionRecencyBoost(?int $timestamp): int
    {
        if ($timestamp === null) {
            return 0;
        }

        $age = time() - $timestamp;

        return match (true) {
            $age <= 30 * 86400 => 18,
            $age <= 180 * 86400 => 10,
            default => 0,
        };
    }

    private function staleWorkingPenalty(?int $timestamp): int
    {
        if ($timestamp === null) {
            return 0;
        }

        $age = time() - $timestamp;

        return match (true) {
            $age >= 60 * 86400 => 35,
            $age >= 14 * 86400 => 20,
            default => 0,
        };
    }
}
