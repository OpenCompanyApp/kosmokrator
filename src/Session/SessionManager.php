<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Kosmokrator\Agent\CompactionPlan;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\Agent\ToolResultDeduplicator;
use Kosmokrator\LLM\MessageSerializer;
use Kosmokrator\Settings\SettingsManager;
use Prism\Prism\Contracts\Message;
use Psr\Log\LoggerInterface;

/**
 * Central facade for session, message, setting, and memory operations.
 *
 * Coordinates the repositories that back a single conversation session,
 * including history persistence, compaction, and project-scoped settings.
 * Memory operations are delegated to MemoryManager.
 */
class SessionManager
{
    private ?string $currentSessionId = null;

    private ?string $project = null;

    private ?string $projectScope = null;

    private readonly MessageSerializer $serializer;

    private readonly MemoryManager $memoryManager;

    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly MessageRepositoryInterface $messages,
        private readonly SettingsRepositoryInterface $settings,
        private readonly MemoryRepositoryInterface $memories,
        private readonly LoggerInterface $log,
        private readonly ?SettingsManager $configSettings = null,
    ) {
        $this->serializer = new MessageSerializer;
        $this->memoryManager = new MemoryManager($this->memories, log: $this->log);
    }

    /**
     * Set the active project directory and derive its settings scope.
     *
     * @param  string  $project  Absolute path to the project root
     */
    public function setProject(string $project): void
    {
        $this->project = $project;
        $this->projectScope = SettingsRepository::projectScope($project);
        $this->configSettings?->setProjectRoot($project);
        $this->memoryManager->setProject($project);
    }

    /**
     * Get the active project directory path.
     */
    public function getProject(): ?string
    {
        return $this->project;
    }

    /**
     * Get the derived settings scope key for the current project.
     */
    public function getProjectScope(): ?string
    {
        return $this->projectScope;
    }

    /**
     * Create a new session for the current project and model.
     *
     * @param  string  $model  LLM model identifier to associate with the session
     * @return string The newly created session ID
     */
    public function createSession(string $model): string
    {
        $id = $this->sessions->create($this->project ?? getcwd(), $model);
        $this->currentSessionId = $id;
        $this->memoryManager->setCurrentSessionId($id);
        $this->log->info('Session created', ['id' => $id]);

        return $id;
    }

    /**
     * Get the ID of the currently active session.
     */
    public function currentSessionId(): ?string
    {
        return $this->currentSessionId;
    }

    /**
     * Switch the active session to an existing one.
     *
     * @param  string  $id  Session ID to activate
     */
    public function setCurrentSession(string $id): void
    {
        $this->currentSessionId = $id;
        $this->memoryManager->setCurrentSessionId($id);
    }

    /**
     * Persist a conversation message and update session metadata.
     *
     * Automatically sets the session title from the first user message.
     *
     * @param  Message  $message  Prism message to persist
     * @param  int  $tokensIn  Input tokens consumed by this message
     * @param  int  $tokensOut  Output tokens generated for this message
     */
    public function saveMessage(Message $message, int $tokensIn = 0, int $tokensOut = 0): void
    {
        if ($this->currentSessionId === null) {
            return;
        }

        $decomposed = $this->serializer->decompose($message);
        $role = $decomposed['role'];
        $content = $decomposed['content'];
        $toolCalls = $decomposed['toolCalls'];
        $toolResults = $decomposed['toolResults'];

        $this->messages->append(
            sessionId: $this->currentSessionId,
            role: $role,
            content: $content,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
        );

        $this->sessions->touch($this->currentSessionId);

        // Auto-title: use the first user message as the session title
        $session = $this->sessions->find($this->currentSessionId);
        if ($session !== null && $session['title'] === null && $role === 'user' && $content !== null) {
            $title = mb_substr($content, 0, 80);
            $this->sessions->updateTitle($this->currentSessionId, $title);
        }
    }

    /**
     * Rename the current session for easy identification later.
     *
     * @param  string  $title  New title for the session
     */
    public function renameSession(string $title): void
    {
        if ($this->currentSessionId === null) {
            return;
        }

        $this->sessions->updateTitle($this->currentSessionId, $title);
    }

    /**
     * Reconstruct the conversation history for a session, with deduplication.
     *
     * @param  string  $sessionId  Session ID to load history from
     * @return ConversationHistory Rebuilt history ready for the agent
     */
    public function loadHistory(string $sessionId): ConversationHistory
    {
        $messages = $this->messages->loadActive($sessionId);
        $history = new ConversationHistory;

        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        (new ToolResultDeduplicator)->deduplicate($history);

        return $history;
    }

    /**
     * Find the most recent session ID for the current project.
     */
    public function latestSession(): ?string
    {
        $session = $this->sessions->latest($this->project ?? getcwd());

        return $session ? $session['id'] : null;
    }

    /**
     * Look up a session by exact ID or unique prefix.
     *
     * @param  string  $idOrPrefix  Full session ID or a unique prefix
     * @return array<string, mixed>|null Session data or null if not found
     */
    public function findSession(string $idOrPrefix): ?array
    {
        $session = $this->sessions->find($idOrPrefix);

        return $session ?? $this->sessions->findByPrefix($idOrPrefix);
    }

    /**
     * Reactivate an existing session and reload its conversation history.
     *
     * @param  string  $sessionId  Session ID to resume
     * @return ConversationHistory The restored conversation history
     */
    public function resumeSession(string $sessionId): ConversationHistory
    {
        $this->currentSessionId = $sessionId;
        $this->memoryManager->setCurrentSessionId($sessionId);
        $this->sessions->touch($sessionId);

        return $this->loadHistory($sessionId);
    }

    /**
     * List recent sessions for the current project.
     *
     * @param  int  $limit  Maximum number of sessions to return
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $limit = 20): array
    {
        return $this->sessions->listByProject($this->project ?? getcwd(), $limit);
    }

    /**
     * Resolve a setting value, checking config file then project/global DB scopes.
     *
     * @param  string  $key  Dot-notation setting key
     */
    public function getSetting(string $key): ?string
    {
        // Config file settings take priority over DB-stored settings
        if ($this->configSettings !== null) {
            $value = $this->configSettings->get($key);
            if ($value !== null) {
                return $value;
            }
        }

        if ($this->projectScope === null) {
            return $this->settings->get('global', $key);
        }

        return $this->settings->resolve($key, $this->projectScope);
    }

    /**
     * Store a setting value in the given scope.
     *
     * @param  string  $key  Dot-notation setting key
     * @param  string  $value  Setting value to store
     * @param  string  $scope  Either 'project' or 'global'
     */
    public function setSetting(string $key, string $value, string $scope = 'project'): void
    {
        if ($this->configSettings !== null) {
            $this->configSettings->set($key, $value, $scope);

            return;
        }

        $resolvedScope = $scope === 'project' && $this->projectScope !== null
            ? $this->projectScope
            : 'global';

        $this->settings->set($resolvedScope, $key, $value);
    }

    /**
     * Get the MemoryManager instance for direct access when needed.
     */
    public function memoryManager(): MemoryManager
    {
        return $this->memoryManager;
    }

    /**
     * Retrieve all active memories for the current project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMemories(): array
    {
        return $this->memoryManager->getMemories();
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
        return $this->memoryManager->addMemory($type, $title, $content, $memoryClass, $pinned, $expiresAt);
    }

    /**
     * Look up a single memory by ID.
     *
     * @param  int  $id  Memory ID
     * @return array<string, mixed>|null Memory data or null if not found
     */
    public function findMemory(int $id): ?array
    {
        return $this->memoryManager->findMemory($id);
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
     */
    public function updateMemory(
        int $id,
        string $content,
        ?string $title = null,
        ?string $memoryClass = null,
        ?bool $pinned = null,
        ?string $expiresAt = null,
    ): void {
        $this->memoryManager->updateMemory($id, $content, $title, $memoryClass, $pinned, $expiresAt);
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
        return $this->memoryManager->searchMemories($type, $query, $limit, $memoryClass);
    }

    /**
     * Delete a memory entry by ID.
     *
     * @param  int  $id  Memory ID to delete
     */
    public function deleteMemory(int $id): void
    {
        $this->memoryManager->deleteMemory($id);
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
        return $this->memoryManager->getRelevantMemories($query, $limit);
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
        return $this->memoryManager->selectRelevantMemories($query, $limit, $markSurfaced);
    }

    /**
     * Full-text search across all session messages in the current project.
     *
     * @param  string  $query  Search terms
     * @param  int  $limit  Maximum results to return
     * @return array<int, array<string, mixed>>
     */
    public function searchSessionHistory(string $query, int $limit = 5): array
    {
        if ($this->project === null || trim($query) === '') {
            return [];
        }

        return $this->messages->searchProjectHistory($this->project, $query, $this->currentSessionId, $limit);
    }

    /**
     * Remove expired and excess compaction memories for the current project.
     *
     * @return int Number of memories removed
     */
    public function consolidateMemories(): int
    {
        return $this->memoryManager->consolidateMemories();
    }

    /**
     * Sum token usage for the current session.
     *
     * @return array{tokens_in: int, tokens_out: int}
     */
    public function getSessionTokenTotals(): array
    {
        if ($this->currentSessionId === null) {
            return ['tokens_in' => 0, 'tokens_out' => 0];
        }

        return $this->messages->sumTokens($this->currentSessionId);
    }

    /**
     * Delete a session and all its messages by ID.
     *
     * If the deleted session is the current one, the current session ID is cleared.
     *
     * @param  string  $id  Full session UUID or unique prefix
     * @return bool True if the session was found and deleted
     */
    public function deleteSession(string $id): bool
    {
        $session = $this->sessions->find($id) ?? $this->sessions->findByPrefix($id);
        if ($session === null) {
            return false;
        }

        $this->sessions->delete($session['id']);

        if ($this->currentSessionId === $session['id']) {
            $this->currentSessionId = null;
            $this->memoryManager->setCurrentSessionId(null);
        }

        $this->log->info('Session deleted', ['id' => $session['id']]);

        return true;
    }

    /**
     * Delete old sessions to prevent unbounded database growth.
     *
     * @param  int  $olderThanDays  Delete sessions not updated in this many days
     * @param  int  $keepPerProject  Always keep at least this many sessions per project
     * @return int Number of sessions deleted
     */
    public function cleanupOldSessions(int $olderThanDays = 30, int $keepPerProject = 5): int
    {
        $count = $this->sessions->cleanup($olderThanDays, $keepPerProject);
        $this->log->info('Session cleanup completed', ['deleted' => $count]);

        return $count;
    }

    /**
     * Compact older messages into a summary, keeping the most recent user turns intact.
     *
     * Walks backward through raw messages to find the boundary after N user turns,
     * then replaces everything before that boundary with the summary.
     *
     * @param  string  $summary  Compacted summary of older messages
     * @param  int  $keepRecentTurns  Number of recent user turns to preserve
     */
    public function persistCompaction(string $summary, int $keepRecentTurns = 3): void
    {
        if ($summary === '' || $this->currentSessionId === null) {
            return;
        }

        $raw = $this->messages->loadRaw($this->currentSessionId);
        if ($raw === []) {
            return;
        }

        // Walk backward to find the compaction boundary after N user turns
        $turnsFound = 0;
        $boundaryId = null;
        for ($i = count($raw) - 1; $i >= 0; $i--) {
            if ($raw[$i]['role'] === 'user') {
                $turnsFound++;
                if ($turnsFound >= $keepRecentTurns) {
                    $boundaryId = (int) $raw[$i]['id'];
                    break;
                }
            }
        }

        if ($boundaryId === null) {
            return;
        }

        // Collect all message IDs before the boundary and compact them
        $ids = array_map(
            fn (array $row): int => (int) $row['id'],
            array_values(array_filter($raw, fn (array $row): bool => (int) $row['id'] < $boundaryId))
        );
        $this->messages->compactWithSummary($this->currentSessionId, $ids, $summary);

        $this->log->info('Compaction persisted', [
            'session' => $this->currentSessionId,
            'boundary_id' => $boundaryId,
        ]);
    }

    /**
     * Apply a structured compaction plan that specifies how many messages to compact.
     *
     * @param  CompactionPlan  $plan  Plan containing the summary and message count to compact
     */
    public function persistCompactionPlan(CompactionPlan $plan): void
    {
        if ($this->currentSessionId === null || $plan->isEmpty()) {
            return;
        }

        $raw = $this->messages->loadRaw($this->currentSessionId);
        if ($raw === []) {
            return;
        }

        $rowsToCompact = array_slice($raw, 0, $plan->compactedMessageCount);
        $ids = array_map(fn (array $row): int => (int) $row['id'], $rowsToCompact);
        $this->messages->compactWithSummary($this->currentSessionId, $ids, $plan->summary);

        $this->log->info('Compaction plan persisted', [
            'session' => $this->currentSessionId,
            'compacted_messages' => count($ids),
            'summary_length' => strlen($plan->summary),
        ]);
    }
}
