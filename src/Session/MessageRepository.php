<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Kosmokrator\LLM\MessageSerializer;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Persists and retrieves conversation messages for sessions via SQLite.
 *
 * Part of the Session subsystem alongside SessionManager and Database.
 * Delegates Prism message serialization/deserialization to MessageSerializer,
 * keeping Prism type knowledge at the boundary.
 */
class MessageRepository implements MessageRepositoryInterface
{
    private readonly MessageSerializer $serializer;

    public function __construct(private Database $db)
    {
        $this->serializer = new MessageSerializer;
    }

    /**
     * Persist a new message and return its auto-incremented row ID.
     *
     * @param  string  $sessionId  Session this message belongs to
     * @param  string  $role  Message role (user, assistant, system, tool_result)
     * @param  string|null  $content  Text content of the message
     * @param  ToolCall[]|null  $toolCalls  Tool calls requested by an assistant message
     * @param  ToolResult[]|null  $toolResults  Tool execution results for a tool_result message
     * @param  int  $tokensIn  Input token count for this message
     * @param  int  $tokensOut  Output token count for this message
     * @return int Inserted row ID
     */
    public function append(
        string $sessionId,
        string $role,
        ?string $content = null,
        ?array $toolCalls = null,
        ?array $toolResults = null,
        int $tokensIn = 0,
        int $tokensOut = 0,
    ): int {
        $stmt = $this->db->connection()->prepare(
            'INSERT INTO messages (session_id, role, content, tool_calls, tool_results, tokens_in, tokens_out, created_at)
             VALUES (:session_id, :role, :content, :tool_calls, :tool_results, :tokens_in, :tokens_out, :now)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'role' => $role,
            'content' => $content,
            // Tool calls/results are JSON-serialized for storage via MessageSerializer
            'tool_calls' => $toolCalls !== null ? json_encode($this->serializer->serializeToolCalls($toolCalls), JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'tool_results' => $toolResults !== null ? json_encode($this->serializer->serializeToolResults($toolResults), JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'now' => date('c'),
        ]);

        return (int) $this->db->connection()->lastInsertId();
    }

    /**
     * Load all non-compacted messages for a session, deserialized into Prism value objects.
     *
     * @param  string  $sessionId  Session to load messages for
     * @return Message[] Ordered list of deserialized messages
     */
    public function loadActive(string $sessionId): array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT * FROM messages WHERE session_id = :session_id AND compacted = 0 ORDER BY id ASC'
        );
        $stmt->execute(['session_id' => $sessionId]);

