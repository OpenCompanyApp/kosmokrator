<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebCrawlRequest;
use Kosmokrator\Web\WebCrawlResponse;
use Kosmokrator\Web\WebFetchRequest;
use Kosmokrator\Web\WebFetchResponse;
use Kosmokrator\Web\WebProviderException;
use Kosmokrator\Web\WebProviderInterface;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

abstract class AbstractHttpWebProvider implements WebProviderInterface
{
    protected HttpClient $http;

    public function __construct(
        protected readonly string $apiKey = '',
        protected readonly ?string $baseUrl = null,
    ) {
        $this->http = HttpClientBuilder::buildDefault();
    }

    public function supports(WebCapability $capability): bool
    {
        return false;
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        throw new WebProviderException($this->label().' does not support web search.');
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        throw new WebProviderException($this->label().' does not support external fetch/extract.');
    }

    public function crawl(WebCrawlRequest $request): WebCrawlResponse
    {
        throw new WebProviderException($this->label().' does not support crawl.');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $body, array $headers = [], int $timeoutSeconds = 30): array
    {
        $request = new Request($url, 'POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        $request->setBody(json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $request->setTransferTimeout($timeoutSeconds);
        $request->setInactivityTimeout($timeoutSeconds);

        return $this->decodeJsonResponse($request);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function getText(string $url, array $headers = [], int $timeoutSeconds = 30): string
    {
        $request = new Request($url, 'GET');
        $request->setHeader('Accept', 'text/markdown, text/plain, application/json;q=0.9, */*;q=0.1');
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        $request->setTransferTimeout($timeoutSeconds);
        $request->setInactivityTimeout($timeoutSeconds);

        $response = $this->http->request($request);
        $status = $response->getStatus();
        $body = $response->getBody()->buffer();
        if ($status < 200 || $status >= 300) {
            throw new WebProviderException("{$this->name()} request failed with HTTP {$status}: ".substr($body, 0, 1000));
        }

        return mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJson(string $url, array $headers = [], int $timeoutSeconds = 30): array
    {
        $request = new Request($url, 'GET');
        $request->setHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        $request->setTransferTimeout($timeoutSeconds);
        $request->setInactivityTimeout($timeoutSeconds);

        return $this->decodeJsonResponse($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(Request $request): array
    {
        $response = $this->http->request($request);
        $status = $response->getStatus();
        $body = $response->getBody()->buffer();
        if ($status < 200 || $status >= 300) {
            throw new WebProviderException("{$this->name()} request failed with HTTP {$status}: ".substr($body, 0, 1000));
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new WebProviderException("{$this->name()} returned invalid JSON.");
        }

        return $decoded;
    }

    protected function requireApiKey(): string
    {
        if ($this->apiKey === '') {
            throw new WebProviderException($this->label().' API key is not configured.');
        }

        return $this->apiKey;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function string(array $data, string $key, string $fallback = ''): string
    {
        $value = $data[$key] ?? $fallback;

        return is_scalar($value) || $value === null ? (string) $value : $fallback;
    }

    protected function url(string $default): string
    {
        return rtrim($this->baseUrl ?: $default, '/');
    }
}
