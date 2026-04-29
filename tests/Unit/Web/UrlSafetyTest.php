<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Web;

use Kosmokrator\Web\UrlSafety;
use Kosmokrator\Web\WebProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlSafetyTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(WebProviderException::class);

        UrlSafety::assertSafe($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'credentialed URL' => ['https://user:pass@example.com'];
        yield 'localhost' => ['https://localhost/'];
        yield 'loopback ip' => ['https://127.0.0.1/'];
        yield 'metadata ip' => ['http://169.254.169.254/latest/meta-data'];
        yield 'private ip' => ['http://10.0.0.1/'];
        yield 'cgnat ip' => ['http://100.64.0.1/'];
    }
}
