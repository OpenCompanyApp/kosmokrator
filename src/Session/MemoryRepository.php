<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

class MemoryRepository
{
    public function __construct(private Database $db)
    {
    }

    public function add(
        string $type,
        string $title,
        string $content,
        ?string $project = null,
        ?string $sessionId = null,
    ): int {
        $now = date('c');
        $stmt = $this->db->connection()->prepare(
            'INSERT INTO memories (project, session_id, type, title, content, created_at, updated_at)
             VALUES (:project, :session_id, :type, :title, :content, :now, :now)'
        );
        $stmt->execute([
            'project' => $project,
            'session_id' => $sessionId,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'now' => $now,
        ]);

        return (int) $this->db->connection()->lastInsertId();
    }

    /**
     * Get memories for a project (includes global memories where project IS NULL).
     *
     * @return array[]
     */
    public function forProject(?string $project): array
    {
        if ($project === null) {
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories WHERE project IS NULL ORDER BY created_at DESC'
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories WHERE project = :project OR project IS NULL ORDER BY type, created_at DESC'
            );
            $stmt->execute(['project' => $project]);
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

    public function update(int $id, string $content, ?string $title = null): void
    {
        if ($title !== null) {
            $stmt = $this->db->connection()->prepare(
                'UPDATE memories SET title = :title, content = :content, updated_at = :now WHERE id = :id'
            );
            $stmt->execute(['id' => $id, 'title' => $title, 'content' => $content, 'now' => date('c')]);
        } else {
            $stmt = $this->db->connection()->prepare(
                'UPDATE memories SET content = :content, updated_at = :now WHERE id = :id'
            );
            $stmt->execute(['id' => $id, 'content' => $content, 'now' => date('c')]);
        }
    }

    /**
     * Search memories with optional type and text filters.
     *
     * @return array[]
     */
    public function search(?string $project, ?string $type = null, ?string $query = null, int $limit = 20): array
    {
        $sql = 'SELECT * FROM memories WHERE 1=1';
        $params = [];

        if ($project !== null) {
            $sql .= ' AND (project = :project OR project IS NULL)';
            $params['project'] = $project;
        } else {
            $sql .= ' AND project IS NULL';
        }

        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }

        if ($query !== null && $query !== '') {
            $sql .= ' AND (title LIKE :query OR content LIKE :query)';
            $params['query'] = "%{$query}%";
        }

        $sql .= ' ORDER BY type, created_at DESC LIMIT :limit';
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
     * @return array[]
     */
    public function all(?string $project = null, int $limit = 50): array
    {
        if ($project === null) {
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories ORDER BY created_at DESC LIMIT :limit'
            );
            $stmt->execute(['limit' => $limit]);
        } else {
            $stmt = $this->db->connection()->prepare(
                'SELECT * FROM memories WHERE project = :project OR project IS NULL ORDER BY created_at DESC LIMIT :limit'
            );
            $stmt->execute(['project' => $project, 'limit' => $limit]);
        }

        return $stmt->fetchAll();
    }
}
