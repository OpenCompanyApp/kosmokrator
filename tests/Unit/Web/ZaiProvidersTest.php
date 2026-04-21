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
use Kosmokrator\LLM\ProviderAuthService;
use Kosmokrator\Web\Extract\MarkdownPageExtractor;
use Kosmokrator\Web\Mcp\McpToolInvokerInterface;
use Kosmokrator\Web\Provider\Fetch\ZaiReaderFetchProvider;
use Kosmokrator\Web\Provider\Search\ZaiMcpSearchProvider;
use Kosmokrator\Web\Safety\WebRequestGuard;
use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebSearchRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ZaiProvidersTest extends TestCase
{
    public function test_zai_search_provider_normalizes_remote_mcp_results(): void
    {
        $auth = $this->createConfiguredMock(ProviderAuthService::class, [
            'apiKey' => 'zai-test-key',
        ]);

        $provider = new ZaiMcpSearchProvider(
            new class implements McpToolInvokerInterface
            {
                public function call(string $remoteUrl, string $toolName, array $arguments, array $headers = []): array
                {
                    return [
                        [
                            'title' => 'Example Result',
                            'content' => 'Summary',
                            'link' => 'https://example.com/page',
                            'media' => 'Example',
                            'publish_date' => '2026-04-13',
                        ],
                    ];
                }
            },
            $auth,
        );

        $response = $provider->search(new WebSearchRequest(
            query: 'example',
            maxResults: 5,
            blockedDomains: ['blocked.example'],
        ));

        self::assertSame('zai', $response->provider);
        self::assertCount(1, $response->results);
        self::assertSame('https://example.com/page', $response->results[0]->url);
    }

    public function test_zai_reader_fetch_provider_builds_sections_from_markdown(): void
    {
        $auth = $this->createConfiguredMock(ProviderAuthService::class, [
            'apiKey' => 'zai-test-key',
        ]);

        $guard = $this->createMock(WebRequestGuard::class);

        $provider = new ZaiReaderFetchProvider(
            auth: $auth,
            guard: $guard,
            extractor: new MarkdownPageExtractor,
            httpClient: new HttpClient(new class implements DelegateHttpClient
            {
                public function request(Request $request, Cancellation $cancellation): Response
                {
                    return new Response(
                        '2',
                        HttpStatus::OK,
                        'OK',
                        [],
                        new ReadableBuffer(json_encode([
                            'model' => 'web-reader',
                            'request_id' => 'req-123',
                            'reader_result' => [
                                'title' => 'Example Docs',
                                'url' => 'https://example.com/docs',
                                'description' => 'Reference',
                                'content' => "# Intro\n\nHello\n\n## Auth\n\nUse a token.\n",
                                'metadata' => ['lang' => 'en'],
                            ],
                        ], JSON_THROW_ON_ERROR)),
                        $request,
                    );
                }
            }, []),
        );

        $response = $provider->fetch(new WebFetchRequest(
            url: 'https://example.com/docs',
            format: 'markdown',
        ));

        self::assertSame('zai', $response->provider);
        self::assertSame('Example Docs', $response->title);
        self::assertArrayHasKey('auth', $response->sections);
        self::assertSame('Reference', $response->metadata['description']);
    }

    public function test_zai_search_provider_falls_back_to_chat_results_when_mcp_is_empty(): void
    {
        $auth = $this->createConfiguredMock(ProviderAuthService::class, [
            'apiKey' => 'zai-test-key',
        ]);

        $provider = new ZaiMcpSearchProvider(
            new class implements McpToolInvokerInterface
            {
                public function call(string $remoteUrl, string $toolName, array $arguments, array $headers = []): array
                {
                    return [];
                }
            },
            $auth,
            null,
            'https://api.z.ai/api/mcp/web_search_prime/mcp',
            'https://api.z.ai/api/coding/paas/v4',
            [1, 2],
            new HttpClient(new class implements DelegateHttpClient
            {
                public function request(Request $request, Cancellation $cancellation): Response
                {
                    return new Response(
                        '2',
                        HttpStatus::OK,
                        'OK',
                        [],
                        new ReadableBuffer(json_encode([
                            'choices' => [[
                                'message' => [
                                    'content' => json_encode([
                                        'answer' => 'Example answer',
                                        'results' => [[
                                            'title' => 'Example Result',
                                            'url' => 'https://example.com/result',
                                            'source' => 'Example Source',
                                            'published_at' => '2026-04-13',
                                            'snippet' => 'Example snippet',
                                        ]],
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ], JSON_THROW_ON_ERROR)),
                        $request,
                    );
                }
            }, []),
        );

        $response = $provider->search(new WebSearchRequest(
            query: 'example',
            maxResults: 5,
            includeAnswer: true,
        ));

        self::assertCount(1, $response->results);
        self::assertSame('https://example.com/result', $response->results[0]->url);
        self::assertSame('Example Source', $response->results[0]->source);
        self::assertSame('Example answer', $response->answer);
    }

    public function test_zai_search_provider_retries_rate_limits_without_falling_back(): void
    {
        $auth = $this->createConfiguredMock(ProviderAuthService::class, [
            'apiKey' => 'zai-test-key',
        ]);

        $attempts = 0;
        $slept = [];

        $provider = new ZaiMcpSearchProvider(
            new class($attempts) implements McpToolInvokerInterface
            {
                public function __construct(private int &$attempts) {}

                public function call(string $remoteUrl, string $toolName, array $arguments, array $headers = []): array
                {
                    $this->attempts++;

                    throw new \RuntimeException('MCP error -429: {"error":{"code":"1302","message":"Rate limit reached for requests"}}');
                }
            },
            $auth,
            null,
            'https://api.z.ai/api/mcp/web_search_prime/mcp',
            'https://api.z.ai/api/coding/paas/v4',
            [0, 0],
            new HttpClient(new class implements DelegateHttpClient
            {
                public function request(Request $request, Cancellation $cancellation): Response
                {
                    throw new \RuntimeException('Chat fallback should not be invoked for rate limits.');
                }
            }, []),
            static function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate limited');

        try {
            $provider->search(new WebSearchRequest(query: 'example', maxResults: 5));
        } finally {
            self::assertSame(3, $attempts);
            self::assertSame([0, 0], $slept);
        }
    }
}
