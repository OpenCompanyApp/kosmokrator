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
use Kosmokrator\Web\Extract\HtmlPageExtractor;
use Kosmokrator\Web\Provider\Fetch\DirectFetchProvider;
use Kosmokrator\Web\Safety\WebRequestGuard;
use Kosmokrator\Web\Value\WebFetchRequest;
use PHPUnit\Framework\TestCase;

final class DirectFetchProviderTest extends TestCase
{
    public function test_it_decodes_gzip_encoded_html_before_extraction(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
<head>
  <title>Example Docs</title>
  <meta name="description" content="Reference">
</head>
<body>
  <main>
    <h1>Authentication</h1>
    <p>Token auth details</p>
  </main>
</body>
</html>
HTML;

        $provider = new DirectFetchProvider(
            new WebRequestGuard,
            new HtmlPageExtractor,
            httpClient: new HttpClient(new class(gzencode($html, 9)) implements DelegateHttpClient
            {
                public function __construct(private readonly string $body) {}

                public function request(Request $request, Cancellation $cancellation): Response
                {
                    return new Response(
                        '2',
                        HttpStatus::OK,
                        'OK',
                        [
                            'content-type' => 'text/html; charset=utf-8',
                            'content-encoding' => 'gzip',
                        ],
                        new ReadableBuffer($this->body),
                        $request,
                    );
                }
            }, []),
        );

        $response = $provider->fetch(new WebFetchRequest(
            url: 'https://example.com/docs',
            provider: 'direct',
            mode: 'main',
        ));

        self::assertSame('Example Docs', $response->title);
        self::assertStringContainsString('Token auth details', $response->content);
        self::assertSame('Reference', $response->metadata['description']);
    }
}
