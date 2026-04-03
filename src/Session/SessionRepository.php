<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

/**
 * Persists and retrieves session records from the SQLite database.
 *
 * Part of the Session subsystem — used by session management commands
 * to create, find, list, and update conversation sessions.
 */
class SessionRepository
{
    public function __construct(private Database $db) {}

    /**
     * @param string $project  Project root path identifying the workspace
     * @param string $model    LLM model identifier to associate with the session
     * @return string          The newly generated session UUID
     */
    public function create(string $project, string $model): string
    {
        $id = $this->uuid();
        $now = $this->now();

        $stmt = $this->db->connection()->prepare(
            'INSERT INTO sessions (id, project, model, created_at, updated_at) VALUES (:id, :project, :model, :now, :now)'
        );
        $stmt->execute([
            'id' => $id,
            'project' => $project,
            'model' => $model,
            'now' => $now,
        ]);

        return $id;
    }

    /**
     * @param string $id  Full session UUID
     * @return array|null Session row as associative array, or null if not found
     */
    public function find(string $id): ?array
    {
        $stmt = $this->db->connection()->prepare('SELECT * FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Find a session by ID prefix (for short-ID input).
     */
    public function findByPrefix(string $prefix): ?array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT * FROM sessions WHERE id LIKE :prefix LIMIT 2'
        );
        $stmt->execute(['prefix' => $prefix.'%']);
        $rows = $stmt->fetchAll();

        // Only return if exactly one match (ambiguous prefix → null)
        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * @param string $id     Session UUID
     * @param string $title  Human-readable title to store
     */
    public function updateTitle(string $id, string $title): void
    {
        $stmt = $this->db->connection()->prepare(
            'UPDATE sessions SET title = :title, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'title' => $title, 'now' => $this->now()]);
    }

    /** Bump the updated_at timestamp to mark the session as recently active. */
    public function touch(string $id): void
    {
        $stmt = $this->db->connection()->prepare(
            'UPDATE sessions SET updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'now' => $this->now()]);
    }

    /**
     * @param string $project  Project root path to filter by
     * @param int    $limit    Maximum number of sessions to return
     * @return array[]         List of session rows with aggregated message_count and last_user_message
     */
    public function listByProject(string $project, int $limit = 20): array
    {
        // Correlated subqueries: count messages and fetch the most recent user message per session
        $stmt = $this->db->connection()->prepare('
            SELECT s.*,
                (SELECT COUNT(*) FROM messages m WHERE m.session_id = s.id) AS message_count,
                (SELECT m.content FROM messages m WHERE m.session_id = s.id AND m.role = \'user\' ORDER BY m.id DESC LIMIT 1) AS last_user_message
            FROM sessions s
            WHERE s.project = :project
            ORDER BY s.updated_at DESC
            LIMIT :limit
        ');
        $stmt->execute(['project' => $project, 'limit' => $limit]);

        return $stmt->fetchAll();
    }

    /**
     * @param string $project  Project root path to filter by
     * @return array|null      Most recently updated session row, or null if none exist
     */
    public function latest(string $project): ?array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT * FROM sessions WHERE project = :project ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute(['project' => $project]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Returns the current timestamp as a high-precision Unix float string. */
    private function now(): string
    {
        return number_format(microtime(true), 6, '.', '');
    }

    /** Generates a version-4 UUID from random bytes. */
    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
