<?php

namespace Kosmokrator\Tests\Unit\Task;

use Kosmokrator\Task\Task;
use Kosmokrator\Task\TaskStatus;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase
{
    public function test_constructor_sets_defaults(): void
    {
        $task = new Task('Do something');

        $this->assertSame('Do something', $task->subject);
        $this->assertSame('', $task->description);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertNull($task->activeForm);
        $this->assertNull($task->parentId);
        $this->assertSame([], $task->blockedBy);
        $this->assertSame([], $task->blocks);
        $this->assertSame([], $task->metadata);
        $this->assertNull($task->startedAt);
        $this->assertNull($task->completedAt);
        $this->assertSame(8, strlen($task->id));
    }

    public function test_constructor_with_all_args(): void
    {
        $task = new Task(
            subject: 'Build feature',
            description: 'Some details',
            activeForm: 'Building feature',
            parentId: 'abc123',
            id: 'custom01',
        );

        $this->assertSame('custom01', $task->id);
        $this->assertSame('Build feature', $task->subject);
        $this->assertSame('Some details', $task->description);
        $this->assertSame('Building feature', $task->activeForm);
        $this->assertSame('abc123', $task->parentId);
    }

    public function test_transition_to_in_progress_sets_started_at(): void
    {
        $task = new Task('Work');
        $this->assertNull($task->startedAt);

        $task->transitionTo(TaskStatus::InProgress);

        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNotNull($task->startedAt);
        $this->assertNull($task->completedAt);
    }

    public function test_transition_to_completed_sets_completed_at(): void
    {
        $task = new Task('Work');
        $task->transitionTo(TaskStatus::InProgress);
        $task->transitionTo(TaskStatus::Completed);

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->completedAt);
    }

    public function test_transition_to_cancelled_sets_completed_at(): void
    {
        $task = new Task('Work');
        $task->transitionTo(TaskStatus::Cancelled);

        $this->assertSame(TaskStatus::Cancelled, $task->status);
        $this->assertNotNull($task->completedAt);
    }

    public function test_elapsed_returns_null_when_not_started(): void
    {
        $task = new Task('Work');
        $this->assertNull($task->elapsed());
    }

    public function test_elapsed_returns_duration_when_started(): void
    {
        $task = new Task('Work');
        $task->transitionTo(TaskStatus::InProgress);

        $elapsed = $task->elapsed();
        $this->assertNotNull($elapsed);
        $this->assertGreaterThanOrEqual(0.0, $elapsed);
    }

    public function test_to_summary_pending(): void
    {
        $task = new Task('Install deps', id: 'aaa');
        $summary = $task->toSummary();

        $this->assertStringContainsString('Install deps', $summary);
        $this->assertStringContainsString("\u{25CB}", $summary); // ○
    }

    public function test_to_summary_in_progress(): void
    {
        $task = new Task('Running tests', id: 'bbb');
        $task->transitionTo(TaskStatus::InProgress);
        $summary = $task->toSummary();

        $this->assertStringContainsString('Running tests', $summary);
        $this->assertStringContainsString("\u{25CE}", $summary); // ◎
    }

    public function test_to_detail_includes_all_fields(): void
    {
        $task = new Task('Build', 'Details here', 'Building', 'parent1', 'detail01');
        $task->metadata = ['key' => 'val'];
        $task->blockedBy = ['xxx'];
        $task->blocks = ['yyy'];

        $detail = $task->toDetail();

        $this->assertStringContainsString('ID: detail01', $detail);
        $this->assertStringContainsString('Subject: Build', $detail);
        $this->assertStringContainsString('Description: Details here', $detail);
        $this->assertStringContainsString('Active form: Building', $detail);
        $this->assertStringContainsString('Parent: parent1', $detail);
        $this->assertStringContainsString('Blocked by: xxx', $detail);
        $this->assertStringContainsString('Blocks: yyy', $detail);
        $this->assertStringContainsString('"key":"val"', $detail);
    }
}
