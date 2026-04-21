<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Value;

final readonly class WebSearchRequest
{
    /**
     * @param  list<string>  $allowedDomains
     * @param  list<string>  $blockedDomains
     */
    public function __construct(
        public string $query,
        public ?string $provider = null,
        public int $maxResults = 5,
        public array $allowedDomains = [],
        public array $blockedDomains = [],
        public string $searchDepth = 'basic',
        public bool $includeSnippets = true,
        public bool $includeAnswer = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCachePayload(): array
    {
        return [
            'query' => $this->query,
            'provider' => $this->provider,
            'max_results' => $this->maxResults,
            'allowed_domains' => $this->allowedDomains,
            'blocked_domains' => $this->blockedDomains,
            'search_depth' => $this->searchDepth,
            'include_snippets' => $this->includeSnippets,
            'include_answer' => $this->includeAnswer,
        ];
    }
}
