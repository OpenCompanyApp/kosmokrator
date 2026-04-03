<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\TuiRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure-logic private methods of TuiRenderer.
 *
 * The TUI renderer is tightly coupled to terminal I/O (Tui, EventLoop, widgets),
 * so we only test the methods that can be exercised in isolation via reflection.
 */
final class TuiRendererTest extends TestCase
{
    // ── containsAnsiEscapes ──────────────────────────────────────────────

    public function test_contains_ansi_escapes_detects_csi_sequence(): void
    {
        $r = $this->invoke('containsAnsiEscapes', "\033[38;2;255;0;0mHello");
        $this->assertTrue($r);
    }

    public function test_contains_ansi_escapes_detects_simple_reset(): void
    {
        $r = $this->invoke('containsAnsiEscapes', "plain\033[0m");
        $this->assertTrue($r);
    }

    public function test_contains_ansi_escapes_returns_false_for_plain_text(): void
    {
        $r = $this->invoke('containsAnsiEscapes', 'Hello, world!');
        $this->assertFalse($r);
    }

    public function test_contains_ansi_escapes_returns_false_for_empty_string(): void
    {
        $r = $this->invoke('containsAnsiEscapes', '');
        $this->assertFalse($r);
    }

    public function test_contains_ansi_escapes_returns_false_for_bare_escape(): void
    {
        // \x1b without [ is not a CSI sequence
        $r = $this->invoke('containsAnsiEscapes', "\x1b");
        $this->assertFalse($r);
    }

    // ── isTaskTool ───────────────────────────────────────────────────────

    public function test_is_task_tool_recognizes_all_task_tools(): void
    {
        foreach (['task_create', 'task_update', 'task_list', 'task_get'] as $name) {
            $this->assertTrue($this->invoke('isTaskTool', $name), "Failed for {$name}");
        }
    }

    public function test_is_task_tool_rejects_non_task_tools(): void
    {
        foreach (['file_read', 'bash', 'subagent', 'ask_user', 'file_edit'] as $name) {
            $this->assertFalse($this->invoke('isTaskTool', $name), "Failed for {$name}");
        }
    }

    // ── isOmensTool (delegates to ExplorationClassifier) ──────────────────

    public function test_is_omens_tool_delegates_read_tools(): void
    {
        $this->assertTrue($this->invoke('isOmensTool', 'file_read', ['path' => 'src/Foo.php']));
        $this->assertTrue($this->invoke('isOmensTool', 'glob', ['pattern' => '*.php']));
        $this->assertTrue($this->invoke('isOmensTool', 'grep', ['pattern' => 'foo']));
    }

    public function test_is_omens_tool_rejects_write_tools(): void
    {
        $this->assertFalse($this->invoke('isOmensTool', 'file_write', ['path' => 'x.php']));
        $this->assertFalse($this->invoke('isOmensTool', 'file_edit', ['path' => 'x.php']));
    }

    // ── findChoice ───────────────────────────────────────────────────────

    public function test_find_choice_finds_matching_label(): void
    {
        $choices = [
            ['label' => 'Option A', 'detail' => null],
            ['label' => 'Option B', 'detail' => 'desc'],
        ];
        $result = $this->invoke('findChoice', $choices, 'Option B');
        $this->assertNotNull($result);
        $this->assertSame('Option B', $result['label']);
        $this->assertSame('desc', $result['detail']);
    }

    public function test_find_choice_returns_null_for_no_match(): void
    {
        $choices = [
            ['label' => 'Option A', 'detail' => null],
        ];
        $this->assertNull($this->invoke('findChoice', $choices, 'Missing'));
    }

    public function test_find_choice_returns_null_for_empty_choices(): void
    {
        $this->assertNull($this->invoke('findChoice', [], 'anything'));
    }

    public function test_find_choice_returns_first_match(): void
    {
        $choices = [
            ['label' => 'Same', 'detail' => 'first'],
            ['label' => 'Same', 'detail' => 'second'],
        ];
        $result = $this->invoke('findChoice', $choices, 'Same');
        $this->assertSame('first', $result['detail']);
    }

    // ── findChoiceFromArgs ───────────────────────────────────────────────

    public function test_find_choice_from_args_parses_json_choices(): void
    {
        $json = json_encode([
            ['label' => 'Yes', 'detail' => 'Proceed'],
            ['label' => 'No', 'detail' => 'Abort'],
        ]);
        $args = ['choices' => $json];
        $result = $this->invoke('findChoiceFromArgs', $args, 'Yes');
        $this->assertNotNull($result);
        $this->assertSame('Yes', $result['label']);
        $this->assertSame('Proceed', $result['detail']);
    }

