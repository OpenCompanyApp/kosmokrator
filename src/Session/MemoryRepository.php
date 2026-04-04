<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

/**
 * Persists and queries agent memories (facts, decisions, preferences) in the session database.
 *
 * Repository layer between the MemoryManager and the SQLite storage used by each session.
 */
class MemoryRepository implements MemoryRepositoryInterface
{
    public function __construct(private Database $db) {}

    /**
     * Insert a new memory record.
     *
     * @param  string  $type  Memory type: "project", "user", "decision", or "compaction"
     * @param  string  $title  Short descriptive title for quick identification
     * @param  string  $content  The full memory content to persist
     * @param  string|null  $project  Project scope, or null for global memories
     * @param  string|null  $sessionId  Owning session ID, or null if session-independent
     * @param  string  $memoryClass  Retention class: "priority", "working", or "durable"
     * @param  bool  $pinned  Whether this memory should be favoured during recall
     * @param  string|null  $expiresAt  ISO-8601 timestamp when the memory expires, or null for permanent
     * @return int The auto-incremented ID of the newly created memory
     */
    public function add(
        string $type,
        string $title,
        string $content,
        ?string $project = null,
        ?string $sessionId = null,
        string $memoryClass = 'durable',
        bool $pinned = false,
        ?string $expiresAt = null,
    ): int {
        $now = date('c');
        $stmt = $this->db->connection()->prepare(
            'INSERT INTO memories (project, session_id, type, memory_class, title, content, pinned, expires_at, created_at, updated_at)
             VALUES (:project, :session_id, :type, :memory_class, :title, :content, :pinned, :expires_at, :now, :now)'
        );
        $stmt->execute([
            'project' => $project,
            'session_id' => $sessionId,
            'type' => $type,
            'memory_class' => $memoryClass,
            'title' => $title,
            'content' => $content,
            'pinned' => $pinned ? 1 : 0,
            'expires_at' => $expiresAt,
            'now' => $now,
        ]);

        return (int) $this->db->connection()->lastInsertId();
    }

