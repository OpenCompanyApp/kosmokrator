<?php

namespace Kosmokrator\Tests\Unit\Task;

use Kosmokrator\Task\Task;
use Kosmokrator\Task\TaskStatus;
use Kosmokrator\Task\TaskStore;
use PHPUnit\Framework\TestCase;

class TaskStoreTest extends TestCase
{
    private TaskStore $store;

    protected function setUp(): void
    {
        $this->store = new TaskStore;
    }

    public function test_add_and_get(): void
    {
        $task = new Task('Do thing', id: 'aaa');
        $this->store->add($task);

        $this->assertSame($task, $this->store->get('aaa'));
    }

    public function test_get_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->store->get('nonexistent'));
    }

    public function test_all_returns_all_tasks(): void
    {
        $this->store->add(new Task('A', id: 'a'));
        $this->store->add(new Task('B', id: 'b'));

        $this->assertCount(2, $this->store->all());
    }

    public function test_is_empty(): void
    {
        $this->assertTrue($this->store->isEmpty());

        $this->store->add(new Task('A', id: 'a'));
        $this->assertFalse($this->store->isEmpty());
    }

    public function test_roots_returns_tasks_without_parent(): void
    {
        $this->store->add(new Task('Root', id: 'root'));
        $this->store->add(new Task('Child', parentId: 'root', id: 'child'));

        $roots = $this->store->roots();
        $this->assertCount(1, $roots);
        $this->assertSame('root', $roots[0]->id);
    }

    public function test_children_returns_child_tasks(): void
    {
        $this->store->add(new Task('Root', id: 'root'));
        $this->store->add(new Task('Child 1', parentId: 'root', id: 'c1'));
        $this->store->add(new Task('Child 2', parentId: 'root', id: 'c2'));
        $this->store->add(new Task('Other', id: 'other'));

        $children = $this->store->children('root');
        $this->assertCount(2, $children);
    }

    public function test_update_changes_status(): void
    {
        $this->store->add(new Task('Work', id: 'w'));

        $updated = $this->store->update('w', ['status' => 'in_progress']);

        $this->assertSame(TaskStatus::InProgress, $updated->status);
        $this->assertNotNull($updated->startedAt);
    }

    public function test_update_changes_subject(): void
    {
        $this->store->add(new Task('Old', id: 'x'));

        $updated = $this->store->update('x', ['subject' => 'New']);

        $this->assertSame('New', $updated->subject);
    }

    public function test_update_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->store->update('nope', ['status' => 'completed']));
    }

    public function test_update_adds_blocked_by_with_inverse(): void
    {
        $this->store->add(new Task('First', id: 'a'));
        $this->store->add(new Task('Second', id: 'b'));

        $this->store->update('b', ['add_blocked_by' => ['a']]);

        $b = $this->store->get('b');
        $a = $this->store->get('a');
        $this->assertContains('a', $b->blockedBy);
        $this->assertContains('b', $a->blocks);
    }

    public function test_update_adds_blocks_with_inverse(): void
    {
        $this->store->add(new Task('First', id: 'a'));
        $this->store->add(new Task('Second', id: 'b'));

        $this->store->update('a', ['add_blocks' => ['b']]);

        $a = $this->store->get('a');
        $b = $this->store->get('b');
        $this->assertContains('b', $a->blocks);
        $this->assertContains('a', $b->blockedBy);
    }

    public function test_is_blocked_returns_true_when_blocker_not_done(): void
    {
        $this->store->add(new Task('Blocker', id: 'a'));
        $this->store->add(new Task('Blocked', id: 'b'));
        $this->store->update('b', ['add_blocked_by' => ['a']]);

        $this->assertTrue($this->store->isBlocked('b'));
    }

    public function test_is_blocked_returns_false_when_blocker_completed(): void
    {
        $this->store->add(new Task('Blocker', id: 'a'));
        $this->store->add(new Task('Blocked', id: 'b'));
        $this->store->update('b', ['add_blocked_by' => ['a']]);

        $this->store->update('a', ['status' => 'in_progress']);
        $this->store->update('a', ['status' => 'completed']);

        $this->assertFalse($this->store->isBlocked('b'));
    }

    public function test_auto_complete_parent_when_all_children_done(): void
    {
        $this->store->add(new Task('Parent', id: 'p'));
        $this->store->add(new Task('Child 1', parentId: 'p', id: 'c1'));
        $this->store->add(new Task('Child 2', parentId: 'p', id: 'c2'));

        $this->store->update('c1', ['status' => 'in_progress']);
        $this->store->update('c1', ['status' => 'completed']);
        $this->assertSame(TaskStatus::Pending, $this->store->get('p')->status);

        $this->store->update('c2', ['status' => 'in_progress']);
        $this->store->update('c2', ['status' => 'completed']);
        $this->assertSame(TaskStatus::Completed, $this->store->get('p')->status);
    }

    public function test_auto_complete_parent_counts_cancelled_as_terminal(): void
    {
        $this->store->add(new Task('Parent', id: 'p'));
        $this->store->add(new Task('Child 1', parentId: 'p', id: 'c1'));
        $this->store->add(new Task('Child 2', parentId: 'p', id: 'c2'));

        $this->store->update('c1', ['status' => 'in_progress']);
        $this->store->update('c1', ['status' => 'completed']);
        $this->store->update('c2', ['status' => 'in_progress']);
        $this->store->update('c2', ['status' => 'cancelled']);

        $this->assertSame(TaskStatus::Completed, $this->store->get('p')->status);
    }

    public function test_render_tree_empty(): void
    {
        $this->assertSame('No tasks.', $this->store->renderTree());
    }

    public function test_render_tree_with_nested_tasks(): void
    {
        $this->store->add(new Task('Root task', id: 'r'));
        $this->store->add(new Task('Sub task', parentId: 'r', id: 's'));

        $tree = $this->store->renderTree();

        $this->assertStringContainsString('Root task', $tree);
        $this->assertStringContainsString('  ', $tree); // indentation for child
        $this->assertStringContainsString('Sub task', $tree);
    }

    public function test_render_tree_shows_blocked_label(): void
    {
        $this->store->add(new Task('First', id: 'a'));
        $this->store->add(new Task('Second', id: 'b'));
        $this->store->update('b', ['add_blocked_by' => ['a']]);

        $tree = $this->store->renderTree();

        $this->assertStringContainsString('[blocked]', $tree);
    }

    public function test_update_metadata_merges(): void
    {
        $this->store->add(new Task('Work', id: 'm'));

        $this->store->update('m', ['metadata' => ['a' => 1]]);
        $this->store->update('m', ['metadata' => ['b' => 2]]);

        $task = $this->store->get('m');
        $this->assertSame(['a' => 1, 'b' => 2], $task->metadata);
    }
}
