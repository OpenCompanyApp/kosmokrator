<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

class SessionRepository
{
    public function __construct(private Database $db)
    {
    }

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

    public function find(string $id): ?array
    {
        $stmt = $this->db->connection()->prepare('SELECT * FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateTitle(string $id, string $title): void
    {
        $stmt = $this->db->connection()->prepare(
            'UPDATE sessions SET title = :title, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'title' => $title, 'now' => $this->now()]);
    }

    public function touch(string $id): void
    {
        $stmt = $this->db->connection()->prepare(
            'UPDATE sessions SET updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'now' => $this->now()]);
    }

    /**
     * @return array[]
     */
    public function listByProject(string $project, int $limit = 20): array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT * FROM sessions WHERE project = :project ORDER BY updated_at DESC LIMIT :limit'
        );
        $stmt->execute(['project' => $project, 'limit' => $limit]);

        return $stmt->fetchAll();
    }

    public function latest(string $project): ?array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT * FROM sessions WHERE project = :project ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute(['project' => $project]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function now(): string
    {
        return number_format(microtime(true), 6, '.', '');
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
