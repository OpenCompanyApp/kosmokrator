<?php

namespace Kosmokrator\Tests\Unit\Task\Tool;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\Task\Tool\TaskCreateTool;
use PHPUnit\Framework\TestCase;

class TaskCreateToolTest extends TestCase
{
    private TaskStore $store;

    private TaskCreateTool $tool;

    protected function setUp(): void
    {
        $this->store = new TaskStore();
        $this->tool = new TaskCreateTool($this->store);
    }

    public function test_name(): void
    {
        $this->assertSame('task_create', $this->tool->name());
    }

    public function test_no_required_parameters(): void
    {
        $this->assertSame([], $this->tool->requiredParameters());
    }

    public function test_create_single_task(): void
    {
        $result = $this->tool->execute([
            'subject' => 'Build feature',
            'description' => 'Some details',
            'active_form' => 'Building feature',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Build feature', $result->output);
        $this->assertCount(1, $this->store->all());
    }

    public function test_create_single_with_parent(): void
    {
        $this->tool->execute(['subject' => 'Parent']);
        $parentId = $this->store->all()[0]->id;

        $result = $this->tool->execute([
            'subject' => 'Child',
            'parent_id' => $parentId,
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(2, $this->store->all());
        $children = $this->store->children($parentId);
        $this->assertCount(1, $children);
        $this->assertSame('Child', $children[0]->subject);
    }

    public function test_error_when_parent_not_found(): void
    {
        $result = $this->tool->execute([
            'subject' => 'Child',
            'parent_id' => 'nonexistent',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_batch_create(): void
    {
        $tasks = json_encode([
            ['subject' => 'Task A', 'description' => 'First'],
            ['subject' => 'Task B'],
            ['subject' => 'Task C', 'active_form' => 'Working on C'],
        ]);

        $result = $this->tool->execute(['tasks' => $tasks]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Created 3 tasks', $result->output);
        $this->assertCount(3, $this->store->all());
    }

    public function test_batch_with_parent_created_in_same_batch(): void
    {
        // First create a parent outside the batch
        $this->tool->execute(['subject' => 'Existing parent']);
        $parentId = $this->store->all()[0]->id;

        $tasks = json_encode([
            ['subject' => 'Child 1', 'parent_id' => $parentId],
            ['subject' => 'Child 2', 'parent_id' => $parentId],
        ]);

        $result = $this->tool->execute(['tasks' => $tasks]);

        $this->assertTrue($result->success);
        $this->assertCount(3, $this->store->all());
    }

    public function test_error_both_subject_and_tasks(): void
    {
        $result = $this->tool->execute([
            'subject' => 'Single',
            'tasks' => '[{"subject": "Batch"}]',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not both', $result->output);
    }

    public function test_error_neither_subject_nor_tasks(): void
    {
        $result = $this->tool->execute([]);

        $this->assertFalse($result->success);
    }

    public function test_error_invalid_json(): void
    {
        $result = $this->tool->execute(['tasks' => 'not json']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid', $result->output);
    }

    public function test_error_batch_missing_subject(): void
    {
        $result = $this->tool->execute(['tasks' => '[{"description": "no subject"}]']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('missing', $result->output);
    }

    public function test_returns_tree_after_creation(): void
    {
        $result = $this->tool->execute(['subject' => 'Task one']);

        $this->assertTrue($result->success);
        // The tree should be in the output
        $this->assertStringContainsString('Task one', $result->output);
    }
}
