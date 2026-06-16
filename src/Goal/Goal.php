<?php

declare(strict_types=1);

namespace Kosmokrator\Goal;

readonly class Goal
{
    public function __construct(
        public string $sessionId,
        public string $goalId,
        public string $objective,
        public GoalStatus $status,
        public ?int $tokenBudget,
        public int $tokensUsed,
        public int $timeUsedSeconds,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'goalId' => $this->goalId,
            'objective' => $this->objective,
            'status' => $this->status->value,
            'tokenBudget' => $this->tokenBudget,
            'tokensUsed' => $this->tokensUsed,
            'timeUsedSeconds' => $this->timeUsedSeconds,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
