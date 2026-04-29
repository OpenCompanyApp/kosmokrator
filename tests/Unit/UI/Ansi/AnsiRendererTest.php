<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi;

use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiConversationRenderer;
use Kosmokrator\UI\Ansi\AnsiRenderer;
use Kosmokrator\UI\Ansi\AnsiSubagentRenderer;
use Kosmokrator\UI\Ansi\AnsiToolRenderer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AnsiRendererTest extends TestCase
{
    private AnsiRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AnsiRenderer;
    }

    // ── Constructor ──────────────────────────────────────────────────────

    public function test_constructor_creates_instance(): void
    {
        $renderer = new AnsiRenderer;
        $this->assertInstanceOf(AnsiRenderer::class, $renderer);
    }

    // ── No-op / simple return methods ────────────────────────────────────

    public function test_get_cancellation_returns_null(): void
    {
        $this->assertNull($this->renderer->getCancellation());
    }

    public function test_consume_queued_message_returns_null(): void
    {
        $this->assertNull($this->renderer->consumeQueuedMessage());
    }

    public function test_approve_plan_returns_defaults_in_non_interactive(): void
    {
        $result = $this->renderer->approvePlan('guardian');
        $this->assertSame(['permission' => 'guardian', 'context' => 'keep'], $result);
    }

    public function test_initialize_is_noop(): void
    {
        $this->renderer->initialize();
        $this->assertTrue(true); // No exception thrown
    }

    public function test_refresh_task_bar_is_noop(): void
    {
        $this->renderer->refreshTaskBar();
        $this->assertTrue(true);
    }

    public function test_clear_thinking_is_noop(): void
    {
        $this->renderer->clearThinking();
        $this->assertTrue(true);
    }

    public function test_clear_compacting_is_noop(): void
    {
        $this->renderer->clearCompacting();
        $this->assertTrue(true);
    }

    public function test_clear_subagent_status_is_noop(): void
    {
        $this->renderer->clearSubagentStatus();
        $this->assertTrue(true);
    }

    public function test_refresh_subagent_tree_is_noop(): void
    {
        $this->renderer->refreshSubagentTree([]);
        $this->assertTrue(true);
    }

    public function test_set_agent_tree_provider_is_noop(): void
    {
        $this->renderer->setAgentTreeProvider(null);
        $this->assertTrue(true);
    }

    public function test_show_auto_approve_indicator_is_noop(): void
    {
        $this->renderer->showAutoApproveIndicator('bash');
        $this->assertTrue(true);
    }

    public function test_set_immediate_command_handler_is_noop(): void
    {
        $this->renderer->setImmediateCommandHandler(fn () => null);
        $this->assertTrue(true);
    }

    // ── setTaskStore ─────────────────────────────────────────────────────

    public function test_set_task_store_accepts_store(): void
    {
        $store = $this->createMock(TaskStore::class);
        $this->renderer->setTaskStore($store);
        $this->assertTrue(true); // No exception
    }

    // ── showMode / setPermissionMode ─────────────────────────────────────

    public function test_show_mode_stores_label(): void
    {
        // showMode stores the label internally — verify via showStatus output
        $this->renderer->showMode('Plan');
        $output = $this->captureOutput(fn () => $this->renderer->showStatus('gpt-4', 100, 50, 0.01, 200000));

        $this->assertStringContainsString('Plan', $output);
        // Plan mode should not show permission label
        $this->assertStringNotContainsString('Guardian', $output);
    }

    public function test_show_mode_edit_shows_permission(): void
    {
        $this->renderer->showMode('Edit');
        $this->renderer->setPermissionMode('Guardian ◈', '');
        $output = $this->captureOutput(fn () => $this->renderer->showStatus('gpt-4', 100, 50, 0.01, 200000));

        $this->assertStringContainsString('Edit', $output);
        $this->assertStringContainsString('Guardian ◈', $output);
    }

    public function test_show_mode_ask_omits_permission(): void
    {
        $this->renderer->showMode('Ask');
        $this->renderer->setPermissionMode('Argus', '');
        $output = $this->captureOutput(fn () => $this->renderer->showStatus('gpt-4', 100, 50, 0.01, 200000));

        $this->assertStringContainsString('Ask', $output);
        $this->assertStringNotContainsString('Argus', $output);
    }

    // ── setPhase ─────────────────────────────────────────────────────────

    public function test_set_phase_thinking_outputs_indicator(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->setPhase(AgentPhase::Thinking));
        $plain = $this->stripAnsi($output);

        $this->assertStringContainsString('┌', $plain);
        $this->assertStringContainsString('...', $plain);
    }

    public function test_set_phase_idle_without_prior_activity_is_noop(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->setPhase(AgentPhase::Idle));

        $this->assertSame('', $output);
    }

    // ── showThinking ─────────────────────────────────────────────────────

    public function test_show_thinking_outputs_text(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showThinking());
        $plain = $this->stripAnsi($output);

        $this->assertStringContainsString('┌', $plain);
        $this->assertStringContainsString('...', $plain);
    }

    // ── showCompacting ───────────────────────────────────────────────────

    public function test_show_compacting_outputs_text(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showCompacting());

        $this->assertStringContainsString('⧫', $output);
        $this->assertStringContainsString('...', $output);
    }

    // ── showNotice ───────────────────────────────────────────────────────

    public function test_show_notice_outputs_message(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showNotice('Something happened'));

        $this->assertStringContainsString('Something happened', $output);
    }

    // ── showError ────────────────────────────────────────────────────────

    public function test_show_error_outputs_message(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showError('Bad things'));

        $this->assertStringContainsString('Bad things', $output);
        $this->assertStringContainsString('Error', $output);
    }

    // ── streamChunk / streamComplete ─────────────────────────────────────

    public function test_stream_chunk_and_complete_renders_markdown(): void
    {
        $this->renderer->streamChunk('Hello **world**');
        $output = $this->captureOutput(fn () => $this->renderer->streamComplete());

        $this->assertStringContainsString('Hello', $output);
        $this->assertStringContainsString('world', $output);
    }

    public function test_stream_complete_with_empty_buffer_outputs_nothing(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->streamComplete());

        $this->assertSame('', $output);
    }

    public function test_stream_complete_with_ansi_passthrough(): void
    {
        $this->renderer->streamChunk("\x1b[31mRed text\x1b[0m");
        $output = $this->captureOutput(fn () => $this->renderer->streamComplete());

        $this->assertStringContainsString("\x1b[31mRed text\x1b[0m", $output);
    }

    // ── showStatus ───────────────────────────────────────────────────────

    public function test_show_status_outputs_model_and_mode(): void
    {
        $this->renderer->showMode('Edit');
        $output = $this->captureOutput(fn () => $this->renderer->showStatus('claude-3', 5000, 2000, 0.05, 200000));

        $this->assertStringContainsString('claude-3', $output);
        $this->assertStringContainsString('Edit', $output);
    }

    // ── clearConversation ────────────────────────────────────────────────

    public function test_clear_conversation_is_noop(): void
    {
        $this->renderer->clearConversation();
        $this->assertTrue(true);
    }

    // ── replayHistory ────────────────────────────────────────────────────

    public function test_replay_history_empty_messages(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->replayHistory([]));

        // Should produce minimal output (just trailing newline)
        $this->assertIsString($output);
    }

    // ── showToolExecuting / clearToolExecuting ───────────────────────────

    public function test_show_tool_executing_outputs_running_for_normal_tool(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolExecuting('bash'));

        $this->assertStringContainsString('running', $output);
    }

    public function test_show_tool_executing_skips_task_tools(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolExecuting('task_create'));

        $this->assertSame('', $output);
    }

    public function test_show_tool_executing_skips_ask_tools(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolExecuting('ask_user'));

        $this->assertSame('', $output);
    }

    public function test_show_tool_executing_skips_subagent(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolExecuting('subagent'));

        $this->assertSame('', $output);
    }

    public function test_clear_tool_executing_outputs_escape_sequence(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->clearToolExecuting());

        $this->assertStringContainsString("\033[2K", $output);
    }

    // ── updateToolExecuting ──────────────────────────────────────────────

    public function test_update_tool_executing_shows_last_nonempty_line(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->updateToolExecuting("line1\nline2\n  \nline3"));

        $this->assertStringContainsString('line3', $output);
    }

    public function test_update_tool_executing_truncates_long_lines(): void
    {
        $longLine = str_repeat('x', 120);
        $output = $this->captureOutput(fn () => $this->renderer->updateToolExecuting($longLine));

        $this->assertStringContainsString('…', $output);
    }

    public function test_update_tool_executing_empty_output_noop(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->updateToolExecuting(''));

        $this->assertSame('', $output);
    }

    public function test_update_tool_executing_only_whitespace_noop(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->updateToolExecuting("  \n  \n "));

        $this->assertSame('', $output);
    }

    // ── showSubagentRunning ──────────────────────────────────────────────

    public function test_show_subagent_running_empty_is_noop(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentRunning([]));

        $this->assertSame('', $output);
    }

    public function test_show_subagent_running_single_agent(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentRunning([['args' => []]]));

        $this->assertStringContainsString('Running', $output);
    }

    public function test_show_subagent_running_multiple_agents(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentRunning([
            ['args' => []],
            ['args' => []],
        ]));

        $this->assertStringContainsString('2 agents running', $output);
    }

    // ── showSubagentStatus ───────────────────────────────────────────────

    public function test_show_subagent_status_empty_is_noop(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentStatus([]));

        $this->assertSame('', $output);
    }

    // ── showSubagentBatch ────────────────────────────────────────────────

    public function test_show_subagent_batch_empty_is_noop(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentBatch([]));

        $this->assertSame('', $output);
    }

    public function test_show_subagent_batch_filters_background_acks(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentBatch([
            ['success' => true, 'result' => 'spawned in background', 'args' => []],
        ]));

        $this->assertSame('', $output);
    }

    public function test_show_subagent_batch_shows_background_completion(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentBatch([
            [
                'kind' => 'completion',
                'success' => true,
                'result' => 'Background research finished.',
                'args' => ['type' => 'explore', 'task' => 'Search', 'id' => 'agent-1', 'mode' => 'background'],
            ],
        ]));

        $this->assertStringContainsString('agent-1', $output);
        $this->assertStringContainsString('Background research finished.', $output);
    }

    public function test_show_subagent_batch_shows_success(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentBatch([
            ['success' => true, 'result' => 'Done', 'args' => []],
        ]));

        $this->assertStringContainsString('Done', $output);
    }

    // ── showSubagentSpawn ────────────────────────────────────────────────

    public function test_show_subagent_spawn_empty_is_noop(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentSpawn([]));

        $this->assertSame('', $output);
    }

    public function test_show_subagent_spawn_single_agent(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentSpawn([
            ['args' => ['task' => 'Explore files', 'type' => 'explore', 'mode' => 'await']],
        ]));

        $this->assertStringContainsString('⏺', $output);
    }

    public function test_show_subagent_spawn_multiple_agents(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showSubagentSpawn([
            ['args' => ['task' => 'Task A', 'type' => 'explore', 'mode' => 'await']],
            ['args' => ['task' => 'Task B', 'type' => 'general', 'mode' => 'await']],
        ]));

        $this->assertStringContainsString('2 agents', $output);
    }

    // ── showToolCall ─────────────────────────────────────────────────────

    public function test_show_tool_call_outputs_tool_info(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolCall('bash', ['command' => 'npm run build']));
        $plain = $this->stripAnsi($output);

        $this->assertStringContainsString('npm run build', $plain);
    }

    public function test_show_tool_call_skips_content_keys(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolCall('file_edit', [
            'path' => '/tmp/test.php',
            'content' => 'should not appear',
            'old_string' => 'old',
            'new_string' => 'new',
        ]));

        $this->assertStringContainsString('test.php', $output);
        $this->assertStringNotContainsString('should not appear', $output);
        $this->assertStringNotContainsString('old_string', $output);
    }

    public function test_show_tool_call_skips_ask_user(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolCall('ask_user', ['question' => 'What?']));

        $this->assertSame('', $output);
    }

    public function test_show_tool_call_skips_ask_choice(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolCall('ask_choice', ['question' => 'Pick']));

        $this->assertSame('', $output);
    }

    public function test_show_tool_call_skips_subagent(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolCall('subagent', []));

        $this->assertSame('', $output);
    }

    public function test_show_tool_call_truncates_long_values(): void
    {
        $longValue = str_repeat('A', 150);
        $output = $this->captureOutput(fn () => $this->renderer->showToolCall('bash', ['command' => $longValue]));

        $this->assertStringContainsString('…', $output);
        // The original 150 chars should not appear in full
        $this->assertStringNotContainsString($longValue, $output);
    }

    // ── showToolResult ───────────────────────────────────────────────────

    public function test_show_tool_result_outputs_status(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('bash', 'ok', true));

        $this->assertStringContainsString('ok', $output);
    }

    public function test_show_tool_result_failure_outputs_error_icon(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('bash', 'error', false));

        // Failed bash commands show all output — the error text should be visible
        $this->assertStringContainsString('error', $output);
    }

    public function test_show_tool_result_file_read_shows_line_count(): void
    {
        $content = "line1\nline2\nline3";
        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('file_read', $content, true));

        $this->assertStringContainsString('3 lines', $output);
    }

    public function test_show_tool_result_skips_task_tools(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('task_create', 'done', true));

        $this->assertSame('', $output);
    }

    public function test_show_tool_result_skips_ask_tools(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('ask_user', 'answer', true));

        $this->assertSame('', $output);
    }

    public function test_show_tool_result_skips_subagent(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('subagent', 'ok', true));

        $this->assertSame('', $output);
    }

    public function test_show_tool_result_truncates_long_output(): void
    {
        $lines = array_fill(0, 30, 'some output line');
        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('bash', implode("\n", $lines), true));

        $this->assertStringContainsString('+28 lines', $output);
    }

    // ── showToolCall + showToolResult for file_edit with diff ────────────

    public function test_show_tool_result_file_edit_shows_diff(): void
    {
        // First call showToolCall to set lastToolArgs
        $this->captureOutput(fn () => $this->renderer->showToolCall('file_edit', [
            'path' => '/tmp/test.php',
            'old_string' => 'hello world',
            'new_string' => 'hello universe',
        ]));

        $output = $this->captureOutput(fn () => $this->renderer->showToolResult('file_edit', 'saved', true));

        // Diff output should contain some indication of change
        $this->assertNotEmpty($output);
    }

    // ── Private method tests via reflection ──────────────────────────────

    public function test_is_task_tool_identifies_task_tools(): void
    {
        $toolRenderer = new AnsiToolRenderer(fn () => null);
        $method = new \ReflectionMethod(AnsiToolRenderer::class, 'isTaskTool');

        $this->assertTrue($method->invoke($toolRenderer, 'task_create'));
        $this->assertTrue($method->invoke($toolRenderer, 'task_update'));
        $this->assertTrue($method->invoke($toolRenderer, 'task_list'));
        $this->assertTrue($method->invoke($toolRenderer, 'task_get'));
        $this->assertFalse($method->invoke($toolRenderer, 'bash'));
        $this->assertFalse($method->invoke($toolRenderer, 'file_edit'));
        $this->assertFalse($method->invoke($toolRenderer, 'ask_user'));
    }

    public function test_find_choice_from_args_with_string_choices(): void
    {
        $convRenderer = $this->createConversationRenderer();
        $method = new \ReflectionMethod(AnsiConversationRenderer::class, 'findChoiceFromArgs');

        $args = ['choices' => json_encode(['Option A', 'Option B'])];
        $result = $method->invoke($convRenderer, $args, 'Option B');

        $this->assertNotNull($result);
        $this->assertSame('Option B', $result['label']);
        $this->assertNull($result['detail']);
        $this->assertFalse($result['recommended']);
    }

    public function test_find_choice_from_args_with_object_choices(): void
    {
        $convRenderer = $this->createConversationRenderer();
        $method = new \ReflectionMethod(AnsiConversationRenderer::class, 'findChoiceFromArgs');

        $args = ['choices' => json_encode([
            ['label' => 'Yes', 'detail' => 'Proceed', 'recommended' => true],
            ['label' => 'No', 'detail' => 'Cancel'],
        ])];
        $result = $method->invoke($convRenderer, $args, 'Yes');

        $this->assertNotNull($result);
        $this->assertSame('Yes', $result['label']);
        $this->assertSame('Proceed', $result['detail']);
        $this->assertTrue($result['recommended']);
    }

    public function test_find_choice_from_args_returns_null_for_missing(): void
    {
        $convRenderer = $this->createConversationRenderer();
        $method = new \ReflectionMethod(AnsiConversationRenderer::class, 'findChoiceFromArgs');

        $args = ['choices' => json_encode(['A', 'B'])];
        $result = $method->invoke($convRenderer, $args, 'C');

        $this->assertNull($result);
    }

    public function test_find_choice_from_args_returns_null_for_invalid_json(): void
    {
        $convRenderer = $this->createConversationRenderer();
        $method = new \ReflectionMethod(AnsiConversationRenderer::class, 'findChoiceFromArgs');

        $result = $method->invoke($convRenderer, ['choices' => 'not json'], 'A');

        $this->assertNull($result);
    }

    public function test_find_choice_from_args_returns_null_for_missing_key(): void
    {
        $convRenderer = $this->createConversationRenderer();
        $method = new \ReflectionMethod(AnsiConversationRenderer::class, 'findChoiceFromArgs');

        $result = $method->invoke($convRenderer, [], 'A');

        $this->assertNull($result);
    }

    public function test_wrap_with_prefix_short_text(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'wrapWithPrefix');

        $result = $method->invoke($this->renderer, 'Hello world', '  • ', '    ', 100);

        $this->assertCount(1, $result);
        $this->assertSame('  • Hello world', $result[0]);
    }

    public function test_wrap_with_prefix_empty_text(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'wrapWithPrefix');

        $result = $method->invoke($this->renderer, '', '  • ', '    ', 100);

        // Empty text should produce a single line with just the prefix
        $this->assertCount(1, $result);
        $this->assertSame('  • ', $result[0]);
    }

    public function test_wrap_with_prefix_long_text_wraps(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'wrapWithPrefix');

        $text = str_repeat('word ', 30);
        $result = $method->invoke($this->renderer, trim($text), '  • ', '    ', 40);

        $this->assertGreaterThan(1, count($result));
        // First line should use firstPrefix
        $this->assertStringStartsWith('  • ', $result[0]);
        // Second line should use restPrefix
        $this->assertStringStartsWith('    ', $result[1]);
    }

    public function test_wrap_with_prefix_very_long_word_truncates(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'wrapWithPrefix');

        $longWord = str_repeat('a', 200);
        $result = $method->invoke($this->renderer, $longWord, '> ', '  ', 50);

        // Should break the long word
        $this->assertGreaterThan(1, count($result));
    }

    // ── formatTaskToolCallLabel via reflection ───────────────────────────

    public function test_format_task_tool_call_label_task_create_with_subject(): void
    {
        $toolRenderer = new AnsiToolRenderer(fn () => null);
        $method = new \ReflectionMethod(AnsiToolRenderer::class, 'formatTaskToolCallLabel');

        $result = $method->invoke(
            $toolRenderer,
            'task_create',
            ['subject' => 'My Task'],
            '◉',
            'Task create',
            "\033[2m",
            "\033[0m",
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('My Task', $result);
    }

    public function test_format_task_tool_call_label_task_create_with_tasks_json(): void
    {
        $toolRenderer = new AnsiToolRenderer(fn () => null);
        $method = new \ReflectionMethod(AnsiToolRenderer::class, 'formatTaskToolCallLabel');

        $tasks = json_encode([['id' => '1'], ['id' => '2'], ['id' => '3']]);
        $result = $method->invoke(
            $toolRenderer,
            'task_create',
            ['tasks' => $tasks],
            '◉',
            'Task create',
            "\033[2m",
            "\033[0m",
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('3 tasks', $result);
    }

    public function test_format_task_tool_call_label_task_update_in_progress_returns_null(): void
    {
        $toolRenderer = new AnsiToolRenderer(fn () => null);
        $method = new \ReflectionMethod(AnsiToolRenderer::class, 'formatTaskToolCallLabel');

        $result = $method->invoke(
            $toolRenderer,
            'task_update',
            ['status' => 'in_progress', 'id' => 't1'],
            '◉',
            'Task update',
            "\033[2m",
            "\033[0m",
        );

        $this->assertNull($result);
    }

    public function test_format_task_tool_call_label_task_get_returns_null(): void
    {
        $toolRenderer = new AnsiToolRenderer(fn () => null);
        $method = new \ReflectionMethod(AnsiToolRenderer::class, 'formatTaskToolCallLabel');

        $result = $method->invoke(
            $toolRenderer,
            'task_get',
            ['id' => 't1'],
            '◉',
            'Task get',
            "\033[2m",
            "\033[0m",
        );

        $this->assertNull($result);
    }

    public function test_format_task_tool_call_label_task_list_returns_null(): void
    {
        $toolRenderer = new AnsiToolRenderer(fn () => null);
        $method = new \ReflectionMethod(AnsiToolRenderer::class, 'formatTaskToolCallLabel');

        $result = $method->invoke(
            $toolRenderer,
            'task_list',
            [],
            '◉',
            'Task list',
            "\033[2m",
            "\033[0m",
        );

        $this->assertNull($result);
    }

    // ── formatDashboard (pure data transformation) ───────────────────────

    public function test_format_dashboard_with_minimal_data(): void
    {
        $subagentRenderer = new AnsiSubagentRenderer;
        $method = new \ReflectionMethod(AnsiSubagentRenderer::class, 'formatDashboard');

        $summary = [
            'total' => 1,
            'done' => 1,
            'running' => 0,
            'queued' => 0,
            'failed' => 0,
            'retrying' => 0,
            'tokensIn' => 100,
            'tokensOut' => 50,
            'cost' => 0.01,
            'avgCost' => 0.01,
            'elapsed' => 5.0,
            'rate' => 12.0,
            'eta' => 0,
            'active' => [],
            'failures' => [],
            'byType' => [],
            'retriedAndRecovered' => 0,
        ];

        $result = $method->invoke($subagentRenderer, $summary, []);
        $plain = $this->stripAnsi($result);

        $this->assertStringContainsString('S W A R M   C O N T R O L', $plain);
        $this->assertStringContainsString('1 of 1 agents completed', $plain);
        $this->assertStringContainsString('100.0%', $plain);
    }

    public function test_format_dashboard_marks_stale_snapshots(): void
    {
        $subagentRenderer = new AnsiSubagentRenderer;
        $method = new \ReflectionMethod(AnsiSubagentRenderer::class, 'formatDashboard');

        $summary = [
            'total' => 1,
            'done' => 1,
            'running' => 0,
            'queued' => 0,
            'failed' => 0,
            'retrying' => 0,
            'tokensIn' => 100,
            'tokensOut' => 50,
            'cost' => 0.01,
            'avgCost' => 0.01,
            'elapsed' => 5.0,
            'rate' => 12.0,
            'eta' => 0,
            'active' => [],
            'failures' => [],
            'byType' => [],
            'retriedAndRecovered' => 0,
            'stale' => true,
        ];

        $result = $method->invoke($subagentRenderer, $summary, []);
        $plain = $this->stripAnsi($result);

        $this->assertStringContainsString('offline snapshot', $plain);
    }

    public function test_format_dashboard_with_failures(): void
    {
        $subagentRenderer = new AnsiSubagentRenderer;
        $method = new \ReflectionMethod(AnsiSubagentRenderer::class, 'formatDashboard');

        $stats = $this->createMock(SubagentStats::class);
        $stats->agentType = 'general';
        $stats->task = 'Failing task';
        $stats->error = 'Some error message';
        $stats->retries = 2;

        $summary = [
            'total' => 3,
            'done' => 2,
            'running' => 0,
            'queued' => 0,
            'failed' => 1,
            'retrying' => 0,
            'tokensIn' => 500,
            'tokensOut' => 200,
            'cost' => 0.05,
            'avgCost' => 0.017,
            'elapsed' => 30.0,
            'rate' => 4.0,
            'eta' => 10.0,
            'active' => [],
            'failures' => [$stats],
            'byType' => [],
            'retriedAndRecovered' => 0,
        ];

        $result = $method->invoke($subagentRenderer, $summary, []);
        $plain = $this->stripAnsi($result);

        $this->assertStringContainsString('Failures', $plain);
        $this->assertStringContainsString('Failing task', $plain);
        $this->assertStringContainsString('remaining', $plain);
    }

    public function test_format_dashboard_with_by_type_breakdown(): void
    {
        $subagentRenderer = new AnsiSubagentRenderer;
        $method = new \ReflectionMethod(AnsiSubagentRenderer::class, 'formatDashboard');

        $summary = [
            'total' => 4,
            'done' => 2,
            'running' => 1,
            'queued' => 0,
            'failed' => 1,
            'retrying' => 0,
            'tokensIn' => 1000,
            'tokensOut' => 500,
            'cost' => 0.10,
            'avgCost' => 0.025,
            'elapsed' => 60.0,
            'rate' => 2.0,
            'eta' => 0,
            'active' => [],
            'failures' => [],
            'byType' => [
                'general' => ['done' => 1, 'running' => 1, 'queued' => 0, 'tokensIn' => 500, 'tokensOut' => 250],
                'explore' => ['done' => 1, 'running' => 0, 'queued' => 0, 'tokensIn' => 500, 'tokensOut' => 250],
            ],
            'retriedAndRecovered' => 0,
        ];

        $result = $method->invoke($subagentRenderer, $summary, []);
        $plain = $this->stripAnsi($result);

        $this->assertStringContainsString('By Type', $plain);
        $this->assertStringContainsString('General', $plain);
        $this->assertStringContainsString('Explore', $plain);
    }

    public function test_format_dashboard_shows_active_agent_activity(): void
    {
        $subagentRenderer = new AnsiSubagentRenderer;
        $method = new \ReflectionMethod(AnsiSubagentRenderer::class, 'formatDashboard');

        $stats = new SubagentStats('active-1');
        $stats->status = 'running';
        $stats->agentType = 'explore';
        $stats->task = 'Inspect worker logs';
        $stats->toolCalls = 3;
        $stats->startTime = microtime(true) - 10.0;
        $stats->markTool('grep');

        $summary = [
            'total' => 1,
            'done' => 0,
            'running' => 1,
            'queued' => 0,
            'failed' => 0,
            'retrying' => 0,
            'tokensIn' => 0,
            'tokensOut' => 0,
            'cost' => 0.0,
            'avgCost' => 0.0,
            'elapsed' => 10.0,
            'rate' => 1.0,
            'eta' => 0,
            'active' => [$stats],
            'failures' => [],
            'byType' => [],
            'retriedAndRecovered' => 0,
        ];

        $result = $method->invoke($subagentRenderer, $summary, []);
        $plain = $this->stripAnsi($result);

        $this->assertStringContainsString('Active', $plain);
        $this->assertStringContainsString('Inspect worker logs', $plain);
        $this->assertStringContainsString('last grep', $plain);
    }

    // ── Question recap system ────────────────────────────────────────────

    public function test_question_recap_flushed_on_show_notice(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'queueQuestionRecap');

        $method->invoke($this->renderer, 'What is your name?', 'Alice', true);
        $output = $this->captureOutput(fn () => $this->renderer->showNotice('Moving on'));

        $this->assertStringContainsString('What is your name?', $output);
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('1/1 answered', $output);
    }

    public function test_question_recap_dismissed_shows_dim(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'queueQuestionRecap');

        $method->invoke($this->renderer, 'Pick one', '', false);
        $output = $this->captureOutput(fn () => $this->renderer->showNotice('Next'));

        $this->assertStringContainsString('Pick one', $output);
        $this->assertStringContainsString('dismissed', $output);
        $this->assertStringContainsString('0/1 answered', $output);
    }

    public function test_question_recap_recommended_shows_tag(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'queueQuestionRecap');

        $method->invoke($this->renderer, 'Which one?', 'Option A', true, true);
        $output = $this->captureOutput(fn () => $this->renderer->showNotice('Done'));

        $this->assertStringContainsString('Recommended', $output);
    }

    public function test_question_recap_cleared_on_clear_conversation(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'queueQuestionRecap');

        $method->invoke($this->renderer, 'Q?', 'A', true);
        $this->renderer->clearConversation();

        // After clearing, next output should NOT contain the recap
        $output = $this->captureOutput(fn () => $this->renderer->showNotice('Fresh'));

        $this->assertStringNotContainsString('Q?', $output);
    }

    public function test_question_recap_flushed_only_once(): void
    {
        $method = new \ReflectionMethod(AnsiRenderer::class, 'queueQuestionRecap');

        $method->invoke($this->renderer, 'Q1?', 'A1', true);
        $first = $this->captureOutput(fn () => $this->renderer->showNotice('First'));
        $second = $this->captureOutput(fn () => $this->renderer->showNotice('Second'));

        $this->assertStringContainsString('Q1?', $first);
        $this->assertStringNotContainsString('Q1?', $second);
    }

    // ── refreshRuntimeSelection ──────────────────────────────────────────

    public function test_refresh_runtime_selection_without_prior_status(): void
    {
        $output = $this->captureOutput(fn () => $this->renderer->refreshRuntimeSelection('openai', 'gpt-4o', 128000));

        $this->assertStringContainsString('openai/gpt-4o', $output);
    }

    // ── showAgentsDashboard ──────────────────────────────────────────────

    public function test_show_agents_dashboard_outputs_formatted(): void
    {
        $summary = [
            'total' => 2,
            'done' => 2,
            'running' => 0,
            'queued' => 0,
            'failed' => 0,
            'retrying' => 0,
            'tokensIn' => 100,
            'tokensOut' => 50,
            'cost' => 0.01,
            'avgCost' => 0.005,
            'elapsed' => 5.0,
            'rate' => 0.0,
            'eta' => 0,
            'active' => [],
            'failures' => [],
            'byType' => [],
            'retriedAndRecovered' => 0,
        ];

        $output = $this->captureOutput(fn () => $this->renderer->showAgentsDashboard($summary, []));
        $plain = $this->stripAnsi($output);

        $this->assertStringContainsString('S W A R M   C O N T R O L', $plain);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function captureOutput(callable $callback): string
    {
        ob_start();
        $callback();

        return ob_get_clean();
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[^m]*m/', '', $text);
    }

    private function createConversationRenderer(): AnsiConversationRenderer
    {
        $toolRenderer = new AnsiToolRenderer(fn () => null);

        return new AnsiConversationRenderer(
            $toolRenderer,
            fn () => null,
            fn () => null,
            fn (string $q, string $a, bool $answered, bool $recommended) => null,
        );
    }
}
