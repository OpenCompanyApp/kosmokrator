<?php

declare(strict_types=1);

namespace Kosmokrator\Task\Tool;

use Kosmokrator\Task\Task;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class TaskCreateTool implements ToolInterface
{
    public function __construct(
        private readonly TaskStore $store,
    ) {}

    public function name(): string { return 'task_create'; }

    public function description(): string
    {
        return 'Create one or more tasks to track work progress. Use "subject" for a single task, or "tasks" (JSON array) to create multiple at once. Each task can optionally be nested under a parent.';
    }

    public function parameters(): array
    {
        return [
            'subject' => ['type' => 'string', 'description' => 'Task title (for creating a single task)'],
            'description' => ['type' => 'string', 'description' => 'Task details (single task mode)'],
            'active_form' => ['type' => 'string', 'description' => 'Present-continuous label for spinner, e.g. "Running tests" (single task mode)'],
            'parent_id' => ['type' => 'string', 'description' => 'Parent task ID for nesting (single task mode)'],
            'tasks' => ['type' => 'string', 'description' => 'JSON array of task objects for batch creation. Each object: {"subject": "...", "description": "...", "active_form": "...", "parent_id": "..."}. Only "subject" is required per object.'],
        ];
    }

    public function requiredParameters(): array { return []; }

    public function execute(array $args): ToolResult
    {
        $hasSubject = isset($args['subject']) && $args['subject'] !== '';
        $hasTasks = isset($args['tasks']) && $args['tasks'] !== '';

        if ($hasSubject && $hasTasks) {
            return ToolResult::error('Provide either "subject" (single task) or "tasks" (batch), not both.');
        }

        if (! $hasSubject && ! $hasTasks) {
            return ToolResult::error('Provide "subject" for a single task or "tasks" (JSON array) for batch creation.');
        }

        if ($hasSubject) {
            return $this->createSingle($args);
        }

        return $this->createBatch($args['tasks']);
    }

    private function createSingle(array $args): ToolResult
    {
        $parentId = $args['parent_id'] ?? null;
        if ($parentId !== null && $this->store->get($parentId) === null) {
            return ToolResult::error("Parent task '{$parentId}' not found.");
        }

        $task = new Task(
            subject: $args['subject'],
            description: $args['description'] ?? '',
            activeForm: $args['active_form'] ?? null,
            parentId: $parentId,
        );
        $this->store->add($task);

        return ToolResult::success("Created task {$task->id}: {$task->subject}\n\n" . $this->store->renderTree());
    }

    private function createBatch(string $json): ToolResult
    {
        $items = json_decode($json, true);
        if (! is_array($items) || $items === []) {
            return ToolResult::error('Invalid "tasks" JSON: expected a non-empty array of objects.');
        }

        $created = [];

        foreach ($items as $i => $item) {
            if (! is_array($item) || ! isset($item['subject']) || $item['subject'] === '') {
                return ToolResult::error("Task at index {$i} is missing a \"subject\".");
            }

            $parentId = $item['parent_id'] ?? null;
            if ($parentId !== null && $this->store->get($parentId) === null) {
                // Check if it was created in this batch
                $found = false;
                foreach ($created as $c) {
                    if ($c->id === $parentId) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return ToolResult::error("Task at index {$i}: parent '{$parentId}' not found.");
                }
            }

            $task = new Task(
                subject: $item['subject'],
                description: $item['description'] ?? '',
                activeForm: $item['active_form'] ?? null,
                parentId: $parentId,
            );
            $this->store->add($task);
            $created[] = $task;
        }

        $ids = implode(', ', array_map(fn (Task $t) => $t->id, $created));

        return ToolResult::success("Created " . count($created) . " tasks ({$ids})\n\n" . $this->store->renderTree());
    }
}
