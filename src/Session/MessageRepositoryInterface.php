<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Contract for conversation message persistence operations.
 *
 * Defines the public API for appending, loading, compacting, counting,
 * and searching messages within sessions.
 */
interface MessageRepositoryInterface
{
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
    ): int;

    /**
     * Load all non-compacted messages for a session, deserialized into Prism value objects.
     *
     * @param  string  $sessionId  Session to load messages for
     * @return Message[] Ordered list of deserialized messages
     */
    public function loadActive(string $sessionId): array;

    /**
     * Load raw message rows for a session, optionally including compacted ones.
     *
     * @param  string  $sessionId  Session to load messages for
     * @param  bool  $includeCompacted  Whether to include compacted (summarized) messages
     * @return array<int, array<string, mixed>> Raw database rows
     */
    public function loadRaw(string $sessionId, bool $includeCompacted = false): array;

    /**
     * Mark all messages before a given ID as compacted (excluded from active context).
     *
     * @param  string  $sessionId  Session whose messages to mark
     * @param  int  $beforeId  Compact all messages with an ID lower than this
     */
    public function markCompacted(string $sessionId, int $beforeId): void;

    /**
     * Mark specific messages as compacted by their row IDs.
     *
     * @param  int[]  $messageIds  Database row IDs to compact
     */
    public function markCompactedIds(array $messageIds): void;

    /**
     * Compact the given message IDs and insert a system summary message in one transaction.
     *
     * @param  string  $sessionId  Session being compacted
     * @param  int[]  $messageIds  Row IDs to mark as compacted
     * @param  string  $summary  Summary text stored as a system message
     */
    public function compactWithSummary(string $sessionId, array $messageIds, string $summary): void;

    /**
     * Count non-compacted messages for a session.
     *
     * @param  string  $sessionId  Session to count messages for
     * @return int Number of active (non-compacted) messages
     */
    public function count(string $sessionId): int;

    /**
     * Sum total token usage across all messages for a session.
     *
     * @param  string  $sessionId  Session to tally tokens for
     * @return array{tokens_in: int, tokens_out: int} Cumulative token counts
     */
    public function sumTokens(string $sessionId): array;

    /**
     * Search messages across all sessions for a project using FTS5.
     *
     * @param  string  $project  Project path to scope the search
     * @param  string  $query  User query text converted to an FTS expression internally
     * @param  string|null  $excludeSessionId  Optional session to exclude from results
     * @param  int  $limit  Maximum number of rows to return
     * @return array<int, array<string, mixed>> Matching message rows with session metadata
     */
    public function searchProjectHistory(string $project, string $query, ?string $excludeSessionId = null, int $limit = 5): array;
}
