<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

class MemoryRepository
{
    public function __construct(private Database $db) {}

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

    public function find(int $id): ?array
    {
        $stmt = $this->db->connection()->prepare('SELECT * FROM memories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(
        int $id,
        string $content,
        ?string $title = null,
        ?string $memoryClass = null,
        ?bool $pinned = null,
        ?string $expiresAt = null,
    ): void {
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
     * @return array<int, array<string, mixed>>
     */
    public function search(?string $project, ?string $type = null, ?string $query = null, int $limit = 20, ?string $memoryClass = null): array
    {
        $sql = 'SELECT * FROM memories WHERE 1=1';
        $params = ['now' => date('c')];

        if ($project !== null) {
            $sql .= ' AND (project = :project OR project IS NULL)';
            $params['project'] = $project;
        } else {
            $sql .= ' AND project IS NULL';
        }

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

    public function delete(int $id): void
    {
        $stmt = $this->db->connection()->prepare('DELETE FROM memories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param  int[]  $ids
     */
    public function touchSurfaced(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->connection()->prepare(
            "UPDATE memories SET last_surfaced_at = ? WHERE id IN ({$placeholders})"
        );
        $stmt->execute([date('c'), ...$ids]);
    }

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

    public function trimCompactionMemories(?string $project = null, int $keep = 10): int
    {
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

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $this->db->connection()->prepare("DELETE FROM memories WHERE id IN ({$placeholders})");
        $delete->execute($ids);

        return $delete->rowCount();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $project = null, int $limit = 50): array
    {
        $now = date('c');
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
