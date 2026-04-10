<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Composition;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Tui\Composition\TaskTree;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class TaskTreeTest extends TestCase
{
    private TuiStateStore $state;

    protected function setUp(): void
    {
        $this->state = new TuiStateStore;
    }

    public function test_empty_store_returns_no_change(): void
    {
        $tree = TaskTree::of(null, $this->state);
        $this->assertFalse($tree->syncFromSignals());
    }

    public function test_empty_store_renders_empty(): void
    {
        $tree = TaskTree::of(null, $this->state);
        $tree->syncFromSignals();

        $result = $tree->render(new RenderContext(80, 24));
        $this->assertSame([], $result);
    }

    public function test_empty_task_store_returns_no_change(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(true);

        $tree = TaskTree::of($store, $this->state);
        $this->assertFalse($tree->syncFromSignals());
    }

    public function test_non_empty_store_returns_change(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('task line');
        $store->method('hasInProgress')->willReturn(false);

        $tree = TaskTree::of($store, $this->state);
        $this->assertTrue($tree->syncFromSignals());
    }

    public function test_non_empty_store_renders_lines(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('task line');
        $store->method('hasInProgress')->willReturn(false);

        $tree = TaskTree::of($store, $this->state);
        $tree->syncFromSignals();

        $result = $tree->render(new RenderContext(80, 24));
        $this->assertNotEmpty($result);
    }

    public function test_set_task_store_updates_store(): void
    {
        $tree = TaskTree::of(null, $this->state);

        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('task');
        $store->method('hasInProgress')->willReturn(false);

        $tree->setTaskStore($store);
        $this->assertTrue($tree->syncFromSignals());
    }

    public function test_re_sync_with_same_store_returns_false(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('task line');
        $store->method('hasInProgress')->willReturn(false);

        $tree = TaskTree::of($store, $this->state);
        $tree->syncFromSignals();

        // Same content → no change
        $this->assertFalse($tree->syncFromSignals());
    }

    public function test_render_contains_tasks_header(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('isEmpty')->willReturn(false);
        $store->method('renderAnsiTree')->willReturn('task line');
        $store->method('hasInProgress')->willReturn(false);

        $tree = TaskTree::of($store, $this->state);
        $tree->syncFromSignals();

        $result = $tree->render(new RenderContext(80, 24));
        $rendered = implode("\n", $result);
        $this->assertStringContainsString('Tasks', $rendered);
    }
}
