<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\RetryableHttpException;
use PHPUnit\Framework\TestCase;

class RetryableHttpExceptionTest extends TestCase
{
    public function test_carries_http_status_and_retry_after(): void
    {
        $e = new RetryableHttpException(429, 'API error (429): rate limited', 5.0);

        $this->assertSame(429, $e->httpStatus);
        $this->assertSame(5.0, $e->retryAfterSeconds);
        $this->assertSame('API error (429): rate limited', $e->getMessage());
    }

    public function test_retry_after_defaults_to_null(): void
    {
        $e = new RetryableHttpException(503, 'API error (503): overloaded');

        $this->assertSame(503, $e->httpStatus);
        $this->assertNull($e->retryAfterSeconds);
    }

    public function test_does_not_extend_runtime_exception(): void
    {
        $e = new RetryableHttpException(500, 'test');

        $this->assertInstanceOf(\Exception::class, $e);
        $this->assertSame(\Exception::class, get_parent_class($e));
    }
}
