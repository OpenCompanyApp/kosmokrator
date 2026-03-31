<?php

declare(strict_types=1);

namespace Kosmokrator\Task\Tool;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class TaskListTool implements ToolInterface
{
    public function __construct(
        private readonly TaskStore $store,
    ) {}

    public function name(): string
    {
        return 'task_list';
    }

    public function description(): string
    {
        return 'List all tasks as a tree with status icons and elapsed time.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function requiredParameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        return ToolResult::success($this->store->renderTree());
    }
}
