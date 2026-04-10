<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\Security\PromptInjectionScanner;

/**
 * Formats stored memories into a structured markdown block for injection into the system prompt.
 * Organises memories by class (priority, durable, working) and type (project, user, decision, compaction).
 * Used by the agent runtime to provide persistent context to the LLM.
 */
class MemoryInjector
{
    /**
     * @param  array<int, array<string, mixed>>  $memories
     */
    public static function format(array $memories): string
    {
        $memories = self::filterSafeEntries($memories, ['title', 'content']);

        if ($memories === []) {
            return '';
        }

        $grouped = [];
        foreach ($memories as $memory) {
            $type = (string) ($memory['type'] ?? 'unknown');
            $grouped[$type][] = $memory;
        }

        $sections = [];

        $priority = array_values(array_filter($memories, fn (array $m): bool => ($m['memory_class'] ?? 'durable') === 'priority'));
        if ($priority !== []) {
            $lines = ['## Priority Context'];
            foreach ($priority as $memory) {
                $lines[] = '- '.$memory['title'].': '.self::truncate((string) $memory['content'], 240);
            }
            $sections[] = implode("\n", $lines);
        }

        if (isset($grouped['project'])) {
            $lines = ['## Project Knowledge'];
            foreach ($grouped['project'] as $memory) {
                if (($memory['memory_class'] ?? 'durable') !== 'durable') {
                    continue;
                }
                $date = isset($memory['created_at']) ? substr((string) $memory['created_at'], 0, 10) : '';
                $lines[] = '- '.$memory['title'].': '.self::truncate((string) $memory['content'], 220).($date !== '' ? " ({$date})" : '');
            }
            if (count($lines) > 1) {
                $sections[] = implode("\n", $lines);
            }
        }

        if (isset($grouped['user'])) {
            $lines = ['## User Preferences'];
            foreach ($grouped['user'] as $memory) {
                if (($memory['memory_class'] ?? 'durable') !== 'durable') {
                    continue;
                }
                $lines[] = '- '.$memory['title'].': '.self::truncate((string) $memory['content'], 220);
            }
            if (count($lines) > 1) {
                $sections[] = implode("\n", $lines);
            }
        }

        if (isset($grouped['decision'])) {
            $lines = ['## Key Decisions'];
            foreach ($grouped['decision'] as $memory) {
                if (($memory['memory_class'] ?? 'durable') !== 'durable') {
                    continue;
                }
                $date = isset($memory['created_at']) ? substr((string) $memory['created_at'], 0, 10) : '';
                $lines[] = '- '.$memory['title'].': '.self::truncate((string) $memory['content'], 220).($date !== '' ? " ({$date})" : '');
            }
            if (count($lines) > 1) {
                $sections[] = implode("\n", $lines);
            }
        }

        $working = array_values(array_filter(
            $memories,
            fn (array $m): bool => ($m['memory_class'] ?? 'durable') === 'working' && ($m['type'] ?? '') !== 'compaction'
        ));
        if ($working !== []) {
            // Cap working memory to 5 most-recent entries to limit context size
            $lines = ['## Working Memory'];
            foreach (array_slice($working, 0, 5) as $memory) {
                $lines[] = '- '.$memory['title'].': '.self::truncate((string) $memory['content'], 180);
            }
            $sections[] = implode("\n", $lines);
        }

        if (isset($grouped['compaction'])) {
            // Show at most 3 compaction entries to keep prompt concise
            $lines = ['## Previous Sessions'];
            foreach (array_slice($grouped['compaction'], 0, 3) as $memory) {
                $date = isset($memory['created_at']) ? substr((string) $memory['created_at'], 0, 10) : '';
                $lines[] = '- ['.$date.'] '.$memory['title'];
            }
            $sections[] = implode("\n", $lines);
        }

        if ($sections === []) {
            return '';
        }

        return "\n\n# Memories\n\n".implode("\n\n", $sections);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function formatSessionRecall(array $rows): string
    {
        $rows = self::filterSafeEntries($rows, ['title', 'session_id', 'content']);

        if ($rows === []) {
            return '';
        }

        $lines = ['## Session Recall'];
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? $row['session_id'] ?? 'session');
            $role = (string) ($row['role'] ?? 'message');
            $lines[] = '- '.$title.' ['.$role.']: '.self::truncate((string) ($row['content'] ?? ''), 220);
        }

        return "\n\n".implode("\n", $lines);
    }

    /**
     * Truncate $text to $limit characters, appending ellipsis when truncated.
     */
    private static function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'...';
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  string[]  $fields
     * @return array<int, array<string, mixed>>
     */
    private static function filterSafeEntries(array $entries, array $fields): array
    {
        $scanner = new PromptInjectionScanner;

        return array_values(array_filter($entries, function (array $entry) use ($fields, $scanner): bool {
            $parts = [];
            foreach ($fields as $field) {
                $value = $entry[$field] ?? null;
                if (is_string($value) && $value !== '') {
                    $parts[] = $value;
                }
            }

            return $parts === [] || $scanner->isSafe(implode("\n", $parts));
        }));
    }
}
