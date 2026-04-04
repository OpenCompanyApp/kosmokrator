<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class UIManagerTest extends TestCase
{
    /**
     * Create a UIManager with a mock renderer injected via reflection.
     */
    private function createManagerWithMock(string $preference = 'ansi'): array
    {
        $manager = new UIManager($preference);
        $mock = $this->createMock(RendererInterface::class);

        $ref = new \ReflectionProperty($manager, 'renderer');
        $ref->setAccessible(true);
        $ref->setValue($manager, $mock);

        return [$manager, $mock];
    }

    // ------------------------------------------------------------------
    // Constructor / resolveRenderer
    // ------------------------------------------------------------------

    public function test_constructor_with_ansi_preference_creates_ansi_renderer(): void
    {
        $manager = new UIManager('ansi');
        $this->assertSame('ansi', $manager->getActiveRenderer());
    }

    public function test_constructor_default_preference_is_auto(): void
    {
        // Default 'auto' — resolves to either 'tui' or 'ansi'
        $manager = new UIManager;
        $active = $manager->getActiveRenderer();
        $this->assertContains($active, ['tui', 'ansi']);
    }

    public function test_get_active_renderer_returns_string(): void
    {
        $manager = new UIManager('ansi');
        $this->assertIsString($manager->getActiveRenderer());
    }

    // ------------------------------------------------------------------
    // Delegation: void methods
    // ------------------------------------------------------------------

    public function test_set_task_store_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $store = $this->createMock(TaskStore::class);

        $mock->expects($this->once())->method('setTaskStore')->with($store);
        $manager->setTaskStore($store);
    }

    public function test_refresh_task_bar_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('refreshTaskBar');
        $manager->refreshTaskBar();
    }

    public function test_initialize_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('initialize');
        $manager->initialize();
    }

    public function test_render_intro_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('renderIntro')->with(true);
        $manager->renderIntro(true);
    }

    public function test_show_user_message_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showUserMessage')->with('hello');
        $manager->showUserMessage('hello');
    }

    public function test_set_phase_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('setPhase')->with(AgentPhase::Thinking);
        $manager->setPhase(AgentPhase::Thinking);
    }

    public function test_show_thinking_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showThinking');
        $manager->showThinking();
    }

    public function test_clear_thinking_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('clearThinking');
        $manager->clearThinking();
    }

    public function test_show_compacting_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showCompacting');
        $manager->showCompacting();
    }

    public function test_clear_compacting_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('clearCompacting');
        $manager->clearCompacting();
    }

    public function test_stream_chunk_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('streamChunk')->with('chunk');
        $manager->streamChunk('chunk');
    }

    public function test_stream_complete_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('streamComplete');
        $manager->streamComplete();
    }

    public function test_show_tool_call_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showToolCall')->with('read', ['path' => '/foo']);
        $manager->showToolCall('read', ['path' => '/foo']);
    }

    public function test_show_tool_result_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showToolResult')->with('read', 'output', true);
        $manager->showToolResult('read', 'output', true);
    }

    public function test_show_auto_approve_indicator_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showAutoApproveIndicator')->with('bash');
        $manager->showAutoApproveIndicator('bash');
    }

    public function test_show_tool_executing_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showToolExecuting')->with('bash');
        $manager->showToolExecuting('bash');
    }

    public function test_update_tool_executing_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('updateToolExecuting')->with('output');
        $manager->updateToolExecuting('output');
    }

    public function test_clear_tool_executing_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('clearToolExecuting');
        $manager->clearToolExecuting();
    }

    public function test_clear_conversation_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('clearConversation');
        $manager->clearConversation();
    }

    public function test_replay_history_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $messages = [['role' => 'user', 'text' => 'hi']];

        $mock->expects($this->once())->method('replayHistory')->with($messages);
        $manager->replayHistory($messages);
    }

    public function test_show_notice_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showNotice')->with('notice');
        $manager->showNotice('notice');
    }

    public function test_show_mode_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showMode')->with('edit', 'green');
        $manager->showMode('edit', 'green');
    }

    public function test_show_mode_delegates_with_default_color(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showMode')->with('plan', '');
        $manager->showMode('plan');
    }

    public function test_set_permission_mode_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('setPermissionMode')->with('guardian', 'red');
        $manager->setPermissionMode('guardian', 'red');
    }

    public function test_set_immediate_command_handler_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $handler = fn (): string => '';

        $mock->expects($this->once())->method('setImmediateCommandHandler')->with($handler);
        $manager->setImmediateCommandHandler($handler);
    }

    public function test_set_immediate_command_handler_delegates_null(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('setImmediateCommandHandler')->with(null);
        $manager->setImmediateCommandHandler(null);
    }

    public function test_show_error_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showError')->with('oops');
        $manager->showError('oops');
    }

    public function test_show_status_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showStatus')->with('gpt-4', 100, 200, 0.05, 128000);
        $manager->showStatus('gpt-4', 100, 200, 0.05, 128000);
    }

    public function test_refresh_runtime_selection_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('refreshRuntimeSelection')->with('openai', 'gpt-4', 128000);
        $manager->refreshRuntimeSelection('openai', 'gpt-4', 128000);
    }

    public function test_show_subagent_status_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $stats = ['running' => 3];

        $mock->expects($this->once())->method('showSubagentStatus')->with($stats);
        $manager->showSubagentStatus($stats);
    }

    public function test_clear_subagent_status_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('clearSubagentStatus');
        $manager->clearSubagentStatus();
    }

    public function test_show_subagent_running_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $entries = [['id' => 'a1']];

        $mock->expects($this->once())->method('showSubagentRunning')->with($entries);
        $manager->showSubagentRunning($entries);
    }

    public function test_show_subagent_spawn_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $entries = [['id' => 'a1']];

        $mock->expects($this->once())->method('showSubagentSpawn')->with($entries);
        $manager->showSubagentSpawn($entries);
    }

    public function test_show_subagent_batch_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $entries = [['id' => 'a1']];

        $mock->expects($this->once())->method('showSubagentBatch')->with($entries);
        $manager->showSubagentBatch($entries);
    }

    public function test_refresh_subagent_tree_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $tree = ['root' => []];

        $mock->expects($this->once())->method('refreshSubagentTree')->with($tree);
        $manager->refreshSubagentTree($tree);
    }

    public function test_set_agent_tree_provider_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $provider = fn (): array => [];

        $mock->expects($this->once())->method('setAgentTreeProvider')->with($provider);
        $manager->setAgentTreeProvider($provider);
    }

    public function test_set_agent_tree_provider_delegates_null(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('setAgentTreeProvider')->with(null);
        $manager->setAgentTreeProvider(null);
    }

    public function test_show_agents_dashboard_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $summary = ['total' => 5];
        $allStats = [['id' => 'a1']];

        $mock->expects($this->once())->method('showAgentsDashboard')->with($summary, $allStats, null);
        $manager->showAgentsDashboard($summary, $allStats);
    }

    public function test_teardown_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('teardown');
        $manager->teardown();
    }

    public function test_show_welcome_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('showWelcome');
        $manager->showWelcome();
    }

    public function test_play_theogony_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('playTheogony');
        $manager->playTheogony();
    }

    public function test_play_prometheus_delegates(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('playPrometheus');
        $manager->playPrometheus();
    }

    // ------------------------------------------------------------------
    // Delegation: methods with return values
    // ------------------------------------------------------------------

    public function test_prompt_delegates_and_returns_value(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('prompt')->willReturn('user input');
        $this->assertSame('user input', $manager->prompt());
    }

    public function test_get_cancellation_delegates_and_returns_null(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('getCancellation')->willReturn(null);
        $this->assertNull($manager->getCancellation());
    }

    public function test_get_cancellation_delegates_and_returns_cancellation(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $cancellation = $this->createMock(Cancellation::class);

        $mock->expects($this->once())->method('getCancellation')->willReturn($cancellation);
        $this->assertSame($cancellation, $manager->getCancellation());
    }

    public function test_ask_tool_permission_delegates_and_returns_value(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('askToolPermission')->with('bash', ['cmd' => 'ls'])->willReturn('allow');
        $this->assertSame('allow', $manager->askToolPermission('bash', ['cmd' => 'ls']));
    }

    public function test_consume_queued_message_delegates_and_returns_null(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('consumeQueuedMessage')->willReturn(null);
        $this->assertNull($manager->consumeQueuedMessage());
    }

    public function test_consume_queued_message_delegates_and_returns_message(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('consumeQueuedMessage')->willReturn('queued msg');
        $this->assertSame('queued msg', $manager->consumeQueuedMessage());
    }

    public function test_show_settings_delegates_and_returns_array(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $current = ['model' => 'gpt-4'];
        $result = ['model' => 'claude'];

        $mock->expects($this->once())->method('showSettings')->with($current)->willReturn($result);
        $this->assertSame($result, $manager->showSettings($current));
    }

    public function test_pick_session_delegates_and_returns_null(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $items = ['sess-1', 'sess-2'];

        $mock->expects($this->once())->method('pickSession')->with($items)->willReturn(null);
        $this->assertNull($manager->pickSession($items));
    }

    public function test_pick_session_delegates_and_returns_session_id(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $items = ['sess-1', 'sess-2'];

        $mock->expects($this->once())->method('pickSession')->with($items)->willReturn('sess-1');
        $this->assertSame('sess-1', $manager->pickSession($items));
    }

    public function test_approve_plan_delegates_and_returns_null(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('approvePlan')->with('guardian')->willReturn(null);
        $this->assertNull($manager->approvePlan('guardian'));
    }

    public function test_approve_plan_delegates_and_returns_array(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $result = ['approved' => true];

        $mock->expects($this->once())->method('approvePlan')->with('auto')->willReturn($result);
        $this->assertSame($result, $manager->approvePlan('auto'));
    }

    public function test_ask_user_delegates_and_returns_value(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();

        $mock->expects($this->once())->method('askUser')->with('name?')->willReturn('Kosmo');
        $this->assertSame('Kosmo', $manager->askUser('name?'));
    }

    public function test_ask_choice_delegates_and_returns_value(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        $choices = ['a', 'b'];

        $mock->expects($this->once())->method('askChoice')->with('pick', $choices)->willReturn('a');
        $this->assertSame('a', $manager->askChoice('pick', $choices));
    }

    // ------------------------------------------------------------------
    // seedMockSession with non-AnsiRenderer mock
    // ------------------------------------------------------------------

    public function test_seed_mock_session_does_nothing_with_non_ansi_renderer(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        // Mock RendererInterface has no seedMockSession, so it must not be called.
        // This should not throw.
        $manager->seedMockSession();
        $this->assertTrue(true); // Reached without error
    }

    // ------------------------------------------------------------------
    // getActiveRenderer with injected mock returns class name
    // ------------------------------------------------------------------

    public function test_get_active_renderer_returns_class_name_for_unknown_renderer(): void
    {
        [$manager, $mock] = $this->createManagerWithMock();
        // The mock is neither TuiRenderer nor AnsiRenderer, so getActiveRenderer returns class name
        $this->assertSame(get_class($mock), $manager->getActiveRenderer());
    }
}
