<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\UrlSafety;
use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebFetchRequest;
use Kosmokrator\Web\WebFetchResponse;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class ParallelProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'parallel';
    }

    public function label(): string
    {
        return 'Parallel';
    }

    public function supports(WebCapability $capability): bool
    {
        return in_array($capability, [WebCapability::Search, WebCapability::Fetch], true);
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $data = $this->postJson($this->url('https://api.parallel.ai').'/v1beta/search', [
            'search_queries' => [$request->query],
            'objective' => $request->query,
            'processor' => $request->mode === 'deep' ? 'pro' : 'base',
            'max_results' => max(1, min(20, $request->maxResults)),
        ], ['x-api-key' => $this->requireApiKey()], $request->timeoutSeconds);

        $raw = is_array($data['results'] ?? null) ? $data['results'] : (is_array($data['web'] ?? null) ? $data['web'] : []);
        $results = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $results[] = new WebSearchItem(
                title: $this->string($item, 'title', $this->string($item, 'url')),
                url: $this->string($item, 'url'),
                snippet: $this->string($item, 'description', $this->string($item, 'snippet')),
            );
        }

        return new WebSearchResponse($this->name(), $request->query, $results, metadata: ['raw' => $data]);
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        UrlSafety::assertSafe($request->url);
        $data = $this->postJson($this->url('https://api.parallel.ai').'/v1beta/extract', [
            'urls' => [$request->url],
            'full_content' => true,
        ], ['x-api-key' => $this->requireApiKey()], $request->timeoutSeconds);

        $item = is_array(($data['results'] ?? [])[0] ?? null) ? $data['results'][0] : $data;
        $content = $this->string($item, 'content', $this->string($item, 'text', $this->string($item, 'markdown')));

        return new WebFetchResponse($this->name(), $request->url, WebFormatter::limit($content, $request->outputLimitChars), $request->format, $this->string($item, 'title') ?: null, ['raw' => $data]);
    }
}
