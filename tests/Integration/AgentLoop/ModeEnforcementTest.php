<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\AgentLoop;

use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionRule;
use Kosmokrator\Tool\Permission\SessionGrants;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\NullLogger;

/**
 * Integration tests for agent mode enforcement (Edit, Plan, Ask).
 * Verifies that mode restrictions correctly block or allow tool operations.
 */
class ModeEnforcementTest extends IntegrationTestCase
{
    public function test_edit_mode_allows_all_tools(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'bash', arguments: ['command' => 'echo hello']),
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

        $executed = false;
        $bashTool = (new Tool)
            ->as('bash')
            ->for('Run commands')
            ->withStringParameter('command', 'Command')
            ->using(function (string $command) use (&$executed) {
                $executed = true;

                return 'output';
            });

        $permissions = new PermissionEvaluator(
            rules: [new PermissionRule('bash', PermissionAction::Allow)],
            grants: new SessionGrants,
            blockedPaths: [],
            guardian: new GuardianEvaluator($this->tmpDir, []),
        );

        $loop = new AgentLoop(
            llm: $this->llm,
            ui: $this->renderer,
            log: new NullLogger,
            baseSystemPrompt: 'test',
            permissions: $permissions,
        );
        $loop->setTools([$bashTool]);
        $loop->setMode(AgentMode::Edit);
        $loop->run('Do something');

        $this->assertTrue($executed, 'Edit mode should allow bash');
    }

    public function test_ask_mode_blocks_write_tools(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'file_write', arguments: [
                    'path' => $this->tmpDir.'/test.txt',
                    'content' => 'content',
                ]),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Understood.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $writeTool = (new Tool)
            ->as('file_write')
            ->for('Write files')
            ->withStringParameter('path', 'Path')
            ->withStringParameter('content', 'Content')
            ->using(fn () => 'written');

        $loop = $this->createAgentLoop(tools: [$writeTool]);
        $loop->setMode(AgentMode::Ask);
        $loop->run('Try to write');

        // Tool should be blocked because file_write is not in Ask mode's allowed tools
        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertFalse($this->renderer->toolResults[0]['success']);
    }

    public function test_plan_mode_blocks_write_tools(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'file_write', arguments: [
                    'path' => $this->tmpDir.'/test.txt',
                    'content' => 'content',
                ]),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Understood.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $writeTool = (new Tool)
            ->as('file_write')
            ->for('Write files')
            ->withStringParameter('path', 'Path')
            ->withStringParameter('content', 'Content')
            ->using(fn () => 'written');

        $loop = $this->createAgentLoop(tools: [$writeTool]);
        $loop->setMode(AgentMode::Plan);
        $loop->run('Try to write');

        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertFalse($this->renderer->toolResults[0]['success']);
    }

    public function test_ask_mode_allows_read_tools(): void
    {
        $this->createFile('readable.txt', 'readable content');

        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'file_read', arguments: [
                    'path' => $this->tmpDir.'/readable.txt',
                ]),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'File contains readable content.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $readTool = (new Tool)
            ->as('file_read')
            ->for('Read files')
            ->withStringParameter('path', 'Path')
            ->using(function (string $path) {
                $tool = new FileReadTool($this->tmpDir);

                return $tool->execute(['path' => $path])->output;
            });

        $loop = $this->createAgentLoop(tools: [$readTool]);
        $loop->setMode(AgentMode::Ask);
        $loop->run('Read the file');

        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertTrue($this->renderer->toolResults[0]['success']);
        $this->assertStringContainsString('readable content', $this->renderer->toolResults[0]['output']);
    }
}
