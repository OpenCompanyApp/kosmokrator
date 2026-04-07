<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\AgentLoop;

use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Permission\PermissionRule;
use Kosmokrator\Tool\Permission\SessionGrants;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\NullLogger;

/**
 * Integration tests for permission flow: allow, deny, ask.
 * Uses real PermissionEvaluator with real tools.
 */
class PermissionFlowTest extends IntegrationTestCase
{
    public function test_prometheus_mode_auto_approves(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'rm -rf /tmp/test']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Done.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $permissions = new PermissionEvaluator(
            rules: [new PermissionRule('bash', PermissionAction::Allow)],
            grants: new SessionGrants,
            blockedPaths: [],
            guardian: new GuardianEvaluator($this->tmpDir, []),
        );
        $permissions->setPermissionMode(PermissionMode::Prometheus);

        $executed = false;
        $bashTool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withStringParameter('command', 'Command')
            ->using(function (string $command) use (&$executed) {
                $executed = true;

                return 'output';
            });

        $loop = new AgentLoop(
            llm: $this->llm,
            ui: $this->renderer,
            log: new NullLogger,
            baseSystemPrompt: 'test',
            permissions: $permissions,
        );
        $loop->setTools([$bashTool]);
        $loop->run('Run dangerous command');

        $this->assertTrue($executed, 'Tool should have been executed in Prometheus mode');
        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertTrue($this->renderer->toolResults[0]['success']);
    }

    public function test_deny_returns_error_to_llm(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'file_write', arguments: ['path' => '/etc/hosts', 'content' => 'hacked']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Understood, I will not write to that path.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 15,
        ));

        $permissions = new PermissionEvaluator(
            rules: [new PermissionRule('file_write', PermissionAction::Deny)],
            grants: new SessionGrants,
            blockedPaths: ['/etc/hosts'],
            guardian: new GuardianEvaluator($this->tmpDir, []),
        );

        $executed = false;
        $writeTool = (new Tool)
            ->as('file_write')
            ->for('Write files')
            ->withStringParameter('path', 'Path')
            ->withStringParameter('content', 'Content')
            ->using(function (string $command) use (&$executed) {
                $executed = true;

                return 'written';
            });

        $loop = new AgentLoop(
            llm: $this->llm,
            ui: $this->renderer,
            log: new NullLogger,
            baseSystemPrompt: 'test',
            permissions: $permissions,
        );
        $loop->setTools([$writeTool]);
        $loop->run('Write to system file');

        $this->assertFalse($executed, 'Tool should NOT have been executed');
        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertFalse($this->renderer->toolResults[0]['success']);
        $this->assertStringContainsString('blocked', $this->renderer->toolResults[0]['output']);
    }

    public function test_ask_permission_user_allows(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'rm -rf /tmp/test']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Deleted.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $this->renderer->queueAskPermissionResponse('allow');

        $permissions = new PermissionEvaluator(
            rules: [new PermissionRule('bash', PermissionAction::Ask)],
            grants: new SessionGrants,
            blockedPaths: [],
            guardian: new GuardianEvaluator($this->tmpDir, []),
        );
        $permissions->setPermissionMode(PermissionMode::Argus);

        $executed = false;
        $bashTool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withStringParameter('command', 'Command')
            ->using(function (string $command) use (&$executed) {
                $executed = true;

                return 'output';
            });

        $loop = new AgentLoop(
            llm: $this->llm,
            ui: $this->renderer,
            log: new NullLogger,
            baseSystemPrompt: 'test',
            permissions: $permissions,
        );
        $loop->setTools([$bashTool]);
        $loop->run('Delete temp files');

        // Permission prompt should have been shown
        $this->assertCount(1, $this->renderer->permissionPrompts);
        $this->assertSame('bash', $this->renderer->permissionPrompts[0]['toolName']);

        // Tool should have executed
        $this->assertTrue($executed);
    }

    public function test_ask_permission_user_denies(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'rm -rf /']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'OK, I will not do that.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 15,
        ));

        $this->renderer->queueAskPermissionResponse('deny');

        $permissions = new PermissionEvaluator(
            rules: [new PermissionRule('bash', PermissionAction::Ask)],
            grants: new SessionGrants,
            blockedPaths: [],
            guardian: new GuardianEvaluator($this->tmpDir, []),
        );
        $permissions->setPermissionMode(PermissionMode::Argus);

        $executed = false;
        $bashTool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withStringParameter('command', 'Command')
            ->using(function (string $command) use (&$executed) {
                $executed = true;

                return 'output';
            });

        $loop = new AgentLoop(
            llm: $this->llm,
            ui: $this->renderer,
            log: new NullLogger,
            baseSystemPrompt: 'test',
            permissions: $permissions,
        );
        $loop->setTools([$bashTool]);
        $loop->run('Delete everything');

        $this->assertFalse($executed, 'Tool should NOT have been executed after user deny');
        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertStringContainsString('denied', strtolower($this->renderer->toolResults[0]['output']));
    }

    public function test_plan_mode_blocks_mutative_tools(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'touch /tmp/newfile']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Blocked as expected.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $permissions = new PermissionEvaluator(
            rules: [new PermissionRule('bash', PermissionAction::Allow)],
            grants: new SessionGrants,
            blockedPaths: [],
            guardian: new GuardianEvaluator($this->tmpDir, ['git *']),
        );

        $executed = false;
        $bashTool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withStringParameter('command', 'Command')
            ->using(function (string $command) use (&$executed) {
                $executed = true;

                return 'should not run';
            });

        $loop = new AgentLoop(
            llm: $this->llm,
            ui: $this->renderer,
            log: new NullLogger,
            baseSystemPrompt: 'test',
            permissions: $permissions,
        );
        $loop->setTools([$bashTool]);
        $loop->setMode(AgentMode::Plan);
        $loop->run('Try to mutate in plan mode');

        $this->assertFalse($executed);
        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertStringContainsString('blocked in Plan mode', $this->renderer->toolResults[0]['output']);
    }
}
