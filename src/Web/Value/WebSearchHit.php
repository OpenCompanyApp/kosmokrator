<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Value;

final readonly class WebSearchHit
{
    public function __construct(
        public string $title,
        public string $url,
        public string $snippet = '',
        public ?float $score = null,
        public ?string $publishedAt = null,
        public ?string $source = null,
    ) {}
}
