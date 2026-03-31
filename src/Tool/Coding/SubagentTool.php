<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

/**
 * Tool the LLM calls to spawn a subagent.
 * Each instance is bound to a parent AgentContext — not registered globally.
 */
class SubagentTool implements ToolInterface
{
    /**
     * @param  \Closure(AgentContext, string): string  $agentFactory
     */
    public function __construct(
        private readonly AgentContext $parentContext,
        private readonly \Closure $agentFactory,
    ) {}

    public function name(): string
    {
        return 'subagent';
    }

    public function description(): string
    {
        return 'Spawn a sub-agent to work on a task autonomously. '
            .'The sub-agent runs its own tool loop and returns a summary. '
            .'Use for parallel research, exploration, or delegated work.';
    }

    public function parameters(): array
    {
        return [
            'task' => [
                'type' => 'string',
                'description' => 'What the agent should do. Be specific and detailed.',
            ],
            'type' => [
                'type' => 'enum',
                'description' => 'Agent type: general (read+write), explore (read-only research), plan (read-only planning)',
                'options' => $this->allowedTypeOptions(),
            ],
            'mode' => [
                'type' => 'enum',
                'description' => 'await: block until done, result inline. background: continue immediately, result injected later.',
                'options' => ['await', 'background'],
            ],
            'id' => [
                'type' => 'string',
                'description' => 'Optional name for this agent. Used by depends_on to reference this agent.',
            ],
            'depends_on' => [
                'type' => 'array',
                'description' => 'Agent IDs that must finish before this one starts. Their results are passed to this agent.',
                'items' => ['type' => 'string'],
            ],
            'group' => [
                'type' => 'string',
                'description' => 'Sequential execution group name. Agents in the same group run one at a time.',
            ],
        ];
    }

    public function requiredParameters(): array
    {
        return ['task'];
    }

    public function execute(array $args): ToolResult
    {
        $task = trim((string) ($args['task'] ?? ''));
        $typeStr = (string) ($args['type'] ?? 'explore');
        $mode = (string) ($args['mode'] ?? 'await');
        $id = isset($args['id']) && $args['id'] !== '' ? (string) $args['id'] : null;
        $dependsOn = $this->normalizeDependsOn($args['depends_on'] ?? []);
        $group = isset($args['group']) && $args['group'] !== '' ? (string) $args['group'] : null;

        if ($task === '') {
            return ToolResult::error('Task is required.');
        }

        $childType = AgentType::tryFrom($typeStr);
        if ($childType === null) {
            return ToolResult::error("Invalid agent type: '{$typeStr}'. Valid: ".implode(', ', $this->allowedTypeOptions()));
        }

        if (! in_array($childType, $this->parentContext->type->allowedChildTypes(), true)) {
            return ToolResult::error(
                "Cannot spawn '{$childType->value}' from '{$this->parentContext->type->value}' agent. "
                .'Allowed: '.implode(', ', $this->allowedTypeOptions())
            );
        }

        if (! $this->parentContext->canSpawn()) {
            return ToolResult::error(
                "Maximum agent depth reached ({$this->parentContext->maxDepth}). Cannot spawn deeper."
            );
        }

        if (! in_array($mode, ['await', 'background'], true)) {
            $mode = 'await';
        }

        try {
            $orchestrator = $this->parentContext->orchestrator;

            // If no ID provided, generate one before spawning so we can reference it
            $id ??= $orchestrator->generateId();

            $future = $orchestrator->spawnAgent(
                parentContext: $this->parentContext,
                task: $task,
                childType: $childType,
                mode: $mode,
                id: $id,
                dependsOn: $dependsOn,
                group: $group,
                agentFactory: $this->agentFactory,
            );

            if ($mode === 'await') {
                $result = $future->await();

                return ToolResult::success($result);
            }

            return ToolResult::success(
                "Agent '{$id}' spawned in background ({$childType->value}). Results will be delivered when ready."
            );
        } catch (\Throwable $e) {
            return ToolResult::error("Failed to spawn agent: {$e->getMessage()}");
        }
    }

    /**
     * @return string[]
     */
    private function allowedTypeOptions(): array
    {
        return array_map(
            fn (AgentType $t) => $t->value,
            $this->parentContext->type->allowedChildTypes(),
        );
    }

    /**
     * @return string[]
     */
    private function normalizeDependsOn(mixed $value): array
    {
        if (is_string($value)) {
            return $value !== '' ? [$value] : [];
        }

        if (is_array($value)) {
            return array_values(array_filter(
                array_map('strval', $value),
                fn (string $v) => $v !== '',
            ));
        }

        return [];
    }
}
