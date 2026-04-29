<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\UrlSafety;
use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebCrawlRequest;
use Kosmokrator\Web\WebCrawlResponse;
use Kosmokrator\Web\WebFetchRequest;
use Kosmokrator\Web\WebFetchResponse;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class FirecrawlProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'firecrawl';
    }

    public function label(): string
    {
        return 'Firecrawl';
    }

    public function supports(WebCapability $capability): bool
    {
        return in_array($capability, [WebCapability::Search, WebCapability::Fetch, WebCapability::Crawl], true);
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $data = $this->postJson($this->url('https://api.firecrawl.dev').'/v1/search', [
            'query' => $request->query,
            'limit' => max(1, min(20, $request->maxResults)),
        ], ['Authorization' => 'Bearer '.$this->requireApiKey()], $request->timeoutSeconds);

        $raw = is_array($data['data'] ?? null) ? $data['data'] : (is_array($data['results'] ?? null) ? $data['results'] : []);
        $results = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $results[] = new WebSearchItem(
                title: $this->string($item, 'title', $this->string($item, 'url')),
                url: $this->string($item, 'url'),
                snippet: $this->string($item, 'description', $this->string($item, 'markdown')),
            );
        }

        return new WebSearchResponse($this->name(), $request->query, $results, metadata: ['raw' => $data]);
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        UrlSafety::assertSafe($request->url);
        $data = $this->postJson($this->url('https://api.firecrawl.dev').'/v1/scrape', [
            'url' => $request->url,
            'formats' => [$request->format === 'html' ? 'html' : 'markdown'],
        ], ['Authorization' => 'Bearer '.$this->requireApiKey()], $request->timeoutSeconds);

        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $content = $request->format === 'html'
            ? $this->string($payload, 'html')
            : $this->string($payload, 'markdown', $this->string($payload, 'content'));

        return new WebFetchResponse($this->name(), $request->url, WebFormatter::limit($content, $request->outputLimitChars), $request->format, $this->string($payload, 'title') ?: null, ['metadata' => $payload['metadata'] ?? []]);
    }

    public function crawl(WebCrawlRequest $request): WebCrawlResponse
    {
        UrlSafety::assertSafe($request->url);
        $data = $this->postJson($this->url('https://api.firecrawl.dev').'/v1/crawl', array_filter([
            'url' => $request->url,
            'limit' => max(1, min(100, $request->maxPages)),
            'scrapeOptions' => ['formats' => ['markdown']],
            'prompt' => $request->instructions,
        ]), ['Authorization' => 'Bearer '.$this->requireApiKey()], $request->timeoutSeconds);

        $raw = is_array($data['data'] ?? null) ? $data['data'] : [];
        $pages = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $url = $this->string($metadata, 'sourceURL', $this->string($item, 'url', $request->url));
            $pages[] = new WebFetchResponse($this->name(), $url, WebFormatter::limit($this->string($item, 'markdown', $this->string($item, 'content')), $request->outputLimitChars), 'markdown', $this->string($metadata, 'title') ?: null);
        }

        return new WebCrawlResponse($this->name(), $request->url, $pages, ['raw' => $data]);
    }
}
