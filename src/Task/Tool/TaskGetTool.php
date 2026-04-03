<?php

declare(strict_types=1);

namespace Kosmokrator\Task\Tool;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

/**
 * Retrieves full details of a single task, including its children.
 * Part of the task management toolset exposed to the AI agent.
 */
class TaskGetTool implements ToolInterface
{
    public function __construct(
        private readonly TaskStore $store,
    ) {}

    /** @return string Tool identifier used by the agent */
    public function name(): string
    {
        return 'task_get';
    }

    /** @return string Human-readable description for the agent's tool catalog */
    public function description(): string
    {
        return 'Get full details of a specific task including status, elapsed time, dependencies, and children.';
    }

    /** @return array<string,array<string,string>> JSON Schema-style parameter definitions */
    public function parameters(): array
    {
        return [
            'id' => ['type' => 'string', 'description' => 'Task ID to retrieve'],
        ];
    }

    /** @return list<string> Parameters that must always be provided */
    public function requiredParameters(): array
    {
        return ['id'];
    }

    /**
     * @param  array<string,mixed> $args Tool call arguments from the agent
     * @return ToolResult           Task detail view with children or error message
     */
    public function execute(array $args): ToolResult
    {
        $id = $args['id'] ?? '';
        if ($id === '') {
            return ToolResult::error('Task ID is required.');
        }

        $task = $this->store->get($id);
        if ($task === null) {
            return ToolResult::error("Task '{$id}' not found.");
        }

        $output = $task->toDetail();

        $children = $this->store->children($id);
        if ($children !== []) {
            $output .= "\n\nChildren:";
            foreach ($children as $child) {
                $output .= "\n  ".$child->toSummary();
            }
        }

        return ToolResult::success($output);
    }
}
