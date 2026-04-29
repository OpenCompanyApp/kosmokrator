<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class PerplexitySearchProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'perplexity';
    }

    public function label(): string
    {
        return 'Perplexity Search';
    }

    public function supports(WebCapability $capability): bool
    {
        return $capability === WebCapability::Search;
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $body = array_filter([
            'query' => $request->query,
            'max_results' => max(1, min(20, $request->maxResults)),
            'country' => $request->country,
            'search_language_filter' => $request->language !== null ? [$request->language] : null,
            'search_recency_filter' => $request->recency,
            'search_domain_filter' => $request->allowedDomains !== [] ? $request->allowedDomains : ($request->blockedDomains !== [] ? array_map(static fn (string $domain): string => '-'.$domain, $request->blockedDomains) : null),
            'max_tokens' => 10000,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $data = $this->postJson($this->url('https://api.perplexity.ai').'/search', $body, [
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
                snippet: $this->string($item, 'snippet'),
                publishedAt: $this->string($item, 'date') ?: null,
                metadata: ['last_updated' => $item['last_updated'] ?? null],
            );
        }

        return new WebSearchResponse($this->name(), $request->query, $results, metadata: ['id' => $data['id'] ?? null]);
    }
}
