<?php

declare(strict_types=1);

namespace Kosmokrator\Goal;

use Kosmokrator\Session\Database;

final class GoalRepository
{
    public const MAX_OBJECTIVE_CHARS = 4000;

    public function __construct(
        private readonly Database $db,
    ) {}

    public function get(string $sessionId): ?Goal
    {
        $stmt = $this->db->connection()->prepare('SELECT * FROM session_goals WHERE session_id = :session_id');
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch();

        return $row ? $this->fromRow($row) : null;
    }

    public function replace(string $sessionId, string $objective, GoalStatus $status = GoalStatus::Active, ?int $tokenBudget = null): Goal
    {
        $objective = self::validateObjective($objective);
        self::validateBudget($tokenBudget);
        $goalId = $this->uuid();
        $now = $this->now();
        $status = $this->statusAfterBudget($status, 0, $tokenBudget);

        $stmt = $this->db->connection()->prepare(
            'INSERT INTO session_goals (
                session_id, goal_id, objective, status, token_budget,
                tokens_used, time_used_seconds, created_at, updated_at
             ) VALUES (
                :session_id, :goal_id, :objective, :status, :token_budget,
                0, 0, :now, :now
             )
             ON CONFLICT(session_id) DO UPDATE SET
                goal_id = excluded.goal_id,
                objective = excluded.objective,
                status = excluded.status,
                token_budget = excluded.token_budget,
                tokens_used = 0,
                time_used_seconds = 0,
                created_at = excluded.created_at,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'goal_id' => $goalId,
            'objective' => $objective,
            'status' => $status->value,
            'token_budget' => $tokenBudget,
            'now' => $now,
        ]);

        return $this->get($sessionId) ?? throw new \RuntimeException('Failed to persist goal.');
    }

    public function create(string $sessionId, string $objective, ?int $tokenBudget = null): Goal
    {
        if ($this->get($sessionId) !== null) {
            throw new \RuntimeException('cannot create a new goal because this session already has a goal; use update_goal only when the existing goal is complete');
        }

        return $this->replace($sessionId, $objective, GoalStatus::Active, $tokenBudget);
    }

    public function update(string $sessionId, ?GoalStatus $status = null, ?int $tokenBudget = null, bool $changeBudget = false): ?Goal
    {
        if ($status === null && ! $changeBudget) {
            return $this->get($sessionId);
        }

        self::validateBudget($tokenBudget);
        $goal = $this->get($sessionId);
        if ($goal === null) {
            return null;
        }

        $nextStatus = $status ?? $goal->status;
        $nextBudget = $changeBudget ? $tokenBudget : $goal->tokenBudget;
        if ($goal->status === GoalStatus::Complete && $nextStatus !== GoalStatus::Complete) {
            $nextStatus = GoalStatus::Complete;
        }
        $nextStatus = $this->statusAfterBudget($nextStatus, $goal->tokensUsed, $nextBudget);
        if ($goal->status === GoalStatus::BudgetLimited && $status === GoalStatus::Paused) {
            $nextStatus = GoalStatus::BudgetLimited;
        }

        $stmt = $this->db->connection()->prepare(
            'UPDATE session_goals
             SET status = :status, token_budget = :token_budget, updated_at = :updated_at
             WHERE session_id = :session_id'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'status' => $nextStatus->value,
            'token_budget' => $nextBudget,
            'updated_at' => $this->now(),
        ]);

        return $this->get($sessionId);
    }

    public function clear(string $sessionId): bool
    {
        $stmt = $this->db->connection()->prepare('DELETE FROM session_goals WHERE session_id = :session_id');
        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->rowCount() > 0;
    }

    public function accountUsage(string $sessionId, int $tokenDelta, int $timeDeltaSeconds): ?Goal
    {
        $tokenDelta = max(0, $tokenDelta);
        $timeDeltaSeconds = max(0, $timeDeltaSeconds);
        if ($tokenDelta === 0 && $timeDeltaSeconds === 0) {
            return $this->get($sessionId);
        }

        $goal = $this->get($sessionId);
        if ($goal === null || $goal->status !== GoalStatus::Active) {
            return $goal;
        }

        $tokensUsed = $goal->tokensUsed + $tokenDelta;
        $timeUsed = $goal->timeUsedSeconds + $timeDeltaSeconds;
        $status = $this->statusAfterBudget(GoalStatus::Active, $tokensUsed, $goal->tokenBudget);

        $stmt = $this->db->connection()->prepare(
            'UPDATE session_goals
             SET tokens_used = :tokens_used,
                 time_used_seconds = :time_used_seconds,
                 status = :status,
                 updated_at = :updated_at
             WHERE session_id = :session_id'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'tokens_used' => $tokensUsed,
            'time_used_seconds' => $timeUsed,
            'status' => $status->value,
            'updated_at' => $this->now(),
        ]);

        return $this->get($sessionId);
    }

    public function pauseActive(string $sessionId): ?Goal
    {
        $goal = $this->get($sessionId);
        if ($goal === null || $goal->status !== GoalStatus::Active) {
            return $goal;
        }

        return $this->update($sessionId, GoalStatus::Paused);
    }

    public static function validateObjective(string $objective): string
    {
        $objective = trim($objective);
        if ($objective === '') {
            throw new \InvalidArgumentException('goal objective must not be empty');
        }
        if (mb_strlen($objective) > self::MAX_OBJECTIVE_CHARS) {
            throw new \InvalidArgumentException('goal objective must be at most '.self::MAX_OBJECTIVE_CHARS.' characters');
        }

        return $objective;
    }

    private static function validateBudget(?int $budget): void
    {
        if ($budget !== null && $budget <= 0) {
            throw new \InvalidArgumentException('goal token budget must be a positive integer');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fromRow(array $row): Goal
    {
        return new Goal(
            sessionId: (string) $row['session_id'],
            goalId: (string) $row['goal_id'],
            objective: (string) $row['objective'],
            status: GoalStatus::from((string) $row['status']),
            tokenBudget: $row['token_budget'] === null ? null : (int) $row['token_budget'],
            tokensUsed: (int) $row['tokens_used'],
            timeUsedSeconds: (int) $row['time_used_seconds'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private function statusAfterBudget(GoalStatus $status, int $tokensUsed, ?int $tokenBudget): GoalStatus
    {
        if ($status === GoalStatus::Active && $tokenBudget !== null && $tokensUsed >= $tokenBudget) {
            return GoalStatus::BudgetLimited;
        }

        return $status;
    }

    private function now(): string
    {
        return number_format(microtime(true), 6, '.', '');
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
