<?php

declare(strict_types=1);

namespace Kosmokrator\Task\Tool;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

/**
 * Lists all tasks as a rendered tree with status icons and elapsed time.
 * Part of the task management toolset exposed to the AI agent.
 */
class TaskListTool implements ToolInterface
{
    public function __construct(
        private readonly TaskStore $store,
    ) {}

    /** @return string Tool identifier used by the agent */
    public function name(): string
    {
        return 'task_list';
    }

    /** @return string Human-readable description for the agent's tool catalog */
    public function description(): string
    {
        return 'List all tasks as a tree with status icons and elapsed time.';
    }

    /** @return array<string,array<string,string>> JSON Schema-style parameter definitions */
    public function parameters(): array
    {
        return [];
    }

    /** @return list<string> Parameters that must always be provided */
    public function requiredParameters(): array
    {
        return [];
    }

    /**
     * @param  array<string,mixed> $args Tool call arguments (unused)
     * @return ToolResult           Rendered task tree
     */
    public function execute(array $args): ToolResult
    {
        return ToolResult::success($this->store->renderTree());
    }
}
