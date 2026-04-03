<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Kosmokrator\Agent\CompactionPlan;
use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\Agent\MemorySelector;
use Kosmokrator\Agent\ToolResultDeduplicator;
use Kosmokrator\Settings\SettingsManager;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Psr\Log\LoggerInterface;

class SessionManager
{
    private ?string $currentSessionId = null;

    private ?string $project = null;

    private ?string $projectScope = null;

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly MessageRepository $messages,
        private readonly SettingsRepository $settings,
        private readonly MemoryRepository $memories,
        private readonly LoggerInterface $log,
        private readonly ?SettingsManager $configSettings = null,
    ) {}

    public function setProject(string $project): void
    {
        $this->project = $project;
        $this->projectScope = SettingsRepository::projectScope($project);
        $this->configSettings?->setProjectRoot($project);
    }

    public function getProject(): ?string
    {
        return $this->project;
    }

    public function getProjectScope(): ?string
    {
        return $this->projectScope;
    }

    public function createSession(string $model): string
    {
        $id = $this->sessions->create($this->project ?? getcwd(), $model);
        $this->currentSessionId = $id;
        $this->log->info('Session created', ['id' => $id]);

        return $id;
    }

    public function currentSessionId(): ?string
    {
        return $this->currentSessionId;
    }

    public function setCurrentSession(string $id): void
    {
        $this->currentSessionId = $id;
    }

    public function saveMessage(Message $message, int $tokensIn = 0, int $tokensOut = 0): void
    {
        if ($this->currentSessionId === null) {
            return;
        }

        [$role, $content, $toolCalls, $toolResults] = $this->decomposeMessage($message);

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

        $session = $this->sessions->find($this->currentSessionId);
        if ($session !== null && $session['title'] === null && $role === 'user' && $content !== null) {
            $title = mb_substr($content, 0, 80);
            $this->sessions->updateTitle($this->currentSessionId, $title);
        }
    }

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

    public function latestSession(): ?string
    {
        $session = $this->sessions->latest($this->project ?? getcwd());

        return $session ? $session['id'] : null;
    }

    public function findSession(string $idOrPrefix): ?array
    {
        $session = $this->sessions->find($idOrPrefix);

        return $session ?? $this->sessions->findByPrefix($idOrPrefix);
    }

    public function resumeSession(string $sessionId): ConversationHistory
    {
        $this->currentSessionId = $sessionId;
        $this->sessions->touch($sessionId);

        return $this->loadHistory($sessionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $limit = 20): array
    {
        return $this->sessions->listByProject($this->project ?? getcwd(), $limit);
    }

    public function getSetting(string $key): ?string
    {
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
     * @return array<int, array<string, mixed>>
     */
    public function getMemories(): array
    {
        $this->memories->pruneExpired($this->project);

        return $this->memories->forProject($this->project);
    }

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

    public function findMemory(int $id): ?array
    {
        return $this->memories->find($id);
    }

    public function updateMemory(
        int $id,
        string $content,
        ?string $title = null,
        ?string $memoryClass = null,
        ?bool $pinned = null,
        ?string $expiresAt = null,
    ): void {
        $this->memories->update($id, $content, $title, $memoryClass, $pinned, $expiresAt);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchMemories(?string $type = null, ?string $query = null, int $limit = 20, ?string $memoryClass = null): array
    {
        return $this->memories->search($this->project, $type, $query, $limit, $memoryClass);
    }

    public function deleteMemory(int $id): void
    {
        $this->memories->delete($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRelevantMemories(?string $query = null, int $limit = 6): array
    {
        return $this->selectRelevantMemories($query, $limit, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selectRelevantMemories(?string $query = null, int $limit = 6, bool $markSurfaced = true): array
    {
        $selector = new MemorySelector;
        $selected = $selector->select($this->getMemories(), $query, $limit);
        if ($markSurfaced) {
            $ids = array_map(fn (array $memory): int => (int) $memory['id'], $selected);
            $this->memories->touchSurfaced($ids);
        }

        return $selected;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchSessionHistory(string $query, int $limit = 5): array
    {
        if ($this->project === null || trim($query) === '') {
            return [];
        }

        return $this->messages->searchProjectHistory($this->project, $query, $this->currentSessionId, $limit);
    }

    public function consolidateMemories(): int
    {
        $removed = $this->memories->pruneExpired($this->project);
        $removed += $this->memories->trimCompactionMemories($this->project, 10);

        return $removed;
    }

    /**
     * @return array{tokens_in: int, tokens_out: int}
     */
    public function getSessionTokenTotals(): array
    {
        if ($this->currentSessionId === null) {
            return ['tokens_in' => 0, 'tokens_out' => 0];
        }

        return $this->messages->sumTokens($this->currentSessionId);
    }

    public function persistCompaction(string $summary, int $keepRecentTurns = 3): void
    {
        if ($summary === '' || $this->currentSessionId === null) {
            return;
        }

        $raw = $this->messages->loadRaw($this->currentSessionId);
        if ($raw === []) {
            return;
        }

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

    /**
     * @return array{string, ?string, ?array, ?array}
     */
    private function decomposeMessage(Message $message): array
    {
        return match (true) {
            $message instanceof UserMessage => ['user', $message->content, null, null],
            $message instanceof AssistantMessage => [
                'assistant',
                $message->content,
                $message->toolCalls !== [] ? $message->toolCalls : null,
                null,
            ],
            $message instanceof ToolResultMessage => ['tool_result', null, null, $message->toolResults],
            $message instanceof SystemMessage => ['system', $message->content, null, null],
            default => ['unknown', null, null, null],
        };
    }
}
