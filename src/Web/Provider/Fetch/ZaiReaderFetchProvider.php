<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider\Fetch;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Kosmokrator\LLM\ProviderAuthService;
use Kosmokrator\Web\Contracts\WebFetchProvider;
use Kosmokrator\Web\Extract\MarkdownPageExtractor;
use Kosmokrator\Web\Safety\WebRequestGuard;
use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebFetchResponse;

final class ZaiReaderFetchProvider implements WebFetchProvider
{
    private readonly HttpClient $httpClient;

    public function __construct(
        private readonly ProviderAuthService $auth,
        private readonly WebRequestGuard $guard,
        private readonly MarkdownPageExtractor $extractor,
        private readonly string $baseUrl = 'https://api.z.ai/api/coding/paas/v4',
        private readonly ?string $apiKeyOverride = null,
        private readonly int $defaultTimeout = 20,
        ?HttpClient $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClientBuilder::buildDefault();
    }

    public function id(): string
    {
        return 'zai';
    }

    public function isAvailable(): bool
    {
        return $this->resolveApiKey() !== '';
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('Z.AI web reader is not configured.');
        }

        $this->guard->assertSafePublicUrl($request->url);

        $payload = [
            'url' => $request->url,
            'timeout' => $request->timeout ?? $this->defaultTimeout,
            'return_format' => $request->format === 'text' ? 'text' : 'markdown',
            'no_cache' => false,
            'retain_images' => true,
            'with_links_summary' => true,
        ];

        $httpRequest = new Request(rtrim($this->baseUrl, '/').'/reader', 'POST');
        $httpRequest->setHeader('Authorization', 'Bearer '.$apiKey);
        $httpRequest->setHeader('Content-Type', 'application/json');
        $httpRequest->setBody(json_encode($payload, JSON_THROW_ON_ERROR));
        $httpRequest->setTransferTimeout($request->timeout ?? $this->defaultTimeout);
        $httpRequest->setInactivityTimeout($request->timeout ?? $this->defaultTimeout);

        $response = $this->httpClient->request($httpRequest);
        $status = $response->getStatus();
        $body = $response->getBody()->buffer();
        $data = json_decode($body, true);

        if ($status !== 200 || ! is_array($data)) {
            $message = is_array($data) ? json_encode($data, JSON_UNESCAPED_SLASHES) : $body;
            throw new \RuntimeException("Z.AI reader failed ({$status}): {$message}");
        }

        $reader = $data['reader_result'] ?? null;
        if (! is_array($reader)) {
            throw new \RuntimeException('Z.AI reader returned no reader_result payload.');
        }

        $content = trim((string) ($reader['content'] ?? ''));
        $title = $this->nullableString($reader['title'] ?? null);
        $finalUrl = $this->nullableString($reader['url'] ?? null) ?? $request->url;
        $description = $this->nullableString($reader['description'] ?? null);
        $metadata = is_array($reader['metadata'] ?? null) ? $reader['metadata'] : [];
        if ($description !== null && ! isset($metadata['description'])) {
            $metadata['description'] = $description;
        }
        if ($title !== null && ! isset($metadata['title'])) {
            $metadata['title'] = $title;
        }
        if (! isset($metadata['canonical_url'])) {
            $metadata['canonical_url'] = $finalUrl;
        }

        $page = $this->extractor->extract($content, $title, $metadata);

        return new WebFetchResponse(
            provider: $this->id(),
            url: $request->url,
            finalUrl: $finalUrl,
            statusCode: $status,
            contentType: 'text/markdown',
            format: $request->format === 'text' ? 'text' : 'markdown',
            title: $page->title,
            metadata: $page->metadata,
            outline: $page->outline,
            sections: $page->sections,
            content: $page->fullContent,
            rawHtml: null,
            extractionMethod: 'zai_reader',
            meta: [
                'transport' => 'rest',
                'model' => $this->nullableString($data['model'] ?? null),
                'request_id' => $this->nullableString($data['request_id'] ?? null),
            ],
        );
    }

    private function resolveApiKey(): string
    {
        $key = $this->apiKeyOverride;
        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }

        return trim($this->auth->apiKey('z'));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
