<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Acp;

use Kosmokrator\Acp\AcpConnection;
use Kosmokrator\Acp\AcpRenderer;
use PHPUnit\Framework\TestCase;

final class AcpRendererTest extends TestCase
{
    public function test_stream_and_tool_updates_are_emitted_as_session_updates(): void
    {
        [$input, $output] = $this->streams();
        $renderer = new AcpRenderer(new AcpConnection($input, $output));
        $renderer->setSessionId('s1');

        $renderer->beginTurn();
        $renderer->streamChunk('hello');
        $renderer->showToolCall('file_read', ['path' => 'README.md']);
        $renderer->showToolResult('file_read', '1 README', true);
        $renderer->endTurn();

        $frames = $this->frames($output);
        $text = $this->firstFrame($frames, 'session/update', 'agent_message_chunk');
        $toolCall = $this->firstFrame($frames, 'session/update', 'tool_call');
        $toolUpdate = $this->firstFrame($frames, 'session/update', 'tool_call_update');
        $toolStarted = $this->firstMethod($frames, 'kosmo/tool_started');
        $toolCompleted = $this->firstMethod($frames, 'kosmo/tool_completed');

        $this->assertSame('hello', $text['params']['update']['content']['text']);
        $this->assertSame('read', $toolCall['params']['update']['kind']);
        $this->assertSame('completed', $toolUpdate['params']['update']['status']);
        $this->assertSame('file_read', $toolStarted['params']['tool']);
        $this->assertSame('file_read', $toolCompleted['params']['tool']);
        $this->assertTrue($toolCompleted['params']['success']);
    }

    public function test_subagent_events_are_emitted_as_kosmokrator_extension_events(): void
    {
        [$input, $output] = $this->streams();
        $renderer = new AcpRenderer(new AcpConnection($input, $output));
        $renderer->setSessionId('s1');

        $renderer->beginTurn();
        $renderer->showSubagentSpawn([
            ['id' => 'a1', 'args' => ['id' => 'a1', 'type' => 'explore', 'task' => 'inspect docs']],
        ]);
        $renderer->refreshSubagentTree([
            [
                'id' => 'a1',
                'type' => 'explore',
                'task' => 'inspect docs',
                'status' => 'running',
                'elapsed' => 0.1,
                'toolCalls' => 1,
                'success' => false,
                'error' => null,
                'children' => [],
            ],
        ]);
        $renderer->showSubagentBatch([
            ['args' => ['id' => 'a1', 'type' => 'explore', 'task' => 'inspect docs'], 'success' => true, 'result' => 'done'],
        ]);

        $frames = $this->frames($output);
        $spawn = $this->firstMethod($frames, 'kosmo/subagent_spawned');
        $tree = $this->firstMethod($frames, 'kosmo/subagent_tree');
        $completed = $this->firstMethod($frames, 'kosmo/subagent_completed');

        $this->assertSame('s1', $spawn['params']['sessionId']);
        $this->assertSame('run_1', $spawn['params']['runId']);
        $this->assertSame('a1', $spawn['params']['entries'][0]['args']['id']);
        $this->assertSame('running', $tree['params']['tree'][0]['status']);
        $this->assertTrue($completed['params']['entries'][0]['success']);
    }

    public function test_permission_response_maps_allow_always_to_session_grant(): void
    {
        [$input, $output] = $this->streams([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow_always']]],
        ]);
        $renderer = new AcpRenderer(new AcpConnection($input, $output));
        $renderer->setSessionId('s1');

        $decision = $renderer->askToolPermission('bash', ['command' => 'php -v']);

        $this->assertSame('always', $decision);
        $frames = $this->frames($output);
        $permissionEvent = $this->firstMethod($frames, 'kosmo/permission_requested');
        $permissionRequest = $this->firstMethod($frames, 'session/request_permission');
        $this->assertSame('bash', $permissionEvent['params']['tool']);
        $this->assertSame('execute', $permissionRequest['params']['toolCall']['kind']);
    }

    public function test_cancel_marks_current_turn_cancelled(): void
    {
        [$input, $output] = $this->streams();
        $renderer = new AcpRenderer(new AcpConnection($input, $output));

        $renderer->beginTurn();
        $renderer->cancel();

        $this->assertTrue($renderer->wasCancelled());
    }

    /**
     * @param  list<array<string, mixed>>  $inputFrames
     * @return array{0: resource, 1: resource}
     */
    private function streams(array $inputFrames = []): array
    {
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'r+');
        $this->assertIsResource($input);
        $this->assertIsResource($output);

        foreach ($inputFrames as $frame) {
            fwrite($input, json_encode($frame, JSON_UNESCAPED_SLASHES)."\n");
        }
        rewind($input);

        return [$input, $output];
    }

    /**
     * @param  resource  $stream
     * @return list<array<string, mixed>>
     */
    private function frames($stream): array
    {
        rewind($stream);
        $frames = [];
        while (($line = fgets($stream)) !== false) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $frames[] = $decoded;
        }

        return $frames;
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return array<string, mixed>
     */
    private function firstMethod(array $frames, string $method): array
    {
        foreach ($frames as $frame) {
            if (($frame['method'] ?? null) === $method) {
                return $frame;
            }
        }

        $this->fail("No frame emitted for method {$method}");
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return array<string, mixed>
     */
    private function firstFrame(array $frames, string $method, string $sessionUpdate): array
    {
        foreach ($frames as $frame) {
            if (($frame['method'] ?? null) === $method && ($frame['params']['update']['sessionUpdate'] ?? null) === $sessionUpdate) {
                return $frame;
            }
        }

        $this->fail("No {$method} frame emitted for update {$sessionUpdate}");
    }
}