    /**
     * Load all non-expired memories visible to a project (includes global/project-null memories).
     *
     * @param  string|null  $project  Project scope, or null for global-only
     * @return array<int, array<string, mixed>>
     */
    public function forProject(?string $project): array
    {
        $now = date('c');
        if ($project === null) {
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories
                 WHERE project IS NULL
                   AND (expires_at IS NULL OR expires_at > :now)
                 ORDER BY pinned DESC, memory_class ASC, created_at DESC'
            );
            $stmt->execute(['now' => $now]);
        } else {
            // Include both project-specific and global (null) memories
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories
                 WHERE (project = :project OR project IS NULL)
                   AND (expires_at IS NULL OR expires_at > :now)
                 ORDER BY pinned DESC, memory_class ASC, type, created_at DESC'
            );
            $stmt->execute(['project' => $project, 'now' => $now]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Find a single memory by its ID.
     *
     * @param  int  $id  The memory record ID
     * @return array<string, mixed>|null The memory row, or null if not found
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->connection()->prepare('SELECT * FROM memories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Update an existing memory's content and optionally its metadata.
     *
     * @param  int  $id  The memory record ID
     * @param  string  $content  Updated memory content
     * @param  string|null  $title  New title, or null to keep existing
     * @param  string|null  $memoryClass  New retention class, or null to keep existing
     * @param  bool|null  $pinned  New pinned status, or null to keep existing
     * @param  string|null  $expiresAt  New expiry timestamp, or null to keep existing
     */
    public function update(
        int $id,
        string $content,
        ?string $title = null,
        ?string $memoryClass = null,
        ?bool $pinned = null,
        ?string $expiresAt = null,
    ): void {
        // Dynamically build SET clause from provided fields only
        $fields = ['content = :content', 'updated_at = :now'];
        $params = ['id' => $id, 'content' => $content, 'now' => date('c')];

        if ($title !== null) {
            $fields[] = 'title = :title';
            $params['title'] = $title;
        }
        if ($memoryClass !== null) {
            $fields[] = 'memory_class = :memory_class';
            $params['memory_class'] = $memoryClass;
        }
        if ($pinned !== null) {
            $fields[] = 'pinned = :pinned';
            $params['pinned'] = $pinned ? 1 : 0;
        }
        if ($expiresAt !== null) {
            $fields[] = 'expires_at = :expires_at';
            $params['expires_at'] = $expiresAt;
        }

        $stmt = $this->db->connection()->prepare(
            'UPDATE memories SET '.implode(', ', $fields).' WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * Search memories with optional filtering by type, class, and full-text query.
     *
     * @param  string|null  $project  Project scope, or null for global-only
     * @param  string|null  $type  Filter by memory type
     * @param  string|null  $query  LIKE search term applied to title and content
     * @param  int  $limit  Maximum number of results
     * @param  string|null  $memoryClass  Filter by retention class
     * @return array<int, array<string, mixed>>
     */
    public function search(?string $project, ?string $type = null, ?string $query = null, int $limit = 20, ?string $memoryClass = null): array
    {
        // Build WHERE clause dynamically based on provided filters
        $sql = 'SELECT * FROM memories WHERE 1=1';
        $params = ['now' => date('c')];

        if ($project !== null) {
            $sql .= ' AND (project = :project OR project IS NULL)';
            $params['project'] = $project;
        } else {
            $sql .= ' AND project IS NULL';
        }

        // Exclude expired memories
        $sql .= ' AND (expires_at IS NULL OR expires_at > :now)';

        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }

        if ($memoryClass !== null) {
            $sql .= ' AND memory_class = :memory_class';
            $params['memory_class'] = $memoryClass;
        }

        if ($query !== null && $query !== '') {
            // Escape SQL wildcards in the user query to prevent unintentional LIKE matches
            $sql .= " AND (title LIKE :query ESCAPE '\\' OR content LIKE :query2 ESCAPE '\\')";
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $params['query'] = "%{$escaped}%";
            $params['query2'] = "%{$escaped}%";
        }

        $sql .= ' ORDER BY pinned DESC, memory_class ASC, type, created_at DESC LIMIT :limit';
        $params['limit'] = $limit;

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Permanently delete a memory by ID.
     *
     * @param  int  $id  The memory record ID
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->connection()->prepare('DELETE FROM memories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Update the last_surfaced_at timestamp for memories that were recently recalled.
     *
     * @param  int[]  $ids  Memory IDs to mark as surfaced
     */
    public function touchSurfaced(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Build parameterised IN clause to avoid SQL injection
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->connection()->prepare(
            "UPDATE memories SET last_surfaced_at = ? WHERE id IN ({$placeholders})"
        );
        $stmt->execute([date('c'), ...$ids]);
    }

    /**
     * Remove all memories whose expiry timestamp has passed.
     *
     * @param  string|null  $project  Limit pruning to a specific project scope
     * @return int Number of deleted rows
     */
    public function pruneExpired(?string $project = null): int
    {
        $sql = 'DELETE FROM memories WHERE expires_at IS NOT NULL AND expires_at <= :now';
        $params = ['now' => date('c')];
        if ($project !== null) {
            $sql .= ' AND (project = :project OR project IS NULL)';
            $params['project'] = $project;
        }

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Keep only the most recent N compaction memories, deleting older ones.
     *
     * @param  string|null  $project  Limit to a specific project scope
     * @param  int  $keep  Number of compaction memories to retain
     * @return int Number of deleted rows
     */
    public function trimCompactionMemories(?string $project = null, int $keep = 10): int
    {
        // Fetch compaction memories ordered newest-first, then slice off the IDs to delete
        $sql = 'SELECT id FROM memories WHERE type = :type';
        $params = ['type' => 'compaction'];
        if ($project !== null) {
            $sql .= ' AND (project = :project OR project IS NULL)';
            $params['project'] = $project;
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC';

        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $ids = array_map(fn (array $row): int => (int) $row['id'], array_slice($rows, $keep));

        if ($ids === []) {
            return 0;
        }

        // Bulk-delete the overflow compaction memories
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $this->db->connection()->prepare("DELETE FROM memories WHERE id IN ({$placeholders})");
        $delete->execute($ids);

        return $delete->rowCount();
    }

    /**
     * List all non-expired memories, optionally scoped to a project.
     *
     * @param  string|null  $project  Project scope, or null for global-only
     * @param  int  $limit  Maximum number of results
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $project = null, int $limit = 50): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($project === null) {
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories
                 WHERE expires_at IS NULL OR expires_at > :now
                 ORDER BY pinned DESC, created_at DESC LIMIT :limit'
            );
            $stmt->execute(['now' => $now, 'limit' => $limit]);
        } else {
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories
                 WHERE (project = :project OR project IS NULL)
                   AND (expires_at IS NULL OR expires_at > :now)
                 ORDER BY pinned DESC, created_at DESC LIMIT :limit'
            );
            $stmt->execute(['project' => $project, 'now' => $now, 'limit' => $limit]);
        }

        return $stmt->fetchAll();
    }
}
