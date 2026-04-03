<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

/**
 * Contract for scoped key-value settings persistence.
 *
 * Defines the public API for getting, setting, deleting, and resolving
 * settings with project-level and global scope support.
 */
interface SettingsRepositoryInterface
{
    /**
     * @param  string  $scope  Settings scope (e.g. a project hash or "global")
     * @param  string  $key  Setting key to look up
     * @return string|null The stored value, or null if not found
     */
    public function get(string $scope, string $key): ?string;

    /**
     * @param  string  $scope  Settings scope to write to
     * @param  string  $key  Setting key
     * @param  string  $value  Setting value to persist (upserts on conflict)
     */
    public function set(string $scope, string $key, string $value): void;

    /**
     * @param  string  $scope  Settings scope to list keys for
     * @return array<string, string> Map of key => value pairs within the given scope
     */
    public function all(string $scope): array;

    /**
     * @param  string  $scope  Settings scope containing the key
     * @param  string  $key  Setting key to remove
     */
    public function delete(string $scope, string $key): void;

    /**
     * Resolve a setting: project scope first, then global fallback.
     *
     * @param  string  $key  Setting key to resolve
     * @param  string  $projectScope  Project-specific scope to check first
     * @return string|null The resolved value, or null if not found in either scope
     */
    public function resolve(string $key, string $projectScope): ?string;
}