    public function test_find_choice_from_args_handles_string_items(): void
    {
        $json = json_encode(['Alpha', 'Beta']);
        $args = ['choices' => $json];
        $result = $this->invoke('findChoiceFromArgs', $args, 'Beta');
        $this->assertNotNull($result);
        $this->assertSame('Beta', $result['label']);
        $this->assertNull($result['detail']);
    }

    public function test_find_choice_from_args_returns_null_on_invalid_json(): void
    {
        $args = ['choices' => 'not-json'];
        $this->assertNull($this->invoke('findChoiceFromArgs', $args, 'anything'));
    }

    public function test_find_choice_from_args_returns_null_on_missing_key(): void
    {
        $this->assertNull($this->invoke('findChoiceFromArgs', [], 'anything'));
    }

    // ── inferHistoricToolSuccess ─────────────────────────────────────────

    public function test_infer_historic_tool_success_returns_true_for_non_object(): void
    {
        $this->assertTrue($this->invoke('inferHistoricToolSuccess', 'file_read', 'string result'));
        $this->assertTrue($this->invoke('inferHistoricToolSuccess', 'file_read', null));
        $this->assertTrue($this->invoke('inferHistoricToolSuccess', 'file_read', 42));
    }

    public function test_infer_historic_tool_success_returns_false_for_error_prefix(): void
    {
        $obj = (object) ['result' => 'Error: something went wrong'];
        $this->assertFalse($this->invoke('inferHistoricToolSuccess', 'file_read', $obj));
    }

    public function test_infer_historic_tool_success_returns_false_for_invalid_memory_search(): void
    {
        $obj = (object) ['result' => 'Invalid query parameter'];
        $this->assertFalse($this->invoke('inferHistoricToolSuccess', 'memory_search', $obj));
    }

    public function test_infer_historic_tool_success_returns_true_for_normal_output(): void
    {
        $obj = (object) ['result' => '42 lines of content'];
        $this->assertTrue($this->invoke('inferHistoricToolSuccess', 'file_read', $obj));
    }

    public function test_infer_historic_tool_success_returns_true_for_non_string_result(): void
    {
        $obj = (object) ['result' => ['array', 'data']];
        $this->assertTrue($this->invoke('inferHistoricToolSuccess', 'file_read', $obj));
    }

    public function test_infer_historic_tool_success_does_not_flag_invalid_for_other_tools(): void
    {
        $obj = (object) ['result' => 'Invalid something'];
        // Only memory_search checks "Invalid " prefix
        $this->assertTrue($this->invoke('inferHistoricToolSuccess', 'bash', $obj));
    }

    // ── cycleMode ────────────────────────────────────────────────────────

    public function test_cycle_mode_edit_to_plan(): void
    {
        $renderer = $this->createRendererWithMode('Edit');
        $result = $this->invokeOn($renderer, 'cycleMode');
        $this->assertSame('plan', $result);
    }

    public function test_cycle_mode_plan_to_ask(): void
    {
        $renderer = $this->createRendererWithMode('Plan');
        $result = $this->invokeOn($renderer, 'cycleMode');
        $this->assertSame('ask', $result);
    }

    public function test_cycle_mode_ask_back_to_edit(): void
    {
        $renderer = $this->createRendererWithMode('Ask');
        $result = $this->invokeOn($renderer, 'cycleMode');
        $this->assertSame('edit', $result);
    }

    // ── summarizeDiscoveryResult ─────────────────────────────────────────

    public function test_summarize_discovery_result_file_read_counts_lines(): void
    {
        $output = "line1\nline2\nline3\n";
        $result = $this->invoke('summarizeDiscoveryResult', 'file_read', $output, true);
        $this->assertSame('3 lines', $result);
    }

    public function test_summarize_discovery_result_file_read_ignores_blank_lines(): void
    {
        $output = "line1\n\nline2\n\n";
        $result = $this->invoke('summarizeDiscoveryResult', 'file_read', $output, true);
        $this->assertSame('2 lines', $result);
    }

    public function test_summarize_discovery_result_glob_counts_files(): void
    {
        $output = "src/A.php\nsrc/B.php\nsrc/C.php";
        $result = $this->invoke('summarizeDiscoveryResult', 'glob', $output, true);
        $this->assertSame('3 files', $result);
    }

    public function test_summarize_discovery_result_glob_single_file(): void
    {
        $output = "src/A.php";
        $result = $this->invoke('summarizeDiscoveryResult', 'glob', $output, true);
        $this->assertSame('1 file', $result);
    }

