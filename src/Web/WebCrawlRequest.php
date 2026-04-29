<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

final readonly class WebCrawlRequest
{
    public function __construct(
        public string $url,
        public int $maxPages = 20,
        public int $timeoutSeconds = 60,
        public int $outputLimitChars = 100000,
        public ?string $instructions = null,
    ) {}
}
