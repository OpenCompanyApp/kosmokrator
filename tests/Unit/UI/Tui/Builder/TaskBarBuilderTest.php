<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Builder;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Tui\Builder\TaskBarBuilder;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;

final class TaskBarBuilderTest extends TestCase
{
    private TuiStateStore $state;

    private TaskBarBuilder $builder;

    protected function setUp(): void
    {
        $this->state = new TuiStateStore;
        $this->builder = TaskBarBuilder::create($this->state);
    }

    public function test_create_produces_widget_with_id(): void
    {
        $widget = $this->builder->getWidget();
        $this->assertSame('task-bar', $widget->getId());
    }

    public function test_update_with_no_task_store_produces_empty_text(): void
    {
        $this->builder->update();
        $this->assertSame('', $this->builder->getWidget()->getText());
    }

    public function test_update_with_empty_task_store_produces_empty_text(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(true);
        $this->builder->setTaskStore($store);

        $this->builder->update();
        $this->assertSame('', $this->builder->getWidget()->getText());
    }

    public function test_update_with_tasks_produces_tree_output(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn("● Task 1\n● Task 2");
        $store->method('hasInProgress')->willReturn(false);
        $this->builder->setTaskStore($store);

        $this->builder->update();

        $text = $this->builder->getWidget()->getText();
        $this->assertStringContainsString('Tasks', $text);
        $this->assertStringContainsString('Task 1', $text);
        $this->assertStringContainsString('Task 2', $text);
    }

    public function test_update_sets_has_tasks_signal(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('● Task');
        $this->builder->setTaskStore($store);

        $this->assertFalse($this->state->getHasTasks());
        $this->builder->update();
        $this->assertTrue($this->state->getHasTasks());
    }

    public function test_update_clears_has_tasks_when_empty(): void
    {
        $this->state->setHasTasks(true);
        $this->builder->update();
        $this->assertFalse($this->state->getHasTasks());
    }

    public function test_update_shows_thinking_phrase_when_no_loader_no_in_progress(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('● Task');
        $store->method('hasInProgress')->willReturn(false);
        $this->builder->setTaskStore($store);

        $this->state->setThinkingPhrase('Consulting the Oracle...');
        $this->state->setHasThinkingLoader(false);
        $this->state->setHasRunningAgents(false);
        $this->state->setThinkingStartTime(microtime(true) - 5.0);

        $this->builder->update();

        $text = $this->builder->getWidget()->getText();
        $this->assertStringContainsString('Consulting the Oracle...', $text);
        $this->assertStringContainsString('0:05', $text);
    }

    public function test_update_hides_thinking_phrase_when_loader_is_active(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('● Task');
        $store->method('hasInProgress')->willReturn(false);
        $this->builder->setTaskStore($store);

        $this->state->setThinkingPhrase('Consulting the Oracle...');
        $this->state->setHasThinkingLoader(true);

        $this->builder->update();

        $text = $this->builder->getWidget()->getText();
        $this->assertStringNotContainsString('Consulting the Oracle...', $text);
    }

    public function test_update_hides_thinking_phrase_when_task_in_progress(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('● Task');
        $store->method('hasInProgress')->willReturn(true);
        $this->builder->setTaskStore($store);

        $this->state->setThinkingPhrase('Consulting the Oracle...');
        $this->state->setHasThinkingLoader(false);

        $this->builder->update();

        $text = $this->builder->getWidget()->getText();
        $this->assertStringNotContainsString('Consulting the Oracle...', $text);
    }

    public function test_update_hides_elapsed_when_agents_running(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('● Task');
        $store->method('hasInProgress')->willReturn(false);
        $this->builder->setTaskStore($store);

        $this->state->setThinkingPhrase('Thinking...');
        $this->state->setHasThinkingLoader(false);
        $this->state->setHasRunningAgents(true);
        $this->state->setThinkingStartTime(microtime(true) - 10.0);

        $this->builder->update();

        $text = $this->builder->getWidget()->getText();
        $this->assertStringContainsString('Thinking...', $text);
        $this->assertStringNotContainsString('0:10', $text);
    }
}