    public function test_summarize_discovery_result_glob_empty(): void
    {
        $result = $this->invoke('summarizeDiscoveryResult', 'glob', '', true);
        $this->assertSame('0 files', $result);
    }

    public function test_summarize_discovery_result_glob_no_files_matching(): void
    {
        $result = $this->invoke('summarizeDiscoveryResult', 'glob', 'No files matching *.xyz', true);
        $this->assertSame('0 files', $result);
    }

    public function test_summarize_discovery_result_grep_counts_matches(): void
    {
        $output = "src/A.php:1:foo\nsrc/B.php:5:foo";
        $result = $this->invoke('summarizeDiscoveryResult', 'grep', $output, true);
        $this->assertSame('2 matches', $result);
    }

    public function test_summarize_discovery_result_grep_no_matches(): void
    {
        $result = $this->invoke('summarizeDiscoveryResult', 'grep', 'No matches found for pattern', true);
        $this->assertSame('0 matches', $result);
    }

    public function test_summarize_discovery_result_bash_counts_lines(): void
    {
        $output = "output line 1\noutput line 2\noutput line 3";
        $result = $this->invoke('summarizeDiscoveryResult', 'bash', $output, true);
        $this->assertSame('3 lines', $result);
    }

    public function test_summarize_discovery_result_bash_empty(): void
    {
        $result = $this->invoke('summarizeDiscoveryResult', 'bash', '', true);
        $this->assertSame('0 lines', $result);
    }

    public function test_summarize_discovery_result_error(): void
    {
        $result = $this->invoke('summarizeDiscoveryResult', 'file_read', '', false);
        $this->assertSame('error', $result);
    }

    public function test_summarize_discovery_result_unknown_tool(): void
    {
        $result = $this->invoke('summarizeDiscoveryResult', 'unknown_tool', 'some output', true);
        $this->assertSame('', $result);
    }

    // ── summarizeMemorySearchResult ──────────────────────────────────────

    public function test_summarize_memory_search_found_count(): void
    {
        $result = $this->invoke('summarizeMemorySearchResult', "Found 3 memories:\n- one\n- two\n- three");
        $this->assertSame('3 recalls', $result);
    }

    public function test_summarize_memory_search_single_result(): void
    {
        $result = $this->invoke('summarizeMemorySearchResult', "Found 1 memories:\n- one");
        $this->assertSame('1 recall', $result);
    }

    public function test_summarize_memory_search_no_memories(): void
    {
        $result = $this->invoke('summarizeMemorySearchResult', 'No memories found.');
        $this->assertSame('0 recalls', $result);
    }

    public function test_summarize_memory_search_no_session_history(): void
    {
        $result = $this->invoke('summarizeMemorySearchResult', 'No session history matches found.');
        $this->assertSame('0 recalls', $result);
    }

    public function test_summarize_memory_search_empty_output(): void
    {
        $result = $this->invoke('summarizeMemorySearchResult', '');
        $this->assertSame('0 recalls', $result);
    }

    public function test_summarize_memory_search_fallback_to_line_count(): void
    {
        $output = "some line\nanother line";
        $result = $this->invoke('summarizeMemorySearchResult', $output);
        $this->assertSame('2 lines', $result);
    }

    // ── countNonEmptyLines ───────────────────────────────────────────────

    public function test_count_non_empty_lines_basic(): void
    {
        $this->assertSame(3, $this->invoke('countNonEmptyLines', "a\nb\nc"));
    }

    public function test_count_non_empty_lines_ignores_blank(): void
    {
        $this->assertSame(2, $this->invoke('countNonEmptyLines', "a\n\n  \nb"));
    }

    public function test_count_non_empty_lines_empty_string(): void
    {
        $this->assertSame(0, $this->invoke('countNonEmptyLines', ''));
    }

    public function test_count_non_empty_lines_trailing_newline(): void
    {
        $this->assertSame(2, $this->invoke('countNonEmptyLines', "a\nb\n"));
    }

    // ── formatDiscoveryReadLabel ─────────────────────────────────────────

    public function test_format_discovery_read_label_basic_path(): void
    {
        $result = $this->invoke('formatDiscoveryReadLabel', ['path' => 'src/Foo.php']);
        $this->assertStringContainsString('Foo.php', $result);
    }

    public function test_format_discovery_read_label_with_offset(): void
    {
        $result = $this->invoke('formatDiscoveryReadLabel', ['path' => 'src/Foo.php', 'offset' => 42]);
        $this->assertStringContainsString(':42', $result);
    }

