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
        $this->assertSame('session/update', $frames[0]['method']);
        $this->assertSame('agent_message_chunk', $frames[0]['params']['update']['sessionUpdate']);
        $this->assertSame('hello', $frames[0]['params']['update']['content']['text']);
        $this->assertSame('tool_call', $frames[1]['params']['update']['sessionUpdate']);
        $this->assertSame('read', $frames[1]['params']['update']['kind']);
        $this->assertSame('tool_call_update', $frames[2]['params']['update']['sessionUpdate']);
        $this->assertSame('completed', $frames[2]['params']['update']['status']);
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
        $this->assertSame('session/request_permission', $frames[0]['method']);
        $this->assertSame('execute', $frames[0]['params']['toolCall']['kind']);
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
}
