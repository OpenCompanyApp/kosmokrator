<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Kosmokrator\Agent\ConversationHistory;
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
    ) {
    }

    public function setProject(string $project): void
    {
        $this->project = $project;
        $this->projectScope = SettingsRepository::projectScope($project);
    }

    public function getProject(): ?string
    {
        return $this->project;
    }

    public function getProjectScope(): ?string
    {
        return $this->projectScope;
    }

    // --- Session lifecycle ---

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

        // Auto-title from first user message
        $session = $this->sessions->find($this->currentSessionId);
        if ($session !== null && $session['title'] === null && $role === 'user' && $content !== null) {
            $title = mb_substr($content, 0, 80);
            $this->sessions->updateTitle($this->currentSessionId, $title);
        }
    }

    public function loadHistory(string $sessionId): ConversationHistory
    {
        $messages = $this->messages->loadActive($sessionId);
        $history = new ConversationHistory();

        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        return $history;
    }

    public function latestSession(): ?string
    {
        $session = $this->sessions->latest($this->project ?? getcwd());

        return $session ? $session['id'] : null;
    }

    /**
     * Find a session by full or partial ID.
     */
    public function findSession(string $idOrPrefix): ?array
    {
        $session = $this->sessions->find($idOrPrefix);

        return $session ?? $this->sessions->findByPrefix($idOrPrefix);
    }

    /**
     * Resume a session: set it as current and load its history.
     */
    public function resumeSession(string $sessionId): ConversationHistory
    {
        $this->currentSessionId = $sessionId;
        $this->sessions->touch($sessionId);

        return $this->loadHistory($sessionId);
    }

    /**
     * @return array[]
     */
    public function listSessions(int $limit = 20): array
    {
        return $this->sessions->listByProject($this->project ?? getcwd(), $limit);
    }

    // --- Settings ---

    public function getSetting(string $key): ?string
    {
        if ($this->projectScope === null) {
            return $this->settings->get('global', $key);
        }

        return $this->settings->resolve($key, $this->projectScope);
    }

    public function setSetting(string $key, string $value, string $scope = 'project'): void
    {
        $resolvedScope = $scope === 'project' && $this->projectScope !== null
            ? $this->projectScope
            : 'global';

        $this->settings->set($resolvedScope, $key, $value);
    }

    // --- Memories ---

    /**
     * @return array[]
     */
    public function getMemories(): array
    {
        return $this->memories->forProject($this->project);
    }

    public function addMemory(string $type, string $title, string $content): int
    {
        return $this->memories->add(
            type: $type,
            title: $title,
            content: $content,
            project: $this->project,
            sessionId: $this->currentSessionId,
        );
    }

    public function findMemory(int $id): ?array
    {
        return $this->memories->find($id);
    }

    public function updateMemory(int $id, string $content, ?string $title = null): void
    {
        $this->memories->update($id, $content, $title);
    }

    /**
     * @return array[]
     */
    public function searchMemories(?string $type = null, ?string $query = null, int $limit = 20): array
    {
        return $this->memories->search($this->project, $type, $query, $limit);
    }

    public function deleteMemory(int $id): void
    {
        $this->memories->delete($id);
    }

    // --- Token totals ---

    /**
     * Get cumulative token usage for the current session.
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

    // --- Compaction ---

    /**
     * Persist compaction: mark old messages as compacted, insert summary.
     */
    public function persistCompaction(string $summary, int $keepRecentTurns = 3): void
    {
        if ($this->currentSessionId === null) {
            return;
        }

        $raw = $this->messages->loadRaw($this->currentSessionId);
        if (count($raw) === 0) {
            return;
        }

        // Find the boundary: count keepRecentTurns user messages from the end
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

        $this->messages->markCompacted($this->currentSessionId, $boundaryId);

        // Insert summary as a system message
        $this->messages->append(
            sessionId: $this->currentSessionId,
            role: 'system',
            content: $summary,
        );

        $this->log->info('Compaction persisted', [
            'session' => $this->currentSessionId,
            'boundary_id' => $boundaryId,
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
