<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

/**
 * @param  list<WebSearchItem>  $results
 * @param  array<string, mixed>  $metadata
 */
final readonly class WebSearchResponse
{
    public function __construct(
        public string $provider,
        public string $query,
        public array $results,
        public ?string $answer = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'query' => $this->query,
            'answer' => $this->answer,
            'results' => array_map(static fn (WebSearchItem $result): array => $result->toArray(), $this->results),
            'metadata' => $this->metadata,
        ];
    }
}
