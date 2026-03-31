<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\Session\MemoryRepository;

class MemoryInjector
{
    /**
     * Load and format memories for injection into the system prompt.
     *
     * @param  array[]  $memories  Raw memory rows from MemoryRepository
     */
    public static function format(array $memories): string
    {
        if ($memories === []) {
            return '';
        }

        $grouped = [];
        foreach ($memories as $memory) {
            $type = $memory['type'];
            $grouped[$type][] = $memory;
        }

        $sections = [];

        // Project knowledge
        if (isset($grouped['project'])) {
            $lines = ['## Project Knowledge'];
            foreach ($grouped['project'] as $m) {
                $date = isset($m['created_at']) ? substr($m['created_at'], 0, 10) : '';
                $lines[] = "- {$m['title']}: {$m['content']}".($date ? " ({$date})" : '');
            }
            $sections[] = implode("\n", $lines);
        }

        // User preferences
        if (isset($grouped['user'])) {
            $lines = ['## User Preferences'];
            foreach ($grouped['user'] as $m) {
                $lines[] = "- {$m['title']}: {$m['content']}";
            }
            $sections[] = implode("\n", $lines);
        }

        // Technical decisions
        if (isset($grouped['decision'])) {
            $lines = ['## Key Decisions'];
            foreach ($grouped['decision'] as $m) {
                $date = isset($m['created_at']) ? substr($m['created_at'], 0, 10) : '';
                $lines[] = "- {$m['title']}: {$m['content']}".($date ? " ({$date})" : '');
            }
            $sections[] = implode("\n", $lines);
        }

        // Compaction summaries (most recent only, as context)
        if (isset($grouped['compaction'])) {
            $recent = array_slice($grouped['compaction'], 0, 3);
            $lines = ['## Previous Sessions'];
            foreach ($recent as $m) {
                $date = isset($m['created_at']) ? substr($m['created_at'], 0, 10) : '';
                $lines[] = "- [{$date}] {$m['title']}";
            }
            $sections[] = implode("\n", $lines);
        }

        if ($sections === []) {
            return '';
        }

        return "\n\n# Memories\n\n".implode("\n\n", $sections);
    }
}
