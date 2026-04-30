<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

/**
 * Contract for memory persistence operations.
 *
 * Defines the public API for adding, finding, updating, searching,
 * deleting, and maintaining agent memories.
 */
interface MemoryRepositoryInterface
{
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
    ): int;

    /**
     * Load all non-expired memories visible to a project (includes global/project-null memories).
     *
     * @param  string|null  $project  Project scope, or null for global-only
     * @return array<int, array<string, mixed>>
     */
    public function forProject(?string $project): array;

    /**
     * Find a single memory by its ID.
     *
     * @param  int  $id  The memory record ID
     * @return array<string, mixed>|null The memory row, or null if not found
     */
    public function find(int $id): ?array;

    /**
     * Update an existing memory's content and optionally its metadata.
     *
     * @param  int  $id  The memory record ID
     * @param  string  $content  Updated memory content
     * @param  string|null  $title  New title, or null to keep existing
     * @param  string|null  $memoryClass  New retention class, or null to keep existing
     * @param  bool|null  $pinned  New pinned status, or null to keep existing
     * @param  string|null  $expiresAt  New expiry timestamp, or null to keep existing
     * @param  bool  $clearExpiresAt  Clear the existing expiry timestamp
     */
    public function update(
        int $id,
        string $content,
        ?string $title = null,
        ?string $memoryClass = null,
        ?bool $pinned = null,
        ?string $expiresAt = null,
        bool $clearExpiresAt = false,
    ): void;

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
    public function search(?string $project, ?string $type = null, ?string $query = null, int $limit = 20, ?string $memoryClass = null): array;

    /**
     * Permanently delete a memory by ID.
     *
     * @param  int  $id  The memory record ID
     */
    public function delete(int $id): void;

    /**
     * Update the last_surfaced_at timestamp for memories that were recently recalled.
     *
     * @param  int[]  $ids  Memory IDs to mark as surfaced
     */
    public function touchSurfaced(array $ids): void;

    /**
     * Remove all memories whose expiry timestamp has passed.
     *
     * @param  string|null  $project  Limit pruning to a specific project scope
     * @return int Number of deleted rows
     */
    public function pruneExpired(?string $project = null): int;

    /**
     * Keep only the most recent N compaction memories, deleting older ones.
     *
     * @param  string|null  $project  Limit to a specific project scope
     * @param  int  $keep  Number of compaction memories to retain
     * @return int Number of deleted rows
     */
    public function trimCompactionMemories(?string $project = null, int $keep = 10): int;

    /**
     * List all non-expired memories, optionally scoped to a project.
     *
     * @param  string|null  $project  Project scope, or null for global-only
     * @param  int  $limit  Maximum number of results
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $project = null, int $limit = 50): array;

    /**
     * Check whether a memory with identical content already exists in the given scope.
     *
     * Compares content (and optionally title) against non-expired memories
     * in the same project scope, including global memories.
     *
     * @param  string  $content  The memory content to check
     * @param  string|null  $project  Project scope, or null for global-only
     * @param  string|null  $title  Optional title to include in the match
     * @return array<string, mixed>|null The matching memory row, or null if no duplicate
     */
    public function findDuplicate(string $content, ?string $project = null, ?string $title = null): ?array;
}