    // ── formatDiscoveryGlobLabel ─────────────────────────────────────────

    public function test_format_discovery_glob_label_pattern_only(): void
    {
        $result = $this->invoke('formatDiscoveryGlobLabel', ['pattern' => '*.php', 'path' => '']);
        $this->assertSame('*.php', $result);
    }

    public function test_format_discovery_glob_label_with_path(): void
    {
        $result = $this->invoke('formatDiscoveryGlobLabel', ['pattern' => '*.php', 'path' => 'src']);
        $this->assertStringContainsString('*.php', $result);
        $this->assertStringContainsString('in', $result);
    }

    public function test_format_discovery_glob_label_dot_path_collapsed(): void
    {
        $result = $this->invoke('formatDiscoveryGlobLabel', ['pattern' => '*.php', 'path' => '.']);
        $this->assertSame('*.php', $result);
    }

    // ── formatDiscoveryGrepLabel ─────────────────────────────────────────

    public function test_format_discovery_grep_label_basic(): void
    {
        $result = $this->invoke('formatDiscoveryGrepLabel', ['pattern' => 'foo', 'path' => '']);
        $this->assertSame('"foo"', $result);
    }

    public function test_format_discovery_grep_label_with_glob(): void
    {
        $result = $this->invoke('formatDiscoveryGrepLabel', ['pattern' => 'foo', 'path' => '', 'glob' => '*.php']);
        $this->assertStringContainsString('(*.php)', $result);
    }

    // ── formatDiscoveryBashLabel ─────────────────────────────────────────

    public function test_format_discovery_bash_label_short_command(): void
    {
        $result = $this->invoke('formatDiscoveryBashLabel', ['command' => 'ls -la']);
        $this->assertSame('ls -la', $result);
    }

    public function test_format_discovery_bash_label_empty_command(): void
    {
        $result = $this->invoke('formatDiscoveryBashLabel', ['command' => '']);
        $this->assertSame('shell probe', $result);
    }

