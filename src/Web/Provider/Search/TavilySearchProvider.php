<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider\Search;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Kosmokrator\Web\Contracts\WebSearchProvider;
use Kosmokrator\Web\Value\WebSearchHit;
use Kosmokrator\Web\Value\WebSearchRequest;
use Kosmokrator\Web\Value\WebSearchResponse;

final class TavilySearchProvider implements WebSearchProvider
{
    private readonly HttpClient $httpClient;

    public function __construct(
        private readonly ?string $apiKey,
    ) {
        $this->httpClient = HttpClientBuilder::buildDefault();
    }

    public function id(): string
    {
        return 'tavily';
    }

    public function isAvailable(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('Tavily is not configured.');
        }

        $payload = [
            'api_key' => $this->apiKey,
            'query' => $request->query,
            'max_results' => max(1, min(10, $request->maxResults)),
            'search_depth' => $request->searchDepth === 'advanced' ? 'advanced' : 'basic',
            'include_answer' => $request->includeAnswer,
            'include_raw_content' => false,
        ];

        if ($request->allowedDomains !== []) {
            $payload['include_domains'] = $request->allowedDomains;
        }

        if ($request->blockedDomains !== []) {
            $payload['exclude_domains'] = $request->blockedDomains;
        }

        $httpRequest = new Request('https://api.tavily.com/search', 'POST');
        $httpRequest->setHeader('Content-Type', 'application/json');
        $httpRequest->setBody(json_encode($payload, JSON_THROW_ON_ERROR));
        $httpRequest->setTransferTimeout(30);
        $httpRequest->setInactivityTimeout(30);

        $response = $this->httpClient->request($httpRequest);
        $status = $response->getStatus();
        $body = $response->getBody()->buffer();
        $data = json_decode($body, true);

        if ($status !== 200 || ! is_array($data)) {
            $message = is_array($data) ? ($data['error'] ?? $body) : $body;
            throw new \RuntimeException("Tavily search failed ({$status}): {$message}");
        }

        $results = [];
        foreach (($data['results'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $results[] = new WebSearchHit(
                title: (string) ($item['title'] ?? $item['url'] ?? 'Untitled result'),
                url: (string) ($item['url'] ?? ''),
                snippet: (string) ($item['content'] ?? ''),
                score: isset($item['score']) ? (float) $item['score'] : null,
                publishedAt: isset($item['published_date']) ? (string) $item['published_date'] : null,
                source: 'tavily',
            );
        }

        return new WebSearchResponse(
            provider: $this->id(),
            query: $request->query,
            results: $results,
            answer: isset($data['answer']) && is_string($data['answer']) ? $data['answer'] : null,
            meta: [
                'cache_hit' => false,
            ],
        );
    }
}
