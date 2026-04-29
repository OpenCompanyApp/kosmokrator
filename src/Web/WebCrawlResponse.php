<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

/**
 * @param  list<WebFetchResponse>  $pages
 * @param  array<string, mixed>  $metadata
 */
final readonly class WebCrawlResponse
{
    public function __construct(
        public string $provider,
        public string $url,
        public array $pages,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'url' => $this->url,
            'pages' => array_map(static fn (WebFetchResponse $page): array => $page->toArray(), $this->pages),
            'metadata' => $this->metadata,
        ];
    }
}
