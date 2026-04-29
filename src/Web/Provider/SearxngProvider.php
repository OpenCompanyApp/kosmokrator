<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebProviderException;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class SearxngProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'searxng';
    }

    public function label(): string
    {
        return 'SearXNG';
    }

    public function supports(WebCapability $capability): bool
    {
        return $capability === WebCapability::Search;
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        if ($this->baseUrl === null || $this->baseUrl === '') {
            throw new WebProviderException('SearXNG base URL is not configured.');
        }

        $query = http_build_query([
            'q' => $request->query,
            'format' => 'json',
            'language' => $request->language ?: 'auto',
        ]);
        $data = $this->getJson($this->url($this->baseUrl).'/search?'.$query, [], $request->timeoutSeconds);

        $results = [];
        foreach (array_slice(is_array($data['results'] ?? null) ? $data['results'] : [], 0, $request->maxResults) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $results[] = new WebSearchItem(
                title: $this->string($item, 'title', $this->string($item, 'url')),
                url: $this->string($item, 'url'),
                snippet: $this->string($item, 'content'),
                score: isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
                metadata: ['engine' => $item['engine'] ?? null],
            );
        }

        return new WebSearchResponse($this->name(), $request->query, $results, metadata: ['answers' => $data['answers'] ?? []]);
    }
}