    public function test_format_discovery_bash_label_long_command_truncated(): void
    {
        $longCommand = str_repeat('x', 100);
        $result = $this->invoke('formatDiscoveryBashLabel', ['command' => $longCommand]);
        $this->assertSame(90 + mb_strlen('…'), mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
    }

    // ── formatDiscoveryMemoryLabel ───────────────────────────────────────

    public function test_format_discovery_memory_label_with_query(): void
    {
        $result = $this->invoke('formatDiscoveryMemoryLabel', ['query' => 'tui rendering']);
        $this->assertSame('"tui rendering"', $result);
    }

    public function test_format_discovery_memory_label_with_query_and_scope(): void
    {
        $result = $this->invoke('formatDiscoveryMemoryLabel', ['query' => 'tui', 'scope' => 'project']);
        $this->assertStringContainsString('in project', $result);
    }

    public function test_format_discovery_memory_label_with_type_and_class(): void
    {
        $result = $this->invoke('formatDiscoveryMemoryLabel', ['type' => 'project', 'class' => 'priority']);
        $this->assertSame('project · priority', $result);
    }

    public function test_format_discovery_memory_label_defaults(): void
    {
        $result = $this->invoke('formatDiscoveryMemoryLabel', []);
        $this->assertSame('saved memories', $result);
    }

    // ── normalizeDiscoveryPath ───────────────────────────────────────────

    public function test_normalize_discovery_path_empty(): void
    {
        $this->assertSame('.', $this->invoke('normalizeDiscoveryPath', ''));
    }

    public function test_normalize_discovery_path_dot(): void
    {
        $this->assertSame('.', $this->invoke('normalizeDiscoveryPath', '.'));
    }

    public function test_normalize_discovery_path_real_path(): void
    {
        $result = $this->invoke('normalizeDiscoveryPath', 'src/UI');
        // Returns the result of Theme::relativePath — at minimum it should contain the path
        $this->assertStringContainsString('src/UI', $result);
    }

    // ── summarizeCountedResult ───────────────────────────────────────────

    public function test_summarize_counted_result_empty(): void
    {
        $result = $this->invoke('summarizeCountedResult', '', 'item', 'items', 'None');
        $this->assertSame('0 items', $result);
    }

    public function test_summarize_counted_result_empty_prefix(): void
    {
        $result = $this->invoke('summarizeCountedResult', 'None found', 'item', 'items', 'None');
        $this->assertSame('0 items', $result);
    }

    public function test_summarize_counted_result_single(): void
    {
        $result = $this->invoke('summarizeCountedResult', "onlyone", 'item', 'items', 'None');
        $this->assertSame('1 item', $result);
    }

    public function test_summarize_counted_result_plural(): void
    {
        $result = $this->invoke('summarizeCountedResult', "a\nb\nc", 'item', 'items', 'None');
        $this->assertSame('3 items', $result);
    }

    // ── SLASH_COMMANDS constant ──────────────────────────────────────────

    public function test_slash_commands_contains_core_commands(): void
    {
        $ref = new \ReflectionClass(TuiRenderer::class);
        $constants = $ref->getConstants();
        $this->assertArrayHasKey('SLASH_COMMANDS', $constants);

        $commands = $constants['SLASH_COMMANDS'];
        $values = array_column($commands, 'value');

        $this->assertContains('/edit', $values);
        $this->assertContains('/plan', $values);
        $this->assertContains('/ask', $values);
        $this->assertContains('/guardian', $values);
        $this->assertContains('/argus', $values);
        $this->assertContains('/prometheus', $values);
        $this->assertContains('/compact', $values);
        $this->assertContains('/new', $values);
        $this->assertContains('/clear', $values);
        $this->assertContains('/quit', $values);
        $this->assertContains('/settings', $values);
        $this->assertContains('/resume', $values);
        $this->assertContains('/sessions', $values);
        $this->assertContains('/memories', $values);
        $this->assertContains('/agents', $values);
    }

    public function test_slash_commands_all_have_required_keys(): void
    {
        $ref = new \ReflectionClass(TuiRenderer::class);
        $commands = $ref->getConstant('SLASH_COMMANDS');

        foreach ($commands as $i => $cmd) {
            $this->assertArrayHasKey('value', $cmd, "Command at index {$i} missing 'value'");
            $this->assertArrayHasKey('label', $cmd, "Command at index {$i} missing 'label'");
            $this->assertArrayHasKey('description', $cmd, "Command at index {$i} missing 'description'");
        }
    }

    // ── updateToolExecuting (pure preview extraction) ────────────────────

    public function test_update_tool_executing_extracts_last_non_empty_line(): void
    {
        // updateToolExecuting sets an internal property; we test by building the renderer
        // and checking the preview string through a side-channel
        $renderer = new TuiRenderer;

        // The method sets $this->toolExecutingPreview. We'll invoke it and read the property.
        $this->invokeOn($renderer, 'updateToolExecuting', "line1\nline2\nline3");
        $preview = $this->getProperty($renderer, 'toolExecutingPreview');
        $this->assertSame('line3', $preview);
    }

    public function test_update_tool_executing_skips_trailing_blank_lines(): void
    {
        $renderer = new TuiRenderer;
        $this->invokeOn($renderer, 'updateToolExecuting', "line1\n  \n");
        $preview = $this->getProperty($renderer, 'toolExecutingPreview');
        $this->assertSame('line1', $preview);
    }

    public function test_update_tool_executing_truncates_long_line(): void
    {
        $renderer = new TuiRenderer;
        $long = str_repeat('x', 120);
        $this->invokeOn($renderer, 'updateToolExecuting', $long);
        $preview = $this->getProperty($renderer, 'toolExecutingPreview');
        $this->assertSame(101, mb_strlen($preview)); // 100 + '…'
        $this->assertStringEndsWith('…', $preview);
    }

    public function test_update_tool_executing_empty_output(): void
    {
        $renderer = new TuiRenderer;
        $this->invokeOn($renderer, 'updateToolExecuting', '');
        $preview = $this->getProperty($renderer, 'toolExecutingPreview');
        $this->assertNull($preview);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Invoke a private static/instance method on a fresh TuiRenderer via reflection.
     * For methods that don't need $this state (pure functions).
     */
    private function invoke(string $method, mixed ...$args): mixed
    {
        return $this->invokeOn(new TuiRenderer, $method, ...$args);
    }

    /**
     * Invoke a private method on a specific TuiRenderer instance.
     */
    private function invokeOn(TuiRenderer $renderer, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($renderer, $method);

        return $ref->invoke($renderer, ...$args);
    }

    /**
     * Get the value of a private property.
     */
    private function getProperty(TuiRenderer $renderer, string $property): mixed
    {
        $ref = new \ReflectionProperty($renderer, $property);

        return $ref->getValue($renderer);
    }

    /**
     * Create a TuiRenderer with a specific mode label set (for cycleMode tests).
     */
    private function createRendererWithMode(string $label): TuiRenderer
    {
        $renderer = new TuiRenderer;
        $prop = new \ReflectionProperty($renderer, 'currentModeLabel');
        $prop->setValue($renderer, $label);

        return $renderer;
    }
}
