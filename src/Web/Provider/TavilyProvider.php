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

final class TavilyProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'tavily';
    }

    public function label(): string
    {
        return 'Tavily';
    }

    public function supports(WebCapability $capability): bool
    {
        return in_array($capability, [WebCapability::Search, WebCapability::Fetch, WebCapability::Crawl], true);
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $data = $this->postJson($this->url('https://api.tavily.com').'/search', array_filter([
            'query' => $request->query,
            'max_results' => max(1, min(20, $request->maxResults)),
            'search_depth' => $request->mode === 'deep' ? 'advanced' : 'basic',
            'include_answer' => true,
            'include_raw_content' => false,
            'include_domains' => $request->allowedDomains,
            'exclude_domains' => $request->blockedDomains,
            'topic' => $request->recency === 'news' ? 'news' : 'general',
        ], static fn (mixed $value): bool => $value !== [] && $value !== null), [
            'Authorization' => 'Bearer '.$this->requireApiKey(),
        ], $request->timeoutSeconds);

        $results = [];
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $results[] = new WebSearchItem(
                title: $this->string($item, 'title', $this->string($item, 'url')),
                url: $this->string($item, 'url'),
                snippet: $this->string($item, 'content'),
                score: isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
                publishedAt: $this->string($item, 'published_date') ?: null,
                metadata: ['raw_content_available' => isset($item['raw_content'])],
            );
        }

        return new WebSearchResponse($this->name(), $request->query, $results, is_string($data['answer'] ?? null) ? WebFormatter::limit($data['answer'], $request->outputLimitChars) : null);
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        UrlSafety::assertSafe($request->url);
        $data = $this->postJson($this->url('https://api.tavily.com').'/extract', [
            'urls' => [$request->url],
            'extract_depth' => 'basic',
            'format' => $request->format,
        ], ['Authorization' => 'Bearer '.$this->requireApiKey()], $request->timeoutSeconds);

        $item = is_array(($data['results'] ?? [])[0] ?? null) ? $data['results'][0] : [];
        $content = $this->string($item, 'raw_content', $this->string($item, 'content'));

        return new WebFetchResponse($this->name(), $request->url, WebFormatter::limit($content, $request->outputLimitChars), $request->format, metadata: ['failed_results' => $data['failed_results'] ?? []]);
    }

    public function crawl(WebCrawlRequest $request): WebCrawlResponse
    {
        UrlSafety::assertSafe($request->url);
        $data = $this->postJson($this->url('https://api.tavily.com').'/crawl', array_filter([
            'url' => $request->url,
            'max_depth' => 2,
            'limit' => max(1, min(100, $request->maxPages)),
            'instructions' => $request->instructions,
        ]), ['Authorization' => 'Bearer '.$this->requireApiKey()], $request->timeoutSeconds);

        $pages = [];
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = $this->string($item, 'url');
            $pages[] = new WebFetchResponse($this->name(), $url, WebFormatter::limit($this->string($item, 'content', $this->string($item, 'raw_content')), $request->outputLimitChars), 'markdown', $this->string($item, 'title') ?: null);
        }

        return new WebCrawlResponse($this->name(), $request->url, $pages, ['raw' => $data]);
    }
}
