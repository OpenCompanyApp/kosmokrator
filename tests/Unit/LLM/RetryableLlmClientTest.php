<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\RetryableHttpException;
use Kosmokrator\LLM\RetryableLlmClient;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Exceptions\PrismServerException;
use Psr\Log\LoggerInterface;

class RetryableLlmClientTest extends TestCase
{
    private function makeResponse(): LlmResponse
    {
        return new LlmResponse(
            text: '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            promptTokens: 0,
            completionTokens: 0,
        );
    }

    private function makeClient(LlmClientInterface $inner, int $maxAttempts = 3): RetryableLlmClient
    {
        $log = $this->createStub(LoggerInterface::class);

        return new RetryableLlmClient($inner, $log, $maxAttempts);
    }

    public function test_delegates_successful_call(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->once())
            ->method('chat')
            ->willReturn($response);

        $client = $this->makeClient($inner);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_retries_on_rate_limit(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw PrismRateLimitedException::make([], 5);
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_retries_on_server_error(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new PrismServerException('Server error');
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_retries_on_provider_overloaded(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new PrismProviderOverloadedException('test-provider');
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_retries_on_http_status_429(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new \RuntimeException('API error (429): rate limited');
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_retries_on_http_status_500(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new \RuntimeException('API error (500): internal error');
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_respects_max_attempts(): void
    {
        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willThrowException(new PrismServerException('Server error'));

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->expectException(PrismServerException::class);
        $client->chat([]);
    }

    public function test_does_not_retry_request_too_large(): void
    {
        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->once())
            ->method('chat')
            ->willThrowException(new PrismRequestTooLargeException('test-provider'));

        $client = $this->makeClient($inner);

        $this->expectException(PrismRequestTooLargeException::class);
        $client->chat([]);
    }

    public function test_does_not_retry_invalid_argument(): void
    {
        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->once())
            ->method('chat')
            ->willThrowException(new \InvalidArgumentException('bad argument'));

        $client = $this->makeClient($inner);

        $this->expectException(\InvalidArgumentException::class);
        $client->chat([]);
    }

    public function test_does_not_retry_generic_runtime_exception(): void
    {
        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->once())
            ->method('chat')
            ->willThrowException(new \RuntimeException('some other error'));

        $client = $this->makeClient($inner);

        $this->expectException(\RuntimeException::class);
        $client->chat([]);
    }

    public function test_retries_on_retryable_http_exception(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new RetryableHttpException(429, 'API error (429): rate limited', 2.0);
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_retries_on_retryable_http_exception_5xx(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new RetryableHttpException(503, 'API error (503): overloaded');
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);

        $this->assertSame($response, $client->chat([]));
    }

    public function test_on_retry_callback_is_called(): void
    {
        $response = $this->makeResponse();
        $callbackArgs = [];

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new RetryableHttpException(429, 'API error (429): rate limited', 3.0);
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);
        $client->setOnRetry(function (int $attempt, float $delay, string $reason) use (&$callbackArgs) {
            $callbackArgs = compact('attempt', 'delay', 'reason');
        });

        $client->chat([]);

        $this->assertSame(1, $callbackArgs['attempt']);
        $this->assertSame(3.0, $callbackArgs['delay']);
        $this->assertStringContainsString('429', $callbackArgs['reason']);
    }

    public function test_on_retry_callback_exception_does_not_break_retry(): void
    {
        $response = $this->makeResponse();

        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use ($response): LlmResponse {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    throw new PrismServerException('Server error');
                }

                return $response;
            });

        $client = $this->makeClient($inner, maxAttempts: 2);
        $client->setOnRetry(function () {
            throw new \RuntimeException('UI crashed');
        });

        // Should still succeed despite callback throwing
        $this->assertSame($response, $client->chat([]));
    }

    public function test_cancellation_checked_during_blocking_delay(): void
    {
        $inner = $this->createMock(LlmClientInterface::class);
        $inner->expects($this->once())
            ->method('chat')
            ->willThrowException(new PrismServerException('Server error'));

        $client = $this->makeClient($inner, maxAttempts: 3);

        // Create a pre-cancelled cancellation token
        $deferred = new DeferredCancellation;
        $deferred->cancel();

        $this->expectException(CancelledException::class);
        $client->chat([], [], $deferred->getCancellation());
    }

    public function test_delegates_interface_methods(): void
    {
        $inner = $this->createMock(LlmClientInterface::class);

        $inner->expects($this->once())
            ->method('getProvider')
            ->willReturn('anthropic');

        $inner->expects($this->once())
            ->method('getModel')
            ->willReturn('claude-4');

        $inner->expects($this->once())
            ->method('getTemperature')
            ->willReturn(0.7);

        $inner->expects($this->once())
            ->method('getMaxTokens')
            ->willReturn(4096);

        $inner->expects($this->once())
            ->method('setSystemPrompt')
            ->with('test prompt');

        $inner->expects($this->once())
            ->method('setProvider')
            ->with('openai');

        $inner->expects($this->once())
            ->method('setModel')
            ->with('gpt-4');

        $inner->expects($this->once())
            ->method('setTemperature')
            ->with(0.5);

        $inner->expects($this->once())
            ->method('setMaxTokens')
            ->with(8192);

        $client = $this->makeClient($inner);

        $this->assertSame('anthropic', $client->getProvider());
        $this->assertSame('claude-4', $client->getModel());
        $this->assertSame(0.7, $client->getTemperature());
        $this->assertSame(4096, $client->getMaxTokens());

        $client->setSystemPrompt('test prompt');
        $client->setProvider('openai');
        $client->setModel('gpt-4');
        $client->setTemperature(0.5);
        $client->setMaxTokens(8192);
    }
}
