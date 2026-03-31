<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

class SettingsRepository
{
    public function __construct(private Database $db) {}

    public function get(string $scope, string $key): ?string
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT value FROM settings WHERE scope = :scope AND key = :key'
        );
        $stmt->execute(['scope' => $scope, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ? $row['value'] : null;
    }

    public function set(string $scope, string $key, string $value): void
    {
        $stmt = $this->db->connection()->prepare(
            'INSERT INTO settings (scope, key, value, updated_at) VALUES (:scope, :key, :value, :now)
             ON CONFLICT(scope, key) DO UPDATE SET value = :value, updated_at = :now'
        );
        $stmt->execute([
            'scope' => $scope,
            'key' => $key,
            'value' => $value,
            'now' => date('c'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function all(string $scope): array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT key, value FROM settings WHERE scope = :scope'
        );
        $stmt->execute(['scope' => $scope]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }

    public function delete(string $scope, string $key): void
    {
        $stmt = $this->db->connection()->prepare(
            'DELETE FROM settings WHERE scope = :scope AND key = :key'
        );
        $stmt->execute(['scope' => $scope, 'key' => $key]);
    }

    /**
     * Resolve a setting: project scope first, then global fallback.
     */
    public function resolve(string $key, string $projectScope): ?string
    {
        $value = $this->get($projectScope, $key);
        if ($value !== null) {
            return $value;
        }

        return $this->get('global', $key);
    }

    /**
     * Get the project scope key for a given path.
     */
    public static function projectScope(string $path): string
    {
        return hash('sha256', $path);
    }
}
