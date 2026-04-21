<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Value;

final readonly class WebSearchResponse
{
    /**
     * @param  list<WebSearchHit>  $results
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $provider,
        public string $query,
        public array $results,
        public ?string $answer = null,
        public array $meta = [],
    ) {}
}
