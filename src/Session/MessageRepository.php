<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Persists and retrieves conversation messages for sessions via SQLite.
 *
 * Part of the Session subsystem alongside SessionManager and Database.
 * Handles serialization of Prism message types (including tool calls/results)
 * to and from the `messages` table, and supports compacting old messages.
 */
class MessageRepository
{
    public function __construct(private Database $db) {}

    /**
     * Persist a new message and return its auto-incremented row ID.
     *
     * @param string       $sessionId   Session this message belongs to
     * @param string       $role        Message role (user, assistant, system, tool_result)
     * @param string|null  $content     Text content of the message
     * @param ToolCall[]|null   $toolCalls    Tool calls requested by an assistant message
     * @param ToolResult[]|null $toolResults  Tool execution results for a tool_result message
     * @param int          $tokensIn    Input token count for this message
     * @param int          $tokensOut   Output token count for this message
     *
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
            // Tool calls/results are JSON-serialized for storage
            'tool_calls' => $toolCalls !== null ? json_encode($this->serializeToolCalls($toolCalls), JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'tool_results' => $toolResults !== null ? json_encode($this->serializeToolResults($toolResults), JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'now' => date('c'),
        ]);

        return (int) $this->db->connection()->lastInsertId();
    }

    /**
     * Load all non-compacted messages for a session, deserialized into Prism value objects.
     *
     * @param string $sessionId Session to load messages for
     *
     * @return Message[] Ordered list of deserialized messages
     */
    public function loadActive(string $sessionId): array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT * FROM messages WHERE session_id = :session_id AND compacted = 0 ORDER BY id ASC'
        );
        $stmt->execute(['session_id' => $sessionId]);
        $rows = $stmt->fetchAll();

        $messages = [];
        foreach ($rows as $row) {
            $message = $this->deserializeMessage($row);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Load raw message rows for a session, optionally including compacted ones.
     *
     * @param string $sessionId        Session to load messages for
     * @param bool   $includeCompacted Whether to include compacted (summarized) messages
     *
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
     * @param string $sessionId Session whose messages to mark
     * @param int    $beforeId  Compact all messages with an ID lower than this
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
     * @param int[] $messageIds Database row IDs to compact
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
     * @param string $sessionId   Session being compacted
     * @param int[]  $messageIds  Row IDs to mark as compacted
     * @param string $summary     Summary text stored as a system message
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
    }

    /**
     * Count non-compacted messages for a session.
     *
     * @param string $sessionId Session to count messages for
     *
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
     * @param string $sessionId Session to tally tokens for
     *
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
     * Search messages across all sessions for a project using a LIKE query.
     *
     * @param string      $project          Project path to scope the search
     * @param string      $query            Search term (LIKE pattern, auto-escaped)
     * @param string|null $excludeSessionId  Optional session to exclude from results
     * @param int         $limit            Maximum number of rows to return
     *
     * @return array<int, array<string, mixed>> Matching message rows with session metadata
     */
    public function searchProjectHistory(string $project, string $query, ?string $excludeSessionId = null, int $limit = 5): array
    {
        $sql = '
            SELECT m.session_id, m.role, m.content, m.created_at, s.title, s.updated_at
            FROM messages m
            INNER JOIN sessions s ON s.id = m.session_id
            WHERE s.project = :project
              AND m.compacted = 0
              AND m.content IS NOT NULL
              AND m.content LIKE :query ESCAPE \'\\\'
        ';
        // Escape LIKE wildcards in the user query
        $params = [
            'project' => $project,
            'query' => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%',
        ];

        if ($excludeSessionId !== null) {
            $sql .= ' AND m.session_id != :exclude_session_id';
            $params['exclude_session_id'] = $excludeSessionId;
        }

        $sql .= ' ORDER BY s.updated_at DESC, m.id DESC LIMIT :limit';
        $params['limit'] = $limit;

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Convert ToolCall objects to plain arrays for JSON storage.
     *
     * @param  ToolCall[]  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    private function serializeToolCalls(array $toolCalls): array
    {
        return array_map(fn (ToolCall $tc) => [
            'id' => $tc->id,
            'name' => $tc->name,
            'arguments' => $tc->arguments(),
        ], $toolCalls);
    }

    /**
     * Convert ToolResult objects to plain arrays for JSON storage.
     *
     * @param  ToolResult[]  $toolResults
     * @return array<int, array<string, mixed>>
     */
    private function serializeToolResults(array $toolResults): array
    {
        return array_map(fn (ToolResult $tr) => [
            'toolCallId' => $tr->toolCallId,
            'toolName' => $tr->toolName,
            'args' => $tr->args,
            'result' => $tr->result,
        ], $toolResults);
    }

    /** Reconstruct a Prism Message value object from a database row. */
    private function deserializeMessage(array $row): ?Message
    {
        return match ($row['role']) {
            'user' => new UserMessage($row['content'] ?? ''),
            'assistant' => new AssistantMessage(
                content: $row['content'] ?? '',
                toolCalls: $row['tool_calls'] ? $this->deserializeToolCalls($row['tool_calls']) : [],
            ),
            'tool_result' => $row['tool_results']
                ? new ToolResultMessage($this->deserializeToolResults($row['tool_results']))
                : null,
            'system' => new SystemMessage($row['content'] ?? ''),
            default => null,
        };
    }

    /**
     * Parse a JSON string back into ToolCall objects.
     *
     * @return ToolCall[]
     */
    private function deserializeToolCalls(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }

        return array_map(fn (array $tc) => new ToolCall(
            id: $tc['id'],
            name: $tc['name'],
            arguments: $tc['arguments'],
        ), $data);
    }

    /**
     * Parse a JSON string back into ToolResult objects.
     *
     * @return ToolResult[]
     */
    private function deserializeToolResults(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }

        return array_map(fn (array $tr) => new ToolResult(
            toolCallId: $tr['toolCallId'],
            toolName: $tr['toolName'] ?? '',
            args: $tr['args'] ?? [],
            result: $tr['result'],
        ), $data);
    }
}
