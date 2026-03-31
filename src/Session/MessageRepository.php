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

class MessageRepository
{
    public function __construct(private Database $db) {}

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
            'tool_calls' => $toolCalls !== null ? json_encode($this->serializeToolCalls($toolCalls)) : null,
            'tool_results' => $toolResults !== null ? json_encode($this->serializeToolResults($toolResults)) : null,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'now' => date('c'),
        ]);

        return (int) $this->db->connection()->lastInsertId();
    }

    /**
     * Load active (non-compacted) messages as Prism Message objects.
     *
     * @return Message[]
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
     * Load raw message rows (for compaction, display, etc.)
     *
     * @return array[]
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

    public function markCompacted(string $sessionId, int $beforeId): void
    {
        $stmt = $this->db->connection()->prepare(
            'UPDATE messages SET compacted = 1 WHERE session_id = :session_id AND id < :before_id'
        );
        $stmt->execute(['session_id' => $sessionId, 'before_id' => $beforeId]);
    }

    public function count(string $sessionId): int
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT COUNT(*) as cnt FROM messages WHERE session_id = :session_id AND compacted = 0'
        );
        $stmt->execute(['session_id' => $sessionId]);

        return (int) $stmt->fetch()['cnt'];
    }

    /**
     * Sum all token usage for a session (including compacted messages).
     *
     * @return array{tokens_in: int, tokens_out: int}
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
     * @param  ToolCall[]  $toolCalls
     * @return array[]
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
     * @param  ToolResult[]  $toolResults
     * @return array[]
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
