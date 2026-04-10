<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Searches prior session messages in the current project using the session database.
 * Exposed separately from memory_search so the agent can explicitly recall old conversations.
 */
class SessionSearchTool extends AbstractTool
{
    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function name(): string
    {
        return 'session_search';
    }

    public function description(): string
    {
        return 'Search prior session history in this project by keywords, phrases, file paths, or command names.';
    }

    public function parameters(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Search terms or an exact phrase in quotes.'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum number of matches to return. Defaults to 8, max 20.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['query'];
    }

    /**
     * @param  array{query?:string, limit?:int|string}  $args
     */
    protected function handle(array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('Query is required.');
        }

        $rawLimit = $args['limit'] ?? 8;
        $limit = is_numeric((string) $rawLimit) ? (int) $rawLimit : 8;
        $limit = max(1, min(20, $limit));

        $rows = $this->session->searchSessionHistory($query, $limit);
        if ($rows === []) {
            return ToolResult::success('No session history matches found.');
        }

        $lines = ['Found '.count($rows).' session history matches:', ''];

        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? $row['session_id'] ?? 'session');
            $role = (string) ($row['role'] ?? 'message');
            $date = isset($row['updated_at']) ? substr((string) $row['updated_at'], 0, 10) : '';
            $datePart = $date !== '' ? " ({$date})" : '';
            $lines[] = '- '.$title.$datePart.' ['.$role.']: '.$this->truncate((string) ($row['content'] ?? ''), 280);
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'...';
    }
}
