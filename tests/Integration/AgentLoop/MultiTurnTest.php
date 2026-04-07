<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\AgentLoop;

use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Integration tests for multi-turn AgentLoop scenarios with the fake LLM.
 * Tests real tool execution against the temp filesystem with scripted LLM responses.
 */
class MultiTurnTest extends IntegrationTestCase
{
    public function test_single_text_response(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: 'Hello! How can I help?',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 100,
            completionTokens: 50,
        ));

        $loop = $this->createAgentLoop();
        $loop->run('Hi');

        $this->assertSame('Hello! How can I help?', $this->renderer->getFullStreamedText());
        $this->assertCount(2, $loop->history()->messages()); // user + assistant
        $this->assertSame(1, $this->llm->getCallCount());
    }

    public function test_tool_call_then_final_response(): void
    {
        // Create a file for the tool to read
        $this->createFile('data.txt', 'secret content here');

        // Turn 1: LLM calls file_read tool
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => $this->tmpDir.'/data.txt']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        // Turn 2: LLM responds with the file contents
        $this->llm->queueResponse(new LlmResponse(
            text: 'The file contains: secret content here',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 30,
        ));

        $fileReadTool = (new Tool)
            ->as('file_read')
            ->for('Read a file')
            ->withStringParameter('path', 'File path')
            ->using(function (string $path) {
                $tool = new FileReadTool($this->tmpDir);

                return $tool->execute(['path' => $path])->output;
            });

        $loop = $this->createAgentLoop(tools: [$fileReadTool]);
        $loop->run('Read the file');

        // Verify tool was called
        $this->assertCount(1, $this->renderer->toolCalls);
        $this->assertSame('file_read', $this->renderer->toolCalls[0]['name']);

        // Verify tool result was shown
        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertTrue($this->renderer->toolResults[0]['success']);

        // Verify final response
        $this->assertSame('The file contains: secret content here', $this->renderer->getFullStreamedText());

        // Verify LLM was called twice (tool call round + final response)
        $this->assertSame(2, $this->llm->getCallCount());

        // Verify history has 4 messages: user, assistant (tool call), tool result, assistant (final)
        $this->assertCount(4, $loop->history()->messages());
    }

    public function test_multiple_tool_calls_in_single_response(): void
    {
        $this->createFile('a.txt', 'content A');
        $this->createFile('b.txt', 'content B');

        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'file_read', arguments: ['path' => $this->tmpDir.'/a.txt']),
                new ToolCall(id: 'tc_2', name: 'file_read', arguments: ['path' => $this->tmpDir.'/b.txt']),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'Read both files successfully.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $fileReadTool = (new Tool)
            ->as('file_read')
            ->for('Read a file')
            ->withStringParameter('path', 'File path')
            ->using(function (string $path) {
                $tool = new FileReadTool($this->tmpDir);

                return $tool->execute(['path' => $path])->output;
            });

        $loop = $this->createAgentLoop(tools: [$fileReadTool]);
        $loop->run('Read both files');

        // Both tool results should be shown
        $this->assertCount(2, $this->renderer->toolResults);
        $this->assertTrue($this->renderer->toolResults[0]['success']);
        $this->assertTrue($this->renderer->toolResults[1]['success']);

        // Verify history has tool result message with 2 results
        $messages = $loop->history()->messages();
        $toolResultMsg = $messages[2];
        $this->assertInstanceOf(ToolResultMessage::class, $toolResultMsg);
        $this->assertCount(2, $toolResultMsg->toolResults);
    }

    public function test_write_then_read_tool_workflow(): void
    {
        // Turn 1: LLM writes a file
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'file_write', arguments: [
                    'path' => $this->tmpDir.'/created.txt',
                    'content' => 'dynamically created',
                ]),
            ],
            promptTokens: 100,
            completionTokens: 20,
        ));

        // Turn 2: LLM reads it back
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_2', name: 'file_read', arguments: [
                    'path' => $this->tmpDir.'/created.txt',
                ]),
            ],
            promptTokens: 150,
            completionTokens: 15,
        ));

        // Turn 3: LLM confirms
        $this->llm->queueResponse(new LlmResponse(
            text: 'File created and verified.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 200,
            completionTokens: 10,
        ));

        $writeTool = (new Tool)
            ->as('file_write')
            ->for('Write a file')
            ->withStringParameter('path', 'File path')
            ->withStringParameter('content', 'File content')
            ->using(function (string $path, string $content) {
                $tool = new FileWriteTool($this->tmpDir);

                return $tool->execute(['path' => $path, 'content' => $content])->output;
            });

        $readTool = (new Tool)
            ->as('file_read')
            ->for('Read a file')
            ->withStringParameter('path', 'File path')
            ->using(function (string $path) {
                $tool = new FileReadTool($this->tmpDir);

                return $tool->execute(['path' => $path])->output;
            });

        $loop = $this->createAgentLoop(tools: [$writeTool, $readTool]);
        $loop->run('Create and verify a file');

        // 3 LLM calls: write tool → read tool → final
        $this->assertSame(3, $this->llm->getCallCount());
        $this->assertSame('File created and verified.', $this->renderer->getFullStreamedText());

        // Verify the file actually exists
        $this->assertFileExistsInTmp('created.txt');
        $this->assertSame('dynamically created', $this->readFile('created.txt'));
    }

    public function test_tool_not_found_returns_error_to_llm(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::ToolCalls,
            toolCalls: [
                new ToolCall(id: 'tc_1', name: 'nonexistent_tool', arguments: []),
            ],
            promptTokens: 100,
            completionTokens: 10,
        ));

        $this->llm->queueResponse(new LlmResponse(
            text: 'I see that tool is not available.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 150,
            completionTokens: 15,
        ));

        $loop = $this->createAgentLoop(tools: []);
        $loop->run('Use a missing tool');

        $this->assertCount(1, $this->renderer->toolResults);
        $this->assertFalse($this->renderer->toolResults[0]['success']);
        $this->assertStringContainsString('not found', $this->renderer->toolResults[0]['output']);
    }

    public function test_empty_response_does_not_stream(): void
    {
        $this->llm->queueResponse(new LlmResponse(
            text: '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 100,
            completionTokens: 0,
        ));

        $loop = $this->createAgentLoop();
        $loop->run('Question');

        $this->assertEmpty($this->renderer->streamedChunks);
        $this->assertEmpty($this->renderer->completedStreams);
    }

    public function test_llm_error_shown_to_user(): void
    {
        $this->llm->queueException(new \RuntimeException('Connection refused'));

        $loop = $this->createAgentLoop();
        $loop->run('Trigger error');

        $this->assertTrue($this->renderer->hasError('Connection refused'));
    }
}