        $messages = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $message = $this->serializer->deserializeMessage($row);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Load raw message rows for a session, optionally including compacted ones.
     *
     * @param  string  $sessionId  Session to load messages for
     * @param  bool  $includeCompacted  Whether to include compacted (summarized) messages
     * @return array<int, array<string, mixed>> Raw database rows
     */
    public function loadRaw(string $sessionId, bool $includeCompacted = false): array
    {
        $sql = 'SELECT * FROM messages WHERE session_id = :session_id';
        if (! $includeCompacted) {
            $sql .= ' AND compacted = 0';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->fetchAll();
    }

    /**
     * Mark all messages before a given ID as compacted (excluded from active context).
     *
     * @param  string  $sessionId  Session whose messages to mark
     * @param  int  $beforeId  Compact all messages with an ID lower than this
     */
    public function markCompacted(string $sessionId, int $beforeId): void
    {
        $stmt = $this->db->connection()->prepare(
            'UPDATE messages SET compacted = 1 WHERE session_id = :session_id AND id < :before_id'
        );
        $stmt->execute(['session_id' => $sessionId, 'before_id' => $beforeId]);
    }

    /**
     * Mark specific messages as compacted by their row IDs.
     *
     * @param  int[]  $messageIds  Database row IDs to compact
     */
    public function markCompactedIds(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        // Build an IN-clause with positional placeholders
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->connection()->prepare(
            "UPDATE messages SET compacted = 1 WHERE id IN ({$placeholders})"
        );
        $stmt->execute($messageIds);
    }

    /**
     * Compact the given message IDs and insert a system summary message in one transaction.
     *
     * @param  string  $sessionId  Session being compacted
     * @param  int[]  $messageIds  Row IDs to mark as compacted
     * @param  string  $summary  Summary text stored as a system message
     */
    public function compactWithSummary(string $sessionId, array $messageIds, string $summary): void
    {
        $pdo = $this->db->connection();
        // Only start a transaction if we're not already inside one
        $startedTransaction = ! $pdo->inTransaction();

        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->markCompactedIds($messageIds);
            $this->append(
                sessionId: $sessionId,
                role: 'system',
                content: $summary,
            );

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        // Purge previously compacted messages to prevent DB bloat
        $this->deleteCompacted($sessionId);
    }

    /**
     * Delete all compacted messages for a session that are no longer needed.
     * Called after successful compaction to prevent unbounded DB growth.
     */
    public function deleteCompacted(string $sessionId): void
    {
        $stmt = $this->db->connection()->prepare(
            'DELETE FROM messages WHERE session_id = :session_id AND compacted = 1'
        );
        $stmt->execute(['session_id' => $sessionId]);
    }

    /**
     * Count non-compacted messages for a session.
     *
     * @param  string  $sessionId  Session to count messages for
     * @return int Number of active (non-compacted) messages
     */
    public function count(string $sessionId): int
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT COUNT(*) as cnt FROM messages WHERE session_id = :session_id AND compacted = 0'
        );
        $stmt->execute(['session_id' => $sessionId]);

