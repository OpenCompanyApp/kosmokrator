<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider\Fetch;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Kosmokrator\Web\Contracts\WebFetchProvider;
use Kosmokrator\Web\Exception\WebFetchPermanentException;
use Kosmokrator\Web\Extract\HtmlPageExtractor;
use Kosmokrator\Web\Safety\WebRequestGuard;
use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebFetchResponse;

final class DirectFetchProvider implements WebFetchProvider
{
    private readonly HttpClient $httpClient;

    public function __construct(
        private readonly WebRequestGuard $guard,
        private readonly HtmlPageExtractor $extractor,
        private readonly int $defaultTimeout = 20,
        private readonly int $maxBytes = 10_485_760,
        ?HttpClient $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClientBuilder::buildDefault();
    }

    public function id(): string
    {
        return 'direct';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        $this->guard->assertSafePublicUrl($request->url);

        $httpRequest = new Request($request->url, 'GET');
        $httpRequest->setHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36');
        $httpRequest->setHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.7');
        $httpRequest->setHeader('Accept-Language', 'en-US,en;q=0.9');
        $httpRequest->setHeader('Accept-Encoding', 'gzip, deflate');
        $httpRequest->setHeader('Cache-Control', 'no-cache');
        $httpRequest->setHeader('Pragma', 'no-cache');
        $httpRequest->setHeader('Upgrade-Insecure-Requests', '1');
        $httpRequest->setTransferTimeout($request->timeout ?? $this->defaultTimeout);
        $httpRequest->setInactivityTimeout($request->timeout ?? $this->defaultTimeout);

        $response = $this->httpClient->request($httpRequest);
        $status = $response->getStatus();
        $body = $response->getBody()->buffer();
        $contentType = strtolower(trim(explode(';', $response->getHeader('content-type') ?? 'text/plain')[0]));
        $contentEncoding = strtolower(trim($response->getHeader('content-encoding') ?? ''));
        $finalUrl = (string) $response->getRequest()->getUri();

        if (strlen($body) > $this->maxBytes) {
            throw new \RuntimeException('Fetched page exceeds the maximum allowed size.');
        }

        if ($status < 200 || $status >= 300) {
            $exceptionClass = $this->isPermanentStatus($status)
                ? WebFetchPermanentException::class
                : \RuntimeException::class;

            throw new $exceptionClass("Direct fetch failed ({$status}) for {$request->url}.");
        }

        $body = $this->decodeBody($body, $contentEncoding);

        if (str_contains($contentType, 'html') || $contentType === 'application/xhtml+xml') {
            $page = $this->extractor->extract($body, $finalUrl);

            return new WebFetchResponse(
                provider: $this->id(),
                url: $request->url,
                finalUrl: $finalUrl,
                statusCode: $status,
                contentType: $contentType,
                format: $request->format,
                title: $page->title,
                metadata: $page->metadata,
                outline: $page->outline,
                sections: $page->sections,
                content: $page->fullContent,
                rawHtml: $body,
                extractionMethod: 'html_dom',
                meta: ['bytes' => strlen($body)],
            );
        }

        if (str_starts_with($contentType, 'text/')) {
            $content = trim(mb_convert_encoding($body, 'UTF-8', 'UTF-8'));

            return new WebFetchResponse(
                provider: $this->id(),
                url: $request->url,
                finalUrl: $finalUrl,
                statusCode: $status,
                contentType: $contentType,
                format: 'text',
                title: null,
                metadata: [],
                outline: [],
                sections: ['full' => $content],
                content: $content,
                rawHtml: null,
                extractionMethod: 'plain_text',
                meta: ['bytes' => strlen($body)],
            );
        }

        throw new \RuntimeException("Unsupported content type for direct fetch: {$contentType}");
    }

    private function decodeBody(string $body, string $contentEncoding): string
    {
        if ($contentEncoding === '' || $contentEncoding === 'identity') {
            return $body;
        }

        return match ($contentEncoding) {
            'gzip', 'x-gzip' => $this->decodeGzip($body),
            'deflate' => $this->decodeDeflate($body),
            default => throw new \RuntimeException("Unsupported content encoding for direct fetch: {$contentEncoding}"),
        };
    }

    private function decodeGzip(string $body): string
    {
        $decoded = gzdecode($body);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode gzip-compressed web response.');
        }

        return $decoded;
    }

    private function decodeDeflate(string $body): string
    {
        $decoded = zlib_decode($body);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode deflate-compressed web response.');
        }

        return $decoded;
    }

    private function isPermanentStatus(int $status): bool
    {
        return $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true);
    }
}
