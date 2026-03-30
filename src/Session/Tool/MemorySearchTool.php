<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class MemorySearchTool implements ToolInterface
{
    private const VALID_TYPES = ['project', 'user', 'decision', 'compaction'];

    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function name(): string
    {
        return 'memory_search';
    }

    public function description(): string
    {
        return 'Search and list saved memories. Use to recall project facts, user preferences, or past decisions before asking the user.';
    }

    public function parameters(): array
    {
        return [
            'type' => ['type' => 'string', 'description' => 'Filter by memory type: "project", "user", "decision", or "compaction"'],
            'query' => ['type' => 'string', 'description' => 'Text to search for in memory titles and content'],
        ];
    }

    public function requiredParameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        $type = $args['type'] ?? null;
        $query = $args['query'] ?? null;

        if ($type !== null && $type !== '' && ! in_array($type, self::VALID_TYPES, true)) {
            return ToolResult::error("Invalid memory type '{$type}'. Must be one of: " . implode(', ', self::VALID_TYPES));
        }

        $type = ($type !== null && $type !== '') ? $type : null;
        $query = ($query !== null && $query !== '') ? $query : null;

        $memories = $this->session->searchMemories($type, $query);

        if ($memories === []) {
            return ToolResult::success('No memories found.');
        }

        $lines = ['Found ' . count($memories) . ' memories:', ''];

        foreach ($memories as $m) {
            $date = isset($m['created_at']) ? substr($m['created_at'], 0, 10) : '';
            $datePart = $date !== '' ? " ({$date})" : '';
            $lines[] = "#{$m['id']} [{$m['type']}] {$m['title']}{$datePart}";
            $lines[] = '  ' . $m['content'];
            $lines[] = '';
        }

        return ToolResult::success(implode("\n", $lines));
    }
}
