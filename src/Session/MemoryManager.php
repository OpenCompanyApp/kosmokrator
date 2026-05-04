<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Kosmokrator\Agent\MemorySelector;
use Psr\Log\LoggerInterface;

/**
 * Facade for all memory operations: CRUD, relevance selection, and consolidation.
 *
 * Delegates to MemoryRepository for persistence and MemorySelector for
 * context-aware ranking. Extracted from SessionManager to separate memory
 * concerns from session lifecycle management.
 */
class MemoryManager
{
    private ?string $project = null;

    private ?string $currentSessionId = null;

    public function __construct(
        private readonly MemoryRepositoryInterface $memories,
        private readonly ?MemorySelector $selector = null,
        private readonly ?LoggerInterface $log = null,
    ) {}

    /**
     * Set the active project scope for memory operations.
     */
    public function setProject(?string $project): void
    {
        $this->project = $project;
    }

    /**
     * Get the active project scope.
     */
    public function getProject(): ?string
    {
        return $this->project;
    }

    /**
     * Set the current session ID (used when adding memories).
     */
    public function setCurrentSessionId(?string $sessionId): void
    {
        $this->currentSessionId = $sessionId;
    }

    /**
     * Retrieve all active memories for the current project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMemories(): array
    {
        return $this->memories->forProject($this->project);
    }

    /**
     * Store a new memory entry.
     *
     * @param  string  $type  Memory type (project, user, decision, compaction)
     * @param  string  $title  Short descriptive title
     * @param  string  $content  Full memory content
     * @param  string  $memoryClass  Retention class ('durable', 'working', 'priority')
     * @param  bool  $pinned  Whether the memory is pinned for priority recall
     * @param  string|null  $expiresAt  ISO timestamp for expiration, or null for no expiry
     * @return int The newly created memory ID
     */
    public function addMemory(
        string $type,
        string $title,
        string $content,
        string $memoryClass = 'durable',
        bool $pinned = false,
        ?string $expiresAt = null,
    ): int {
        return $this->memories->add(
            type: $type,
            title: $title,
            content: $content,
            project: $this->project,
            sessionId: $this->currentSessionId,
            memoryClass: $memoryClass,
            pinned: $pinned,
            expiresAt: $expiresAt,
        );
    }

    /**
     * Look up a single memory by ID.
     *
     * @param  int  $id  Memory ID
     * @return array<string, mixed>|null Memory data or null if not found
     */
    public function findMemory(int $id): ?array
    {
        return $this->memories->find($id);
    }

    /**
     * Update an existing memory's content and optional metadata.
     *
     * @param  int  $id  Memory ID to update
     * @param  string  $content  New memory content
     * @param  string|null  $title  Updated title, or null to keep existing
     * @param  string|null  $memoryClass  Updated retention class, or null to keep existing
     * @param  bool|null  $pinned  Updated pinned flag, or null to keep existing
     * @param  string|null  $expiresAt  Updated expiration, or null to keep existing
     * @param  bool  $clearExpiresAt  Clear the existing expiration
     */
    public function updateMemory(
        int $id,
        string $content,
        ?string $title = null,
        ?string $memoryClass = null,
        ?bool $pinned = null,
        ?string $expiresAt = null,
        bool $clearExpiresAt = false,
    ): void {
        $this->memories->update($id, $content, $title, $memoryClass, $pinned, $expiresAt, $clearExpiresAt);
    }

    /**
     * Search memories by type, query text, and/or class.
     *
     * @param  string|null  $type  Filter by memory type
     * @param  string|null  $query  Search text for title/content matching
     * @param  int  $limit  Maximum results to return
     * @param  string|null  $memoryClass  Filter by retention class
     * @return array<int, array<string, mixed>>
     */
    public function searchMemories(?string $type = null, ?string $query = null, int $limit = 20, ?string $memoryClass = null): array
    {
        return $this->memories->search($this->project, $type, $query, $limit, $memoryClass);
    }

    /**
     * Delete a memory entry by ID.
     *
     * @param  int  $id  Memory ID to delete
     */
    public function deleteMemory(int $id): void
    {
        $this->memories->delete($id);
    }

    /**
     * Select contextually relevant memories and mark them as surfaced.
     *
     * @param  string|null  $query  Query text for relevance scoring
     * @param  int  $limit  Maximum memories to return
     * @return array<int, array<string, mixed>>
     */
    public function getRelevantMemories(?string $query = null, int $limit = 6): array
    {
        return $this->selectRelevantMemories($query, $limit, true);
    }

    /**
     * Use the MemorySelector to pick the most relevant memories for the current context.
     *
     * @param  string|null  $query  Query text for relevance scoring
     * @param  int  $limit  Maximum memories to return
     * @param  bool  $markSurfaced  Whether to update the surfaced_at timestamp on selected memories
     * @return array<int, array<string, mixed>>
     */
    public function selectRelevantMemories(?string $query = null, int $limit = 6, bool $markSurfaced = true): array
    {
        $selector = $this->selector ?? new MemorySelector;
        $selected = $selector->select($this->getMemories(), $query, $limit);
        if ($markSurfaced) {
            $ids = array_map(fn (array $memory): int => (int) $memory['id'], $selected);
            $this->memories->touchSurfaced($ids);
        }

        return $selected;
    }

    /**
     * Consolidation
     */
    public function consolidateMemories(): int
    {
        $removed = $this->memories->pruneExpired($this->project);
        $removed += $this->memories->trimCompactionMemories($this->project, 10);

        return $removed;
    }

    /**
     * Find an existing memory with identical content (and optionally title) in the current scope.
     *
     * @return array<string, mixed>|null The matching memory, or null if no duplicate
     */
    public function findDuplicate(string $content, ?string $title = null): ?array
    {
        return $this->memories->findDuplicate($content, $this->project, $title);
    }
}
