<?php

namespace Kosmokrator\Tests\Unit\Task;

use Kosmokrator\Task\TaskStatus;
use PHPUnit\Framework\TestCase;

class TaskStatusTest extends TestCase
{
    public function test_isTerminal_returns_true_for_completed(): void
    {
        $this->assertTrue(TaskStatus::Completed->isTerminal());
    }

    public function test_isTerminal_returns_true_for_cancelled(): void
    {
        $this->assertTrue(TaskStatus::Cancelled->isTerminal());
    }

    public function test_isTerminal_returns_true_for_failed(): void
    {
        $this->assertTrue(TaskStatus::Failed->isTerminal());
    }

    public function test_isTerminal_returns_false_for_pending(): void
    {
        $this->assertFalse(TaskStatus::Pending->isTerminal());
    }

    public function test_isTerminal_returns_false_for_in_progress(): void
    {
        $this->assertFalse(TaskStatus::InProgress->isTerminal());
    }

    public function test_isActive_returns_true_for_pending(): void
    {
        $this->assertTrue(TaskStatus::Pending->isActive());
    }

    public function test_isActive_returns_true_for_in_progress(): void
    {
        $this->assertTrue(TaskStatus::InProgress->isActive());
    }

    public function test_isActive_returns_false_for_terminal_states(): void
    {
        $this->assertFalse(TaskStatus::Completed->isActive());
        $this->assertFalse(TaskStatus::Cancelled->isActive());
        $this->assertFalse(TaskStatus::Failed->isActive());
    }

    public function test_label_returns_non_empty_string_for_all_cases(): void
    {
        foreach (TaskStatus::cases() as $status) {
            $label = $status->label();
            $this->assertIsString($label, "{$status->name} label is not a string");
            $this->assertNotEmpty($label, "{$status->name} label is empty");
        }
    }

    public function test_icon_returns_non_empty_string_for_all_cases(): void
    {
        foreach (TaskStatus::cases() as $status) {
            $icon = $status->icon();
            $this->assertIsString($icon, "{$status->name} icon is not a string");
            $this->assertNotEmpty($icon, "{$status->name} icon is empty");
        }
    }

    public function test_transitions_terminal_states_have_no_outgoing(): void
    {
        $transitions = TaskStatus::transitions();

        foreach ([TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Failed] as $status) {
            $this->assertSame([], $transitions[$status->value], "{$status->name} should have no outgoing transitions");
        }
    }

    public function test_transitions_pending_can_go_to_in_progress_and_cancelled(): void
    {
        $transitions = TaskStatus::transitions();

        $this->assertSame(['in_progress', 'cancelled'], $transitions['pending']);
    }

    public function test_transitions_in_progress_can_go_to_all_terminal_states(): void
    {
        $transitions = TaskStatus::transitions();

        $this->assertSame(['completed', 'cancelled', 'failed'], $transitions['in_progress']);
    }

    public function test_canTransitionTo_returns_true_for_valid_transition(): void
    {
        $this->assertTrue(TaskStatus::Pending->canTransitionTo(TaskStatus::InProgress));
        $this->assertTrue(TaskStatus::InProgress->canTransitionTo(TaskStatus::Failed));
    }

    public function test_canTransitionTo_returns_false_for_invalid_transition(): void
    {
        $this->assertFalse(TaskStatus::Pending->canTransitionTo(TaskStatus::Completed));
        $this->assertFalse(TaskStatus::Completed->canTransitionTo(TaskStatus::InProgress));
        $this->assertFalse(TaskStatus::Failed->canTransitionTo(TaskStatus::InProgress));
    }
}