        return (int) $stmt->fetch()['cnt'];
    }

    /**
     * Sum total token usage across all messages for a session.
     *
     * @param  string  $sessionId  Session to tally tokens for
     * @return array{tokens_in: int, tokens_out: int} Cumulative token counts
     */
    public function sumTokens(string $sessionId): array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT COALESCE(SUM(tokens_in), 0) AS tokens_in,
                    COALESCE(SUM(tokens_out), 0) AS tokens_out
             FROM messages WHERE session_id = :sid'
        );
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'tokens_in' => (int) $row['tokens_in'],
            'tokens_out' => (int) $row['tokens_out'],
        ];
    }

    /**
     * Search messages across all sessions for a project using FTS5 ranking.
     *
     * @param  string  $project  Project path to scope the search
     * @param  string  $query  Free-text query converted to an FTS expression
     * @param  string|null  $excludeSessionId  Optional session to exclude from results
     * @param  int  $limit  Maximum number of rows to return
     * @return array<int, array<string, mixed>> Matching message rows with session metadata
     */
    public function searchProjectHistory(string $project, string $query, ?string $excludeSessionId = null, int $limit = 5): array
    {
        $ftsQuery = $this->buildFtsQuery($query);
        if ($ftsQuery === null) {
            return [];
        }

        $sql = '
            SELECT m.session_id, m.role, m.content, m.created_at, s.title, s.updated_at,
                   bm25(messages_fts) AS rank
            FROM messages_fts
            INNER JOIN messages m ON m.id = messages_fts.rowid
            INNER JOIN sessions s ON s.id = m.session_id
            WHERE s.project = :project
              AND m.compacted = 0
              AND m.content IS NOT NULL
              AND messages_fts MATCH :query
        ';
        $params = [
            'project' => $project,
            'query' => $ftsQuery,
        ];

        if ($excludeSessionId !== null) {
            $sql .= ' AND m.session_id != :exclude_session_id';
            $params['exclude_session_id'] = $excludeSessionId;
        }

        $sql .= ' ORDER BY rank ASC, s.updated_at DESC, m.id DESC LIMIT :limit';
        $params['limit'] = $limit;

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();

        if ($rows === [] || ($this->looksIdentifierLike($query) && count($rows) < $limit)) {
            $rows = $this->mergeHistoryRows(
                $rows,
                $this->searchProjectHistoryLikeFallback($project, $query, $excludeSessionId, $limit),
                $limit,
            );
        }

        return $rows;
    }

    private function buildFtsQuery(string $query): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        preg_match_all('/"([^"]+)"|([[:alnum:]_\\.\\/-]+)/u', $query, $matches, \PREG_SET_ORDER);
        $terms = [];

        foreach ($matches as $match) {
            $phrase = trim((string) ($match[1] ?? ''));
            if ($phrase !== '') {
                $terms[] = '"'.str_replace('"', '""', $phrase).'"';

                continue;
            }

            $term = trim((string) ($match[2] ?? ''));
            if ($term === '') {
                continue;
            }

            $quoted = strpbrk($term, '/._-') !== false;
            $escaped = str_replace('"', '""', $term);
            $terms[] = $quoted ? '"'.$escaped.'"' : $escaped.'*';
        }

        if ($terms === []) {
            return null;
        }

        return implode(' AND ', $terms);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchProjectHistoryLikeFallback(string $project, string $query, ?string $excludeSessionId, int $limit): array
    {
        $tokens = $this->fallbackTerms($query);
        if ($tokens === []) {
            return [];
        }

        $sql = '
            SELECT m.session_id, m.role, m.content, m.created_at, s.title, s.updated_at
            FROM messages m
            INNER JOIN sessions s ON s.id = m.session_id
            WHERE s.project = :project
              AND m.compacted = 0
              AND m.content IS NOT NULL
        ';
        $params = ['project' => $project];

        if ($excludeSessionId !== null) {
            $sql .= ' AND m.session_id != :exclude_session_id';
            $params['exclude_session_id'] = $excludeSessionId;
        }

        $clauses = [];
        foreach ($tokens as $index => $term) {
            $param = "query_{$index}";
            $clauses[] = "m.content LIKE :{$param} ESCAPE '\\'";
            $params[$param] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        }

        $sql .= ' AND ('.implode(' OR ', $clauses).')';
        $sql .= ' ORDER BY s.updated_at DESC, m.id DESC LIMIT :limit';
        $params['limit'] = $limit;

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return string[]
     */
    private function fallbackTerms(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $terms = [$query];
        preg_match_all('/[[:alnum:]_\\.\\/-]+/u', $query, $matches);

        foreach (($matches[0] ?? []) as $term) {
            $normalized = trim($term);
            if ($normalized === '') {
                continue;
            }

            if ($this->looksIdentifierLike($normalized) || mb_strlen($normalized) >= 3) {
                $terms[] = $normalized;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * FTS5 search grouped by session — returns per-session match info with context.
     *
     * For each matched session, returns the best-matching message and up to 2
     * surrounding messages for context, plus a count of total matches in that session.
     *
     * @param  string  $project  Project path to scope the search
     * @param  string  $query  Free-text query
     * @param  string|null  $excludeSessionId  Optional session to exclude
     * @param  int  $limit  Maximum number of unique sessions to return
     * @return array<int, array{session_id: string, title: ?string, updated_at: string, match_count: int, best_match: array{role: string, content: string, created_at: string}, context: list<array{role: string, content: string}>}>
     */
    public function searchProjectHistoryGrouped(string $project, string $query, ?string $excludeSessionId = null, int $limit = 5): array
    {
        $ftsQuery = $this->buildFtsQuery($query);
        if ($ftsQuery === null) {
            return [];
        }

        // Fetch more results than needed so we can group and rank by session
        $fetchLimit = $limit * 10;

        $sql = '
            SELECT m.id, m.session_id, m.role, m.content, m.created_at,
                   s.title, s.updated_at, bm25(messages_fts) AS rank
            FROM messages_fts
            INNER JOIN messages m ON m.id = messages_fts.rowid
            INNER JOIN sessions s ON s.id = m.session_id
            WHERE s.project = :project
              AND m.compacted = 0
              AND m.content IS NOT NULL
              AND messages_fts MATCH :query
        ';
        $params = ['project' => $project, 'query' => $ftsQuery];

        if ($excludeSessionId !== null) {
            $sql .= ' AND m.session_id != :exclude_session_id';
            $params['exclude_session_id'] = $excludeSessionId;
        }

        $sql .= ' ORDER BY rank ASC, s.updated_at DESC LIMIT :fetch_limit';
        $params['fetch_limit'] = $fetchLimit;

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Group by session, keeping the best match (lowest rank = most relevant)
        $sessions = [];
        foreach ($rows as $row) {
            $sid = $row['session_id'];
            if (! isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'session_id' => $sid,
                    'title' => $row['title'],
                    'updated_at' => $row['updated_at'],
                    'match_count' => 0,
                    'best_match_id' => (int) $row['id'],
                    'best_match' => [
                        'role' => $row['role'],
                        'content' => $row['content'],
                        'created_at' => $row['created_at'],
                    ],
                ];
            }
            $sessions[$sid]['match_count']++;

            if (count($sessions) >= $limit && ! isset($sessions[$sid])) {
                break;
            }
        }

        // Fetch context (surrounding messages) for each session's best match
        $result = [];
        foreach (array_slice(array_values($sessions), 0, $limit) as $entry) {
            $entry['context'] = $this->loadContextAroundMessage($entry['session_id'], $entry['best_match_id']);
            unset($entry['best_match_id']);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Load 1 message before and 1 after the given message ID within the same session.
     *
     * @return list<array{role: string, content: string}>
     */
    private function loadContextAroundMessage(string $sessionId, int $messageId): array
    {
        $context = [];

        // Message before
        $stmt = $this->db->connection()->prepare('
            SELECT role, content FROM messages
            WHERE session_id = :sid AND id < :mid AND compacted = 0 AND content IS NOT NULL
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute(['sid' => $sessionId, 'mid' => $messageId]);
        $before = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($before) {
            $context[] = ['role' => $before['role'], 'content' => $before['content']];
        }

        // Message after
        $stmt = $this->db->connection()->prepare('
            SELECT role, content FROM messages
            WHERE session_id = :sid AND id > :mid AND compacted = 0 AND content IS NOT NULL
            ORDER BY id ASC LIMIT 1
        ');
        $stmt->execute(['sid' => $sessionId, 'mid' => $messageId]);
        $after = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($after) {
            $context[] = ['role' => $after['role'], 'content' => $after['content']];
        }

        return $context;
    }

    /**
     * Load a session's messages formatted as a readable transcript.
     *
     * @param  string  $sessionId  Session to load
     * @param  int  $limit  Maximum messages to return (0 = all)
     * @return list<array{role: string, content: string, tool_calls: ?string, created_at: string}>
     */
    public function loadTranscript(string $sessionId, int $limit = 0): array
    {
        $sql = 'SELECT role, content, tool_calls, created_at
                FROM messages
                WHERE session_id = :sid AND compacted = 0
                ORDER BY id ASC';
        $params = ['sid' => $sessionId];

        if ($limit > 0) {
            $sql .= ' LIMIT :lim';
            $params['lim'] = $limit;
        }

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function looksIdentifierLike(string $query): bool
    {
        return strpbrk($query, '/._-') !== false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $primary
     * @param  array<int, array<string, mixed>>  $fallback
     * @return array<int, array<string, mixed>>
     */
    private function mergeHistoryRows(array $primary, array $fallback, int $limit): array
    {
        $merged = [];

        foreach ([$primary, $fallback] as $rows) {
            foreach ($rows as $row) {
                $key = implode(':', [
                    (string) ($row['session_id'] ?? ''),
                    (string) ($row['created_at'] ?? ''),
                    (string) ($row['role'] ?? ''),
                    (string) ($row['content'] ?? ''),
                ]);

                if (isset($merged[$key])) {
                    continue;
                }

                $merged[$key] = $row;
                if (count($merged) >= $limit) {
                    break 2;
                }
            }
        }

        return array_values($merged);
    }
}
