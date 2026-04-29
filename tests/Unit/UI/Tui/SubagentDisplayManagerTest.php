<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\SubagentDisplayManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class SubagentDisplayManagerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Register the custom 'cosmos' spinner used by SubagentDisplayManager
        CancellableLoaderWidget::addSpinner('cosmos', ['✦', '✧', '⊛', '◈', '⊛', '✧']);
    }

    private ContainerWidget $conversation;

    private bool $spinnersEnsured;

    private function createManager(): SubagentDisplayManager
    {
        $this->conversation = new ContainerWidget;
        $this->spinnersEnsured = false;

        return new SubagentDisplayManager(
            state: new TuiStateStore,
            conversation: $this->conversation,
            ensureSpinners: function (): void {
                $this->spinnersEnsured = true;
            },
            log: null,
        );
    }

    public function test_initial_has_running_agents_is_false(): void
    {
        $manager = $this->createManager();
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_set_tree_provider_accepts_closure(): void
    {
        $manager = $this->createManager();
        $manager->setTreeProvider(fn (): array => []);
        // No exception = success
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_set_tree_provider_accepts_null(): void
    {
        $manager = $this->createManager();
        $manager->setTreeProvider(fn (): array => []);
        $manager->setTreeProvider(null);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_spawn_with_empty_entries_is_noop(): void
    {
        $manager = $this->createManager();
        $manager->showSpawn([]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_spawn_with_entries_adds_widget(): void
    {
        $manager = $this->createManager();
        $manager->showSpawn([
            ['args' => ['type' => 'explore', 'task' => 'Search codebase'], 'id' => 'agent-1'],
        ]);
        $this->assertFalse($manager->hasRunningAgents()); // spawn doesn't create a loader
    }

    public function test_show_running_with_empty_entries_is_noop(): void
    {
        $manager = $this->createManager();
        $manager->showRunning([]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_running_with_entries_sets_running(): void
    {
        $manager = $this->createManager();
        $manager->showRunning([
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
        ]);
        $this->assertTrue($manager->hasRunningAgents());
        $this->assertTrue($this->spinnersEnsured);
    }

    public function test_show_running_multiple_agents_sets_running(): void
    {
        $manager = $this->createManager();
        $manager->showRunning([
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
            ['args' => ['type' => 'general', 'task' => 'Implement'], 'id' => 'agent-2'],
            ['args' => ['type' => 'plan', 'task' => 'Analyze'], 'id' => 'agent-3'],
        ]);
        $this->assertTrue($manager->hasRunningAgents());
    }

    public function test_cleanup_after_running_clears_loader(): void
    {
        $manager = $this->createManager();
        $manager->showRunning([
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
        ]);
        $this->assertTrue($manager->hasRunningAgents());

        $manager->cleanup();
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_cleanup_without_running_is_safe(): void
    {
        $manager = $this->createManager();
        $manager->cleanup(); // Should not throw
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_batch_with_empty_entries_is_noop(): void
    {
        $manager = $this->createManager();
        $manager->showBatch([]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_batch_with_background_only_keeps_loader(): void
    {
        $manager = $this->createManager();
        // First start a running state
        $manager->showRunning([
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
        ]);
        $this->assertTrue($manager->hasRunningAgents());

        // Batch with only background acks should keep the loader
        $manager->showBatch([
            [
                'args' => ['type' => 'explore', 'task' => 'Search', 'id' => 'agent-1'],
                'result' => 'Agent spawned in background (session abc123)',
                'success' => true,
            ],
        ]);

        // Loader should still be running since all entries were background acks
        $this->assertTrue($manager->hasRunningAgents());
    }

    public function test_show_batch_with_background_completion_stops_loader(): void
    {
        $manager = $this->createManager();
        $manager->showRunning([
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
        ]);
        $this->assertTrue($manager->hasRunningAgents());

        $manager->showBatch([
            [
                'kind' => 'completion',
                'args' => ['type' => 'explore', 'task' => 'Search', 'id' => 'agent-1', 'mode' => 'background'],
                'result' => 'Background research finished.',
                'success' => true,
            ],
        ]);

        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_batch_with_single_success_result(): void
    {
        $manager = $this->createManager();
        $manager->showBatch([
            [
                'args' => ['type' => 'explore', 'task' => 'Search', 'id' => 'test-agent'],
                'result' => 'Found 3 files matching the pattern.',
                'success' => true,
            ],
        ]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_batch_with_single_failure_result(): void
    {
        $manager = $this->createManager();
        $manager->showBatch([
            [
                'args' => ['type' => 'general', 'task' => 'Implement', 'id' => 'fail-agent'],
                'result' => 'Error: permission denied',
                'success' => false,
            ],
        ]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_batch_with_multiple_results(): void
    {
        $manager = $this->createManager();
        $manager->showBatch([
            [
                'args' => ['type' => 'explore', 'task' => 'Search', 'id' => 'a1'],
                'result' => 'Found files',
                'success' => true,
            ],
            [
                'args' => ['type' => 'general', 'task' => 'Write', 'id' => 'a2'],
                'result' => 'Wrote file',
                'success' => true,
            ],
            [
                'args' => ['type' => 'explore', 'task' => 'Read', 'id' => 'a3'],
                'result' => 'Read error',
                'success' => false,
            ],
        ]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_batch_with_children(): void
    {
        $manager = $this->createManager();
        $manager->showBatch([
            [
                'args' => ['type' => 'general', 'task' => 'Parallel', 'id' => 'parent'],
                'result' => 'All done',
                'success' => true,
                'children' => [
                    ['id' => 'child-1', 'type' => 'explore', 'status' => 'done', 'task' => 'Subtask', 'elapsed' => 1.5, 'toolCalls' => 3, 'success' => true],
                ],
            ],
        ]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_tick_tree_refresh_without_provider_is_noop(): void
    {
        $manager = $this->createManager();
        // No tree provider set — should not throw
        $manager->tickTreeRefresh();
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_tick_tree_refresh_calls_provider(): void
    {
        $manager = $this->createManager();
        $called = false;
        $manager->setTreeProvider(function () use (&$called): array {
            $called = true;

            return [];
        });
        $manager->tickTreeRefresh();
        $this->assertTrue($called);
    }

    public function test_tick_tree_refresh_with_empty_tree_does_not_error(): void
    {
        $manager = $this->createManager();
        $manager->setTreeProvider(fn (): array => []);
        $manager->tickTreeRefresh();
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_tick_tree_refresh_after_batch_is_noop(): void
    {
        $manager = $this->createManager();
        $called = false;
        $manager->setTreeProvider(function () use (&$called): array {
            $called = true;

            return [];
        });

        // Show batch to set batchDisplayed flag
        $manager->showBatch([
            [
                'args' => ['type' => 'explore', 'task' => 'Search', 'id' => 'a1'],
                'result' => 'Done',
                'success' => true,
            ],
        ]);

        $called = false;
        $manager->tickTreeRefresh();
        $this->assertFalse($called); // Provider should NOT be called after batch
    }

    public function test_refresh_tree_with_empty_removes_existing_tree(): void
    {
        $manager = $this->createManager();

        // First show a spawn to create a tree
        $manager->showSpawn([
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
        ]);

        // Refresh with empty tree should clean up
        $manager->refreshTree([]);
        // No exception = success
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_refresh_tree_with_data_updates_display(): void
    {
        $manager = $this->createManager();

        $tree = [
            [
                'id' => 'agent-1',
                'type' => 'explore',
                'task' => 'Searching codebase for patterns',
                'status' => 'running',
                'elapsed' => 5.2,
                'toolCalls' => 3,
                'children' => [],
            ],
        ];

        $manager->refreshTree($tree);
        // No exception, render was called
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_refresh_tree_with_nested_children(): void
    {
        $manager = $this->createManager();

        $tree = [
            [
                'id' => 'parent',
                'type' => 'general',
                'task' => 'Running parallel agents',
                'status' => 'running',
                'elapsed' => 10.0,
                'toolCalls' => 5,
                'children' => [
                    [
                        'id' => 'child-1',
                        'type' => 'explore',
                        'task' => 'Searching files',
                        'status' => 'done',
                        'elapsed' => 8.0,
                        'toolCalls' => 2,
                        'children' => [],
                    ],
                    [
                        'id' => 'child-2',
                        'type' => 'explore',
                        'task' => 'Reading code',
                        'status' => 'running',
                        'elapsed' => 6.0,
                        'toolCalls' => 4,
                        'children' => [],
                    ],
                ],
            ],
        ];

        $manager->refreshTree($tree);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_full_lifecycle_spawn_running_batch(): void
    {
        $manager = $this->createManager();

        $entries = [
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
            ['args' => ['type' => 'general', 'task' => 'Write'], 'id' => 'agent-2'],
        ];

        // Spawn
        $manager->showSpawn($entries);
        $this->assertFalse($manager->hasRunningAgents());

        // Running
        $manager->showRunning($entries);
        $this->assertTrue($manager->hasRunningAgents());

        // Batch results
        $manager->showBatch([
            [
                'args' => $entries[0]['args'],
                'result' => 'Found files',
                'success' => true,
            ],
            [
                'args' => $entries[1]['args'],
                'result' => 'Wrote file',
                'success' => true,
            ],
        ]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_multiple_spawn_cycles(): void
    {
        $manager = $this->createManager();

        // First cycle
        $manager->showSpawn([['args' => ['type' => 'explore', 'task' => 'A'], 'id' => 'a1']]);
        $manager->showRunning([['args' => ['type' => 'explore', 'task' => 'A'], 'id' => 'a1']]);
        $this->assertTrue($manager->hasRunningAgents());

        $manager->showBatch([
            ['args' => ['type' => 'explore', 'task' => 'A', 'id' => 'a1'], 'result' => 'Done A', 'success' => true],
        ]);
        $this->assertFalse($manager->hasRunningAgents());

        // Second cycle — should work cleanly
        $manager->showSpawn([['args' => ['type' => 'general', 'task' => 'B'], 'id' => 'b1']]);
        $manager->showRunning([['args' => ['type' => 'general', 'task' => 'B'], 'id' => 'b1']]);
        $this->assertTrue($manager->hasRunningAgents());

        $manager->showBatch([
            ['args' => ['type' => 'general', 'task' => 'B', 'id' => 'b1'], 'result' => 'Done B', 'success' => true],
        ]);
        $this->assertFalse($manager->hasRunningAgents());
    }

    public function test_show_batch_filters_background_acks(): void
    {
        $manager = $this->createManager();
        $manager->showRunning([
            ['args' => ['type' => 'explore', 'task' => 'Search'], 'id' => 'agent-1'],
        ]);
        $this->assertTrue($manager->hasRunningAgents());

        // Mix of background and real results
        $manager->showBatch([
            [
                'args' => ['type' => 'explore', 'task' => 'Search', 'id' => 'agent-1'],
                'result' => 'Agent spawned in background (session xyz)',
                'success' => true,
            ],
            [
                'args' => ['type' => 'general', 'task' => 'Write', 'id' => 'agent-2'],
                'result' => 'Completed successfully',
                'success' => true,
            ],
        ]);

        // Real result should have been displayed, loader stopped
        $this->assertFalse($manager->hasRunningAgents());
    }
}
