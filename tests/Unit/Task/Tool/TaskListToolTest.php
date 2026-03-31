<?php

namespace Kosmokrator\Tests\Unit\Task\Tool;

use Kosmokrator\Task\Task;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Task\Tool\TaskListTool;
use PHPUnit\Framework\TestCase;

class TaskListToolTest extends TestCase
{
    private TaskStore $store;

    private TaskListTool $tool;

    protected function setUp(): void
    {
        $this->store = new TaskStore;
        $this->tool = new TaskListTool($this->store);
    }

    public function test_name(): void
    {
        $this->assertSame('task_list', $this->tool->name());
    }

    public function test_no_parameters(): void
    {
        $this->assertSame([], $this->tool->parameters());
        $this->assertSame([], $this->tool->requiredParameters());
    }

    public function test_empty_store(): void
    {
        $result = $this->tool->execute([]);

        $this->assertTrue($result->success);
        $this->assertSame('No tasks.', $result->output);
    }

    public function test_lists_tasks(): void
    {
        $this->store->add(new Task('First', id: 'a'));
        $this->store->add(new Task('Second', id: 'b'));

        $result = $this->tool->execute([]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('First', $result->output);
        $this->assertStringContainsString('Second', $result->output);
    }

    public function test_shows_nested_tree(): void
    {
        $this->store->add(new Task('Parent', id: 'p'));
        $this->store->add(new Task('Child', parentId: 'p', id: 'c'));

        $result = $this->tool->execute([]);

        $this->assertTrue($result->success);
        // Child should be indented
        $lines = explode("\n", $result->output);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('  ', $lines[1]); // indented
    }
}
