<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\OutputTruncator;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\Agent\ToolExecutor;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionResult;
use Kosmokrator\UI\RendererInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class ToolExecutorTest extends TestCase
{
    private RendererInterface&MockObject $ui;

    private LoggerInterface&MockObject $log;

    private function createExecutor(
        ?PermissionEvaluator $permissions = null,
        ?OutputTruncator $truncator = null,
    ): ToolExecutor {
        return new ToolExecutor(
            $this->ui,
            $this->log,
            $permissions,
            $truncator,
        );
    }

    /**
     * Create a Prism Tool that returns the given string when handle() is called.
     * Uses a variadic closure so it accepts any named arguments the ToolCall passes.
     */
    private function makeTool(string $name, string $returnOutput): Tool
    {
        return (new Tool)
            ->as($name)
            ->for("Test tool: {$name}")
            ->using(function (...$_) use ($returnOutput) { return $returnOutput; });
    }

    /** Create a Prism Tool whose handle() invokes the callback with any args. */
    private function makeToolWithCallback(string $name, callable $callback): Tool
    {
        return (new Tool)
            ->as($name)
            ->for("Test tool: {$name}")
            ->using(function (...$args) use ($callback) { return $callback(...$args); });
    }

    protected function setUp(): void
    {
        $this->ui = $this->createMock(RendererInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);
    }

    // ── 1. Empty tool calls array returns empty results ──────────────────

    public function test_empty_tool_calls_returns_empty_array(): void
    {
        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [],
            tools: [],
            allTools: [],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertSame([], $results);
    }

    // ── 2. Single tool call executes and returns result ──────────────────

    public function test_single_tool_call_executes_and_returns_result(): void
    {
        $tool = $this->makeTool('file_read', 'contents of file');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/test.txt']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('tc_1', $results[0]->toolCallId);
        $this->assertSame('file_read', $results[0]->toolName);
        $this->assertSame('contents of file', $results[0]->result);
    }

    public function test_single_tool_call_increments_stats(): void
    {
        $tool = $this->makeTool('file_read', 'output');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/test.txt']);
        $stats = new SubagentStats('agent-1');

        $executor = $this->createExecutor();

        $this->assertSame(0, $stats->toolCalls);

        $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: $stats,
        );

        $this->assertSame(1, $stats->toolCalls);
    }

    // ── 3. Denied tool (PermissionEvaluator returns Deny) ───────────────

    public function test_denied_tool_returns_error_result(): void
    {
        $tool = $this->makeTool('file_write', 'should not be called');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_write', arguments: ['path' => '/etc/passwd', 'content' => 'hacked']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->with('file_write', $this->anything())
            ->willReturn(new PermissionResult(PermissionAction::Deny, 'Writing to system files is blocked'));

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('tc_1', $results[0]->toolCallId);
        $this->assertSame('file_write', $results[0]->toolName);
        $this->assertStringContainsString('Writing to system files is blocked', $results[0]->result);
        $this->assertStringContainsString('Try a different approach', $results[0]->result);
    }

    public function test_denied_tool_does_not_increment_stats(): void
    {
        $tool = $this->makeTool('file_write', 'should not be called');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_write', arguments: ['path' => '/etc/hosts', 'content' => 'x']);
        $stats = new SubagentStats('agent-1');

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Deny, 'Blocked'));

        $executor = $this->createExecutor($permissions);

        $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: $stats,
        );

        $this->assertSame(0, $stats->toolCalls);
    }

    // ── 4. Tool not found ────────────────────────────────────────────────

    public function test_tool_not_found_in_either_set(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'nonexistent_tool', arguments: []);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [],
            allTools: [],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('tc_1', $results[0]->toolCallId);
        $this->assertStringContainsString("not found", $results[0]->result);
    }

    public function test_tool_not_found_shows_not_found_message(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'imaginary_tool', arguments: ['x' => '1']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [],
            allTools: [],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertStringContainsString("'imaginary_tool' not found.", $results[0]->result);
    }

    // ── 5. Tool exists in allTools but not in current mode's tools ───────

    public function test_tool_exists_in_alltools_but_not_in_mode_tools(): void
    {
        // file_write is available in Edit mode but not Ask mode
        $writeTool = $this->makeTool('file_write', 'content written');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_write', arguments: ['path' => '/tmp/test.txt', 'content' => 'hello']);

        $executor = $this->createExecutor();

        // tools = empty (Ask mode would not include file_write)
        // allTools = contains file_write
        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [],
            allTools: [$writeTool],
            mode: AgentMode::Ask,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('tc_1', $results[0]->toolCallId);
        $this->assertStringContainsString('not available in Ask mode', $results[0]->result);
        $this->assertStringContainsString('Switch to Edit mode', $results[0]->result);
    }

    public function test_tool_exists_in_alltools_edit_mode(): void
    {
        // Verify it works in Edit mode properly
        $writeTool = $this->makeTool('file_write', 'content written');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_write', arguments: ['path' => '/tmp/test.txt', 'content' => 'hello']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$writeTool],
            allTools: [$writeTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('content written', $results[0]->result);
    }

    // ── 6. Multiple tool calls with concurrent execution ─────────────────

    public function test_multiple_tool_calls_execute_concurrently(): void
    {
        $readTool = $this->makeTool('file_read', 'file contents');
        $globTool = $this->makeTool('glob', '*.php');

        $tc1 = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'glob', arguments: ['pattern' => '*.php']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$readTool, $globTool],
            allTools: [$readTool, $globTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);

        // Results should be keyed by tool call id
        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('file contents', $byId['tc_1']->result);
        $this->assertSame('*.php', $byId['tc_2']->result);
    }

    public function test_write_write_conflict_partitions_sequentially(): void
    {
        // Two file_write calls to same path should be in sequential groups
        $writeTool = $this->makeTool('file_write', 'written');
        $tc1 = new ToolCall(id: 'tc_1', name: 'file_write', arguments: ['path' => '/tmp/same.txt', 'content' => 'first']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'file_write', arguments: ['path' => '/tmp/same.txt', 'content' => 'second']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$writeTool],
            allTools: [$writeTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);
        // Both should still execute
        $this->assertSame('written', $results[0]->result);
        $this->assertSame('written', $results[1]->result);
    }

    public function test_bash_and_write_forces_sequential(): void
    {
        $bashTool = $this->makeTool('bash', 'bash output');
        $writeTool = $this->makeTool('file_write', 'written');

        $tc1 = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'ls']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'file_write', arguments: ['path' => '/tmp/test.txt', 'content' => 'data']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$bashTool, $writeTool],
            allTools: [$bashTool, $writeTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);
        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }
        $this->assertSame('bash output', $byId['tc_1']->result);
        $this->assertSame('written', $byId['tc_2']->result);
    }

    // ── 7. Ask tool enforcement (only one per turn) ──────────────────────

    public function test_first_ask_user_tool_succeeds(): void
    {
        $askTool = $this->makeTool('ask_user', 'user response');
        $toolCall = new ToolCall(id: 'tc_1', name: 'ask_user', arguments: ['question' => 'What is your name?']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$askTool],
            allTools: [$askTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('user response', $results[0]->result);
    }

    public function test_second_ask_user_tool_is_rejected(): void
    {
        $askTool = $this->makeTool('ask_user', 'response');
        $tc1 = new ToolCall(id: 'tc_1', name: 'ask_user', arguments: ['question' => 'Q1?']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'ask_user', arguments: ['question' => 'Q2?']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$askTool],
            allTools: [$askTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        // First ask succeeds
        $this->assertSame('response', $byId['tc_1']->result);

        // Second ask is rejected
        $this->assertStringContainsString('Only one interactive question', $byId['tc_2']->result);
    }

    public function test_ask_user_then_ask_choice_rejects_second(): void
    {
        $askUserTool = $this->makeTool('ask_user', 'user answer');
        $askChoiceTool = $this->makeTool('ask_choice', 'choice answer');

        $tc1 = new ToolCall(id: 'tc_1', name: 'ask_user', arguments: ['question' => 'Continue?']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'ask_choice', arguments: ['question' => 'Which option?', 'options' => ['a', 'b']]);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$askUserTool, $askChoiceTool],
            allTools: [$askUserTool, $askChoiceTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('user answer', $byId['tc_1']->result);
        $this->assertStringContainsString('Only one interactive question', $byId['tc_2']->result);
    }

    // ── Permission Ask flow ──────────────────────────────────────────────

    public function test_permission_ask_user_allows(): void
    {
        $tool = $this->makeTool('bash', 'command output');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'rm -rf /tmp/test']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Ask));

        $this->ui->method('askToolPermission')->willReturn('allow');

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('command output', $results[0]->result);
    }

    public function test_permission_ask_user_denies(): void
    {
        $tool = $this->makeTool('bash', 'should not execute');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'rm -rf /']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Ask));

        $this->ui->method('askToolPermission')->willReturn('deny');

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertStringContainsString("User denied permission for 'bash'", $results[0]->result);
    }

    public function test_permission_ask_always_grants_session(): void
    {
        $tool = $this->makeTool('bash', 'output');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'ls']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Ask));
        $permissions->expects($this->once())->method('grantSession')->with('bash');

        $this->ui->method('askToolPermission')->willReturn('always');

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('output', $results[0]->result);
    }

    public function test_permission_allow_auto_approved(): void
    {
        $tool = $this->makeTool('file_read', 'contents');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/test.txt']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Allow, autoApproved: true));

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('contents', $results[0]->result);
    }

    // ── Null permissions means no restrictions ────────────────────────────

    public function test_null_permissions_allows_all(): void
    {
        $tool = $this->makeTool('file_write', 'written');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_write', arguments: ['path' => '/tmp/out.txt', 'content' => 'x']);

        $executor = $this->createExecutor(permissions: null);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('written', $results[0]->result);
    }

    // ── OutputTruncator integration ──────────────────────────────────────

    public function test_output_truncator_is_applied(): void
    {
        $longOutput = str_repeat('x', 200);
        $tool = $this->makeTool('bash', $longOutput);
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'cat bigfile']);

        $truncator = $this->createMock(OutputTruncator::class);
        $truncator->expects($this->once())
            ->method('truncate')
            ->with($longOutput, 'tc_1')
            ->willReturn('truncated output');

        $executor = $this->createExecutor(truncator: $truncator);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertSame('truncated output', $results[0]->result);
    }

    public function test_null_truncator_skips_truncation(): void
    {
        $output = 'raw output';
        $tool = $this->makeTool('bash', $output);
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'echo hi']);

        $executor = $this->createExecutor(truncator: null);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertSame('raw output', $results[0]->result);
    }

    // ── Tool execution error handling ────────────────────────────────────

    public function test_tool_exception_returns_error_result(): void
    {
        $tool = $this->makeToolWithCallback('bash', function (...$_) { throw new \RuntimeException('Something broke'); });
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'bad-cmd']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Error:', $results[0]->result);
        $this->assertStringContainsString('Something broke', $results[0]->result);
    }

    // ── Read-only mode shell guard ───────────────────────────────────────

    public function test_readonly_mode_blocks_mutative_bash_command(): void
    {
        $bashTool = $this->makeTool('bash', 'should not run');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'rm -rf /tmp/test']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        // Allow through permission evaluator
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Allow));
        // But identify as mutative command
        $permissions->method('isMutativeCommand')
            ->with('rm -rf /tmp/test')
            ->willReturn(true);

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$bashTool],
            allTools: [$bashTool],
            mode: AgentMode::Ask,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertStringContainsString('blocked in Ask mode', $results[0]->result);
        $this->assertStringContainsString('read-only', $results[0]->result);
    }

    public function test_readonly_mode_allows_non_mutative_bash_command(): void
    {
        $bashTool = $this->makeTool('bash', 'ls output');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'ls -la']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Allow));
        $permissions->method('isMutativeCommand')
            ->willReturn(false);

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$bashTool],
            allTools: [$bashTool],
            mode: AgentMode::Ask,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('ls output', $results[0]->result);
    }

    public function test_plan_mode_blocks_mutative_bash_command(): void
    {
        $bashTool = $this->makeTool('bash', 'should not run');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'npm install']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Allow));
        $permissions->method('isMutativeCommand')
            ->willReturn(true);

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$bashTool],
            allTools: [$bashTool],
            mode: AgentMode::Plan,
            agentContext: null,
            stats: null,
        );

        $this->assertStringContainsString('blocked in Plan mode', $results[0]->result);
    }

    public function test_edit_mode_allows_mutative_bash_command(): void
    {
        $bashTool = $this->makeTool('bash', 'install output');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'npm install']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Allow));

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$bashTool],
            allTools: [$bashTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertSame('install output', $results[0]->result);
    }

    // ── Mixed: approved + denied in same batch ───────────────────────────

    public function test_mixed_approved_and_denied_results(): void
    {
        $readTool = $this->makeTool('file_read', 'file contents');
        $toolCall1 = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);
        $toolCall2 = new ToolCall(id: 'tc_2', name: 'file_read', arguments: ['path' => '/etc/shadow']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturnCallback(function (string $name, array $args) {
                if (isset($args['path']) && str_contains($args['path'], 'shadow')) {
                    return new PermissionResult(PermissionAction::Deny, 'Access to shadow denied');
                }

                return new PermissionResult(PermissionAction::Allow);
            });

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall1, $toolCall2],
            tools: [$readTool],
            allTools: [$readTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('file contents', $byId['tc_1']->result);
        $this->assertStringContainsString('Access to shadow denied', $byId['tc_2']->result);
    }

    public function test_denied_and_not_found_and_approved_in_same_batch(): void
    {
        $readTool = $this->makeTool('file_read', 'ok');

        $tcApproved = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);
        $tcDenied = new ToolCall(id: 'tc_2', name: 'file_read', arguments: ['path' => '/blocked']);
        $tcNotFound = new ToolCall(id: 'tc_3', name: 'nonexistent', arguments: []);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturnCallback(function (string $name, array $args) {
                if (isset($args['path']) && $args['path'] === '/blocked') {
                    return new PermissionResult(PermissionAction::Deny, 'Blocked path');
                }

                return new PermissionResult(PermissionAction::Allow);
            });

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$tcApproved, $tcDenied, $tcNotFound],
            tools: [$readTool],
            allTools: [$readTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(3, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('ok', $byId['tc_1']->result);
        $this->assertStringContainsString('Blocked path', $byId['tc_2']->result);
        $this->assertStringContainsString('not found', $byId['tc_3']->result);
    }

    // ── Partition concurrent groups edge cases ────────────────────────────

    public function test_ask_tool_forces_sequential_groups(): void
    {
        // When an ask tool is present, all calls should be in separate groups
        $askTool = $this->makeTool('ask_user', 'answer');
        $readTool = $this->makeTool('file_read', 'contents');

        $tc1 = new ToolCall(id: 'tc_1', name: 'ask_user', arguments: ['question' => 'Q?']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);
        $tc3 = new ToolCall(id: 'tc_3', name: 'file_read', arguments: ['path' => '/tmp/b.txt']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2, $tc3],
            tools: [$askTool, $readTool],
            allTools: [$askTool, $readTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(3, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('answer', $byId['tc_1']->result);
        $this->assertSame('contents', $byId['tc_2']->result);
        $this->assertSame('contents', $byId['tc_3']->result);
    }

    public function test_shell_tool_forces_sequential_groups(): void
    {
        // shell_start forces sequential groups
        $shellTool = $this->makeTool('shell_start', 'session started');
        $readTool = $this->makeTool('file_read', 'contents');

        $tc1 = new ToolCall(id: 'tc_1', name: 'shell_start', arguments: ['command' => 'bash']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$shellTool, $readTool],
            allTools: [$shellTool, $readTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);
    }

    public function test_independent_reads_run_concurrently(): void
    {
        // Two file_read calls to different paths should run concurrently
        $readTool = $this->makeTool('file_read', 'data');

        $tc1 = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'file_read', arguments: ['path' => '/tmp/b.txt']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$readTool],
            allTools: [$readTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('data', $byId['tc_1']->result);
        $this->assertSame('data', $byId['tc_2']->result);
    }

    // ── Denied default reason when no reason given ───────────────────────

    public function test_deny_without_reason_uses_default_message(): void
    {
        $tool = $this->makeTool('bash', 'nope');
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'danger']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Deny));

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertStringContainsString("Permission denied: 'bash' is blocked by policy.", $results[0]->result);
    }

    // ── Stats are null-safe ──────────────────────────────────────────────

    public function test_null_stats_does_not_throw(): void
    {
        $tool = $this->makeTool('file_read', 'output');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/test.txt']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('output', $results[0]->result);
    }

    // ── Tool call args are preserved in result ───────────────────────────

    public function test_result_preserves_tool_call_args(): void
    {
        $tool = $this->makeTool('file_read', 'content');
        $args = ['path' => '/tmp/test.txt'];
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_read', arguments: $args);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertSame($args, $results[0]->args);
    }

    // ── Denied result preserves args too ─────────────────────────────────

    public function test_denied_result_preserves_tool_name_and_args(): void
    {
        $tool = $this->makeTool('bash', 'x');
        $args = ['command' => 'rm -rf /'];
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: $args);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Deny, 'Dangerous'));

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertSame('bash', $results[0]->toolName);
        $this->assertSame($args, $results[0]->args);
    }

    // ── Logging integration ──────────────────────────────────────────────

    public function test_logs_tool_call_info(): void
    {
        $tool = $this->makeTool('file_read', 'contents');
        $toolCall = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);

        $this->log->expects($this->atLeastOnce())
            ->method('info')
            ->with('Tool call', $this->callback(fn (array $context) => $context['tool'] === 'file_read'));

        $executor = $this->createExecutor();

        $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );
    }

    public function test_logs_error_on_tool_exception(): void
    {
        $tool = (new Tool)
            ->as('bash')
            ->for('Test bash')
            ->using(function (...$_) { throw new \RuntimeException('Boom'); })
            ->withoutErrorHandling();
        $toolCall = new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'fail']);

        $this->log->expects($this->atLeastOnce())
            ->method('error')
            ->with('Tool execution failed', $this->callback(fn (array $ctx) => $ctx['tool'] === 'bash'));

        $executor = $this->createExecutor();

        $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$tool],
            allTools: [$tool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );
    }

    // ── Subagent tool call ───────────────────────────────────────────────

    public function test_subagent_tool_call_executes(): void
    {
        $subagentTool = $this->makeTool('subagent', 'subagent result');
        $toolCall = new ToolCall(id: 'tc_1', name: 'subagent', arguments: ['id' => 'sub-1', 'task' => 'do stuff']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$subagentTool],
            allTools: [$subagentTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(1, $results);
        $this->assertSame('subagent result', $results[0]->result);
    }

    // ── Multiple stats increments ────────────────────────────────────────

    public function test_multiple_tool_calls_increment_stats_individually(): void
    {
        $readTool = $this->makeTool('file_read', 'data');
        $grepTool = $this->makeTool('grep', 'matches');

        $tc1 = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => '/tmp/a.txt']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'grep', arguments: ['pattern' => 'test']);

        $stats = new SubagentStats('agent-1');
        $executor = $this->createExecutor();

        $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$readTool, $grepTool],
            allTools: [$readTool, $grepTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: $stats,
        );

        $this->assertSame(2, $stats->toolCalls);
    }

    // ── Shell read-only mode with shell_start ────────────────────────────

    public function test_shell_write_blocked_in_readonly_mode(): void
    {
        $shellTool = $this->makeTool('shell_write', 'should not run');
        $toolCall = new ToolCall(id: 'tc_1', name: 'shell_write', arguments: ['input' => 'rm -rf /tmp/test']);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->method('evaluate')
            ->willReturn(new PermissionResult(PermissionAction::Allow));
        $permissions->method('isMutativeCommand')
            ->with('rm -rf /tmp/test')
            ->willReturn(true);

        $executor = $this->createExecutor($permissions);

        $results = $executor->executeToolCalls(
            toolCalls: [$toolCall],
            tools: [$shellTool],
            allTools: [$shellTool],
            mode: AgentMode::Plan,
            agentContext: null,
            stats: null,
        );

        $this->assertStringContainsString('blocked in Plan mode', $results[0]->result);
    }

    // ── Edge: apply_patch forces sequential ──────────────────────────────

    public function test_apply_patch_with_bash_forces_sequential(): void
    {
        $patchTool = $this->makeTool('apply_patch', 'patched');
        $bashTool = $this->makeTool('bash', 'bash output');

        $tc1 = new ToolCall(id: 'tc_1', name: 'apply_patch', arguments: ['patch' => '*** Begin Patch']);
        $tc2 = new ToolCall(id: 'tc_2', name: 'bash', arguments: ['command' => 'ls']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$patchTool, $bashTool],
            allTools: [$patchTool, $bashTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('patched', $byId['tc_1']->result);
        $this->assertSame('bash output', $byId['tc_2']->result);
    }

    // ── read-write conflict on same path forces sequential ───────────────

    public function test_read_write_same_path_forces_sequential(): void
    {
        $readTool = $this->makeTool('file_read', 'contents');
        $writeTool = $this->makeTool('file_write', 'written');

        $path = tempnam(sys_get_temp_dir(), 'kkr_test_');
        $tc1 = new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => $path]);
        $tc2 = new ToolCall(id: 'tc_2', name: 'file_write', arguments: ['path' => $path, 'content' => 'new']);

        $executor = $this->createExecutor();

        $results = $executor->executeToolCalls(
            toolCalls: [$tc1, $tc2],
            tools: [$readTool, $writeTool],
            allTools: [$readTool, $writeTool],
            mode: AgentMode::Edit,
            agentContext: null,
            stats: null,
        );

        $this->assertCount(2, $results);

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->toolCallId] = $r;
        }

        $this->assertSame('contents', $byId['tc_1']->result);
        $this->assertSame('written', $byId['tc_2']->result);

        @unlink($path);
    }
}
