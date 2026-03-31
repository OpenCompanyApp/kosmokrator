<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\Cancellation;

/**
 * Immutable context that travels down the subagent tree.
 * Each child gets a new context with incremented depth and narrowed type.
 */
readonly class AgentContext
{
    public function __construct(
        public AgentType $type,
        public int $depth,
        public int $maxDepth,
        public SubagentOrchestrator $orchestrator,
        public string $id,
        public string $task,
        public ?Cancellation $cancellation = null,
    ) {}

    /**
     * Whether this agent can spawn children (depth has room for one more level).
     */
    public function canSpawn(): bool
    {
        return $this->depth < $this->maxDepth - 1;
    }

    /**
     * Create a child context with incremented depth and validated type narrowing.
     *
     * @throws \InvalidArgumentException if the child type violates permission inheritance
     */
    public function childContext(AgentType $childType, string $childId, string $childTask, ?Cancellation $cancellation = null): self
    {
        if (! in_array($childType, $this->type->allowedChildTypes(), true)) {
            throw new \InvalidArgumentException(
                "Agent type '{$this->type->value}' cannot spawn '{$childType->value}'. "
                .'Allowed: '.implode(', ', array_map(fn (AgentType $t) => $t->value, $this->type->allowedChildTypes()))
            );
        }

        return new self(
            type: $childType,
            depth: $this->depth + 1,
            maxDepth: $this->maxDepth,
            orchestrator: $this->orchestrator,
            id: $childId,
            task: $childTask,
            cancellation: $cancellation ?? $this->cancellation,
        );
    }
}
