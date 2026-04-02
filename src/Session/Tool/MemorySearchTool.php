<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class MemorySearchTool implements ToolInterface
{
    private const VALID_TYPES = ['project', 'user', 'decision', 'compaction'];

    private const VALID_CLASSES = ['priority', 'working', 'durable'];

    private const VALID_SCOPES = ['memories', 'history', 'both'];

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
            'class' => ['type' => 'string', 'description' => 'Filter by memory class: "priority", "working", or "durable"'],
            'query' => ['type' => 'string', 'description' => 'Text to search for in memory titles and content'],
            'scope' => ['type' => 'string', 'description' => 'Search scope: "memories", "history", or "both". Defaults to "memories".'],
        ];
    }

    public function requiredParameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        $type = $args['type'] ?? null;
        $memoryClass = $args['class'] ?? null;
        $query = $args['query'] ?? null;
        $scope = $args['scope'] ?? 'memories';

        if ($type !== null && $type !== '' && ! in_array($type, self::VALID_TYPES, true)) {
            return ToolResult::error("Invalid memory type '{$type}'. Must be one of: ".implode(', ', self::VALID_TYPES));
        }
        if ($memoryClass !== null && $memoryClass !== '' && ! in_array($memoryClass, self::VALID_CLASSES, true)) {
            return ToolResult::error("Invalid memory class '{$memoryClass}'. Must be one of: ".implode(', ', self::VALID_CLASSES));
        }
        if (! in_array($scope, self::VALID_SCOPES, true)) {
            return ToolResult::error("Invalid scope '{$scope}'. Must be one of: ".implode(', ', self::VALID_SCOPES));
        }

        $type = ($type !== null && $type !== '') ? $type : null;
        $memoryClass = ($memoryClass !== null && $memoryClass !== '') ? $memoryClass : null;
        $query = ($query !== null && $query !== '') ? $query : null;

        $memories = [];
        if ($scope === 'memories' || $scope === 'both') {
            $memories = $this->session->searchMemories($type, $query, 20, $memoryClass);
        }
        $history = [];
        if (($scope === 'history' || $scope === 'both') && $query !== null) {
            $history = $this->session->searchSessionHistory($query, 8);
        }

        if ($memories === [] && $history === []) {
            return ToolResult::success($scope === 'history' ? 'No session history matches found.' : 'No memories found.');
        }

        $lines = [];

        if ($memories !== []) {
            $lines[] = 'Found '.count($memories).' memories:';
            $lines[] = '';
            foreach ($memories as $m) {
                $date = isset($m['created_at']) ? substr((string) $m['created_at'], 0, 10) : '';
                $datePart = $date !== '' ? " ({$date})" : '';
                $classPart = isset($m['memory_class']) ? '/'.$m['memory_class'] : '';
                $lines[] = "#{$m['id']} [{$m['type']}{$classPart}] {$m['title']}{$datePart}";
                $lines[] = '  '.$m['content'];
                $lines[] = '';
            }
        }

        if ($history !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'Session history matches:';
            $lines[] = '';
            foreach ($history as $row) {
                $title = (string) ($row['title'] ?? $row['session_id'] ?? 'session');
                $lines[] = '- '.$title.' ['.$row['role'].']: '.$row['content'];
            }
        }

        return ToolResult::success(implode("\n", $lines));
    }
}
