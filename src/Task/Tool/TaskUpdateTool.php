<?php

declare(strict_types=1);

namespace Kosmokrator\Task\Tool;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Updates a task's status, subject, description, or dependency links.
 * Part of the task management toolset exposed to the AI agent.
 */
class TaskUpdateTool extends AbstractTool
{
    public function __construct(
        private readonly TaskStore $store,
    ) {}

    /** @return string Tool identifier used by the agent */
    public function name(): string
    {
        return 'task_update';
    }

    /** @return string Human-readable description for the agent's tool catalog */
    public function description(): string
    {
        return 'Update a task\'s status, subject, description, or dependencies. Status flow: pending -> in_progress -> completed | cancelled.';
    }

    /** @return array<string,array<string,string>> JSON Schema-style parameter definitions */
    public function parameters(): array
    {
        return [
            'id' => ['type' => 'string', 'description' => 'Task ID to update'],
            'status' => ['type' => 'string', 'description' => 'New status: pending, in_progress, completed, cancelled'],
            'subject' => ['type' => 'string', 'description' => 'Updated task title'],
            'description' => ['type' => 'string', 'description' => 'Updated task details'],
            'active_form' => ['type' => 'string', 'description' => 'Updated spinner label'],
            'add_blocked_by' => ['type' => 'string', 'description' => 'JSON array of task IDs that block this task'],
            'add_blocks' => ['type' => 'string', 'description' => 'JSON array of task IDs this task blocks'],
        ];
    }

    /** @return list<string> Parameters that must always be provided */
    public function requiredParameters(): array
    {
        return ['id'];
    }

    /**
     * @param  array<string,mixed>  $args  Tool call arguments from the agent
     * @return ToolResult Updated task detail with tree view or error message
     */
    protected function handle(array $args): ToolResult
    {
        $id = $args['id'] ?? '';
        if ($id === '') {
            return ToolResult::error('Task ID is required.');
        }

        $task = $this->store->get($id);
        if ($task === null) {
            return ToolResult::error("Task '{$id}' not found.");
        }

        $changes = [];

        if (isset($args['status']) && $args['status'] !== '') {
            $valid = ['pending', 'in_progress', 'completed', 'cancelled'];
            if (! in_array($args['status'], $valid, true)) {
                return ToolResult::error("Invalid status '{$args['status']}'. Valid: ".implode(', ', $valid));
            }
            $changes['status'] = $args['status'];
        }

        if (isset($args['subject']) && $args['subject'] !== '') {
            $changes['subject'] = $args['subject'];
        }
        if (isset($args['description'])) {
            $changes['description'] = $args['description'];
        }
        if (isset($args['active_form'])) {
            $changes['active_form'] = $args['active_form'];
        }

        if (isset($args['add_blocked_by']) && $args['add_blocked_by'] !== '') {
            $blockedBy = json_decode($args['add_blocked_by'], true);
            if (! is_array($blockedBy)) {
                return ToolResult::error('add_blocked_by must be a JSON array of task IDs.');
            }
            $changes['add_blocked_by'] = $blockedBy;
        }

        if (isset($args['add_blocks']) && $args['add_blocks'] !== '') {
            $blocks = json_decode($args['add_blocks'], true);
            if (! is_array($blocks)) {
                return ToolResult::error('add_blocks must be a JSON array of task IDs.');
            }
            $changes['add_blocks'] = $blocks;
        }

        if ($changes === []) {
            return ToolResult::error('No changes provided.');
        }

        $updated = $this->store->update($id, $changes);

        return ToolResult::success($updated->toDetail()."\n\n".$this->store->renderTree());
    }
}
