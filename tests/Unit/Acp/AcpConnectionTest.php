<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Acp;

use Kosmokrator\Acp\AcpConnection;
use PHPUnit\Framework\TestCase;

final class AcpConnectionTest extends TestCase
{
    public function test_sends_json_rpc_result_frames(): void
    {
        [$input, $output] = $this->streams();
        $connection = new AcpConnection($input, $output);

        $connection->sendResult(7, ['ok' => true]);

        $frame = $this->lastFrame($output);
        $this->assertSame('2.0', $frame['jsonrpc']);
        $this->assertSame(7, $frame['id']);
        $this->assertSame(['ok' => true], $frame['result']);
    }

    public function test_request_waits_for_matching_response_and_dispatches_notifications(): void
    {
        [$input, $output] = $this->streams([
            ['jsonrpc' => '2.0', 'method' => 'session/cancel', 'params' => ['sessionId' => 's1']],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow_once']]],
        ]);
        $connection = new AcpConnection($input, $output);
        $cancelled = false;
        $connection->onNotification('session/cancel', function (array $params) use (&$cancelled): void {
            $cancelled = $params['sessionId'] === 's1';
        });

        $result = $connection->request('session/request_permission', ['sessionId' => 's1']);

        $this->assertTrue($cancelled);
        $this->assertSame('allow_once', $result['outcome']['optionId']);

        rewind($output);
        $requestFrame = json_decode((string) fgets($output), true);
        $this->assertSame('session/request_permission', $requestFrame['method']);
        $this->assertSame(1, $requestFrame['id']);
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
     * @return array<string, mixed>
     */
    private function lastFrame($stream): array
    {
        rewind($stream);
        $line = '';
        while (($next = fgets($stream)) !== false) {
            $line = $next;
        }

        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
