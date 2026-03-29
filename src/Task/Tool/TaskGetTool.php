<?php

declare(strict_types=1);

namespace Kosmokrator\Task\Tool;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class TaskGetTool implements ToolInterface
{
    public function __construct(
        private readonly TaskStore $store,
    ) {}

    public function name(): string { return 'task_get'; }

    public function description(): string
    {
        return 'Get full details of a specific task including status, elapsed time, dependencies, and children.';
    }

    public function parameters(): array
    {
        return [
            'id' => ['type' => 'string', 'description' => 'Task ID to retrieve'],
        ];
    }

    public function requiredParameters(): array { return ['id']; }

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
                $output .= "\n  " . $child->toSummary();
            }
        }

        return ToolResult::success($output);
    }
}
