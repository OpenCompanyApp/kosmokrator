<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI;

use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\UI\AgentDisplayFormatter;
use Kosmokrator\UI\Theme;
use PHPUnit\Framework\TestCase;

class AgentDisplayFormatterTest extends TestCase
{
    private AgentDisplayFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new AgentDisplayFormatter;
    }

    // ── summarizeAgentTypes ─────────��────────────────────��───────────

    public function test_summarize_single_type_single_entry(): void
    {
        $entries = [
            ['args' => ['type' => 'explore']],
        ];

        $this->assertSame('Explore agent', $this->formatter->summarizeAgentTypes($entries));
    }

    public function test_summarize_single_type_multiple_entries(): void
    {
        $entries = [
            ['args' => ['type' => 'explore']],
            ['args' => ['type' => 'explore']],
        ];

        $this->assertSame('Explore agents', $this->formatter->summarizeAgentTypes($entries));
    }

    public function test_summarize_multiple_types(): void
    {
        $entries = [
            ['args' => ['type' => 'explore']],
            ['args' => ['type' => 'explore']],
            ['args' => ['type' => 'general']],
        ];

        $this->assertSame('2 Explore + 1 General agents', $this->formatter->summarizeAgentTypes($entries));
    }

    public function test_summarize_defaults_to_explore_when_type_missing(): void
    {
        $entries = [
            ['args' => []],
        ];

        $this->assertSame('Explore agent', $this->formatter->summarizeAgentTypes($entries));
    }

    // ── extractResultPreview ─────────────────────────────────────────

    public function test_extract_preview_skips_headers_and_empty_lines(): void
    {
        $output = "# Title\n\n---\n\nSome actual content";
        $this->assertSame('Some actual content', $this->formatter->extractResultPreview($output));
    }

    public function test_extract_preview_truncates_at_80_chars(): void
    {
        $longLine = str_repeat('x', 100);
        $result = $this->formatter->extractResultPreview($longLine);

        $this->assertSame(83, strlen($result)); // 80 chars + '...'
        $this->assertStringEndsWith('...', $result);
        $this->assertSame(str_repeat('x', 80).'...', $result);
    }

    public function test_extract_preview_returns_empty_for_empty_input(): void
    {
        $this->assertSame('', $this->formatter->extractResultPreview(''));
        $this->assertSame('', $this->formatter->extractResultPreview('   '));
        $this->assertSame('', $this->formatter->extractResultPreview("\n\n"));
    }

    public function test_extract_preview_strips_leading_list_markers(): void
    {
        $this->assertSame('item text', $this->formatter->extractResultPreview('- item text'));
        $this->assertSame('item text', $this->formatter->extractResultPreview('* item text'));
    }

    public function test_extract_preview_skips_horizontal_rules(): void
    {
        $this->assertSame('', $this->formatter->extractResultPreview('---'));
        $this->assertSame('after rule', $this->formatter->extractResultPreview("---\nafter rule"));
    }

    // ── renderChildTree ──────────────────────────────────────────────

    public function test_render_child_tree_basic_structure(): void
    {
        $children = [
            ['type' => 'explore', 'task' => 'find files', 'success' => true, 'elapsed' => 5.0],
        ];

        $result = $this->formatter->renderChildTree($children, '');

        $this->assertStringContainsString('└─', $result);
        $this->assertStringContainsString('✓', $result);
        $this->assertStringContainsString('Explore', $result);
        $this->assertStringContainsString('find files', $result);
        $this->assertStringContainsString('5s', $result);
    }

    public function test_render_child_tree_shows_summary_nodes_neutrally(): void
    {
        $children = [
            [
                'status' => 'summary',
                'type' => 'summary',
                'task' => '12 more agents (10 done, 2 queued)',
                'success' => true,
                'elapsed' => 0.0,
                'hiddenCount' => 12,
                'hiddenStatuses' => ['done' => 10, 'queued' => 2],
            ],
        ];

        $result = $this->formatter->renderChildTree($children, '');

        $this->assertStringContainsString('12 more agents', $result);
        $this->assertStringContainsString('…', $result);
        $this->assertStringNotContainsString('✗', $result);
    }

    public function test_render_child_tree_multiple_uses_branch_connectors(): void
    {
        $children = [
            ['type' => 'explore', 'task' => 'task a', 'success' => true, 'elapsed' => 0.0],
            ['type' => 'general', 'task' => 'task b', 'success' => false, 'elapsed' => 10.0],
        ];

        $result = $this->formatter->renderChildTree($children, '');

        $this->assertStringContainsString('├─', $result);
        $this->assertStringContainsString('└─', $result);
        $this->assertStringContainsString('✓', $result);
        $this->assertStringContainsString('✗', $result);
    }

    public function test_render_child_tree_with_nested_children(): void
    {
        $children = [
            [
                'type' => 'general',
                'task' => 'parent task',
                'success' => true,
                'elapsed' => 2.0,
                'children' => [
                    ['type' => 'explore', 'task' => 'child task', 'success' => true, 'elapsed' => 1.0],
                ],
            ],
        ];

        $result = $this->formatter->renderChildTree($children, '');

        $this->assertStringContainsString('parent task', $result);
        $this->assertStringContainsString('child task', $result);
        // Last child uses └─ and continuation is spaces, nested child also gets └─
        $this->assertStringContainsString('└─', $result);
    }

    public function test_render_child_tree_nested_with_siblings_shows_pipe(): void
    {
        $children = [
            [
                'type' => 'general',
                'task' => 'parent task',
                'success' => true,
                'elapsed' => 2.0,
                'children' => [
                    ['type' => 'explore', 'task' => 'child task', 'success' => true, 'elapsed' => 1.0],
                ],
            ],
            [
                'type' => 'explore',
                'task' => 'sibling task',
                'success' => true,
                'elapsed' => 3.0,
            ],
        ];

        $result = $this->formatter->renderChildTree($children, '');

        // First child uses ├─ so continuation should contain │ for its nested children
        $this->assertStringContainsString('│', $result);
        $this->assertStringContainsString('child task', $result);
    }

    public function test_render_child_tree_truncates_long_task(): void
    {
        $longTask = str_repeat('a', 50);
        $children = [
            ['type' => 'explore', 'task' => $longTask, 'success' => true, 'elapsed' => 0.0],
        ];

        $result = $this->formatter->renderChildTree($children, '');
        // Task should be truncated to 40 chars + '…'
        $this->assertStringContainsString('…', $result);
    }

    public function test_render_child_tree_empty_children(): void
    {
        $this->assertSame('', $this->formatter->renderChildTree([], ''));
    }

    public function test_render_child_tree_no_elapsed_when_zero(): void
    {
        $children = [
            ['type' => 'explore', 'task' => 'task', 'success' => true, 'elapsed' => 0.0],
        ];

        $result = $this->formatter->renderChildTree($children, '');
        // Should not contain parenthetical time when elapsed is 0
        $this->assertStringNotContainsString('(0s)', $result);
    }

    // ── formatAgentLabel ─────────────────────────────────────────────

    public function test_format_agent_label_with_all_args(): void
    {
        $result = $this->formatter->formatAgentLabel([
            'type' => 'general',
            'id' => 'agent-1',
            'task' => 'do something',
        ]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        [$label, $typeColor] = $result;

        $this->assertStringContainsString('General', $label);
        $this->assertStringContainsString('agent-1', $label);
        $this->assertStringContainsString('do something', $label);
        // General type color
        $this->assertSame("\033[38;2;218;165;32m", $typeColor);
    }

    public function test_format_agent_label_without_id(): void
    {
        $result = $this->formatter->formatAgentLabel([
            'type' => 'explore',
            'task' => 'search code',
        ]);

        [$label, $typeColor] = $result;

        $this->assertStringContainsString('Explore', $label);
        $this->assertStringNotContainsString('·', explode(Theme::dim(), $label)[0]); // no id segment before dot
        $this->assertStringContainsString('search code', $label);
        // Default type color (explore)
        $this->assertSame("\033[38;2;100;200;220m", $typeColor);
    }

    public function test_format_agent_label_plan_type_color(): void
    {
        [, $typeColor] = $this->formatter->formatAgentLabel([
            'type' => 'plan',
            'task' => 'plan task',
        ]);

        $this->assertSame("\033[38;2;160;120;255m", $typeColor);
    }

    public function test_format_agent_label_truncates_long_task(): void
    {
        $longTask = str_repeat('z', 60);
        [$label] = $this->formatter->formatAgentLabel([
            'type' => 'explore',
            'task' => $longTask,
        ]);

        $this->assertStringContainsString('...', $label);
    }

    public function test_format_agent_label_defaults_to_explore(): void
    {
        [$label] = $this->formatter->formatAgentLabel([]);
        $this->assertStringContainsString('Explore', $label);
    }

    // ── formatElapsed ────────────────────────────────────────────────

    public function test_format_elapsed_seconds_only(): void
    {
        $this->assertSame('42s', $this->formatter->formatElapsed(42.0));
        $this->assertSame('0s', $this->formatter->formatElapsed(0.0));
        $this->assertSame('59s', $this->formatter->formatElapsed(59.9));
    }

    public function test_format_elapsed_minutes_and_seconds(): void
    {
        $this->assertSame('1m 30s', $this->formatter->formatElapsed(90.0));
        $this->assertSame('5m 0s', $this->formatter->formatElapsed(300.0));
    }

    public function test_format_elapsed_hours_and_minutes(): void
    {
        $this->assertSame('1h 5m', $this->formatter->formatElapsed(3900.0));
        $this->assertSame('2h 0m', $this->formatter->formatElapsed(7200.0));
    }

    // ── formatAgentStats ─────────────────────────────────────────────

    public function test_format_agent_stats_with_subagent_stats(): void
    {
        $stats = new SubagentStats('test-id');
        $stats->toolCalls = 5;
        $stats->startTime = 100.0;
        $stats->endTime = 142.0; // elapsed = 42s

        $result = $this->formatter->formatAgentStats(['stats' => $stats]);

        $this->assertStringContainsString('42s', $result);
        $this->assertStringContainsString('5 tools', $result);
    }

    public function test_format_agent_stats_singular_tool_call(): void
    {
        $stats = new SubagentStats('test-id');
        $stats->toolCalls = 1;
        $stats->startTime = 100.0;
        $stats->endTime = 105.0;

        $result = $this->formatter->formatAgentStats(['stats' => $stats]);

        $this->assertStringContainsString('1 tool', $result);
        $this->assertStringNotContainsString('1 tools', $result);
    }

    public function test_format_agent_stats_without_stats_returns_empty(): void
    {
        $this->assertSame('', $this->formatter->formatAgentStats([]));
        $this->assertSame('', $this->formatter->formatAgentStats(['stats' => null]));
    }

    // ── formatCoordinationTags ───────────────────────────────────────

    public function test_format_coordination_tags_with_depends_on(): void
    {
        $result = $this->formatter->formatCoordinationTags([
            'depends_on' => ['id1', 'id2'],
        ]);

        $this->assertStringContainsString('depends on: id1, id2', $result);
    }

    public function test_format_coordination_tags_with_group(): void
    {
        $result = $this->formatter->formatCoordinationTags([
            'group' => 'writers',
        ]);

        $this->assertStringContainsString('group: writers', $result);
    }

    public function test_format_coordination_tags_both_depends_and_group(): void
    {
        $result = $this->formatter->formatCoordinationTags([
            'depends_on' => ['alpha'],
            'group' => 'writers',
        ]);

        $this->assertStringContainsString('depends on: alpha', $result);
        $this->assertStringContainsString('group: writers', $result);
        $this->assertStringContainsString('·', $result);
    }

    public function test_format_coordination_tags_empty_returns_empty(): void
    {
        $this->assertSame('', $this->formatter->formatCoordinationTags([]));
        $this->assertSame('', $this->formatter->formatCoordinationTags(['depends_on' => [], 'group' => '']));
    }

    // ── countNodes ───────────────────────────────────────────────────

    public function test_count_nodes_flat(): void
    {
        $nodes = [
            ['type' => 'a'],
            ['type' => 'b'],
            ['type' => 'c'],
        ];

        $this->assertSame(3, $this->formatter->countNodes($nodes));
    }

    public function test_count_nodes_empty(): void
    {
        $this->assertSame(0, $this->formatter->countNodes([]));
    }

    public function test_count_nodes_nested(): void
    {
        $nodes = [
            ['type' => 'a', 'children' => [
                ['type' => 'b'],
                ['type' => 'c', 'children' => [
                    ['type' => 'd'],
                ]],
            ]],
            ['type' => 'e'],
        ];

        // a, b, c, d, e = 5
        $this->assertSame(5, $this->formatter->countNodes($nodes));
    }

    public function test_count_nodes_includes_summary_hidden_count(): void
    {
        $nodes = [
            ['type' => 'a'],
            ['status' => 'summary', 'hiddenCount' => 9],
        ];

        $this->assertSame(10, $this->formatter->countNodes($nodes));
    }

    // ── countByStatus ────────────────────────────────────────────────

    public function test_count_by_status_matching_flat(): void
    {
        $nodes = [
            ['status' => 'completed'],
            ['status' => 'running'],
            ['status' => 'completed'],
        ];

        $this->assertSame(2, $this->formatter->countByStatus($nodes, 'completed'));
        $this->assertSame(1, $this->formatter->countByStatus($nodes, 'running'));
        $this->assertSame(0, $this->formatter->countByStatus($nodes, 'failed'));
    }

    public function test_count_by_status_nested(): void
    {
        $nodes = [
            ['status' => 'completed', 'children' => [
                ['status' => 'failed'],
                ['status' => 'completed', 'children' => [
                    ['status' => 'completed'],
                ]],
            ]],
            ['status' => 'failed'],
        ];

        $this->assertSame(3, $this->formatter->countByStatus($nodes, 'completed'));
        $this->assertSame(2, $this->formatter->countByStatus($nodes, 'failed'));
    }

    public function test_count_by_status_includes_summary_hidden_statuses(): void
    {
        $nodes = [
            ['status' => 'running'],
            ['status' => 'summary', 'hiddenStatuses' => ['done' => 7, 'queued' => 3]],
        ];

        $this->assertSame(1, $this->formatter->countByStatus($nodes, 'running'));
        $this->assertSame(7, $this->formatter->countByStatus($nodes, 'done'));
        $this->assertSame(3, $this->formatter->countByStatus($nodes, 'queued'));
    }

    public function test_count_by_status_empty_nodes(): void
    {
        $this->assertSame(0, $this->formatter->countByStatus([], 'completed'));
    }
}
