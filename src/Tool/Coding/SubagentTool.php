<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\Agent\AgentType;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

use function Amp\Future\await;

/**
 * Spawns child agents that run their own autonomous tool loops.
 *
 * Two modes of operation:
 * - Single: pass `task` (string) — spawns one agent. The existing LLM-facing API.
 * - Batch:  pass `agents` (array of specs) — spawns all concurrently, blocks until all complete.
 *           Designed for Lua where the synchronous sandbox prevents parallel loops.
 *
 * Each instance is bound to a parent AgentContext — not registered globally.
 */
class SubagentTool extends AbstractTool
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
            .'Use for parallel research, exploration, or delegated work. '
            .'Supports batch mode: pass `agents` array to spawn multiple agents concurrently.';
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
            'agents' => [
                'type' => 'array',
                'description' => 'Batch mode: array of agent specs to run concurrently. Each spec: {task (required), type, id, depends_on, group}. '
                    .'Use top-level `mode` to control await/background behavior for the entire batch. '
                    .'When set, the `task`, `type`, `id`, `depends_on`, `group` parameters at top level are ignored.',
                'items' => ['type' => 'object'],
            ],
        ];
    }

    public function requiredParameters(): array
    {
        return [];
    }

    protected function handle(array $args): ToolResult
    {
        // Batch mode: agents array provided
        $agents = $args['agents'] ?? null;
        if (is_array($agents) && $agents !== []) {
            $mode = ($args['mode'] ?? 'await') === 'background' ? 'background' : 'await';

            return $this->handleBatch($agents, $mode);
        }

        // Single mode: task string provided
        $task = trim((string) ($args['task'] ?? ''));
        if ($task !== '') {
            return $this->handleSingle($task, $args);
        }

        return ToolResult::error('Provide either `task` (string) or `agents` (array).');
    }

    /**
     * Single agent spawn — the original API.
     *
     * @param  array{type?: string, mode?: string, id?: string, depends_on?: string[], group?: string}  $args
     */
    private function handleSingle(string $task, array $args): ToolResult
    {
        $typeStr = (string) ($args['type'] ?? 'explore');
        $mode = (string) ($args['mode'] ?? 'await');
        $id = isset($args['id']) && $args['id'] !== '' ? (string) $args['id'] : null;
        $dependsOn = $this->normalizeDependsOn($args['depends_on'] ?? []);
        $group = isset($args['group']) && $args['group'] !== '' ? (string) $args['group'] : null;

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

        $orchestrator = $this->parentContext->orchestrator;
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
            $orchestrator->yieldSlot($this->parentContext->id);
            try {
                $result = $future->await();
            } finally {
                $orchestrator->reclaimSlot($this->parentContext->id);
            }

            return ToolResult::success("Agent '{$id}' completed:\n{$result}");
        }

        return ToolResult::success(
            "Agent '{$id}' spawned in background ({$childType->value}). Results will be delivered when ready."
        );
    }

    /**
     * Batch mode — spawn all agents concurrently.
     *
     * @param  array<int, array{task?: string, type?: string, id?: string, depends_on?: string[], group?: string}>  $agents
     * @param  string  $mode  'await' (block until all complete) or 'background' (fire and forget)
     */
    private function handleBatch(array $agents, string $mode = 'await'): ToolResult
    {
        if (! $this->parentContext->canSpawn()) {
            return ToolResult::error(
                "Maximum agent depth reached ({$this->parentContext->maxDepth}). Cannot spawn deeper."
            );
        }

        $orchestrator = $this->parentContext->orchestrator;
        $allowedTypes = $this->parentContext->type->allowedChildTypes();

        $errors = [];
        $specs = [];

        // Validate all specs upfront before spawning any
        foreach ($agents as $i => $spec) {
            $task = trim((string) ($spec['task'] ?? ''));
            if ($task === '') {
                $errors[] = "Agent at index {$i}: task is required.";

                continue;
            }

            $typeStr = (string) ($spec['type'] ?? 'explore');
            $childType = AgentType::tryFrom($typeStr);
            if ($childType === null) {
                $errors[] = "Agent at index {$i}: invalid type '{$typeStr}'. Valid: ".implode(', ', array_map(fn (AgentType $t) => $t->value, $allowedTypes));

                continue;
            }

            if (! in_array($childType, $allowedTypes, true)) {
                $errors[] = "Agent at index {$i}: type '{$childType->value}' not allowed from '{$this->parentContext->type->value}' agent.";

                continue;
            }

            $id = isset($spec['id']) && $spec['id'] !== '' ? (string) $spec['id'] : $orchestrator->generateId();
            $dependsOn = $this->normalizeDependsOn($spec['depends_on'] ?? []);
            $group = isset($spec['group']) && $spec['group'] !== '' ? (string) $spec['group'] : null;

            $specs[] = [
                'task' => $task,
                'type' => $childType,
                'id' => $id,
                'depends_on' => $dependsOn,
                'group' => $group,
            ];
        }

        $duplicateIds = $this->duplicateIds(array_map(fn (array $spec) => $spec['id'], $specs));
        foreach ($duplicateIds as $duplicateId) {
            $errors[] = "Duplicate agent id '{$duplicateId}'. Agent IDs must be unique within a batch.";
        }

        if ($errors !== []) {
            return ToolResult::error("Validation errors:\n".implode("\n", $errors));
        }

        // Spawn all agents and collect their futures
        $futures = [];
        foreach ($specs as $spec) {
            $futures[$spec['id']] = $orchestrator->spawnAgent(
                parentContext: $this->parentContext,
                task: $spec['task'],
                childType: $spec['type'],
                mode: $mode,
                id: $spec['id'],
                dependsOn: $spec['depends_on'],
                group: $spec['group'],
                agentFactory: $this->agentFactory,
            );
        }

        if ($mode === 'background') {
            $ids = implode(', ', array_map(fn (array $s) => "'{$s['id']}' ({$s['type']->value})", $specs));

            return ToolResult::success(
                'Batch spawned '.count($specs).' agents in background: '.$ids.'. Results will be delivered when ready.'
            );
        }

        // Await mode — block until all complete
        $orchestrator->yieldSlot($this->parentContext->id);

        try {
            $results = await($futures);
        } catch (\Throwable $e) {
            return ToolResult::error('Batch execution failed: '.$e->getMessage());
        } finally {
            $orchestrator->reclaimSlot($this->parentContext->id);
        }

        $lines = [];
        $lines[] = 'Batch complete: '.count($results).' agents finished.';
        $lines[] = '';

        foreach ($results as $agentId => $result) {
            $spec = null;
            foreach ($specs as $s) {
                if ($s['id'] === $agentId) {
                    $spec = $s;
                    break;
                }
            }
            $type = $spec !== null ? $spec['type']->value : 'unknown';
            $lines[] = "--- Agent '{$agentId}' ({$type}) ---";
            $lines[] = (string) $result;
            $lines[] = '';
        }

        return ToolResult::success(implode("\n", $lines));
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

    /**
     * @param  string[]  $ids
     * @return string[]
     */
    private function duplicateIds(array $ids): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                $duplicates[$id] = $id;

                continue;
            }

            $seen[$id] = true;
        }

        return array_values($duplicates);
    }
}
