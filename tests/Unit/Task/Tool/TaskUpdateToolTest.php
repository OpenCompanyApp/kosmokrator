<?php

namespace Kosmokrator\Tests\Unit\Task\Tool;

use Kosmokrator\Task\Task;
use Kosmokrator\Task\TaskStatus;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Task\Tool\TaskUpdateTool;
use PHPUnit\Framework\TestCase;

class TaskUpdateToolTest extends TestCase
{
    private TaskStore $store;

    private TaskUpdateTool $tool;

    protected function setUp(): void
    {
        $this->store = new TaskStore;
        $this->tool = new TaskUpdateTool($this->store);
    }

    public function test_name(): void
    {
        $this->assertSame('task_update', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['id'], $this->tool->requiredParameters());
    }

    public function test_update_status(): void
    {
        $this->store->add(new Task('Work', id: 'abc'));

        $result = $this->tool->execute(['id' => 'abc', 'status' => 'in_progress']);

        $this->assertTrue($result->success);
        $this->assertSame(TaskStatus::InProgress, $this->store->get('abc')->status);
    }

    public function test_update_subject(): void
    {
        $this->store->add(new Task('Old', id: 'abc'));

        $result = $this->tool->execute(['id' => 'abc', 'subject' => 'New']);

        $this->assertTrue($result->success);
        $this->assertSame('New', $this->store->get('abc')->subject);
    }

    public function test_error_unknown_id(): void
    {
        $result = $this->tool->execute(['id' => 'nope', 'status' => 'completed']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_error_invalid_status(): void
    {
        $this->store->add(new Task('Work', id: 'abc'));

        $result = $this->tool->execute(['id' => 'abc', 'status' => 'invalid']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid status', $result->output);
    }

    public function test_error_empty_id(): void
    {
        $result = $this->tool->execute(['id' => '']);

        $this->assertFalse($result->success);
    }

    public function test_error_no_changes(): void
    {
        $this->store->add(new Task('Work', id: 'abc'));

        $result = $this->tool->execute(['id' => 'abc']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No changes', $result->output);
    }

    public function test_add_blocked_by(): void
    {
        $this->store->add(new Task('First', id: 'a'));
        $this->store->add(new Task('Second', id: 'b'));

        $result = $this->tool->execute(['id' => 'b', 'add_blocked_by' => '["a"]']);

        $this->assertTrue($result->success);
        $this->assertContains('a', $this->store->get('b')->blockedBy);
    }

    public function test_add_blocks(): void
    {
        $this->store->add(new Task('First', id: 'a'));
        $this->store->add(new Task('Second', id: 'b'));

        $result = $this->tool->execute(['id' => 'a', 'add_blocks' => '["b"]']);

        $this->assertTrue($result->success);
        $this->assertContains('b', $this->store->get('a')->blocks);
    }

    public function test_error_invalid_blocked_by_json(): void
    {
        $this->store->add(new Task('Work', id: 'abc'));

        $result = $this->tool->execute(['id' => 'abc', 'add_blocked_by' => 'not json']);

        $this->assertFalse($result->success);
    }

    public function test_returns_detail_and_tree(): void
    {
        $this->store->add(new Task('Work', id: 'abc'));

        $result = $this->tool->execute(['id' => 'abc', 'status' => 'in_progress']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('ID: abc', $result->output);
        $this->assertStringContainsString('Work', $result->output);
    }
}
