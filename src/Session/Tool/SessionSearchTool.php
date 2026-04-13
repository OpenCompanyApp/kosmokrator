<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Searches prior session history or lists recent sessions in the current project.
 *
 * Two modes:
 * - Browse (empty query): returns recent sessions with titles, dates, and previews
 * - Search (with query): FTS5 search grouped by session with surrounding context
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
        return <<<'DESC'
Search prior sessions or browse recent ones in this project.

Two modes:
1. Browse (no query): Returns recent sessions with titles, dates, message counts, and preview of the last user message. Zero cost, instant.
2. Search (with query): FTS5 keyword search across all past sessions. Returns results grouped by session with match counts and surrounding context.

Use this proactively when:
- The user says "we did this before", "remember when", "last time", "as I mentioned"
- The user references a topic worked on before but not in current context
- You want to check if a similar problem was solved before
- The user asks "what did we do about X?" or "how did we fix Y?"

Search syntax: keywords (joined with AND by default), "exact phrase" in quotes, path/identifiers like src/Foo.php.
DESC;
    }

    public function parameters(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Search terms, exact phrases in quotes, or file paths. Omit to browse recent sessions.'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum results. Defaults to 5 (browse) or 5 (search), max 10.'],
        ];
    }

    public function requiredParameters(): array
    {
        return [];
    }

    /**
     * @param  array{query?:string, limit?:int|string}  $args
     */
    protected function handle(array $args): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        $rawLimit = $args['limit'] ?? 5;
        $limit = is_numeric((string) $rawLimit) ? (int) $rawLimit : 5;
        $limit = max(1, min(10, $limit));

        if ($query === '') {
            return $this->browseRecent($limit);
        }

        return $this->searchGrouped($query, $limit);
    }

    private function browseRecent(int $limit): ToolResult
    {
        $sessions = $this->session->listSessions($limit);
        if ($sessions === []) {
            return ToolResult::success('No prior sessions found in this project.');
        }

        $lines = ['Recent sessions ('.count($sessions).'):', ''];

        foreach ($sessions as $s) {
            $title = (string) ($s['title'] ?? 'untitled');
            $date = isset($s['updated_at']) ? $this->formatDate((string) $s['updated_at']) : '';
            $count = (int) ($s['message_count'] ?? 0);
            $preview = $this->truncate((string) ($s['last_user_message'] ?? ''), 120);
            $id = substr((string) $s['id'], 0, 8);

            $lines[] = "- [{$id}] {$title} ({$date}, {$count} msgs)";
            if ($preview !== '') {
                $lines[] = "  > {$preview}";
            }
        }

        $lines[] = '';
        $lines[] = 'Use session_search with a query to search across sessions, or session_read with a session ID to view a full transcript.';

        return ToolResult::success(implode("\n", $lines));
    }

    private function searchGrouped(string $query, int $limit): ToolResult
    {
        $results = $this->session->searchSessionHistoryGrouped($query, $limit);
        if ($results === []) {
            return ToolResult::success("No session history matches for \"{$query}\".");
        }

        $lines = ['Found matches in '.count($results)." session(s) for \"{$query}\":", ''];

        foreach ($results as $entry) {
            $title = (string) ($entry['title'] ?? 'untitled');
            $date = isset($entry['updated_at']) ? $this->formatDate((string) $entry['updated_at']) : '';
            $matchCount = (int) ($entry['match_count'] ?? 1);
            $id = substr((string) $entry['session_id'], 0, 8);

            $lines[] = "## [{$id}] {$title} ({$date}, {$matchCount} matches)";
            $lines[] = '';

            // Show context messages before the match
            foreach ($entry['context'] ?? [] as $ctx) {
                $role = strtoupper((string) ($ctx['role'] ?? 'unknown'));
                $content = $this->truncate((string) ($ctx['content'] ?? ''), 200);
                if ($content !== '') {
                    $lines[] = "  [{$role}]: {$content}";
                }
            }

            // Show the best match
            $best = $entry['best_match'] ?? [];
            $bestRole = strtoupper((string) ($best['role'] ?? 'unknown'));
            $bestContent = $this->truncate((string) ($best['content'] ?? ''), 400);
            $lines[] = "  **[{$bestRole}]**: {$bestContent}";
            $lines[] = '';
        }

        $lines[] = 'Use session_read with a session ID prefix to view the full transcript.';

        return ToolResult::success(implode("\n", $lines));
    }

    private function truncate(string $text, int $limit): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'...';
    }

    private function formatDate(string $timestamp): string
    {
        // Handle both ISO-8601 and Unix float timestamps
        if (is_numeric($timestamp)) {
            return date('Y-m-d', (int) ((float) $timestamp));
        }

        $time = strtotime($timestamp);

        return $time !== false ? date('Y-m-d', $time) : substr($timestamp, 0, 10);
    }
}
