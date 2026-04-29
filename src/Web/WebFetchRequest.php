<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

final readonly class WebFetchRequest
{
    public function __construct(
        public string $url,
        public string $format = 'markdown',
        public int $timeoutSeconds = 30,
        public int $outputLimitChars = 100000,
    ) {}
}
