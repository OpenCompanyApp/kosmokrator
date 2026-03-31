<?php

namespace Kosmokrator\Tests\Unit\Task\Tool;

use Kosmokrator\Task\Task;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Task\Tool\TaskGetTool;
use PHPUnit\Framework\TestCase;

class TaskGetToolTest extends TestCase
{
    private TaskStore $store;

    private TaskGetTool $tool;

    protected function setUp(): void
    {
        $this->store = new TaskStore;
        $this->tool = new TaskGetTool($this->store);
    }

    public function test_name(): void
    {
        $this->assertSame('task_get', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['id'], $this->tool->requiredParameters());
    }

    public function test_get_existing_task(): void
    {
        $this->store->add(new Task('Build it', 'Some desc', id: 'abc'));

        $result = $this->tool->execute(['id' => 'abc']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('ID: abc', $result->output);
        $this->assertStringContainsString('Build it', $result->output);
        $this->assertStringContainsString('Some desc', $result->output);
    }

    public function test_get_with_children(): void
    {
        $this->store->add(new Task('Parent', id: 'p'));
        $this->store->add(new Task('Child 1', parentId: 'p', id: 'c1'));
        $this->store->add(new Task('Child 2', parentId: 'p', id: 'c2'));

        $result = $this->tool->execute(['id' => 'p']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Children:', $result->output);
        $this->assertStringContainsString('Child 1', $result->output);
        $this->assertStringContainsString('Child 2', $result->output);
    }

    public function test_error_unknown_id(): void
    {
        $result = $this->tool->execute(['id' => 'nope']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_error_empty_id(): void
    {
        $result = $this->tool->execute(['id' => '']);

        $this->assertFalse($result->success);
    }

    public function test_error_missing_id(): void
    {
        $result = $this->tool->execute([]);

        $this->assertFalse($result->success);
    }
}
