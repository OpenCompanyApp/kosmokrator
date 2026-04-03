<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

/**
 * Contract for session persistence operations.
 *
 * Defines the public API for creating, finding, listing, and updating
 * conversation sessions in the backing store.
 */
interface SessionRepositoryInterface
{
    /**
     * @param  string  $project  Project root path identifying the workspace
     * @param  string  $model  LLM model identifier to associate with the session
     * @return string The newly generated session UUID
     */
    public function create(string $project, string $model): string;

    /**
     * @param  string  $id  Full session UUID
     * @return array|null Session row as associative array, or null if not found
     */
    public function find(string $id): ?array;

    /**
     * Find a session by ID prefix (for short-ID input).
     */
    public function findByPrefix(string $prefix): ?array;

    /**
     * @param  string  $id  Session UUID
     * @param  string  $title  Human-readable title to store
     */
    public function updateTitle(string $id, string $title): void;

    /** Bump the updated_at timestamp to mark the session as recently active. */
    public function touch(string $id): void;

    /**
     * @param  string  $project  Project root path to filter by
     * @param  int  $limit  Maximum number of sessions to return
     * @return array[] List of session rows with aggregated message_count and last_user_message
     */
    public function listByProject(string $project, int $limit = 20): array;

    /**
     * @param  string  $project  Project root path to filter by
     * @return array|null Most recently updated session row, or null if none exist
     */
    public function latest(string $project): ?array;
}
