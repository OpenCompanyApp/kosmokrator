<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Web;

use Amp\ByteStream\ReadableBuffer;
use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\HttpStatus;
use Kosmokrator\Web\Mcp\StreamableMcpToolInvoker;
use PHPUnit\Framework\TestCase;

final class StreamableMcpToolInvokerTest extends TestCase
{
    public function test_it_parses_streamable_http_sse_tool_payloads(): void
    {
        $phase = 0;

        $invoker = new StreamableMcpToolInvoker(new HttpClient(new class($phase) implements DelegateHttpClient
        {
            public function __construct(private int &$phase) {}

            public function request(Request $request, Cancellation $cancellation): Response
            {
                $this->phase++;

                return match ($this->phase) {
                    1 => new Response(
                        '2',
                        HttpStatus::OK,
                        'OK',
                        [
                            'content-type' => 'text/event-stream',
                            'mcp-session-id' => 'session-123',
                        ],
                        new ReadableBuffer("data: {\"result\":{\"protocolVersion\":\"2025-11-25\"}}\n\n"),
                        $request,
                    ),
                    2 => new Response(
                        '2',
                        HttpStatus::OK,
                        'OK',
                        ['content-type' => 'application/json'],
                        new ReadableBuffer('{}'),
                        $request,
                    ),
                    default => new Response(
                        '2',
                        HttpStatus::OK,
                        'OK',
                        ['content-type' => 'text/event-stream'],
                        new ReadableBuffer("event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"[{\\\"title\\\":\\\"Result\\\",\\\"link\\\":\\\"https://example.com\\\"}]\"}],\"isError\":false}}\n\n"),
                        $request,
                    ),
                };
            }
        }, []));

        $result = $invoker->call(
            'https://api.z.ai/api/mcp/web_search_prime/mcp',
            'web_search_prime',
            ['search_query' => 'example'],
            ['Authorization' => 'Bearer zai-test-key'],
        );

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('Result', $result[0]['title']);
        self::assertSame('https://example.com', $result[0]['link']);
    }
}
