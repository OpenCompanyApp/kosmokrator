<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class BraveProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'brave';
    }

    public function label(): string
    {
        return 'Brave Search';
    }

    public function supports(WebCapability $capability): bool
    {
        return $capability === WebCapability::Search;
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $query = http_build_query(array_filter([
            'q' => $request->query,
            'count' => max(1, min(20, $request->maxResults)),
            'country' => $request->country,
            'search_lang' => $request->language,
            'freshness' => $request->recency,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $data = $this->getJson($this->url('https://api.search.brave.com').'/res/v1/web/search?'.$query, [
            'X-Subscription-Token' => $this->requireApiKey(),
        ], $request->timeoutSeconds);

        $results = [];
        foreach (is_array(($data['web'] ?? [])['results'] ?? null) ? $data['web']['results'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $results[] = new WebSearchItem(
                title: $this->string($item, 'title', $this->string($item, 'url')),
                url: $this->string($item, 'url'),
                snippet: strip_tags($this->string($item, 'description')),
                publishedAt: $this->string($item, 'age') ?: null,
            );
        }

        return new WebSearchResponse($this->name(), $request->query, $results, metadata: ['query' => $data['query'] ?? []]);
    }
}
