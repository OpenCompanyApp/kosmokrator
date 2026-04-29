<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

/**
 * @param  list<string>  $allowedDomains
 * @param  list<string>  $blockedDomains
 */
final readonly class WebSearchRequest
{
    public function __construct(
        public string $query,
        public int $maxResults = 8,
        public int $timeoutSeconds = 30,
        public int $outputLimitChars = 60000,
        public ?string $mode = null,
        public ?string $country = null,
        public ?string $language = null,
        public ?string $recency = null,
        public array $allowedDomains = [],
        public array $blockedDomains = [],
    ) {}
}
