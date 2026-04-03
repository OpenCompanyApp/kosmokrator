<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

/**
 * Persists and retrieves scoped key-value settings from the SQLite database.
 *
 * Part of the Session subsystem — supports project-level and global settings
 * with a layered resolution strategy (project overrides global).
 */
class SettingsRepository
{
    public function __construct(private Database $db) {}

    /**
     * @param string $scope  Settings scope (e.g. a project hash or "global")
     * @param string $key    Setting key to look up
     * @return string|null   The stored value, or null if not found
     */
    public function get(string $scope, string $key): ?string
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT value FROM settings WHERE scope = :scope AND key = :key'
        );
        $stmt->execute(['scope' => $scope, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ? $row['value'] : null;
    }

    /**
     * @param string $scope  Settings scope to write to
     * @param string $key    Setting key
     * @param string $value  Setting value to persist (upserts on conflict)
     */
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
     * @param string $scope  Settings scope to list keys for
     * @return array<string, string>  Map of key => value pairs within the given scope
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

    /**
     * @param string $scope  Settings scope containing the key
     * @param string $key    Setting key to remove
     */
    public function delete(string $scope, string $key): void
    {
        $stmt = $this->db->connection()->prepare(
            'DELETE FROM settings WHERE scope = :scope AND key = :key'
        );
        $stmt->execute(['scope' => $scope, 'key' => $key]);
    }

    /**
     * Resolve a setting: project scope first, then global fallback.
     *
     * @param string $key           Setting key to resolve
     * @param string $projectScope  Project-specific scope to check first
     * @return string|null          The resolved value, or null if not found in either scope
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
     *
     * @param string $path  Absolute project directory path
     * @return string       SHA-256 hash used as the settings scope identifier
     */
    public static function projectScope(string $path): string
    {
        return hash('sha256', $path);
    }
}
